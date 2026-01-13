<?php
// check_account.php 檢查帳戶餘額
include("../functions.php");

error_reporting(E_ALL);
ini_set('display_errors', 1);

// 設定 HTTP 標頭為 JSON，防止亂碼
header('Content-Type: application/json; charset=utf-8');

// 啟動會話並引入資料庫連接函式
// session_start();
// require_once('functions.php'); // 假設 functions.php 包含 openDB 函式

// 檢查使用者是否已登入
if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
    // http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => '未登入或會話已過期']);
    exit();
}

$userId = $_SESSION['userId'];
$useCurrency = $_SESSION['useCurrency'];
try {
    $db = openDB($db_server, $db_name, $db_user, $db_passwd);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $results = [];

    // 1. 取得所有帳戶資料，並將帳戶ID存入陣列
    $sqlAccounts = "SELECT `accountId`, `accountName`, `initialBalance`, `currentBalance`, `add_minus`, `currency` FROM `accountTable` WHERE `userId` = ? ";
    $stmtAccounts = $db->prepare($sqlAccounts);
    $stmtAccounts->execute([$userId]);
    $accounts = $stmtAccounts->fetchAll(PDO::FETCH_ASSOC);

    $recalculatedBalances = [];
    $accountIds = []; // 用於儲存所有帳戶的 ID
    foreach ($accounts as $account) {
        $accountIds[] = $account['accountId'];
        $recalculatedBalances[$account['accountId']] = [
            'accountName' => $account['accountName'],
            'before' => $account['currentBalance'],
            'newBalance' => $account['initialBalance'],
            'currency' => $account['currency'],
            'add_minus' => $account['add_minus']
        ];
    }
    
    // 如果沒有找到任何帳戶，直接回傳
    if (empty($accountIds)) {
        echo json_encode(['status' => 'success', 'message' => '找不到任何帳戶，無需更新。', 'results' => []]);
        exit();
    }
    
    // 2. 使用帳戶ID陣列，一次性從 transactions_sub 取得所有相關子交易
    $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
    $sqlTransactions = "SELECT `amount`, `add_minus`, `account_id` FROM `transactions_sub` WHERE `account_id` IN ($placeholders)";
    $stmtTransactions = $db->prepare($sqlTransactions);
    $stmtTransactions->execute($accountIds); // 直接傳入帳戶ID陣列
    $transactions = $stmtTransactions->fetchAll(PDO::FETCH_ASSOC);

    // 3. 遍歷所有子交易，計算每個帳戶的變動
    foreach ($transactions as $transaction) {
        $accountId = $transaction['account_id'];

        if (isset($recalculatedBalances[$accountId])) {
            $account_add_minus = $recalculatedBalances[$accountId]['add_minus'];
            
            // 根據帳戶類型 (資產/負債) 和交易類型 (收入/支出) 進行運算
            $factor = $account_add_minus * $transaction['add_minus'];
            $recalculatedBalances[$accountId]['newBalance'] += $transaction['amount'] * $factor;
        }
    }

    // 4. 更新帳戶餘額並收集結果
    $finalResults = [];
    foreach ($recalculatedBalances as $accountId => $data) {
        $sqlUpdate = "UPDATE `accountTable` SET `currentBalance` = ? WHERE `accountId` = ? AND `userId` = ?";
        $stmtUpdate = $db->prepare($sqlUpdate);
        $stmtUpdate->execute([$data['newBalance'], $accountId, $userId]);
        
        $finalResults[] = [
            'accountId' => $accountId,
            'accountName' => $data['accountName'],
            'before' => round((float)$data['before'], 2),
            'after' => round((float)$data['newBalance'], 2),
            'currency' => $data['currency'],
            'isChanged' => (round((float)$data['before'], 2) !== round((float)$data['newBalance'], 2))
        ];
    }
    
    // 5. 回傳最終結果
    echo json_encode([
        'status' => 'success',
        'message' => '所有帳戶餘額已重新計算並更新',
        'results' => $finalResults
    ]);

} catch (PDOException $e) {
    error_log("Database Error in check_account.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => '資料庫錯誤：' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("General Error in check_account.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => '伺服器發生未知錯誤。']);
} finally {
    $db = null;
}
?>