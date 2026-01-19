<?php
// update_bill_transactions.php
    // error_reporting(E_ALL);
	// ini_set('display_errors', 1);
    include '../functions.php';
    header('Content-Type: application/json');

    // 檢查使用者是否已登入
    if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'NoUser: Session userId not found or invalid. Please log in.']);
        exit(); 
    }
    
    $input = json_decode(file_get_contents('php://input'), true);

    $billId = isset($input['billId']) ? (int)$input['billId'] : 0;
    $boundSIds = isset($input['boundSIds']) ? $input['boundSIds'] : [];
    $unboundSIds = isset($input['unboundSIds']) ? $input['unboundSIds'] : [];

    if ($billId <= 0) {
        echo json_encode(['status' => 'error', 'message' => '無效的帳單ID。']);
        exit;
    }

    try {
        $db = openDB($db_server, $db_name, $db_user, $db_passwd);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); 
        $db->beginTransaction();

        // 更新 transactions_sub 表
        //更新前紀錄
        log_audit_action($db, 'UPDATE_BEFORE', 'transactions_sub','bill_id', $billId, $userId, 'update_bill_transactions.php');
        // 1. 將新的交易綁定到帳單
        if (!empty($boundSIds)) {
            $placeholders = implode(',', array_fill(0, count($boundSIds), '?'));
            $sqlUpdateBound = "UPDATE `transactions_sub` SET bill_id = ? WHERE s_id IN ({$placeholders})";
            $stmtUpdateBound = $db->prepare($sqlUpdateBound);
            $stmtUpdateBound->execute(array_merge([$billId], $boundSIds));
        }
        
        // 2. 將舊的交易解除綁定
        if (!empty($unboundSIds)) {
            $placeholders = implode(',', array_fill(0, count($unboundSIds), '?'));
            $sqlUpdateUnbound = "UPDATE `transactions_sub` SET bill_id = 0 WHERE s_id IN ({$placeholders})";
            $stmtUpdateUnbound = $db->prepare($sqlUpdateUnbound);
            $stmtUpdateUnbound->execute(array_merge($unboundSIds));
        }
        //更新後紀錄
        $sqlSelectUpdated = "SELECT * FROM transactions_sub WHERE bill_id = ?";
		$stmtSelectUpdated = $db->prepare($sqlSelectUpdated);
		$stmtSelectUpdated->execute([$bill_id]);
		$afterData = $stmtSelectUpdated->fetch(PDO::FETCH_ASSOC);
        log_audit_action($db, 'UPDATE_AFTER', 'transactions_sub','bill_id', $billId, $userId, 'update_bill_transactions.php',$afterData);


        // 更新 billsTable 的金額
        $sqlCalculateAmount = "SELECT SUM(amount) FROM `transactions_sub` WHERE bill_id = ?";
        $stmtCalculate = $db->prepare($sqlCalculateAmount);
        $stmtCalculate->execute([$billId]);
        $newAmount = (float)$stmtCalculate->fetchColumn();

        $sqlUpdateBill = "UPDATE `billsTable` SET transactions_amount = ?, total_due = ? ,is_user_modified = 1 WHERE bill_id = ?";
        $stmtUpdateBill = $db->prepare($sqlUpdateBill);

        //更新前紀錄
        log_audit_action($db, 'UPDATE_BEFORE', 'billsTable','bill_id', $billId, $userId, 'update_bill_transactions.php');

        $stmtUpdateBill->execute([$newAmount, $newAmount, $billId]);
        
        //更新後紀錄
        $sqlSelectUpdated = "SELECT * FROM billsTable WHERE bill_id = ?";
		$stmtSelectUpdated = $db->prepare($sqlSelectUpdated);
		$stmtSelectUpdated->execute([$bill_id]);
		$afterData = $stmtSelectUpdated->fetch(PDO::FETCH_ASSOC);
        log_audit_action($db, 'UPDATE_AFTER', '','', '', $userId, 'update_bill_transactions.php',$afterData);

        
        $db->commit();
        echo json_encode(['status' => 'success', 'message' => '帳單已成功更新。']);

    } catch (PDOException $e) {
        $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => '資料庫錯誤: ' . $e->getMessage()]);
    }
?>