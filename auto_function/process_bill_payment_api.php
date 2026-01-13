<?php
// process_bill_payment_cron.php - 專門處理所有信用卡帳戶的繳費應用 (批次執行)

// 假設已經包含了 functions.php 和必要的資料庫配置
include("functions.php"); 
error_reporting(E_ALL); 
ini_set('display_errors', 1);

header('Content-Type: application/json'); // 即使是 Cron job，也建議保持 JSON 輸出

$log = []; // 用於記錄處理過程和結果

// 建議使用 -1 作為已應用繳費的標記，避免與真實 bill_id 混淆
const PAID_MARKER = -1; 

$db = null;
$db_log = null; // 假設 log 連線也是可用的

try {
    $db = openDB($db_server, $db_name, $db_user, $db_passwd);
    // 假設 add_log 函式已存在並可使用
    // $db_log = openDB($db_server, $db_name, $db_user, $db_passwd); 
    // $db_log->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->beginTransaction(); // 開始事務處理

    // --- 1. 獲取所有信用卡帳戶 (accountTypeId = 11) ---
    $sqlAccounts = "SELECT a.accountId, a.userId, a.accountName
                    FROM `accountTable` a
                    WHERE a.accountTypeId = 11";
    $stmtAccounts = $db->prepare($sqlAccounts);
    $stmtAccounts->execute();
    $accounts = $stmtAccounts->fetchAll(PDO::FETCH_ASSOC);

    $log[] = "--- 開始處理 {$stmtAccounts->rowCount()} 個信用卡帳戶的繳費應用 ---";

    // --- 2. 依序處理每個帳戶 ---
    foreach ($accounts as $account) {
        $accountId = (int)$account['accountId'];
        $userId = (int)$account['userId'];
        $accountName = $account['accountName'];
        
        $log[] = "=================================================";
        $log[] = "開始處理帳戶 ID: {$accountId} ({$accountName})";
        
        // 核心處理邏輯
        $result = apply_payments_to_bills($db, $accountId, $userId);
        
        $log[] = "處理結果: " . $result['message'];
        $log = array_merge($log, $result['log']);
    }

    $db->commit();
    // 成功紀錄 Log
    // add_log($db_log, 0, 1, "process_bill_payment_cron.php", "所有帳戶繳費應用處理完成");
    echo json_encode(['status' => 'success', 'message' => '所有帳戶繳費應用處理完成', 'log' => $log]);

} catch (PDOException $e) {
    if ($db != null) {
        $db->rollBack();
    }
    $log[] = ['type' => 'error', 'message' => '資料庫操作失敗: ' . $e->getMessage(), 'sqlstate' => $e->getCode()];
    // 錯誤紀錄 Log
    // add_log($db_log, 0, -1, "process_bill_payment_cron.php", "資料庫操作失敗: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => '資料庫操作失敗', 'log' => $log]);
} catch (Exception $e) {
    if ($db != null) {
        $db->rollBack();
    }
    $log[] = ['type' => 'error', 'message' => '一般伺服器錯誤: ' . $e->getMessage()];
    // 錯誤紀錄 Log
    // add_log($db_log, 0, -1, "process_bill_payment_cron.php", "一般伺服器錯誤: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => '一般伺服器錯誤', 'log' => $log]);
} finally {
    $db = null;
    $db_log = null;
}

// ====================================================================
// ========================= 核心處理函式群 ===========================
// ====================================================================

// 將核心處理邏輯獨立為函式，這樣可以為每個帳戶獨立執行

/**
 * 核心繳費應用邏輯：處理單一帳戶下所有未處理的繳費交易。
 * [此函式與之前提供的邏輯相同，只是被包裝在批次處理中]
 * @param PDO $db 資料庫連線物件
 * @param int $accountId 信用卡帳戶 ID
 * @param int $userId 使用者 ID
 * @return array 處理結果和日誌
 */
function apply_payments_to_bills(PDO $db, int $accountId, int $userId): array {
    $log = [];
    $totalPaidAmountApplied = 0.00;
    $updatedBillCount = 0;
    
    // 1. 獲取所有未處理的繳費交易 (add_minus = 1 且 bill_id = 0)
    $sqlPayments = "SELECT ts.s_id, ts.amount
                    FROM `transactions_sub` ts
                    WHERE ts.account_id = ? AND ts.add_minus = 1 AND ts.bill_id = 0";
    $stmtPayments = $db->prepare($sqlPayments);
    $stmtPayments->execute([$accountId]);
    $payments = $stmtPayments->fetchAll(PDO::FETCH_ASSOC);

    if (empty($payments)) {
        return ['applied_count' => 0, 'total_amount' => 0.00, 'message' => '無新的繳費交易需要應用。', 'log' => $log];
    }
    
    // 將所有未處理的繳費交易金額加總成一個「繳費金額池」
    $paymentSIds = array_column($payments, 's_id');
    $remainingPayment = array_sum(array_column($payments, 'amount'));
    $initialTotalPayment = $remainingPayment; // 記錄原始總繳費金額

    $log[] = " -> 發現 {$stmtPayments->rowCount()} 筆未處理繳費交易，總金額: {$initialTotalPayment}。";

    // 2. 獲取所有需要繳費的帳單 (paid_status = -1 或 1)，優先處理最舊的
    $sqlBillsToPay = "SELECT bill_id, transactions_amount, paid_amount, due_date
                      FROM `billsTable`
                      WHERE account_id = ? AND user_id = ? AND paid_status IN (-1, 1) 
                      ORDER BY due_date ASC"; 
    $stmtBillsToPay = $db->prepare($sqlBillsToPay);
    $stmtBillsToPay->execute([$accountId, $userId]);
    $billsToPay = $stmtBillsToPay->fetchAll(PDO::FETCH_ASSOC);

    if (empty($billsToPay)) {
        $log[] = " -> 無需要繳費的欠款帳單 (paid_status -1 或 1)。";
    }

    // 3. 遍歷欠款帳單並應用繳費金額
    foreach ($billsToPay as $bill) {
        if ($remainingPayment <= 0) break; 
        
        $billId = (int)$bill['bill_id'];
        $totalExpense = (float)$bill['transactions_amount'];
        $currentlyPaid = (float)$bill['paid_amount'];
        
        // 應繳金額 (transactions_amount - 已繳的)
        $amountDue = $totalExpense - $currentlyPaid; 
        
        if ($amountDue > 0) {
            $paymentApplied = min($remainingPayment, $amountDue);
            $newPaidAmount = $currentlyPaid + $paymentApplied;

            // 確定新的繳費狀態
            $newPaidStatus = determine_paid_status($totalExpense, $newPaidAmount);
            
            // 更新 billsTable 的 paid_amount 和 paid_status
            $sqlUpdateBill = "UPDATE `billsTable` 
                              SET paid_amount = ?, paid_status = ? 
                              WHERE bill_id = ?";
            $stmtUpdateBill = $db->prepare($sqlUpdateBill);
            $stmtUpdateBill->execute([$newPaidAmount, $newPaidStatus, $billId]);
            
            // 更新餘額和總應用金額
            $remainingPayment -= $paymentApplied;
            $totalPaidAmountApplied += $paymentApplied;
            $updatedBillCount++;
            
            $log[] = " -> 帳單 ID: {$billId} (應繳: {$amountDue}) 應用了 {$paymentApplied} 元。新狀態: {$newPaidStatus}。";
        }
    }
    
    // 4. 標記已處理的繳費交易
    if (!empty($paymentSIds)) {
        $placeholders = implode(',', array_fill(0, count($paymentSIds), '?'));
        
        // 使用 const PAID_MARKER = -1; 來標記「已應用繳費」
        $sqlUpdateTransactions = "UPDATE `transactions_sub` SET bill_id = ? WHERE s_id IN ({$placeholders})";
        $stmtUpdateTransaction = $db->prepare($sqlUpdateTransactions);
        $stmtUpdateTransaction->execute(array_merge([PAID_MARKER], $paymentSIds));
        
        $log[] = " -> 成功標記 {$stmtUpdateTransaction->rowCount()} 筆繳費交易為已應用 (bill_id=".PAID_MARKER.")。";
    }

    return [
        'applied_count' => $updatedBillCount,
        'total_amount' => $initialTotalPayment,
        'amount_applied' => $totalPaidAmountApplied,
        'amount_remaining' => $remainingPayment,
        'message' => "帳戶繳費處理完成。總繳費 {$initialTotalPayment}，應用 {$totalPaidAmountApplied}。",
        'log' => $log
    ];
}

/**
 * 根據總消費金額和已繳金額，計算新的 paid_status。
 * 遵循您提供的邏輯。
 */
function determine_paid_status(float $totalExpense, float $paidAmount): int {
    if ($totalExpense <= 0) {
        // 3: 結清 (無消費)
        return 3;
    } 
    
    if ($paidAmount >= $totalExpense) {
        // 2: 已全繳
        return 2;
    } 
    
    if ($paidAmount > 0) {
        // 1: 未全繳
        return 1;
    } 
    
    // -1: 未繳費
    return -1;
}
?>