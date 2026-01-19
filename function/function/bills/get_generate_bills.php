<?php
    // 開啟錯誤報告，用於開發調試
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    include("../functions.php"); 

    header('Content-Type: application/json; charset=utf-8');


    // 檢查使用者是否已登入
    if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'NoUser: Session userId not found or invalid. Please log in.']);
        exit(); 
    }

    $userId = $_SESSION['userId'];

  // 接收年份參數，如果沒有則預設為今年
    $year = isset($_POST['year']) ? (int)$_POST['year'] : date('Y');

    // 檢查年份是否為有效數字
    if (!is_numeric($year) || $year < 2000 || $year > 2100) {
        echo json_encode(['status' => 'error', 'message' => '無效的年份。']);
        exit;
    }

    try {
        $db = openDB($db_server, $db_name, $db_user, $db_passwd);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); 
        // 查詢指定年份的所有帳單記錄，並按月份排序
        $sql = "SELECT b.*, a.accountName
                FROM `billsTable` b
                JOIN `accountTable` a ON b.account_id = a.accountId
                WHERE b.user_id = ? AND YEAR(b.period_end_date) = ?
                ORDER BY b.period_end_date DESC"; // 按照結算日期降序排列
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$userId, $year]);
        $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 將帳單記錄按月份分組
        $monthlyData = [];
        foreach ($bills as $bill) {
            // 以帳單結束月份作為分組依據
            $month = date('n', strtotime($bill['period_end_date']));
            $monthName = $month . '月';
            
            // 確保月份分組存在
            if (!isset($monthlyData[$monthName])) {
                $monthlyData[$monthName] = [
                    'month' => $monthName,
                    'month_data' => []
                ];
            }

            $sql_ts = "SELECT COUNT(*) AS tCount FROM `transactions_sub` WHERE `bill_id`=? AND `categoryId`!=-20";
            $stmt_ts = $db->prepare($sql_ts);
            $stmt_ts->execute([$bill['bill_id']]);
            $arr = $stmt_ts->fetchAll(PDO::FETCH_ASSOC);
            $tCount = $arr[0]['tCount'];
            
            // 將帳單資料整理後加入對應的月份分組
            $monthlyData[$monthName]['month_data'][] = [
                'accountId' => (int)$bill['account_id'],
                'accountName' => $bill['accountName'],
                'billId' => (int)$bill['bill_id'],
                'periodStartDate' => $bill['period_start_date'],
                'periodEndDate' => $bill['period_end_date'],
                'transactionsAmount' => (float)$bill['transactions_amount'],
                'totalDue' => (float)$bill['total_due'],
                'paidAmount' => (float)$bill['paid_amount'],
                'dueDate' => $bill['due_date'],
                'paidStatus' => (int)$bill['paid_status'],
                'isUserModified' => (bool)$bill['is_user_modified'],
                'transaction_count' => $tCount,
            ];
        }
        
        // 將關聯陣列轉換為索引陣列，並確保月份從新到舊排序
        $sortedData = array_values($monthlyData);

        // 按照月份降序排列
        usort($sortedData, function($a, $b) {
            return (int)str_replace('月', '', $b['month']) <=> (int)str_replace('月', '', $a['month']);
        });
        
        echo json_encode(['status' => 'success', 'data' => $sortedData]);

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => '資料庫錯誤: ' . $e->getMessage()]);
    }

//====================

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