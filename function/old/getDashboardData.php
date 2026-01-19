<?php
    //使用頁面：dashboard、transactions_manager
    //獲取“總餘額、本期支出、本期收入”小卡片用的

    // 設定 HTTP 標頭為 JSON，防止亂碼
    header('Content-Type: application/json; charset=utf-8');

    // 開啟錯誤報告，用於開發調試
	// error_reporting(E_ALL);
	// ini_set('display_errors', 1);

    include("functions.php"); 

    // 確保使用者已登入
    if (!isset($_SESSION['userId'])) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => '未登入或會話已過期']);
        exit();
    }

    $userId = $_SESSION['userId'];
    $useCurrency = $_SESSION['useCurrency'];

    $db = openDB($db_server,$db_name,$db_user,$db_passwd);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    

    /**
     * 將給定金額從來源幣別轉換成目標幣別
     * @param float $amount
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param array $rates 匯率陣列
     * @return float
     */
    

    try {
        // 獲取匯率資料
        $exchangeRates = getExchangeRates($db);

        // ===============================================
        // 1. 計算總餘額
        // ===============================================
        $totalBalance = 0;

        $sql1="SELECT `currentBalance`, `currency` FROM `accountTable` WHERE `userId` = '$userId' AND `status` = 1 ";
        $res1 = $db -> query($sql1);
        $rows1 = $res1 -> fetchAll();
        $atCount = $res1 -> rowCount();

        foreach($rows1 as $row){
            $convertedAmount = convertCurrency($row['currentBalance'], $row['currency'], $useCurrency, $exchangeRates);
            $totalBalance += $convertedAmount;
        }
        

        // ===============================================
        // 2. 計算本期支出與收入
        // ===============================================
        $totalIncome = 0;
        $totalExpense = 0;

        // 定義本期範圍，例如：本月
        $firstDayOfMonth = date('Y-m-01');
        $lastDayOfMonth = date('Y-m-t');


        $sql2="SELECT `t_id` FROM `transactions` 
                WHERE `userId` = '$userId' AND `transactionDate` BETWEEN '$firstDayOfMonth' AND '$lastDayOfMonth' ";
        $res2 = $db -> query($sql2);
        $rows2 = $res2 -> fetchAll();
        $count2 = $res2 -> rowCount();

        $transactionIds = [];
        foreach($rows2 as $row2){
            $transactionIds[] = $row2['t_id'];
        }
        $errorSqls = [];
        if (!empty($transactionIds)) {
            // 查詢子交易表
            foreach($transactionIds as $tid){
                $sql3="SELECT `amount`, `currency`, `add_minus` FROM `transactions_sub` WHERE `t_id` = '$tid' AND `categoryId` != '-10' AND `categoryId` != '-20'  ";
                $res3 = $db -> query($sql3);
                $rows3 = $res3 -> fetchAll();
                $count3 = $res3 -> rowCount();
                
                foreach($rows3 as $row){
                    $convertedAmount = convertCurrency($row['amount'], $row['currency'], $useCurrency, $exchangeRates);

                    if ($row['add_minus'] == 1) {
                        $totalIncome += $convertedAmount;
                    } else if ($row['add_minus'] == -1) {
                        $totalExpense += $convertedAmount;
                    }
                }
            }
            
           
        }

        // ===============================================
        // 3. 封裝並回傳 JSON 數據
        // ===============================================
        $response = [
            'status' => 'success',
            'totalBalance' => round($totalBalance, 2),
            'totalIncome' => round($totalIncome, 2),
            'totalExpense' => round($totalExpense, 2),
            'currency' => $useCurrency,
            'errorSqls' =>$errorSqls,
        ];

        echo json_encode($response);

    } catch (PDOException $e) {
        // 關鍵！在這裡輸出詳細的錯誤訊息
        error_log("Database Error in addAccount.php: " . $e->getMessage() . " --- SQLSTATE: " . $e->getCode());
        // 為了調試目的，直接輸出到響應體中 (生產環境不建議這樣做，以免暴露敏感資訊)
        echo "false_db_error: " . $e->getMessage() . " (SQLSTATE: " . $e->getCode() . ")";
        // 或者更詳細：
        // echo "false_db_error. Details: " . $e->getMessage() . 
        //      " | SQLSTATE: " . $e->getSQLSTATE() . 
        //      " | Error Code: " . $e->getCode();

    } catch (Exception $e) {
        // Catch any other general errors
        error_log("General Error in manager_addProduct.php: " . $e->getMessage());
        echo "false_general_error";
    } finally {
        // Close DB connection
        $db = null;
    }

    // ===============================================
    // 匯率轉換函式
    // 假設你有一個函式可以從資料庫獲取匯率
    // ===============================================
    /**
     * 假設這是一個從資料庫獲取匯率的函式。
     * 資料庫中應存有 TWD 到其他幣別的匯率。
     * 格式應為 array(
     * array('base_currency' => 'TWD', 'target_currency' => 'USD', 'rate' => '0.033440'),
     * array('base_currency' => 'TWD', 'target_currency' => 'JPY', 'rate' => '4.943600'),
     * )
     * @return array 匯率陣列
     */
    function getExchangeRates($db) {
        $rates = [];
        $sql = "SELECT `base_currency`, `target_currency`, `rate` FROM `exchange_rates` ";
        
        $res = $db -> query($sql);
        $rows = $res -> fetchAll();
        $count = $res -> rowCount();
        
        if ($count > 0) {
            foreach($rows as $row){
                $rates[] = $row;
            }
        }
        return $rates;
    }
?>