<?php
    //此api給自動化使用，定期更新匯率資料表
    include("functions.php"); 

    $apiKey = "5ac116f9354af12d81dece35"; // 從 ExchangeRate-API 取得的 API Key
    // $baseCurrency = $_SESSION['useCurrency']; // 基礎貨幣，這裡是新臺幣
    $targetCurrencies = ["USD", "JPY"]; // 你想要獲取的目標貨幣陣列
    $lastUpdated = date("Y-m-d H:i:s");
    $returnEnd = [];

    try {
        // --- 更新資料庫中的匯率 ---
        $db = openDB($db_server, $db_name, $db_user, $db_passwd);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Set error mode to throw exceptions
        // 使用 PDO 的預備語句，這能有效防止 SQL 注入

        // 步驟一：從 userTable 中獲取所有獨特的使用者幣別
        $sql = "SELECT DISTINCT useCurrency FROM `userTable` WHERE useCurrency IS NOT NULL";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $baseCurrencies = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($baseCurrencies)) {
            echo "沒有找到任何使用者設定的基礎幣別。腳本結束。\n";
            exit;
        }
        echo "找到以下獨特的基礎幣別: " . implode(', ', $baseCurrencies) . "\n";

        $sqlDelRates = "DELETE FROM `exchange_rates`  ";
        $stmtDelRates = $db->prepare($sqlDelRates);
        $stmtDelRates->execute();

        // 步驟二：遍歷每個基礎幣別，並呼叫 API 獲取匯率
        foreach ($baseCurrencies as $base) {
            // --- 呼叫 ExchangeRate-API 獲取匯率 ---
            // 使用 file_get_contents 進行簡單的 API 呼叫
            $apiUrl = "https://v6.exchangerate-api.com/v6/{$apiKey}/latest/{$base}";
            $response = @file_get_contents($apiUrl);

            if ($response === FALSE) {
                $returnEnd['status_ExchangeRate']="error";
                $returnEnd['message_ExchangeRate']="呼叫 ExchangeRate-API 失敗，請檢查你的 API Key 或網路連線";
            }
            $data = json_decode($response, true);
            if ($data["result"] !== "success") {
                $returnEnd['status_ExchangeRate']="success";
                $returnEnd['message_ExchangeRate']="API 回傳錯誤: " . $data["error-type"];
                exit;
            }
            // echo "成功從 API 取得匯率資料。<br>";
            $returnEnd['status_ExchangeRate']="success";
            $returnEnd['message_ExchangeRate']=$data;

            $stmt = $db->prepare("
                INSERT INTO exchange_rates (base_currency, target_currency, rate, last_updated)
                VALUES (:base_currency, :target_currency, :rate, :last_updated)
                ON DUPLICATE KEY UPDATE
                rate = VALUES(rate),
                last_updated = VALUES(last_updated)
            ");

            foreach ($targetCurrencies as $currency) {
                $rate = $data['conversion_rates'][$currency];
                
                if ($rate) {
                    $stmt->bindParam(':base_currency', $base);
                    $stmt->bindParam(':target_currency', $currency);
                    $stmt->bindParam(':rate', $rate);
                    $stmt->bindParam(':last_updated', $lastUpdated);
                    
                    $stmt->execute();
                    // echo "成功更新 {$base} 對 {$currency} 的匯率: {$rate}<br>";
                } else {
                    echo "API 資料中未找到 {$currency} 的匯率。<br>";
                }
            }
            
            // echo "所有匯率更新作業已完成。<br>";
            $returnEnd['status_db']="success";
            $returnEnd['status_message']="更新成功";
        }

        echo json_encode($returnEnd);

    } catch (PDOException $e) {
        $returnEnd['status_db']="error";
        $returnEnd['status_message']="Database Error: " . $e->getMessage() . " --- SQLSTATE: " . $e->getCode();
        echo json_encode($returnEnd);
    } catch (Exception $e) {
        $returnEnd['status_db']="error";
        $returnEnd['status_message']="General Error: " . $e->getMessage();
        echo json_encode($returnEnd);
    } finally {
        // 關閉資料庫連線
        if (isset($db)) {
            $db = null;
        }
    }

?>
