<?php
// get_trend_chart_data.php - 獲取收支趨勢長條圖數據 (單層查詢版)

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
$parentCategoryId = isset($_GET['parentCategoryId']) ? (int)$_GET['parentCategoryId'] : null;

if (empty($startDate) || empty($endDate)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => '缺少開始或結束日期。']);
    exit();
}

$db = null;

try {
    $db = openDB($db_server, $db_name, $db_user, $db_passwd);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $results = [];

    // 判斷查詢的層級
    if ($parentCategoryId === null) {
        // 第一層：查詢「收入」和「支出」總額
        $sql = "
            SELECT
                tt.Type_t1_id,
                tt.Type_t1_Name AS name,
                SUM(ts.amount) AS totalAmount
            FROM `transactions_sub` AS ts
            JOIN `transactions` AS t ON ts.t_id = t.t_id
            JOIN `transactionType_Table` AS tt ON t.transactionTypeId = tt.Type_t1_id
            WHERE t.userId = :userId
              AND t.transactionDate BETWEEN :startDate AND :endDate
              AND (tt.Type_t1_id = 5 OR tt.Type_t1_id = 6)
        ";
        if ($accountId && $accountId !== 'all') {
            $sql .= " AND ts.account_id = :accountId";
        }
        $sql .= " GROUP BY tt.Type_t1_id, tt.Type_t1_Name ORDER BY tt.Type_t1_id";

        $stmt = $db->prepare($sql);
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':startDate', $startDate, PDO::PARAM_STR);
        $stmt->bindParam(':endDate', $endDate, PDO::PARAM_STR);
        if ($accountId && $accountId !== 'all') {
            $stmt->bindParam(':accountId', $accountId, PDO::PARAM_INT);
        }
        $stmt->execute();
        $rawResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 格式化結果並檢查是否有子類別 (所有收入/支出都有子類別)
        foreach ($rawResults as $row) {
            $results[] = [
                'id' => $row['Type_t1_id'],
                'name' => $row['name'],
                'totalAmount' => (float)$row['totalAmount'],
                'hasChildren' => true
            ];
        }

    } else {
        // 第二層或更深：查詢特定父類別下的子類別總額
        $sql = "
            SELECT
                c.categoryId,
                c.categoryName AS name,
                c.color,
                SUM(ts.amount) AS totalAmount
            FROM `transactions_sub` AS ts
            JOIN `transactions` AS t ON ts.t_id = t.t_id
            JOIN `categories_Table` AS c ON ts.categoryId = c.categoryId
            WHERE t.userId = :userId
              AND t.transactionDate BETWEEN :startDate AND :endDate
              
        ";
        if($parentCategoryId < 0) {
            $sql .= "AND t.transactionTypeId = :parentCategoryId";
            $parentCategoryId = $parentCategoryId * -1;
        }else{
            $sql .= "AND c.parentCategoryId = :parentCategoryId";
        }
        if ($accountId && $accountId !== 'all') {
            $sql .= " AND ts.account_id = :accountId";
        }
        $sql .= " GROUP BY c.categoryId, c.categoryName, c.color ORDER BY totalAmount DESC";

        $stmt = $db->prepare($sql);
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':startDate', $startDate, PDO::PARAM_STR);
        $stmt->bindParam(':endDate', $endDate, PDO::PARAM_STR);
        
        $stmt->bindParam(':parentCategoryId', $parentCategoryId , PDO::PARAM_INT);
        
        if ($accountId && $accountId !== 'all') {
            $stmt->bindParam(':accountId', $accountId, PDO::PARAM_INT);
        }
        $stmt->execute();
        $rawResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 格式化結果並檢查是否有更深的子類別
        foreach ($rawResults as $row) {
            $hasChildren = false;
            $checkChildrenSql = "SELECT COUNT(*) FROM `categories_Table` WHERE parentCategoryId = :categoryId AND userId = :userId";
            $checkStmt = $db->prepare($checkChildrenSql);
            $checkStmt->bindParam(':categoryId', $row['categoryId'], PDO::PARAM_INT);
            $checkStmt->bindParam(':userId', $userId, PDO::PARAM_INT);
            $checkStmt->execute();
            if ($checkStmt->fetchColumn() > 0) {
                $hasChildren = true;
            }
            $results[] = [
                'id' => $row['categoryId'],
                'name' => $row['name'],
                'totalAmount' => (float)$row['totalAmount'],
                'color' => $row['color'],
                'hasChildren' => $hasChildren
            ];
        }
    }

    echo json_encode([
        'status' => 'success',
        'data' => $results
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