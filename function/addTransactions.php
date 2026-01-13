<?php
    error_reporting(E_ALL);
	ini_set('display_errors', 1);
    include("functions.php"); // 確保這裡包含了 session_start() 和 openDB()

    header('Content-Type: application/json'); // 統一返回 JSON 響應

    // 檢查使用者是否已登入
    if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'NoUser: Session userId not found or invalid. Please log in.']);
        exit(); 
    }

    $userId = $_SESSION['userId'];

    // 獲取原始 JSON 輸入
    $json_input = file_get_contents('php://input');
    $data = json_decode($json_input, true); // 解碼為關聯陣列

    // 檢查 JSON 解析是否成功
    if ($data === null) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input.']);
        exit();
    }

    // 獲取 transactions 主表的數據
    $transactionTypeId = isset($data['t_id']) ? (int)$data['t_id'] : 0;
    $transactionDate = isset($data['transaction_date']) ? $data['transaction_date'] : date('Y-m-d'); // 預設今天
    $ps = isset($data['ps']) ? trim($data['ps']) : ''; 

    // $account_id = isset($data['account_id']) ? (int)$data['account_id'] : 0;
    $subTransactions = isset($data['sub_transactions']) ? $data['sub_transactions'] : [];

    // 基本數據驗證
    if ($transactionTypeId <= 0 || !is_array($subTransactions) || count($subTransactions) === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid transaction type or empty sub_transactions.']);
        exit();
    }

    $ck_sql='';//用於catch時知道是在哪行的sql
    try {
        $db = openDB($db_server, $db_name, $db_user, $db_passwd);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); 
        $db->beginTransaction(); // **開始事務處理：確保主表和所有子表記錄同時成功或同時失敗**
        $db_log = openDB($db_server, $db_name, $db_user, $db_passwd);
        $db_log->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // 2. 設定自動提交模式
        $db_log->setAttribute(PDO::ATTR_AUTOCOMMIT, true);

        // 1. 插入 `transactions` 主表數據
        $insertMainSQL = "INSERT INTO `transactions`( `userId`, `transactionTypeId`, `transactionDate`, `createdAt`, `updatedAt`, `ps`) 
                           VALUES (:userId, :transactionTypeId, :transactionDate, NOW(), NOW(), :ps)";
        $ck_sql = $insertMainSQL;
        $stmtMain = $db->prepare($insertMainSQL);
        $stmtMain->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmtMain->bindParam(':transactionTypeId', $transactionTypeId, PDO::PARAM_INT);
        $stmtMain->bindParam(':transactionDate', $transactionDate, PDO::PARAM_STR); // 日期綁定為字串
        $stmtMain->bindParam(':ps', $ps, PDO::PARAM_STR);
        
        $stmtMain->execute();

        // 2. 獲取剛剛插入的 `t_id`
        $t_id = $db->lastInsertId();
        add_log($db_log,$userId,1,"web-addTransactions.php","
                            記錄點1 Main Transactions
                            t_id:{$t_id}
                            transactionTypeId:{$transactionTypeId}
                            transactionDate:{$transactionDate}
                            ps:{$ps}");

        // 查詢新增後的完整數據，作為 afterData
        $sqlSelectNew = "SELECT * FROM transactions WHERE t_id = ?";
        $stmtSelectNew = $db->prepare($sqlSelectNew);
        $stmtSelectNew->execute([$t_id]);
        $afterData = $stmtSelectNew->fetch(PDO::FETCH_ASSOC);
        log_audit_action($db, 'INSERT', 'transactions','t_id', $t_id, $userId, 'addTransactions.php', $afterData);

        // 3. 遍歷 `sub_transactions` 陣列，逐一插入 `transactions_sub` 子表數據
        $insertSubSQL = "INSERT INTO `transactions_sub`( `t_id`, `account_id`,`userId`, `categoryId`, `currency`, `amount`, `add_minus`,`bill_id`) 
                          VALUES (:t_id, :account_id, :userId, :categoryId, :currency, :amount, :add_minus, :bill_id)";
        // 注意：這裡假設你的 transactions_sub 表新增了一個 `sub_description` 欄位
        // 如果沒有，請移除 `:sub_description` 和其綁定
        $ck_sql = $insertSubSQL;
        $stmtSub = $db->prepare($insertSubSQL);

        $total = 0;//總計，用於計算加減後帳戶餘額
        $total_sec = 0;//總計，用於計算加減後帳戶餘額

        $use_accountId = -1;//主使用帳戶
        $in_accountId = -1;//轉帳、應收款項、應付款項 入帳帳戶id

        $arr_upd_balance_history = [];
        foreach ($subTransactions as $sub) {
            $sub_accountId = isset($sub['account_id']) ? (int)$sub['account_id'] : 0;
            if(count($arr_upd_balance_history)>0){
                foreach($arr_upd_balance_history as $ob){
                    if($ob['account_id'] == $sub_accountId && $ob['t_id']){}
                    else{
                        $arr_upd_balance_history [] =  [
                            'account_id'=>$sub_accountId,
                            't_id'=>$t_id,
                        ];
                    }
                }
            }else{
                $arr_upd_balance_history [] =  [
                    'account_id'=>$sub_accountId,
                    't_id'=>$t_id,
                ];
            }
            $sub_categoryId = isset($sub['category_id']) ? (int)$sub['category_id'] : 0;
            $sub_currency = isset($sub['currency']) ? trim($sub['currency']) : (isset($_SESSION['useCurrency'])?$_SESSION['useCurrency']:"TWD");
            $sub_amount = isset($sub['amount']) ? (float)$sub['amount'] : 0.00;
            $sub_add_minus = isset($sub['add_minus']) ? (float)$sub['add_minus'] : 0;
            

            $stmtSub->bindParam(':t_id', $t_id, PDO::PARAM_INT); // 使用主表的 t_id
            $stmtSub->bindParam(':account_id', $sub_accountId, PDO::PARAM_INT);
            $stmtSub->bindParam(':userId', $userId, PDO::PARAM_INT);
            $stmtSub->bindParam(':categoryId', $sub_categoryId, PDO::PARAM_INT);
            $stmtSub->bindParam(':currency', $sub_currency, PDO::PARAM_STR);
            $stmtSub->bindParam(':amount', $sub_amount, PDO::PARAM_STR); 
            $stmtSub->bindParam(':add_minus', $sub_add_minus, PDO::PARAM_INT);
            if($transactionTypeId == 9 && $sub_categoryId == -20){
                $billIdValue = 0;
                $stmtSub->bindParam(':bill_id', $billIdValue, PDO::PARAM_INT);
            }else{
                $billIdValue = null;
                $stmtSub->bindParam(':bill_id', $billIdValue, PDO::PARAM_NULL);
            }

            // 這就是關鍵！在 execute() 之前呼叫 debugDumpParams()
            // 這將會將詳細資訊輸出到標準輸出或 log 中
            ob_start(); // 啟動緩衝，捕捉輸出
            $stmtMain->debugDumpParams();
            $debugInfo = ob_get_clean(); // 取得並清除緩衝的內容
            
            $stmtSub->execute();
            
            if($transactionTypeId == 9){//若是進行“應付款項”交易便進行更新帳單
                updateBillPaidAmount($db, $sub_accountId, $sub_amount);
            }

            if($transactionTypeId == 5 || $transactionTypeId == 6){
                $use_accountId = $sub_accountId;
                //計算總
                $total = $total + ($sub_amount*$sub_add_minus );
            }else{
                if($sub_categoryId == -20){
                    $in_accountId = $sub_accountId;
                    $total_sec = $total_sec + ($sub_amount*$sub_add_minus );
                }else{
                    $use_accountId = $sub_accountId;
                    $total = $total + ($sub_amount*$sub_add_minus );
                }
            }
            add_log($db_log,$userId,1,"web-addTransactions.php","
                            記錄點2 sub Transactions
                            t_id:{$t_id}
                            account_id:{$sub_accountId}
                            categoryId:{$sub_categoryId}
                            currency:{$sub_currency}
                            amount:{$sub_amount}");
        }
        // 查詢新增後的完整數據，作為 afterData
        $sqlSelectNew = "SELECT * FROM transactions_sub WHERE t_id = ?";
        $stmtSelectNew = $db->prepare($sqlSelectNew);
        $stmtSelectNew->execute([$t_id]);
        $afterData = $stmtSelectNew->fetchAll(PDO::FETCH_ASSOC);
        //子交易是用同一個t_id，紀錄“審計日誌”時將一同紀錄
        log_audit_action($db, 'INSERT', 'transactions_sub','t_id', $t_id, $userId, 'addTransactions.php', $afterData);


        $log = []; // post回傳
        //更新帳戶餘額
        if($transactionTypeId == 5 || $transactionTypeId == 6){//單影響帳戶
            //獲取目前餘額
            $atSQL="SELECT `accountId`,`currentBalance`,`add_minus`,`accountTypeId` FROM `accountTable` WHERE `userId` = '$userId' AND `accountId`= '$use_accountId' ";
            $ck_sql = $atSQL;
            $atRes = $db -> query($atSQL);
            $atRows = $atRes -> fetchAll();
            $atCount = $atRes -> rowCount();

            if($atCount != 1 ){
                $db->rollBack(); // 取消前面的修改暫存
                add_log($db_log,$userId,1,"web-addTransactions.php","
                            記錄點3-error 
                            獲取帳戶資料錯誤，獲取數量:{$atCount}，用戶id:{$userId}，帳戶id:{$use_accountId}");
                echo json_encode([
                    'status' => 'error',
                    'message' => '新增失敗，沒查到指定帳戶',
                    'account_id' => $use_accountId ,
                ]);
            }else{
                $row = $atRows[0];
                $new_currentBalance = (int)$row['currentBalance']+($total*(int)$row['add_minus']);
                $updateSQL = "UPDATE `accountTable` SET `currentBalance`='$new_currentBalance' WHERE `accountId` = '$use_accountId' AND `userId` = '$userId';";
                $stmt = $db->prepare($updateSQL);
                $stmt->execute();

                if($row['accountTypeId'] == 11){
                    //如果非信用卡繳費將更新
                    $updateSQL = "UPDATE `transactions_sub` SET `bill_id`='0' WHERE `t_id` = '$t_id' AND `userId` = '$userId';";
                    $stmt = $db->prepare($updateSQL);
                    $stmt->execute();
                }

                // 4. 如果所有插入都成功，提交事務
                $db->commit();

                add_log($db_log,$userId,1,"web-addTransactions.php","
                            記錄點3 end
                            帳戶餘額更新成功，帳戶id{$use_accountId}，新餘額:{$new_currentBalance}");
                
                foreach($arr_upd_balance_history as $ob){
                    update_balance_history($db,$userId,$ob['account_id'],$ob['t_id']);
                }

                // 返回成功訊息 (建議使用 JSON)
                echo json_encode([
                    'status' => 'success',
                    'message' => '多子項交易記錄成功新增',
                    't_id' => $t_id ,
                ]);
            }
        }else{
            // 4.更新帳戶餘額
            $atSQL="SELECT `accountId`,`currentBalance`,`add_minus` FROM `accountTable` WHERE `userId` = '$userId' AND `accountId`= '$use_accountId' ";
            $atRes = $db -> query($atSQL);
            $atRows = $atRes -> fetchAll();
            $atCount = $atRes -> rowCount();

            $at2SQL="SELECT `accountId`,`currentBalance`,`add_minus` FROM `accountTable` WHERE `userId` = '$userId' AND `accountId`= '$in_accountId' ";
            $at2Res = $db -> query($at2SQL);
            $at2Rows = $at2Res -> fetchAll();
            $at2Count = $at2Res -> rowCount();

            if($atCount != 1 || $at2Count != 1 ){
                $db->rollBack(); // 取消前面的修改暫存
                add_log($db_log,$userId,1,"web-addTransactions.php","
                            記錄點3-error 
                            獲取帳戶資料錯誤，獲取數量:{$atCount}，用戶id:{$userId}，帳戶id:{$use_accountId}");
                echo json_encode([
                    'status' => 'error',
                    'message' => '新增失敗，沒查到指定帳戶',
                    'other' => "出帳帳戶id：".$out_account_id."，入帳帳戶id：".$in_accountId,
                ]);
            }else{
                $row = $atRows[0];
                $row2 = $at2Rows[0];
                $new_out_currentBalance = (int)$row['currentBalance']+($total*(int)$row['add_minus']);
               
                $new_in_currentBalance = (int)$row2['currentBalance']+($total_sec*(int)$row2['add_minus']);

                $updateSQL = "UPDATE `accountTable` SET `currentBalance`='$new_out_currentBalance' WHERE `accountId` = '$use_accountId' AND `userId` = '$userId';";
                $stmt = $db->prepare($updateSQL);
                $stmt->execute();

                $update2SQL = "UPDATE `accountTable` SET `currentBalance`='$new_in_currentBalance' WHERE `accountId` = '$in_accountId' AND `userId` = '$userId';";
                $stmt = $db->prepare($update2SQL);
                $stmt->execute();

                // 4. 如果所有插入都成功，提交事務
                $db->commit();
                add_log($db_log,$userId,1,"web-addTransactions.php","
                            記錄點3 end
                            帳戶餘額更新成功，出帳帳戶id{$use_accountId}，新餘額:{$new_out_currentBalance}
                                           入帳帳戶id{$in_accountId}，新餘額:{$new_in_currentBalance}");
                foreach($arr_upd_balance_history as $ob){
                    update_balance_history($db,$userId,$ob['account_id'],$ob['t_id']);
                }

                // 返回成功訊息 (建議使用 JSON)
                echo json_encode([
                    'status' => 'success',
                    'message' => '多子項交易記錄成功新增',
                    't_id' => $t_id ,
                ]);
            }
            
        }
        $db = null; // 確保關閉 DB 連線
    } catch (PDOException $e) {
        error_log("Database Error in add_transaction_with_sub_items.php: " . $e->getMessage() . " --- SQLSTATE: " . $e->getCode());
        if($db!=null){
            $db->rollBack(); // 回滾所有更改
        }
        
        echo json_encode([
            'status' => 'error',
            'message' => '資料庫操作失敗: ' . $e->getMessage(),
            'sqlstate' => $e->getCode(),
            'sql' => $ck_sql,
            'debugInfo' => $debugInfo,
        ]);

    } catch (Exception $e) {
        if($db!=null){
            $db->rollBack(); // 回滾所有更改
        }
        error_log("General Error in add_transaction_with_sub_items.php: " . $e->getMessage());
        echo json_encode([
            'status' => 'error', 
            'message' => '一般伺服器錯誤: ' . $e->getMessage(),
            'sql' => $ck_sql,
            'json' => json_encode($data),
        ]);
    } finally {
        $db = null; // 確保關閉 DB 連線
    }

    

?>