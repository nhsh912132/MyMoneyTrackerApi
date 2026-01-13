<?php
// get_monthly_trend.php - 獲取近月收支趨勢數據

include("../functions.php");
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 檢查使用者是否已登入
if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => '未登入或會話已過期。請重新登入。']);
    exit();
}

$userId = $_SESSION['userId'];
$db = null;

try {
    $db = openDB($db_server, $db_name, $db_user, $db_passwd);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. 計算過去五個月的月份，並建立一個月曆
    $months = [];
    $currentDate = new DateTime('now');
    for ($i = 0; $i < 5; $i++) {
        $monthKey = $currentDate->format('Y-m');
        $monthLabel = $currentDate->format('n月'); // 例如：8月
        $months[$monthKey] = [
            'label' => $monthLabel,
            'income' => 0,
            'expense' => 0
        ];
        $currentDate->modify('-1 month'); // 往前推一個月
    }
    // 反轉陣列，讓月份從舊到新排列
    $months = array_reverse($months);

    // 2. 獲取過去五個月的所有交易數據
    $fiveMonthsAgo = (new DateTime('now'))->modify('-5 months')->format('Y-m-01');
    $currentDate = (new DateTime('now'))->format('Y-m-d');
    
    // 修正: 將排除的類別轉換為具名參數
    $excludedCategories = [-10, -20, -30, -40]; 
    $excludedPlaceholders = [];
    $bindParams = [];
    foreach ($excludedCategories as $key => $cat) {
        $paramName = ":cat" . $key;
        $excludedPlaceholders[] = $paramName;
        $bindParams[$paramName] = $cat;
    }
    $inClause = implode(',', $excludedPlaceholders);
    
    $sql = "
        SELECT
            DATE_FORMAT(t.transactionDate, '%Y-%m') AS month,
            ts.amount,
            ts.add_minus
        FROM `transactions_sub` AS ts
        JOIN `transactions` AS t ON ts.`t_id` = t.`t_id`
        WHERE t.userId = :userId
          AND t.transactionDate >= :fiveMonthsAgo AND t.transactionDate <= :currentDate
          AND ts.categoryId NOT IN ($inClause)
        ORDER BY t.transactionDate ASC
    ";
    
    $stmt = $db->prepare($sql);
    
    // 綁定所有具名參數
    $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
    $stmt->bindParam(':fiveMonthsAgo', $fiveMonthsAgo, PDO::PARAM_STR);
    $stmt->bindParam(':currentDate', $currentDate, PDO::PARAM_STR);
    foreach ($bindParams as $paramName => $value) {
        $stmt->bindValue($paramName, $value, PDO::PARAM_INT);
    }
    
    $stmt->execute();
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. 遍歷交易數據，加總收入與支出
    foreach ($transactions as $tx) {
        $month = $tx['month'];
        $amount = (float)$tx['amount'];
        $add_minus = (int)$tx['add_minus'];

        if (isset($months[$month])) {
            if ($add_minus == 1) {
                $months[$month]['income'] += $amount;
            } elseif ($add_minus == -1) {
                $months[$month]['expense'] += $amount;
            }
        }
    }

    // 4. 將結果整理為前端所需的陣列格式
    $labels = array_column($months, 'label');
    $incomeData = array_column($months, 'income');
    $expenseData = array_column($months, 'expense');

    echo json_encode([
        'status' => 'success',
        'data' => [
            'labels' => $labels,
            'incomeData' => $incomeData,
            'expenseData' => $expenseData
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    error_log("Database Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => '資料庫錯誤: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    error_log("General Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => '程式錯誤: ' . $e->getMessage()]);
} finally {
    $db = null;
}
?>