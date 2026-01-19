<?php
// 在帳單頁面載入“選中之帳單”的交易
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
    $userId = $_SESSION['userId'];

    $billId = isset($_POST['billId']) ? (int)$_POST['billId'] : 0;
    if ($billId <= 0) {
        echo json_encode(['status' => 'error', 'message' => '無效的帳單ID。']);
        exit;
    }

    try {
        $db = openDB($db_server, $db_name, $db_user, $db_passwd);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); 
        $db->beginTransaction(); // **開始事務處理：確保主表和所有子表記錄同時成功或同時失敗**

        // 查詢與該 bill_id 關聯的所有交易
        $sql = "SELECT ts.s_id, ts.account_id, ts.categoryId, ts.amount, ts.add_minus,
                    t.t_id, t.transactionDate, t.ps
                FROM `transactions_sub` ts
                JOIN `transactions` t ON ts.t_id = t.t_id
                WHERE ts.userId = ? AND ts.bill_id = ? AND ts.add_minus = -1";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$userId, $billId]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'data' => $transactions]);

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => '資料庫錯誤: ' . $e->getMessage()]);
    }
?>