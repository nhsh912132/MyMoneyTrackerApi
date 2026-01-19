<?php
    // ... (前置程式碼，如 error_reporting, include, header 等)

    include("../functions.php");
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    // 獲取原始 JSON 輸入
    $json_input = file_get_contents('php://input');
    $data = json_decode($json_input, true);

    // 檢查 JSON 解析是否成功
    if ($data === null) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input.']);
        exit();
    }

    // 獲取要修改的交易 ID
    $t_id = isset($data['t_id']) ? (int)$data['t_id'] : 0;
    if ($t_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid transaction ID (t_id).']);
        exit();
    }

    $userId = $_SESSION['userId'];
    $ck_sql = '';

    try {
        $db = openDB($db_server, $db_name, $db_user, $db_passwd);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); 
        // 啟動前先檢查，避免重複啟動 (解決 "already an active transaction")
        if (!$db->inTransaction()) {
            $db->beginTransaction();
        }

        // 1. 取得舊的子交易數據，以便計算回滾金額
        $oldSubSQL = "SELECT `s_id`, `account_id`, `amount`, `add_minus`, `categoryId`, `currency` 
                      FROM `transactions_sub` 
                      WHERE `t_id` = :t_id AND `userId` = :userId";
        $ck_sql = $oldSubSQL;
        $stmtOld = $db->prepare($oldSubSQL);
        $stmtOld->bindParam(':t_id', $t_id, PDO::PARAM_INT);
        $stmtOld->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmtOld->execute();
        $oldSubTransactions = $stmtOld->fetchAll(PDO::FETCH_ASSOC);

        if (count($oldSubTransactions) === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Old transaction not found.']);
            exit();
        }

        // 將舊資料轉為以 s_id 為 Key 的 Map，方便比對
        $oldSubMap = [];
        foreach ($oldSubTransactions as $sub) {
            $oldSubMap[(int)$sub['s_id']] = $sub;
        }

        // 2. 回滾舊交易對帳戶的影響 (完全回滾)
        $ck_sql = "回滾舊交易";
        rollback_transaction_balance($db,$userId,$oldSubTransactions);
                    
        // 3. 更新主交易
        // 獲取 transactions 主表的數據
        $transactionTypeId = isset($data['transaction_type_id']) ? (int)$data['transaction_type_id'] : 0;
        $transactionDate = isset($data['transaction_date']) ? $data['transaction_date'] : date('Y-m-d'); 
        $ps = isset($data['ps']) ? trim($data['ps']) : ''; 
        
        // **重要：這裡使用 INSERT 語法，但如果希望保留舊的 t_id，可以改為 UPDATE 和 INSERT**
        // 如果要保留舊的 `t_id`，你必須修改主表的 `UPDATE` 語法，並確保 `transactions_sub` 的插入使用這個舊的 `t_id`。
        // 這取決於你的設計，是將修改視為「新記錄」，還是「在舊記錄上修改」。
        // 考量到你的需求是「刪除重建」，重新插入並產生新的 t_id 會更直觀。
        $ck_sql = "更新前紀錄";
        // log_audit_action($db, 'UPDATE_BEFORE', 'transactions','t_id', $t_id, $userId, 'editTransactions.php',null);
        
        // 插入 transactions 主表數據，產生新的 t_id
        $insertMainSQL = "UPDATE `transactions` SET  
                            `transactionTypeId` = :transactionTypeId, 
                            `transactionDate` = :transactionDate, 
                            `updatedAt` = NOW(), 
                            `ps` = :ps 
                        WHERE `userId` = :userId AND `t_id` = :t_id ";
        $ck_sql = $insertMainSQL;
        $stmtMain = $db->prepare($insertMainSQL);
        $stmtMain->bindParam(':transactionTypeId', $transactionTypeId, PDO::PARAM_INT);
        $stmtMain->bindParam(':transactionDate', $transactionDate, PDO::PARAM_STR);
        $stmtMain->bindParam(':ps', $ps, PDO::PARAM_STR);
        $stmtMain->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmtMain->bindParam(':t_id', $t_id, PDO::PARAM_INT);
        $stmtMain->execute();
        // $new_t_id = $db->lastInsertId();
        
        // 查詢修改後的完整數據，作為 afterData
        $ck_sql = "更新後紀錄";
        $sqlSelectUpdated = "SELECT * FROM transactions WHERE t_id = ?";
        $stmtSelectUpdated = $db->prepare($sqlSelectUpdated);
        $stmtSelectUpdated->execute([$t_id]);
        $afterData = $stmtSelectUpdated->fetchAll(PDO::FETCH_ASSOC);
        // log_audit_action($db, 'UPDATE_AFTER', 'transactions','t_id', $t_id, $userId, 'editTransactions.php', $afterData); // 注意操作名稱變更

       //==================================================
        // 4. 處理子交易差異更新 (transactions_sub 表)
        //==================================================
        $subTransactions = isset($data['sub_transactions']) ? $data['sub_transactions'] : [];
        $involvedAccountIds = []; // 用於記錄所有變動過的帳戶，最後更新歷史紀錄用

        // 先把舊帳戶全部加入變動清單
        foreach ($oldSubTransactions as $os) {
            $involvedAccountIds[] = (int)$os['account_id'];
        }

        foreach ($subTransactions as $sub) {
            $s_id = isset($sub['s_id']) ? (int)$sub['s_id'] : 0;
            $sub_accountId = (int)$sub['account_id'];
            $sub_categoryId = (int)$sub['category_id'];
            $sub_amount = (float)$sub['amount'];
            $sub_add_minus = (int)$sub['add_minus'];
            $sub_currency = $sub['currency'] ?? ($_SESSION['useCurrency'] ?? "TWD");

            $involvedAccountIds[] = $sub_accountId; // 紀錄新帳戶

            if ($s_id > 0 && isset($oldSubMap[$s_id])) {
                // [情況 B-1]：s_id 存在於資料庫 -> 更新
                $updateSubSQL = "UPDATE `transactions_sub` SET 
                                    `account_id` = :acc, `categoryId` = :cat, 
                                    `amount` = :amt, `add_minus` = :am, `currency` = :cur
                                 WHERE `s_id` = :sid AND `t_id` = :tid AND `userId` = :uid";
                $stmtUpdSub = $db->prepare($updateSubSQL);
                $stmtUpdSub->execute([
                    ':acc' => $sub_accountId, ':cat' => $sub_categoryId,
                    ':amt' => $sub_amount, ':am' => $sub_add_minus, ':cur' => $sub_currency,
                    ':sid' => $s_id, ':tid' => $t_id, ':uid' => $userId
                ]);

                // 從 Map 中移除，剩下的就是沒出現在 JSON 裡的，代表要刪除
                unset($oldSubMap[$s_id]);

            } else {
                // [情況 B-3]：s_id 為 0 或不存在於資料庫 -> 新增
                $insertSubSQL = "INSERT INTO `transactions_sub` (`t_id`, `account_id`, `userId`, `categoryId`, `currency`, `amount`, `add_minus`) 
                                 VALUES (:tid, :acc, :uid, :cat, :cur, :amt, :am)";
                $stmtInsSub = $db->prepare($insertSubSQL);
                $stmtInsSub->execute([
                    ':tid' => $t_id, ':acc' => $sub_accountId, ':uid' => $userId,
                    ':cat' => $sub_categoryId, ':cur' => $sub_currency,
                    ':amt' => $sub_amount, ':am' => $sub_add_minus
                ]);
            }
            $ck_sql = "sid=".$s_id."更新或修改結束";
        }

        // [情況 B-2]：處理剩餘在 $oldSubMap 裡的資料 -> 刪除
        if (!empty($oldSubMap)) {
            $deleteIds = array_keys($oldSubMap);
            $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
            $deleteSubSQL = "DELETE FROM `transactions_sub` WHERE `s_id` IN ($placeholders) AND `userId` = ?";
            $stmtDelSub = $db->prepare($deleteSubSQL);
            $stmtDelSub->execute(array_merge($deleteIds, [$userId]));
        }

        //==================================================
        // 5. 套用新交易對帳戶的影響
        //==================================================
        // 重新讀取目前資料庫中該 t_id 的所有子交易
        $stmtNew = $db->prepare("SELECT `account_id`, `amount`, `add_minus` FROM `transactions_sub` WHERE `t_id` = ?");
        $stmtNew->execute([$t_id]);
        $newSubTransactions = $stmtNew->fetchAll(PDO::FETCH_ASSOC);
        $ck_sql = "套用新交易對帳戶的影響";

        // 借用 rollback 的邏輯，但傳入新的數據。
        // 注意：因為您的 rollback 邏輯是「減去變動量」，要套用「增加變動量」
        // 最簡單的方法是寫一個 apply_transaction_balance，或是暫時將 add_minus 取反
        // apply_transaction_balance($db, $userId, $newSubTransactions);
        
        // 5. 如果所有操作成功，提交事務
        $db->commit();
         $ck_sql = "提交交易";
        
        //==================================================
        // 6. 更新餘額歷史紀錄 (涉及的所有帳戶)
        //==================================================
        $involvedAccountIds = array_unique($involvedAccountIds);
        foreach ($involvedAccountIds as $accId) {
            update_balance_history($db, $userId, $accId, $t_id);
        }

        echo json_encode([
            'status' => 'success',
            'message' => '交易記錄修改成功',
            'new_t_id' => $t_id, // 回傳新的 t_id
        ]);

    } catch (PDOException $e) {
        // ... (錯誤處理，回滾)
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        echo json_encode([
            'status' => 'error',
            'message' => '資料庫操作失敗: ' . $e->getMessage(),
            'sqlstate' => $e->getCode(),
            'sql' => $ck_sql,
        ]);
    } catch (Exception $e) {
        // ... (一般錯誤處理，回滾)
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        echo json_encode([
            'status' => 'error', 
            'message' => '一般伺服器錯誤: ' . $e->getMessage(),
            'sql' => $ck_sql,
        ]);
    } finally {
        $db = null;
    }


    function rollback_transaction_balance(PDO $db, int $userId, array $oldSubTransactions) {
        // 使用關聯陣列來彙總每個帳戶的總回滾金額，以避免重複查詢和更新
        $accountsToUpdate = [];

        // 彙總每個帳戶的總金額
        foreach ($oldSubTransactions as $sub) {
            $accountId = (int)$sub['account_id'];
            $amount = (float)$sub['amount'];
            $addMinus = (int)$sub['add_minus']; // `transactions_sub` 裡的 add_minus
            
            // 確保陣列有這個帳戶的鍵
            if (!isset($accountsToUpdate[$accountId])) {
                $accountsToUpdate[$accountId] = 0;
            }

            // 根據 transactions_sub 的 add_minus 來計算總影響
            // 正數表示增加餘額，負數表示減少餘額
            $accountsToUpdate[$accountId] += $amount * $addMinus;
        }

        // 遍歷需要更新的帳戶，並執行回滾操作
        foreach ($accountsToUpdate as $accountId => $totalChange) {
            // 獲取帳戶當前餘額和 add_minus
            $atSQL = "SELECT `currentBalance`, `add_minus` FROM `accountTable` WHERE `userId` = :userId AND `accountId` = :accountId";
            $stmtAt = $db->prepare($atSQL);
            $stmtAt->bindParam(':userId', $userId, PDO::PARAM_INT);
            $stmtAt->bindParam(':accountId', $accountId, PDO::PARAM_INT);
            $stmtAt->execute();
            $accountData = $stmtAt->fetch(PDO::FETCH_ASSOC);

            if (!$accountData) {
                // 如果找不到帳戶，拋出異常，讓事務回滾
                throw new Exception("Account ID: {$accountId} not found for rollback.");
            }

            $currentBalance = (float)$accountData['currentBalance'];
            $accountAddMinus = (int)$accountData['add_minus']; // `accountTable` 裡的 add_minus

            // 這是最關鍵的邏輯：
            // 原始交易是 "增加" (transactions_sub.add_minus = 1) -> 回滾時要 "減少"
            // 原始交易是 "減少" (transactions_sub.add_minus = -1) -> 回滾時要 "增加"
            // 
            // 考慮到 `accountTable` 的 add_minus：
            // - 如果是 "資產" 帳戶 (accountTable.add_minus = 1)，原本加的，現在要減回去： new = current - totalChange
            // - 如果是 "負債" 帳戶 (accountTable.add_minus = -1)，原本加的，現在要減回去： new = current + totalChange
            // 
            // 統一公式： new_balance = current_balance - (totalChange * accountAddMinus)
            // 因為 transactions_sub.add_minus 是 1/-1，所以 totalChange 已經是帶有方向性的金額
            $newBalance = $currentBalance - ($totalChange * $accountAddMinus);

            // 更新帳戶餘額
            $updateSQL = "UPDATE `accountTable` SET `currentBalance` = :newBalance WHERE `accountId` = :accountId AND `userId` = :userId";
            $stmtUpd = $db->prepare($updateSQL);
            $stmtUpd->bindParam(':newBalance', $newBalance, PDO::PARAM_STR);
            $stmtUpd->bindParam(':accountId', $accountId, PDO::PARAM_INT);
            $stmtUpd->bindParam(':userId', $userId, PDO::PARAM_INT);
            $stmtUpd->execute();
        }
    }
?>