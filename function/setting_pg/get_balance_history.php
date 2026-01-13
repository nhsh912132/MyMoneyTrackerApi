<?php
    //餘額變動歷史記錄獲取
	include("../functions.php");
    // 開啟錯誤報告，用於開發調試
	error_reporting(E_ALL);
	ini_set('display_errors', 1);
       // 檢查使用者是否已登入
    if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'NoUser: Session userId not found or invalid.']);
        exit(); // 如果沒有有效用戶，立即退出
    }

    $userid = $_SESSION['userId'];
    $accountId = $_GET['accountId'];

	$db = openDB($db_server,$db_name,$db_user,$db_passwd);

    $atSQL="SELECT 
            h_id, 
            account_id, 
            transactions_date, 
            `before`, 
            `after`, 
            currency, 
            t_id 
        FROM 
            balance_history
        WHERE 
            account_id = '$accountId'
        ORDER BY 
            transactions_date ASC, h_id ASC ";
    $atRes = $db -> query($atSQL);
    $atRows = $atRes -> fetchAll();
    $atCount = $atRes -> rowCount();

    if ($atCount == 0) {
        // 如果沒有自己的帳戶，返回空陣列
        // echo json_encode([]);
        echo json_encode([
            'status' => 'success',
            'data' => [],
        ]);
    } else {
        // 遍歷每個帳戶，進行貨幣轉換
        foreach ($atRows as $account) {
            // 將處理後的帳戶數據添加到 results 陣列
            $accounts[] = $account;
        }
        
        echo json_encode([
            'status' => 'success',
            'data' => $accounts
        ]);
        // echo json_encode($accounts);
    }

	$db = null;
?>