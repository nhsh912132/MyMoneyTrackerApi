<?php
// get_unbilled_transactions.php

    include '../functions.php';
    header('Content-Type: application/json');

    // 檢查使用者是否已登入
    if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'NoUser: Session userId not found or invalid. Please log in.']);
        exit(); 
    }
        
    $userId = $_SESSION['userId'];

    $accountId = isset($_POST['accountId']) ? (int)$_POST['accountId'] : 0;
    $billId = isset($_POST['billId']) ? (int)$_POST['billId'] : 0;

    if ($accountId <= 0 || $billId <= 0) {
        echo json_encode(['status' => 'error', 'message' => '無效的參數。']);
        exit;
    }

    try {
        $db = openDB($db_server, $db_name, $db_user, $db_passwd);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); 
        $db->beginTransaction(); // **開始事務處理：確保主表和所有子表記錄同時成功或同時失敗**
        // 根據 billId 獲取帳單週期
        $sqlBillPeriod = "SELECT period_start_date, period_end_date FROM `billsTable` WHERE bill_id = ? AND user_id = ?";
        $stmtPeriod = $db->prepare($sqlBillPeriod);
        $stmtPeriod->execute([$billId, $userId]);
        $billPeriod = $stmtPeriod->fetch(PDO::FETCH_ASSOC);

        if (!$billPeriod) {
            echo json_encode(['status' => 'error', 'message' => '找不到對應的帳單。']);
            exit;
        }

        // 將日期字串轉換為 DateTime 物件
        $startDateObj = new DateTime($billPeriod['period_start_date']);
        $endDateObj = new DateTime($billPeriod['period_end_date']);
        
        // 將開始日期往前推 15 天
        $startDateObj->modify('-15 days');
        // 將結束日期往後推 15 天
        $endDateObj->modify('+15 days');
        
        // 重新格式化為 YYYY-MM-DD 字串以供 SQL 查詢使用
        $startDate = $startDateObj->format('Y-m-d');
        $endDate = $endDateObj->format('Y-m-d');

        // 查詢在該週期內、未綁定、且屬於該帳戶的交易
        $sql = "SELECT ts.s_id, ts.account_id, ts.categoryId, ts.amount, ts.add_minus,
                    t.t_id, t.transactionDate, t.ps
                FROM `transactions_sub` ts
                JOIN `transactions` t ON ts.t_id = t.t_id
                WHERE ts.userId = ? AND ts.account_id = ? 
                AND ts.bill_id = 0 AND ts.add_minus = -1
                AND t.transactionDate >= ? AND t.transactionDate <= ?
                ORDER BY t.transactionDate DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$userId, $accountId, $startDate, $endDate]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'data' => $transactions]);

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => '資料庫錯誤: ' . $e->getMessage()]);
    }
?>