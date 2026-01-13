<?php
// get_available_bill_years.php - 獲取所有有帳單記錄的年份

error_reporting(E_ALL);
ini_set('display_errors', 1);

include("../functions.php");

header('Content-Type: application/json; charset=utf-8');

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

    // 查詢該用戶所有不重複的帳單年份，並按降序排列
    $sql = "SELECT DISTINCT YEAR(period_end_date) AS bill_year 
            FROM `billsTable` 
            WHERE user_id = ? 
            ORDER BY bill_year DESC";
            
    $stmt = $db->prepare($sql);
    $stmt->execute([$userId]);
    $years = $stmt->fetchAll(PDO::FETCH_COLUMN, 0); // 直接取出年份欄位的值

    // 將年份資料轉換為數字類型 (雖然 SQL 結果通常就是字串，但確保一致性)
    $data = array_map('intval', $years);

    echo json_encode(['status' => 'success', 'data' => $data]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => '資料庫錯誤: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => '程式錯誤: ' . $e->getMessage()]);
} finally {
    $db = null;
}
?>