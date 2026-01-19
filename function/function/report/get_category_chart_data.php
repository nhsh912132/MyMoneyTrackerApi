<?php
// get_category_chart_data.php - 獲取類別佔比圓餅圖數據

include("../functions.php");
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => '未登入或會話已過期。請重新登入。']);
    exit();
}

$userId = $_SESSION['userId'];

// 獲取 GET 請求參數
$startDate = isset($_GET['startDate']) ? $_GET['startDate'] : null;
$endDate = isset($_GET['endDate']) ? $_GET['endDate'] : null;
$accountId = isset($_GET['accountId']) ? $_GET['accountId'] : 'all';

if (empty($startDate) || empty($endDate)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => '缺少開始或結束日期。']);
    exit();
}

$db = null;

try {
    $db = openDB($db_server, $db_name, $db_user, $db_passwd);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = "
        SELECT
            c.categoryName,
            c.color,
            ts.add_minus,
            SUM(ts.amount) AS totalAmount
        FROM `transactions_sub` AS ts
        JOIN `transactions` AS t ON ts.t_id = t.t_id
        INNER JOIN `categories_Table` AS c ON ts.categoryId = c.categoryId
        WHERE t.userId = :userId
          AND t.transactionDate BETWEEN :startDate AND :endDate
    ";
    
    // 處理帳戶篩選
    if ($accountId && $accountId !== 'all') {
        $sql .= " AND ts.account_id = :accountId";
    }

    $sql .= " GROUP BY c.categoryName, c.color, ts.add_minus
              ORDER BY totalAmount DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
    $stmt->bindParam(':startDate', $startDate, PDO::PARAM_STR);
    $stmt->bindParam(':endDate', $endDate, PDO::PARAM_STR);
    
    if ($accountId && $accountId !== 'all') {
        $stmt->bindParam(':accountId', $accountId, PDO::PARAM_INT);
    }

    $stmt->execute();
    $rawData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 分離收入和支出數據
    $incomeData = [];
    $expenseData = [];
    foreach ($rawData as $row) {
        // add_minus: 1 是收入，-1 是支出
        if ($row['add_minus'] == '1') {
            $incomeData[] = [
                'name' => $row['categoryName'],
                'amount' => (float)$row['totalAmount'],
                'color' => $row['color']
            ];
        } else {
            $expenseData[] = [
                'name' => $row['categoryName'],
                'amount' => (float)$row['totalAmount'],
                'color' => $row['color']
            ];
        }
    }
    
    echo json_encode([
        'status' => 'success',
        'data' => [
            'income' => $incomeData,
            'expense' => $expenseData
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