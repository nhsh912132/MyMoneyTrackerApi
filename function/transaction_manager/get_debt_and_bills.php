<?php
    //交易紀錄頁面“帳單提醒”
    // 開啟錯誤報告，用於開發調試
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    include("../functions.php"); 

    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => '未登入或會話已過期。請重新登入。']);
        exit(); 
    }

    $userId = $_SESSION['userId'];
    $output = [];
    $db = null;

    try {
        $db = openDB($db_server, $db_name, $db_user, $db_passwd);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $sqlAccounts = "SELECT `accountId`, `accountName`, `accountTypeId`, `initialBalance`, `currentBalance`, `billingCycleDay`, `paymentDueDay`, `createdAt` FROM `accountTable` WHERE `userId` = ? AND  `accountTypeId` = 14";
        $stmtAccounts = $db->prepare($sqlAccounts);
        $stmtAccounts->execute([$userId]);
        $accounts = $stmtAccounts->fetchAll(PDO::FETCH_ASSOC);

        foreach ($accounts as $account) {
            $accountType = $account['accountTypeId'];
            
            $accountData = [
                "accountId" => $account['accountId'],
                "accountName" => $account['accountName'],
                "accountType" => $accountType
            ];

            if ($accountType == 11) { // 處理信用卡帳戶
                // 呼叫輔助函式來獲取帳單資料
                $accountData['lastPeriod'] = getLastPeriodData($db, $userId, $account);
                $accountData['currentPeriod'] = getCurrentPeriodData($db, $userId, $account);
            } else if ($accountType == 14) { // 處理一般負債帳戶
                // 負債帳戶只有一期，可以直接計算
                $currentBalance = $account['currentBalance'];
                $totalDue = ($currentBalance < 0) ? abs($currentBalance) : 0;
                $periodStatus = '無需結算';
                $transactionsAmount = $totalDue;

                $accountData['currentPeriod'] = [
                    "transactionsAmount" => $transactionsAmount,
                    "paidAmount" => 0,
                    "totalDue" => $totalDue,
                    "period" => null, // 負債沒有週期
                    "dueDate" => "無",
                    "periodStatus" => $periodStatus,
                    "Paid_status" => ($totalDue > 0) ? "未繳費" : "不用繳"
                ];
            }
            
            $output[] = $accountData;
        }
        
        echo json_encode(['status' => 'success', 'data' => $output]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => '資料庫操作失敗: ' . $e->getMessage(),
            'sqlstate' => $e->getCode(),
        ]);
    } finally {
        $db = null;
    }

//====================

    /**
     * 獲取並計算指定信用卡帳戶的「上一期」已結算帳單資料。
     *
     * @param PDO $db 資料庫連線物件
     * @param int $userId 使用者 ID
     * @param array $account 帳戶資料陣列
     * @return array 包含上一期帳單資訊的關聯陣列
     */
    function getLastPeriodData(PDO $db, $userId, $account) {
        $today = new DateTime();
        $billingDay = (int)$account['billingCycleDay'];
        $paymentDay = (int)$account['paymentDueDay'];
        $initialBalance = $account['initialBalance'];   
        $accountCreatedAt = new DateTime($account['createdAt']);
        
        // 計算上一期週期
        $periodStart = new DateTime();
        $periodStart->setDate($today->format('Y'), $today->format('m'), $billingDay);
        $periodStart->modify('-2 months');
        $periodStart->modify('+1 day');
        $periodStart->setTime(0, 0, 0); // 確保時間為當天開始
        
        $periodEnd = new DateTime();
        $periodEnd->setDate($today->format('Y'), $today->format('m'), $billingDay);
        $periodEnd->modify('-1 month');
        $periodEnd->setTime(0, 0, 0); // **確保時間為當天開始**

        // **修正邏輯：初始金額只在帳戶創建後的第一個結算週期內被計入**
        $transactionsAmount = 0;
        if ($accountCreatedAt >= $periodStart && $accountCreatedAt <= $periodEnd) {
            $transactionsAmount += $initialBalance;
        }

        // 查詢上一期所有相關交易
        $sqlTransactions = "SELECT SUM(ts.amount ) AS totalAmount
                            FROM `transactions_sub` ts
                            JOIN `transactions` t ON ts.t_id = t.t_id
                            WHERE ts.userId = ? AND ts.account_id = ? AND t.transactionDate >= ? AND t.transactionDate < ? AND ts.add_minus = -1";
        $stmtTransactions = $db->prepare($sqlTransactions);
        $stmtTransactions->execute([$userId, $account['accountId'], $periodStart->format('Y-m-d H:i:s'), $periodEnd->format('Y-m-d H:i:s')]);
        $transactionsAmount += (float)$stmtTransactions->fetchColumn();



        $dueDateStart = new DateTime();
        $dueDateStart->setDate($today->format('Y'), $today->format('m'), $billingDay);
        $dueDateStart->modify('-1 month');
        // $dueDateStart->modify('+1 day');
        $dueDateStart->setTime(0, 0, 0); // 確保時間為當天開始
        
        // 計算繳費期限
        $dueDateEnd = new DateTime();
        $dueDateEnd->setDate($dueDateStart->format('Y'), $dueDateStart->format('m'), $paymentDay);
        if ($billingDay >= $paymentDay) {
            $dueDateEnd->modify('+1 month');
        }
        $dueDateEnd->setTime(19, 0, 0); // 確保時間為當天開始

       

        // 查詢已繳金額 (繳費期間)
        $sqlPaid = "SELECT SUM(ts.amount) AS paidAmount
                    FROM `transactions_sub` ts
                    JOIN `transactions` t ON ts.t_id = t.t_id
                    WHERE ts.userId = ? AND ts.account_id = ? AND t.transactionDate >= ? AND t.transactionDate < ? AND ts.add_minus = 1";
        $stmtPaid = $db->prepare($sqlPaid);
        $stmtPaid->execute([$userId, $account['accountId'], $dueDateStart->format('Y-m-d H:i:s'), $dueDateEnd->format('Y-m-d H:i:s')]);
        $paidAmount = (float)$stmtPaid->fetchColumn();

        $totalDue = $transactionsAmount - $paidAmount;
        $periodStatus = ($today > $dueDateEnd) ? '已逾期' : '已結算';
        if ($totalDue <= 0) {
            $totalDue = 0;
            $periodStatus = '已結算';
        }

        $Paid_status = getPaidStatus($transactionsAmount, $paidAmount);

        return [
            "transactionsAmount" => $transactionsAmount,
            "paidAmount" => $paidAmount,
            "totalDue" => $totalDue,
            "period" => $periodStart->format('m-d H:i:s') . ' ~ ' . $periodEnd->format('m-d H:i:s'),
            "dueDate" => $dueDateEnd->format('Y-m-d'),
            "due_period" => $dueDateStart->format('m-d H:i:s') . ' ~ ' .$dueDateEnd->format('m-d H:i:s'),
            "periodStatus" => $periodStatus,
            "Paid_status" => $Paid_status
        ];
    }

    /**
     * 獲取並計算指定信用卡帳戶的「本期/新一期」未結算帳單資料。
     *
     * @param PDO $db 資料庫連線物件
     * @param int $userId 使用者 ID
     * @param array $account 帳戶資料陣列
     * @return array 包含本期帳單資訊的關聯陣列
     */
    function getCurrentPeriodData(PDO $db, $userId, $account) {
        $today = new DateTime();
        $billingDay = (int)$account['billingCycleDay'];
        $initialBalance = $account['initialBalance'];
        $accountCreatedAt = new DateTime($account['createdAt']);
        
        // 計算本期週期
        $periodStart = new DateTime();
        $periodStart->setDate($today->format('Y'), $today->format('m'), $billingDay);
        $periodStart->modify('-1 month');
        // $periodStart->modify('+1 day');
        $periodStart->setTime(0,0,0);

        $periodEnd = new DateTime();
        $periodEnd->setDate($today->format('Y'), $today->format('m'), $billingDay);
        $periodEnd->setTime(0,0,0);

        // 如果今天已過結算日，則計算下期週期
        if ($today->format('d') >= $billingDay) {
            $periodStart->setDate($today->format('Y'), $today->format('m'), $billingDay);
            $periodStart->modify('+1 day');
            $periodEnd->setDate($today->format('Y'), $today->format('m'), $billingDay);
            $periodEnd->modify('+1 month');
        }

        // **修正：本期不計入初始金額，因為它只屬於創建的第一個結算週期**
        $transactionsAmount = 0;
        
        // 查詢本期所有相關交易
        $sqlTransactions = "SELECT SUM(ts.amount ) AS totalAmount
                            FROM `transactions_sub` ts
                            JOIN `transactions` t ON ts.t_id = t.t_id
                            WHERE ts.userId = ? AND ts.account_id = ? AND t.transactionDate >= ? AND t.transactionDate <= ? AND ts.add_minus = -1 ";
        $stmtTransactions = $db->prepare($sqlTransactions);
        $stmtTransactions->execute([$userId, $account['accountId'], $periodStart->format('Y-m-d H:i:s'), $periodEnd->format('Y-m-d H:i:s')]);
        $transactionsAmount += (float)$stmtTransactions->fetchColumn();

        
        
        $paidAmount = 0; // 本期未結算，已繳金額為 0
        $totalDue = $transactionsAmount - $paidAmount;
        $Paid_status = getPaidStatus($transactionsAmount, $paidAmount);

        return [
            "transactionsAmount" => $transactionsAmount,
            "paidAmount" => $paidAmount,
            "totalDue" => $totalDue,
            "period" => $periodStart->format('m-d') . ' ~ ' . $periodEnd->format('m-d'),
            "dueDate" => "未結算",
            "periodStatus" => "未結算",
            "Paid_status" => $Paid_status
        ];
    }

    /**
     * 輔助函式：根據金額計算繳費狀態
     */
    function getPaidStatus($transactionsAmount, $paidAmount) {
        if ($transactionsAmount > 0) {
            if ($paidAmount >= $transactionsAmount) {
                return "已全繳";
            } else if ($paidAmount > 0) {
                return "未全繳";
            } else {
                return "未繳費";
            }
        } else {
            return "不用繳";
        }
    }

?>