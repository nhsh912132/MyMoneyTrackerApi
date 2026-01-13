<?php
// get_expense_by_category.php - 獲取支出類別佔比數據 (修正版)

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
$startDate = isset($_POST['startDate']) ? $_POST['startDate'] : null;
$endDate = isset($_POST['endDate']) ? $_POST['endDate'] : null;

if (empty($startDate) || empty($endDate)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => '缺少開始或結束日期。']);
    exit();
}

$db = null;

try {
    $db = openDB($db_server, $db_name, $db_user, $db_passwd);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 關鍵修正：直接在 SQL 中處理類別層級關係
    $sql = "
        SELECT
            COALESCE(parent.categoryName, child.categoryName) AS `mainCategoryName`,
            SUM(ts.amount) AS total_amount
        FROM `transactions_sub` AS ts
        JOIN `transactions` AS t ON ts.`t_id` = t.`t_id`
        JOIN `categories_Table` AS child ON ts.`categoryId` = child.`categoryId`
        LEFT JOIN `categories_Table` AS parent ON child.`parentCategoryId` = parent.`categoryId`
        WHERE t.userId = :userId
          AND t.transactionDate BETWEEN :startDate AND :endDate
          AND child.transactionTypeId = 5
        GROUP BY `mainCategoryName`
        ORDER BY total_amount DESC
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
    $stmt->bindParam(':startDate', $startDate, PDO::PARAM_STR);
    $stmt->bindParam(':endDate', $endDate, PDO::PARAM_STR);
    $stmt->execute();
    $expenseData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $labels = [];
    $data = [];

    foreach ($expenseData as $item) {
        $labels[] = $item['mainCategoryName'];
        $data[] = (float)$item['total_amount'];
    }

    echo json_encode([
        'status' => 'success',
        'data' => [
            'labels' => $labels,
            'data' => $data
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