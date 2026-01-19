<?php
// 這個檔案將負責新增單筆子交易，專供 iPhone 捷徑使用。
// 接收參數：email, password, t_id (主交易類型), transaction_date, ps, 
//           accountId, categoryId, currency, amount, addMinus

    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    include("functions.php"); 

    header('Content-Type: application/json'); // 統一返回 JSON 響應

    $db = null;
    $userId = 0;
    $ck_sql = ''; // 用於 catch 時知道是在哪行的 SQL
    $debugInfo = ''; // 用於儲存 PDO 參數調試信息

    // --- 1. 驗證使用者 (使用 POST 參數傳遞 Email 和 Password) ---
    $json_input = file_get_contents('php://input');
    $data = json_decode($json_input, true); // 解碼為關聯陣列

    if ($data === null) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input.']);
        exit();
    }
    
    // 從輸入中獲取驗證資訊
    $userEmail = isset($data['email']) ? trim($data['email']) : '';
    $userPwd = isset($data['password']) ? $data['password'] : '';

    if (empty($userEmail) || empty($userPwd)) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'NoUser: Email 或 Password 欄位缺失。']);
        exit();
    }

    try {
        $db = openDB($db_server, $db_name, $db_user, $db_passwd);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db_log = openDB($db_server, $db_name, $db_user, $db_passwd);
        $db_log->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // 2. 設定自動提交模式
        $db_log->setAttribute(PDO::ATTR_AUTOCOMMIT, true);

        // 驗證使用者並取得 userId
        $userInfo = checkuserAccount($db, $userEmail, $userPwd); 

        if (!$userInfo || !isset($userInfo['userId']) || (int)$userInfo['userId'] <= 0) {
            add_log($db_log,$userId,-1,"phone-addTransactions.php","登入驗證失敗，{$userInfo}");
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => '使用者驗證失敗或無效。']);
            exit();
        }

        $userId = (int)$userInfo['userId'];
        add_log($db_log,$userId,1,"phone-addTransactions.php","驗證成功，使用者{$userInfo['username']}");
        
        // --- 2. 獲取單一子交易的數據並準備 ---
        $transactionTypeId = isset($data['t_id']) ? (int)$data['t_id'] : 0;
        $transactionDate = date('Y-m-d H:i:s'); 
        $ps = isset($data['ps']) ? trim($data['ps']) : '';

        // 取得單一子交易所需的所有欄位 (從 JSON 根部)
        $sub_accountId = isset($data['account_id']) ? (int)$data['account_id'] : 0;
        $sub_categoryId = isset($data['category_id']) ? (int)$data['category_id'] : 0;
        $sub_currency = $userInfo['useCurrency']; // 
        $sub_amount = isset($data['amount']) ? (float)$data['amount'] : 0.00;
        $sub_add_minus = $transactionTypeId == 5 ? -1 : 1;

        // 檢查關鍵欄位
        if ($transactionTypeId <= 0 || $sub_accountId <= 0 || $sub_categoryId <= 0 || $sub_amount <= 0 || ($sub_add_minus !== 1 && $sub_add_minus !== -1)) {
            add_log($db_log,$userId,-1,"phone-addTransactions.php","檢查關鍵欄位有缺，請查看：{$data}");
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid or missing transaction fields for single sub-transaction.']);
            exit();
        }
        
        // 將單筆資料包裝成一個只包含單一元素的陣列，以便兼容後續邏輯
        $subTransactions = [[
            'account_id' => $sub_accountId,
            'category_id' => $sub_categoryId,
            'currency' => $sub_currency,
            'amount' => $sub_amount,
            'add_minus' => $sub_add_minus,
        ]];
        
        // **開始事務處理**
        $db->beginTransaction(); 

        // 1. 插入 `transactions` 主表數據
        $insertMainSQL = "INSERT INTO `transactions`( `userId`, `transactionTypeId`, `transactionDate`, `createdAt`, `updatedAt`, `ps`)
                         VALUES (:userId, :transactionTypeId, :transactionDate, NOW(), NOW(), :ps)";
        $ck_sql = $insertMainSQL;
        $stmtMain = $db->prepare($insertMainSQL);
        $stmtMain->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmtMain->bindParam(':transactionTypeId', $transactionTypeId, PDO::PARAM_INT);
        $stmtMain->bindParam(':transactionDate', $transactionDate, PDO::PARAM_STR);
        $stmtMain->bindParam(':ps', $ps, PDO::PARAM_STR);
        $stmtMain->execute();

        // 2. 獲取剛剛插入的 `t_id`
        $t_id = $db->lastInsertId();
        add_log($db_log,$userId,1,"phone-addTransactions.php","新增主交易成功，t_id={$t_id}");

        // 3. 遍歷 `subTransactions` (這裡只有一筆)
        $insertSubSQL = "INSERT INTO `transactions_sub`( `t_id`, `account_id`,`userId`, `categoryId`, `currency`, `amount`, `add_minus`,`bill_id`)
                         VALUES (:t_id, :account_id, :userId, :categoryId, :currency, :amount, :add_minus, 0)";
        $ck_sql = $insertSubSQL;
        $stmtSub = $db->prepare($insertSubSQL);

        $total = 0; // 總計，用於計算加減後帳戶餘額
        $total_sec = 0; // 總計，用於計算第二帳戶的餘額 (用於轉帳等)

        $use_accountId = -1; // 主使用帳戶
        $in_accountId = -1; // 轉帳、應收款項、應付款項 入帳帳戶id

        $arr_upd_balance_history = [];
        
        foreach ($subTransactions as $sub) {
            $sub_accountId = $sub['account_id'];
            
            // 由於只有單筆，直接處理 balance history
            $arr_upd_balance_history [] = ['account_id' => $sub_accountId, 't_id' => $t_id];
            
            $sub_categoryId = $sub['category_id'];
            $sub_currency = $sub['currency'];
            $sub_amount = $sub['amount'];
            $sub_add_minus = $sub['add_minus'];

            add_log($db_log,$userId,1,"phone-addTransactions.php","子交易新增完畢，內容：
                                        t_id:{$t_id}
                                        account_id:{$sub_accountId}
                                        userId:{$userId}
                                        sub_categoryId:{$sub_categoryId}
                                        sub_currency:{$sub_currency}
                                        sub_amount:{$sub_amount}
                                        sub_add_minus:{$sub_add_minus}");

            $stmtSub->bindParam(':t_id', $t_id, PDO::PARAM_INT);
            $stmtSub->bindParam(':account_id', $sub_accountId, PDO::PARAM_INT);
            $stmtSub->bindParam(':userId', $userId, PDO::PARAM_INT);
            $stmtSub->bindParam(':categoryId', $sub_categoryId, PDO::PARAM_INT);
            $stmtSub->bindParam(':currency', $sub_currency, PDO::PARAM_STR);
            $stmtSub->bindParam(':amount', $sub_amount, PDO::PARAM_STR);
            $stmtSub->bindParam(':add_minus', $sub_add_minus, PDO::PARAM_INT);
            
            // 執行子表插入
            $stmtSub->execute();
            
            // 以下是原有的複雜餘額計算邏輯（適用於轉帳/收付款項）
            if ($transactionTypeId == 9) {
                // 若是進行“應付款項”交易便進行更新帳單 (假設 updateBillPaidAmount 存在)
                // updateBillPaidAmount($db, $sub_accountId, $sub_amount);
            }

            if ($transactionTypeId == 5 || $transactionTypeId == 6) { // 單一帳戶影響
                $use_accountId = $sub_accountId;
                $total = $total + ($sub_amount * $sub_add_minus);
            } else { // 轉帳、應收、應付等（雙帳戶影響）
                // 這裡的邏輯原本是為了處理多子項中的轉帳（例如：支出 $100，其中 $50 是轉帳給另一帳戶）
                // 在單筆交易模式下，若 t_id != 5/6，且沒有第二筆子交易 (category_id = -20)，
                // 則邏輯會不完整，但我們仍保留您的原始計算結構。
                
                if ($sub_categoryId == -20) {
                    $in_accountId = $sub_accountId;
                    $total_sec = $total_sec + ($sub_amount * $sub_add_minus);
                } else {
                    $use_accountId = $sub_accountId;
                    $total = $total + ($sub_amount * $sub_add_minus);
                }
            }
        }
        
        // --- 4. 更新帳戶餘額並提交事務 ---
        
        if ($transactionTypeId == 5 || $transactionTypeId == 6) { // 單影響帳戶
            add_log($db_log,$userId,1,"phone-addTransactions.php","更新帳戶餘額  start");
            $atSQL="SELECT `accountId`,`currentBalance`,`add_minus`,`accountTypeId` FROM `accountTable` WHERE `userId` = ? AND `accountId`= ? ";
            $ck_sql = $atSQL;
            $stmtAt = $db->prepare($atSQL);
            $stmtAt->execute([$userId, $use_accountId]);
            $row = $stmtAt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $db->rollBack(); 
                add_log($db_log,$userId,-1,"phone-addTransactions.php","更新帳戶餘額時沒查到指定帳戶，userId:{$userId};accountId:{$use_accountId}");
                $db_log=null;
                echo json_encode(['status' => 'error', 'message' => '新增失敗，沒查到指定帳戶', 'account_id' => $use_accountId]);
                exit();
            }
            
            $new_currentBalance = (float)$row['currentBalance'] + ($total * (int)$row['add_minus']);
            $updateSQL = "UPDATE `accountTable` SET `currentBalance`=? WHERE `accountId` = ? AND `userId` = ?;";
            $stmt = $db->prepare($updateSQL);
            $stmt->execute([$new_currentBalance, $use_accountId, $userId]);
            add_log($db_log,$userId,1,"phone-addTransactions.php","目前餘額：{$row['currentBalance']}，交易金額：{$total}，帳戶價值：{$row['add_minus']}，交易後餘額：{$new_currentBalance}");

            if ((int)$row['accountTypeId'] == 11) {
                // 如果非信用卡繳費將更新 (這段邏輯應根據您的業務規則保留或刪除)
                $updateSQL = "UPDATE `transactions_sub` SET `bill_id`='0' WHERE `t_id` = ? AND `userId` = ?;";
                $stmt = $db->prepare($updateSQL);
                $stmt->execute([$t_id, $userId]);
            }
            
            // 提交事務
            $db->commit();

            // 更新歷史餘額 (假設 update_balance_history 存在)
            // foreach($arr_upd_balance_history as $ob){ update_balance_history($db,$userId,$ob['account_id'],$ob['t_id']); }

            add_log($db_log,$userId,1,"phone-addTransactions.php","更新餘額完畢");
            echo json_encode(['status' => 'success', 'message' => '單筆交易記錄成功新增', 't_id' => $t_id]);
            
        } else { // 轉帳等雙影響帳戶的邏輯（請注意：這需要捷徑端傳遞兩組子交易資料）
            // 這裡的邏輯需要 t_id != 5/6 且 $in_accountId, $use_accountId 均有效。
            // 由於捷徑只傳遞單筆資料，此處邏輯可能在單筆模式下無法完整運作。
            // 若要支援轉帳，請確保捷徑端傳遞兩筆子交易資料：一筆出帳、一筆入帳(category_id=-20)。
            
            $db->rollBack(); // 單筆模式下無法完整運作雙帳戶影響邏輯，故回滾
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => '交易類型非單純收支，需要雙筆子交易資料，請使用標準網頁介面或修改捷徑傳輸格式。',
                'transactionTypeId' => $transactionTypeId
            ]);
        }

    } catch (PDOException $e) {
        // error_log("Database Error in addSingleTransaction.php: " . $e->getMessage() . " --- SQLSTATE: " . $e->getCode());
        if($db!=null){ $db->rollBack(); }
        add_log($db_log,$userId,-1,"phone-addTransactions.php","資料庫操作失敗， " . $e->getMessage() . " --- SQLSTATE: " . $e->getCode());
        $db_log=null;
        http_response_code(500);
        // echo json_encode(['status' => 'error', 'message' => '資料庫操作失敗: ' . $e->getMessage(), 'sqlstate' => $e->getCode()]);
    } catch (Exception $e) {
        if($db!=null){ $db->rollBack(); }
        // error_log("General Error in addSingleTransaction.php: " . $e->getMessage());
        add_log($db_log,$userId,-1,"phone-addTransactions.php","一般伺服器錯誤: ". $e->getMessage());
        $db_log=null;
        http_response_code(500);
        // echo json_encode(['status' => 'error', 'message' => '一般伺服器錯誤: ' . $e->getMessage()]);
    } finally {
        $db = null;
        $db_log = null;
    }
?>