<?php
    // deleteTransaction.php
    // error_reporting(E_ALL);
    // ini_set('display_errors', 1);

    // 設定 HTTP 標頭為 JSON，防止亂碼
    header('Content-Type: application/json; charset=utf-8');

    // 引入資料庫連接檔案和通用函式
    include("../functions.php");
    // session_start() 應該在 functions.php 或其他被引入的檔案中
    // 確保已啟動 session

    // 檢查使用者是否已登入
    if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
        echo "NoUser:".$_SESSION['userId'];
        exit(); // Exit immediately if no valid user
    }

    $userId = $_SESSION['userId'];
    $t_id = isset($_POST['t_id']) ? (int)$_POST['t_id'] : 0;

    if ($t_id <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => '無效的交易ID']);
        exit();
    }

    try {
        $db = openDB($db_server, $db_name, $db_user, $db_passwd);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // 開始一個資料庫交易
        $db->beginTransaction();


        // 1. 取得舊交易數據，以便計算回滾金額
        $oldTransactionSQL = "SELECT *
                            FROM `transactions_sub` WHERE `t_id` = :t_id AND `userId` = :userId";
        $ck_sql = $oldTransactionSQL;
        $stmtOld = $db->prepare($oldTransactionSQL);
        $stmtOld->bindParam(':t_id', $t_id, PDO::PARAM_INT);
        $stmtOld->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmtOld->execute();
        $oldSubTransactions = $stmtOld->fetchAll(PDO::FETCH_ASSOC);

        if (count($oldSubTransactions) === 0) {
            $db->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Old transaction not found.']);
            exit();
        }
       
        // 1. 刪除 transactions 表中的資料
        $sqlMain = "DELETE FROM `transactions` WHERE `t_id` = '$t_id' AND `userId` = '$userId' ";
        $stmtMain = $db->prepare($sqlMain);

        $afterData = null;
        log_audit_action($db, 'DELETE', 'transactions','t_id', $t_id, $userId, 'delete_transaction.php', $afterData);

        $stmtMain->execute();
        
        // 2. 刪除 transactions_sub 表中的資料
        $sqlSub = "DELETE FROM `transactions_sub` WHERE `t_id` = '$t_id'  ";
        $stmtSub = $db->prepare($sqlSub);

        $afterData = null;
        log_audit_action($db, 'DELETE', 'transactions_sub','t_id', $t_id, $userId, 'delete_transaction.php', $afterData);

        $stmtSub->execute();
        
        // 如果兩個刪除都成功，提交交易
        $db->commit();

        // 5. 執行次要動作：回滾帳戶餘額。**使用獨立的 try...catch**
        try {
            rollback_transaction_balance($db, $userId, $oldSubTransactions);
        } catch (Exception $e) {
            // 記錄錯誤，但繼續執行，不影響主要操作的成功回傳
            error_log("Rollback failed for t_id: {$t_id}. Error: " . $e->getMessage());
        }
        
        echo json_encode(['status' => 'success', 'message' => '交易和相關子項目已成功刪除']);

    } catch (PDOException $e) {
        // 如果發生錯誤，回滾交易
        $db->rollBack();
        error_log("Database Error in deleteTransaction.php: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '資料庫錯誤，交易未被刪除。']);

    } catch (Exception $e) {
        // 捕捉其他一般錯誤
        error_log("General Error in deleteTransaction.php: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '伺服器發生未知錯誤。']);
        
    } finally {
        // 關閉 DB 連線
        $db = null;
    }

    function rollback_transaction_balance(PDO $db, int $userId, array $oldSubTransactions) {
        // 使用關聯陣列來彙總每個帳戶的總回滾金額，以避免重複查詢和更新
        $accountsToUpdate = [];

        // 彙總每個帳戶的總金額
        foreach ($oldSubTransactions as $sub) {
            $account_id = (int)$sub['account_id'];
            $amount = (float)$sub['amount'];
            $addMinus = (int)$sub['add_minus']; // `transactions_sub` 裡的 add_minus
            
            // 確保陣列有這個帳戶的鍵
            if (!isset($accountsToUpdate[$account_id])) {
                $accountsToUpdate[$account_id] = 0;
            }

            // 根據 transactions_sub 的 add_minus 來計算總影響
            // 正數表示增加餘額，負數表示減少餘額
            $accountsToUpdate[$account_id] += $amount * $addMinus;
        }

        // 遍歷需要更新的帳戶，並執行回滾操作
        foreach ($accountsToUpdate as $account_id => $totalChange) {
            // 獲取帳戶當前餘額和 add_minus
            $atSQL = "SELECT `currentBalance`, `add_minus` FROM `accountTable` WHERE `userId` = :userId AND `account_id` = :account_id";
            $stmtAt = $db->prepare($atSQL);
            $stmtAt->bindParam(':userId', $userId, PDO::PARAM_INT);
            $stmtAt->bindParam(':account_id', $account_id, PDO::PARAM_INT);
            $stmtAt->execute();
            $accountData = $stmtAt->fetch(PDO::FETCH_ASSOC);

            if (!$accountData) {
                // 如果找不到帳戶，拋出異常，讓事務回滾
                throw new Exception("Account ID: {$account_id} not found for rollback.");
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
            $updateSQL = "UPDATE `accountTable` SET `currentBalance` = :newBalance WHERE `account_id` = :account_id AND `userId` = :userId";
            $stmtUpd = $db->prepare($updateSQL);
            $stmtUpd->bindParam(':newBalance', $newBalance, PDO::PARAM_STR);
            $stmtUpd->bindParam(':account_id', $account_id, PDO::PARAM_INT);
            $stmtUpd->bindParam(':userId', $userId, PDO::PARAM_INT);
            $stmtUpd->execute();
        }
    }
?>