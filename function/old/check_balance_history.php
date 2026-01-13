<?php
// check_balance_integrity.php - 檢查並修復餘額歷史記錄的API

include("functions.php");
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 檢查使用者是否已登入
if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => '未登入或會話已過期。請重新登入。']);
    exit();
}

$userId = $_SESSION['userId'];
$db = null;

try {
    $db = openDB($db_server, $db_name, $db_user, $db_passwd);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->beginTransaction(); // 使用事務以確保資料操作的原子性

    $log = []; // 紀錄修復過程的日誌

    // 步驟1：獲取所有帳戶的初始金額、目前餘額與add_minus欄位，並建立映射表
    $sqlAccounts = "SELECT `accountId`, `initialBalance`, `currentBalance`, `add_minus` FROM `accountTable` WHERE `userId` = :userId";
    $stmtAccounts = $db->prepare($sqlAccounts);
    $stmtAccounts->bindParam(':userId', $userId, PDO::PARAM_INT);
    $stmtAccounts->execute();
    $accounts = $stmtAccounts->fetchAll(PDO::FETCH_ASSOC);
    $accountData = [];
    foreach ($accounts as $acc) {
        $accountData[$acc['accountId']] = [
            'initialBalance' => (float)$acc['initialBalance'],
            'currentBalance' => (float)$acc['currentBalance'],
            'add_minus_account' => (int)$acc['add_minus'],
        ];
    }
    
    // 步驟2：從交易主表和子表獲取所有交易，並按時間排序
    // 注意：使用 SUM 和 GROUP BY 來聚合每個 t_id 的總金額
    $sqlTransactions = "
        SELECT
            t.t_id,
            t.transactionDate,
            ts.account_id,
            ts.currency,
            SUM(ts.amount * ts.add_minus) AS total_amount_with_sign
        FROM `transactions_sub` AS ts
        JOIN `transactions` AS t ON ts.t_id = t.t_id
        WHERE t.userId = :userId AND ts.account_id IS NOT NULL AND ts.account_id > 0
        GROUP BY t.t_id, ts.account_id
        ORDER BY t.transactionDate ASC, t.t_id ASC
    ";
    $stmtTransactions = $db->prepare($sqlTransactions);
    $stmtTransactions->bindParam(':userId', $userId, PDO::PARAM_INT);
    $stmtTransactions->execute();
    $transactions = $stmtTransactions->fetchAll(PDO::FETCH_ASSOC);

    // 步驟3：遍歷帳戶，進行檢查與修復
    foreach ($accountData as $accountId => $data) {
        $log[] = "--- 正在檢查帳戶 ID: {$accountId} ---";
        
        $initialBalance = $data['initialBalance'];
        $addMinusAccount = $data['add_minus_account'];
        $runningBalance = $initialBalance;
        $needsRepair = false;
        $repairFromDate = null;

        // 篩選出該帳戶的所有交易
        $accountTransactions = array_filter($transactions, function($tx) use ($accountId) {
            return $tx['account_id'] == $accountId;
        });

        if (empty($accountTransactions)) {
            $log[] = "此帳戶沒有交易紀錄，無需檢查餘額變動。";
            continue;
        }

        // 遍歷交易，比對餘額歷史記錄
        foreach ($accountTransactions as $tx) {
            // 根據account_id的add_minus計算實際的金額變動
            $transactionAmount = (float)$tx['total_amount_with_sign'] * $addMinusAccount;

            // 計算預期的交易前、後餘額
            $expectedBefore = $runningBalance;
            $runningBalance += $transactionAmount;
            $expectedAfter = $runningBalance;
            
            // 查詢對應的餘額歷史記錄
            $sqlHistory = "SELECT `before`, `after` FROM `balance_history` WHERE `t_id` = :tId";
            $stmtHistory = $db->prepare($sqlHistory);
            $stmtHistory->bindParam(':tId', $tx['t_id'], PDO::PARAM_INT);
            $stmtHistory->execute();
            $historyRecord = $stmtHistory->fetch(PDO::FETCH_ASSOC);

            // 檢查是否需要修復
            if (!$historyRecord) {
                $log[] = "發現遺漏的餘額變動記錄 (t_id: {$tx['t_id']})。";
                $needsRepair = true;
                $repairFromDate = $tx['transactionDate'];
                break; // 找到第一個不一致就跳出，準備修復
            } elseif (
                abs((float)$historyRecord['before'] - $expectedBefore) > 0.01 || 
                abs((float)$historyRecord['after'] - $expectedAfter) > 0.01
            ) {
                $log[] = "發現不一致的餘額變動 (t_id: {$tx['t_id']})。";
                $log[] = "  期望: 前{$expectedBefore}, 後{$expectedAfter} | 實際: 前{$historyRecord['before']}, 後{$historyRecord['after']}";
                $needsRepair = true;
                $repairFromDate = $tx['transactionDate'];
                break; // 找到第一個不一致就跳出，準備修復
            }
        }
        
        // 步驟4：執行修復
        if ($needsRepair) {
            $log[] = "--- 開始修復 ---";
            $log[] = "刪除從 {$repairFromDate} 開始的所有餘額變動記錄。";
            
            // 刪除有問題的時間點之後的所有記錄
            $sqlDelete = "DELETE FROM `balance_history` WHERE `account_id` = :accountId AND `transactions_date` >= :repairFromDate";
            $stmtDelete = $db->prepare($sqlDelete);
            $stmtDelete->bindParam(':accountId', $accountId, PDO::PARAM_INT);
            $stmtDelete->bindParam(':repairFromDate', $repairFromDate, PDO::PARAM_STR);
            $stmtDelete->execute();

            // 重新計算並插入新記錄
            $runningBalance = $initialBalance;
            $sqlInsert = "INSERT INTO `balance_history` (`account_id`, `transactions_date`, `before`, `after`, `currency`, `t_id`) VALUES (:accountId, :date, :before, :after, :currency, :tId)";
            $stmtInsert = $db->prepare($sqlInsert);
            
            $transactionsToReinsert = array_filter($accountTransactions, function($tx) use ($repairFromDate) {
                return $tx['transactionDate'] >= $repairFromDate;
            });
            
            foreach ($transactionsToReinsert as $tx) {
                $transactionAmount = (float)$tx['total_amount_with_sign'] * $addMinusAccount;
                $beforeAmount = $runningBalance;
                $afterAmount = $beforeAmount + $transactionAmount;
                $runningBalance = $afterAmount; // 更新跑動餘額
                
                $stmtInsert->bindParam(':accountId', $accountId, PDO::PARAM_INT);
                $stmtInsert->bindParam(':date', $tx['transactionDate'], PDO::PARAM_STR);
                $stmtInsert->bindParam(':before', $beforeAmount, PDO::PARAM_STR);
                $stmtInsert->bindParam(':after', $afterAmount, PDO::PARAM_STR);
                $stmtInsert->bindParam(':currency', $tx['currency'], PDO::PARAM_STR);
                $stmtInsert->bindParam(':tId', $tx['t_id'], PDO::PARAM_INT);
                $stmtInsert->execute();
            }
            $log[] = "--- 修復完成 ---";
        }
    }
    
    $db->commit();
    echo json_encode(['status' => 'success', 'message' => '所有帳戶餘額變動記錄檢查完成。', 'log' => $log]);

} catch (PDOException $e) {
    $db->rollBack();
    http_response_code(500);
    error_log("Database Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => '資料庫錯誤: ' . $e->getMessage()]);
} catch (Exception $e) {
    $db->rollBack();
    http_response_code(500);
    error_log("General Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => '程式錯誤: ' . $e->getMessage()]);
} finally {
    $db = null;
}
?>