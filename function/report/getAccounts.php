<?php
	include("../functions.php");
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

    $atSQL="SELECT * FROM `accountTable` WHERE `userId` = $userid AND `status` = '1' ";
    $atRes = $db -> query($atSQL);
    $atRows = $atRes -> fetchAll();
    $atCount = $atRes -> rowCount();

    if ($atCount == 0) {
            // 如果沒有自己的帳戶，返回空陣列
            echo json_encode([]);
    } else {
        // 遍歷每個帳戶，進行貨幣轉換
        foreach ($atRows as $account) {
            // 獲取帳戶本身的幣別和當前餘額
            $accountCurrency = $account['currency']; // 帳戶自己的幣別，例如 'USD'
            $currentBalance = (float)$account['currentBalance']; // 確保是數字類型

            $convertedBalance = 0.0;

            // 進行貨幣轉換
            if ($accountCurrency === $useCurrency) {
                // 如果帳戶幣別與使用者設定幣別相同，則直接使用原餘額
                $convertedBalance = $currentBalance;
            } else {
                // 如果不同，則進行匯率轉換
                // 確保 convertCurrencyFixedRate 函數在 functions.php 中且能正確處理
                // $convertedBalance = convertCurrencyFixedRate($currentBalance, $accountCurrency, $useCurrency);
                $convertedBalance = null;
                // 處理轉換失敗的情況 (例如匯率不存在)
                if ($convertedBalance === null) { // 假設 convertCurrencyFixedRate 在失敗時返回 null
                    error_log("貨幣轉換失敗：從 {$accountCurrency} 到 {$useCurrency}，金額：{$currentBalance}");
                    // 可以選擇在這裡設置一個預設值，或跳過此轉換
                    $convertedBalance = $currentBalance; // 轉換失敗則顯示原始餘額
                }
            }
            
            // 將轉換後的餘額添加到當前帳戶的數據中
            // 這裡新增一個名為 'currentBalanceConverted' 的欄位
            $account['currentBalanceConverted'] = $convertedBalance;

            // 將處理後的帳戶數據添加到 results 陣列
            $accounts[] = $account;
        }
        
        echo json_encode($accounts);
    }

	$db = null;
?>