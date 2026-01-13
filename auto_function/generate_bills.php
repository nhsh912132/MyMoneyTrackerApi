<?php
// generate_bills.php - 信用卡帳單自動化處理腳本 (最終版，包含繳費處理及狀態檢查)

    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    include("functions.php"); // 假設此處包含了 session_start(), openDB(), db_server 等資訊

    // 為了排程任務，我們通常會禁用瀏覽器輸出，但為了測試，暫時保留 JSON 輸出
    header('Content-Type: application/json');

    $today = new DateTime();
    $today_date = $today->format('Y-m-d');
    $log = []; // 用於記錄處理過程和結果

    try {
        $db = openDB($db_server, $db_name, $db_user, $db_passwd);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->beginTransaction(); // 開始事務處理

        // 1. 獲取所有信用卡帳戶 (accountTypeId = 11)
        $sqlAccounts = "SELECT a.accountId, a.userId, a.accountName, a.billingCycleDay, a.paymentDueDay, a.createdAt
                        FROM `accountTable` a
                        WHERE a.accountTypeId = 11";
        $stmtAccounts = $db->prepare($sqlAccounts);
        $stmtAccounts->execute();
        $accounts = $stmtAccounts->fetchAll(PDO::FETCH_ASSOC);

        $log[] = "今日日期: " . $today_date;
        $log[] = "--- 開始處理 {$stmtAccounts->rowCount()} 個信用卡帳戶 ---";

        // 2. 依序處理每個帳戶
        foreach ($accounts as $account) {
            $accountLog = process_account($db, $account, $today);
            $log[] = $accountLog;
        }

        $db->commit();
        echo json_encode(['status' => 'success', 'message' => '所有帳戶帳單處理完成', 'log' => $log]);

    } catch (PDOException $e) {
        if ($db != null) {
            $db->rollBack();
        }
        $log[] = ['type' => 'error', 'message' => '資料庫操作失敗: ' . $e->getMessage(), 'sqlstate' => $e->getCode()];
        http_response_code(500); // 設置 HTTP 狀態碼
        echo json_encode(['status' => 'error', 'message' => '資料庫操作失敗', 'log' => $log]);
    } catch (Exception $e) {
        if ($db != null) {
            $db->rollBack();
        }
        $log[] = ['type' => 'error', 'message' => '一般伺服器錯誤: ' . $e->getMessage()];
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '一般伺服器錯誤', 'log' => $log]);
    } finally {
        $db = null;
    }

// ====================================================================
// ========================= 核心處理函式群 ===========================
// ====================================================================

/**
* 處理單個信用卡帳戶的帳單邏輯 (五步驟主流程)。
* @param PDO $db 資料庫連線物件
* @param array $account 帳戶資料
* @param DateTime $today 今日日期物件
* @return array 處理日誌
*/
function process_account(PDO $db, array $account, DateTime $today): array {
    $accountId = $account['accountId'];
    $userId = $account['userId'];
    $accountName = $account['accountName'];
    $log = ["帳戶 {$accountId} ({$accountName})"];

    // 步驟 1: 結算所有已過期的 active 帳單 (status=0)
    $log[] = close_overdue_bills($db, $accountId, $today);

    // 步驟 2: 確保存在一個 status=0 的當前帳單，若無則創建
    $activeBillInfo = ensure_active_bill($db, $account, $today);
    $log[] = $activeBillInfo['message'];

    // 步驟 3: 將未綁定的支出交易綁定到最新的 active 帳單
    if ($activeBillInfo['bill_id'] > 0) {
        $log[] = bind_unbilled_transactions($db, $accountId, $activeBillInfo['bill_id']);
    } else {
        $log[] = " -> 無 active 帳單，跳過交易綁定。";
    }

        // 【步驟 5】: 檢查所有可能狀態已變更的帳單並更新 paid_status
    $log[] = finalize_bill_statuses($db, $accountId, $userId);

    return $log;
}


// --- 步驟 1 函式: 結算過期的帳單 (period_end_date < today) ---
function close_overdue_bills(PDO $db, int $accountId, DateTime $today): string {
    $today_format = $today->format('Y-m-d');
    $count = 0;

    // 查找所有 paid_status=0 (未結算) 且 period_end_date 已過期的帳單
    $sqlOverdueBills = "SELECT bill_id, transactions_amount, paid_amount
    FROM `billsTable`
    WHERE account_id = ? AND paid_status = 0 AND period_end_date < ?";
    $stmtOverdueBills = $db->prepare($sqlOverdueBills);
    $stmtOverdueBills->execute([$accountId, $today_format]);
    $overdueBills = $stmtOverdueBills->fetchAll(PDO::FETCH_ASSOC);

    if (empty($overdueBills)) {
        return " -> 無過期帳單需要結算 (status=0)。";
    }

    foreach ($overdueBills as $bill) {
        $totalExpense = $bill['transactions_amount'];
                $currentlyPaid = $bill['paid_amount'];
        $totalDue = $totalExpense - $currentlyPaid;
                $paidStatus = 0; // 預設值
                $finalDue = 0;
                
        if ($totalExpense <= 0) {
            // 無消費
            $paidStatus = 3;// 3: 結清 (Cleared - No Transactions)
            $finalDue = 0;
        } else if ($totalDue <= 0) {
            // 已全數繳清或超繳
            $paidStatus = 2; // 2: 已全繳 (Fully Paid)
            $finalDue = 0;
        } else if ($currentlyPaid > 0) {
            // 有消費且有部分繳費但未全清
            $paidStatus = 1; // 1: 部分繳費 (Partially Paid)
            $finalDue = $totalDue;
        } else {
            // 有消費且未繳費
            $paidStatus = -1; // -1: 未繳費 (Unpaid/Outstanding)
            $finalDue = $totalDue;
        }

        // 更新帳單狀態和實際應付金額
        $sqlUpdate = "UPDATE `billsTable`
        SET paid_status = ?, total_due = ?
        WHERE bill_id = ?";
        $stmtUpdate = $db->prepare($sqlUpdate);
        $stmtUpdate->execute([$paidStatus, $finalDue, $bill['bill_id']]);
        $count++;
    }

    return " -> 成功結算 {$count} 筆已過期 (period_end_date < {$today_format}) 的帳單。";
}


// --- 步驟 2 函式: 確保存在 status=0 的當前帳單 ---
function ensure_active_bill(PDO $db, array $account, DateTime $today): array {
    $accountId = $account['accountId'];
    $userId = $account['userId'];
    $billingDay = (int)$account['billingCycleDay'];
    $paymentDay = (int)$account['paymentDueDay'];
    $accountName = $account['accountName'];
    $createdAt = new DateTime($account['createdAt']);

    // 1. 查找現有的 active 帳單 (paid_status = 0, 尚未到結算日)
    $sqlActiveBill = "SELECT bill_id, period_end_date FROM `billsTable` WHERE account_id = ? AND paid_status = 0 LIMIT 1";
    $stmtActiveBill = $db->prepare($sqlActiveBill);
    $stmtActiveBill->execute([$accountId]);
    $activeBill = $stmtActiveBill->fetch(PDO::FETCH_ASSOC);

    if ($activeBill) {
        return [
        'bill_id' => (int)$activeBill['bill_id'],
        'message' => " -> 找到現有 active 帳單 ID: {$activeBill['bill_id']}，週期至 {$activeBill['period_end_date']}，無需新建。"
        ];
    }

    // 2. 如果沒有 active 帳單 (status=0)，則需要創建一個新的帳單週期。

    // 2.1 查找最後一筆帳單的結束日期
    $sqlLastBillEnd = "SELECT period_end_date FROM `billsTable`
    WHERE account_id = ? ORDER BY period_end_date DESC LIMIT 1";
    $stmtLastBillEnd = $db->prepare($sqlLastBillEnd);
    $stmtLastBillEnd->execute([$accountId]);
    $lastBillEnd = $stmtLastBillEnd->fetchColumn();

    // 計算新的週期開始日期 (如果沒有上期帳單，則從帳戶創建日開始)
    $periodStartDate = $lastBillEnd ? new DateTime($lastBillEnd) : $createdAt;
    $newPeriodStart = $periodStartDate;
    $newPeriodStart->modify('+1 day');
    $newPeriodStart->setTime(0, 0, 0);

    // 計算新的週期結束日期
    $newPeriodEnd = clone $newPeriodStart;
    $newPeriodEnd->setDate($newPeriodEnd->format('Y'), $newPeriodEnd->format('m'), $billingDay);

    // 確保結束日期在開始日期之後 (如果結帳日已經過了，則跳到下個月的結帳日)
    if ($newPeriodEnd <= $newPeriodStart) {
        $newPeriodEnd->modify('+1 month');
    }
    $newPeriodEnd->setTime(0, 0, 0);
    
    // 計算繳費期限 (Due Date)
    $newDueDate = clone $newPeriodEnd;
    $newDueDate->setDate($newDueDate->format('Y'), $newDueDate->format('m'), $paymentDay);

    // 調整繳費期限以確保在結帳日之後的合理時間
    if ($paymentDay < $billingDay) {
        // 例: 結帳日 25，繳費日 10 (下個月 10 號)
        if($newDueDate <= $newPeriodEnd){
            $newDueDate->modify('+1 month');
        }
    } else {
        // 例: 結帳日 10，繳費日 25 (當月 25 號)
        if($newDueDate < $newPeriodEnd){
            $newDueDate->modify('+1 month');
        }
    }
    $newDueDate->setTime(0, 0, 0);

    // 3. 建立新的空帳單記錄 (paid_status = 0)
    $sqlInsertBill = "INSERT INTO `billsTable`
    (user_id, account_id, accountName, period_start_date, period_end_date, transactions_amount, total_due, paid_amount, due_date, paid_status, is_user_modified, is_user_see)
    VALUES (?, ?, ?, ?, ?, 0, 0, 0, ?, ?, 0, 0)";
    $stmtInsertBill = $db->prepare($sqlInsertBill);
    $stmtInsertBill->execute([
    $userId,
    $accountId,
    $accountName,
    $newPeriodStart->format('Y-m-d'),
    $newPeriodEnd->format('Y-m-d'),
    $newDueDate->format('Y-m-d'),
    0 // paid_status = 0 (Active/未結算)
    ]);

    $newBillId = $db->lastInsertId();
    $message = " -> 成功建立新的 active 帳單 ID: {$newBillId}。週期: {$newPeriodStart->format('Y-m-d')} ~ {$newPeriodEnd->format('Y-m-d')}。";
    
    return [
    'bill_id' => (int)$newBillId,
    'message' => $message
    ];
}

// --- 步驟 3 函式: 綁定未結算交易 (支出) ---
function bind_unbilled_transactions(PDO $db, int $accountId, int $billId): string {
    // 1. 查詢該帳戶最新的 active 帳單的週期
    $sqlBillPeriod = "SELECT period_start_date, period_end_date, is_user_modified
    FROM `billsTable` WHERE bill_id = ?";
    $stmtBillPeriod = $db->prepare($sqlBillPeriod);
    $stmtBillPeriod->execute([$billId]);
    $bill = $stmtBillPeriod->fetch(PDO::FETCH_ASSOC);

    if (!$bill || $bill['is_user_modified'] == 1) {
    // 如果帳單被使用者手動修改過，則停止自動更新
    return " -> 帳單 ID: {$billId} (is_user_modified: {$bill['is_user_modified']}) 不允許系統自動綁定。";
    }

    $periodStart = $bill['period_start_date'];
    $periodEnd = $bill['period_end_date'];
    
    // 2. 查詢此帳單週期內所有未指定 bill_id (bill_id=0) 的【支出】交易 (add_minus=-1)
    $sqlUnbilledTransactions = "SELECT ts.s_id, ts.amount
    FROM `transactions_sub` ts
    JOIN `transactions` t ON ts.t_id = t.t_id
    WHERE ts.account_id = ? AND ts.bill_id = 0
    AND ts.add_minus = -1
    AND t.transactionDate >= ? AND t.transactionDate <= ?";
    $stmtUnbilled = $db->prepare($sqlUnbilledTransactions);
    $stmtUnbilled->execute([$accountId, $periodStart, $periodEnd]);
    $unbilledTransactions = $stmtUnbilled->fetchAll(PDO::FETCH_ASSOC);

    if (empty($unbilledTransactions)) {
    return " -> 帳單 ID: {$billId} 在週期內 ({$periodStart} ~ {$periodEnd}) 無新的未綁定支出交易。";
    }

    $totalAmountToAdd = 0;
    $sIdsToUpdate = [];
    foreach ($unbilledTransactions as $transaction) {
    $totalAmountToAdd += $transaction['amount'];
    $sIdsToUpdate[] = $transaction['s_id'];
    }

    // 3. 更新 `transactions_sub` 的 `bill_id`
    $placeholders = implode(',', array_fill(0, count($sIdsToUpdate), '?'));
    $sqlUpdateTransactions = "UPDATE `transactions_sub` SET bill_id = ? WHERE s_id IN ({$placeholders})";
    $stmtUpdateTransactions = $db->prepare($sqlUpdateTransactions);
    $stmtUpdateTransactions->execute(array_merge([$billId], $sIdsToUpdate));

    // 4. 更新 `billsTable` 的 transactions_amount
    $sqlUpdateBillAmount = "UPDATE `billsTable`
    SET transactions_amount = transactions_amount + ?
    WHERE bill_id = ?";
    $stmtUpdateBillAmount = $db->prepare($sqlUpdateBillAmount);
    $stmtUpdateBillAmount->execute([$totalAmountToAdd, $billId]);

    return " -> 帳單 ID: {$billId} 成功綁定 {$stmtUpdateTransactions->rowCount()} 筆支出交易 (金額: {$totalAmountToAdd})。";
}

// --- 步驟 5 函式: 最終檢查所有帳單狀態 ---
function finalize_bill_statuses(PDO $db, int $accountId, int $userId): string {
    $updatedCount = 0;
    
    // 獲取所有未全繳的帳單 (paid_status = -1, 0, 1)
    $sqlBills = "SELECT bill_id, transactions_amount,total_due, paid_amount
                 FROM `billsTable`
                 WHERE account_id = ? AND user_id = ? AND paid_status IN (-1,1)";
    $stmtBills = $db->prepare($sqlBills);
    $stmtBills->execute([$accountId, $userId]);
    $bills = $stmtBills->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($bills)) {
        return " -> 無帳單需要最終狀態檢查 (paid_status 皆 > 1)。";
    }

    foreach ($bills as $bill) {
        $billId = (int)$bill['bill_id'];
        $totalExpense = (float)($bill['total_due']>0?$bill['total_due']:$bill['transactions_amount']);
        $currentlyPaid = (float)$bill['paid_amount'];
        $newPaidStatus = -99; // 初始值

        if ($totalExpense <= 0) {
            $newPaidStatus = 3; // 3: 結清 (無消費)
        } else if ($currentlyPaid >= $totalExpense) {
            $newPaidStatus = 2; // 2: 已全繳 (Fully Paid)
        } else if ($currentlyPaid > 0) {
            $newPaidStatus = 1; // 1: 部分繳費 (Partially Paid)
        } else {
            $newPaidStatus = -1; // -1: 未繳費 (Unpaid/Outstanding)
        }
        
        $unPaid = max(0, $totalExpense - $currentlyPaid); // 確保應付金額不為負數

        // 只有當狀態或應付金額實際發生變化時才更新 (為了減少寫入操作)
        $sqlUpdate = "UPDATE `billsTable`
                      SET paid_status = ?, unPaid = ?
                      WHERE bill_id = ? AND (paid_status != ? OR unPaid != ?)";
        $stmtUpdate = $db->prepare($sqlUpdate);
        $stmtUpdate->execute([$newPaidStatus, $unPaid, $billId, $newPaidStatus, $unPaid]);
        
        if ($stmtUpdate->rowCount() > 0) {
            $updatedCount++;
        }
    }
    
    return " -> 最終狀態檢查完成。更新了 {$updatedCount} 筆帳單的 paid_status/unPaid";
}