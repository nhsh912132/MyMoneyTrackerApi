<?php
// bill_overview.php - 帳單概覽API

// 開啟錯誤報告，用於開發調試
error_reporting(E_ALL);
ini_set('display_errors', 1);

include("../functions.php");

header('Content-Type: application/json; charset=utf-8');

// 檢查使用者是否已登入
if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
    echo json_encode(['status' => 'error', 'message' => '未登入或會話已過期。請重新登入。']);
    exit();
}

$userId = $_SESSION['userId'];
$db = null;

try {
    $db = openDB($db_server, $db_name, $db_user, $db_passwd);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 步驟1：查詢該用戶所有信用卡帳戶，以便後續遍歷
    $sqlAccounts = "SELECT accountId, accountName FROM `accountTable` WHERE userId = ? AND accountTypeId = 11 ORDER BY accountId ASC";
    $stmtAccounts = $db->prepare($sqlAccounts);
    $stmtAccounts->execute([$userId]);
    $accounts = $stmtAccounts->fetchAll(PDO::FETCH_ASSOC);

    $allBillsData = [];

    // 步驟2：遍歷每個帳戶，獲取其當期和前一期帳單
    foreach ($accounts as $account) {
        $accountId = $account['accountId'];
        $accountName = $account['accountName'];

        // 查詢該帳戶最新的兩筆帳單
        $sqlLatestBills = "
            SELECT
                b.*
            FROM `billsTable` b
            WHERE b.account_id = ? AND b.user_id = ?
            ORDER BY b.period_end_date DESC, b.bill_id DESC
            LIMIT 2";
        $stmtLatestBills = $db->prepare($sqlLatestBills);
        $stmtLatestBills->execute([$accountId, $userId]);
        $latestBills = $stmtLatestBills->fetchAll(PDO::FETCH_ASSOC);

        if (empty($latestBills)) {
            continue; // 如果沒有任何帳單，跳過此帳戶
        }

        $accountData = [
            'accountId' => (int)$accountId,
            'accountName' => $accountName,
            'bills' => []
        ];

        // 步驟3：處理並整理帳單資料
        $periodIndex = 0;
        foreach ($latestBills as $bill) {
            $periodName = ($periodIndex === 0) ? "本期帳單" : "前一期帳單";

            // 查詢該帳單的交易數量
            $sqlTransactionCount = "SELECT COUNT(*) FROM `transactions_sub` WHERE `bill_id` = ?";
            $stmtTransactionCount = $db->prepare($sqlTransactionCount);
            $stmtTransactionCount->execute([$bill['bill_id']]);
            $transactionCount = $stmtTransactionCount->fetchColumn();

            $accountData['bills'][] = [
                'periodName' => $periodName,
                'billId' => (int)$bill['bill_id'],
                'periodStartDate' => $bill['period_start_date'],
                'periodEndDate' => $bill['period_end_date'],
                'transactionsAmount' => (float)$bill['transactions_amount'], // 自動計算的應繳金額
                'totalDue' => (float)$bill['total_due'], // 實際應繳金額
                'paidAmount' => (float)$bill['paid_amount'],
                'dueDate' => $bill['due_date'],
                'paidStatus' => (int)$bill['paid_status'],
                'transactionCount' => (int)$transactionCount,
            ];

            $periodIndex++;
        }
        $allBillsData[] = $accountData;
    }

    echo json_encode(['status' => 'success', 'data' => $allBillsData]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => '資料庫錯誤: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => '程式錯誤: ' . $e->getMessage()]);
} finally {
    $db = null;
}
?>