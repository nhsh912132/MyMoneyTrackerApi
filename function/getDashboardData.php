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

    $input = json_decode(file_get_contents('php://input'), true);

    // 1. 取得指定的年月份，若未提供則預設為當前
    $year  = isset($input['year'])  ? (int)$input['year']  : (int)date('Y');
    $month = isset($input['month']) ? (int)$input['month'] : (int)date('m');

    // 定義該月份範圍
    $startDate = "{$year}-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
    $endDate   = date('Y-m-t', strtotime($startDate));

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

        $sqlSum = "
            SELECT ts.amount, ts.currency, ts.add_minus 
            FROM transactions t
            JOIN transactions_sub ts ON t.t_id = ts.t_id
            WHERE t.userId = :userId 
              AND t.transactionDate BETWEEN :start AND :end
              AND ts.categoryId NOT IN (-10, -20)
        ";
        
        $stmtSum = $db->prepare($sqlSum);
        $stmtSum->execute([
            ':userId' => $userId,
            ':start'  => $startDate,
            ':end'    => $endDate
        ]);

        while ($row = $stmtSum->fetch(PDO::FETCH_ASSOC)) {
            $converted = convertCurrency($row['amount'], $row['currency'], $useCurrency, $exchangeRates);
            if ($row['add_minus'] == 1) {
                $totalIncome += $converted;
            } else if ($row['add_minus'] == -1) {
                $totalExpense += $converted;
            }
        }

        // ===============================================
        // 3. 封裝並回傳 JSON 數據
        // ===============================================
        $response = [
            'status' => 'success',
            'year' => $year,   // 回傳年份確認
            'month' => $month, // 回傳月份確認
            'input' => json_encode($input),
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