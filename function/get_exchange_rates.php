<?php
	include("functions.php");
    // 開啟錯誤報告，用於開發調試
	error_reporting(E_ALL);
	ini_set('display_errors', 1);
       // 檢查使用者是否已登入
    if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'NoUser: Session userId not found or invalid.']);
        exit(); // 如果沒有有效用戶，立即退出
    }

    $userid = $_SESSION['userId'];

     // 獲取使用者設定的主要幣別，如果不存在則預設為 'TWD'
    $useCurrency = isset($_SESSION['useCurrency']) ? $_SESSION['useCurrency'] : 'TWD';

	$db = openDB($db_server,$db_name,$db_user,$db_passwd);

    $atSQL="SELECT * FROM `exchange_rates` ";
    $atRes = $db -> query($atSQL);
    $atRows = $atRes -> fetchAll();
    $atCount = $atRes -> rowCount();

    if ($atCount > 0) {
        echo json_encode([
            'status' => 'success', 
            'json' => $atRows,
            'currency' => $_SESSION['useCurrency'] 
        ]);
    }else{
        echo json_encode(['status' => 'success', 'json' => []]);
    }

	$db = null;
?>