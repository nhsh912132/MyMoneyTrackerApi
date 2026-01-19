<?php
// get_quick_templates.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
include('functions.php');

$db = null;
$userId = 0;

try {
    $json_input = file_get_contents('php://input');
    $data = json_decode($json_input, true);
    $userEmail = isset($data['email']) ? trim($data['email']) : '';
    $userPwd = isset($data['password']) ? $data['password'] : '';
    $templateId = isset($data['templateId']) ? $data['templateId'] : '';
    if (empty($userEmail) || empty($userPwd)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => '缺少必要的 Email, Password 或 accountTypeId 參數。','data' => $data]);
        exit();
    }

    $db = openDB($db_server, $db_name, $db_user, $db_passwd);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db_log = openDB($db_server, $db_name, $db_user, $db_passwd);
    $db_log->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $userInfo = checkuserAccount($db, $userEmail, $userPwd); 
    if (!$userInfo || !isset($userInfo['userId']) || (int)$userInfo['userId'] <= 0) {
        add_log($db_log,$userId,-1,"phone-check_quick_templates.php","登入驗證失敗，{$userInfo}");
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => '使用者驗證失敗。']);
        exit();
    }

    $userId = (int)$userInfo['userId'];
    add_log($db_log,$userId,1,"phone-check_quick_templates.php","驗證成功，使用者{$userInfo['username']}");
    $sql = "SELECT 
                qt.templateId,
                qt.templateName,
                qt.`t_id`,
                qt.categoryId,
                ct.categoryName,
                qt.accountId,
                at.accountName,
                qt.amount,
                qt.add_minus,
                qt.`ps`
            FROM 
                quick_templates_Table AS qt
            JOIN
                categories_Table AS ct ON qt.categoryId = ct.categoryId
            JOIN
                accountTable AS at ON qt.accountId = at.accountId
            WHERE 
                qt.templateId = ?
            ORDER BY qt.templateId DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute([$templateId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    // error_log("Database Error: " . $e->getMessage());
    add_log($db_log,$userId,-1,"phone-check_quick_templates.php","資料庫操作失敗， " . $e->getMessage() . " --- SQLSTATE: " . $e->getCode());
    http_response_code(500);
    // echo json_encode(['status' => 'error', 'message' => '資料庫錯誤。']);
} catch (Exception $e) {
    // error_log("General Error: " . $e->getMessage());
    add_log($db_log,$userId,-1,"phone-check_quick_templates.php","一般伺服器錯誤: ". $e->getMessage());
    http_response_code(500);
    // echo json_encode(['status' => 'error', 'message' => '伺服器發生未知錯誤。']);
} finally {
    $db = null;
    $db_log = null;
}
?>