<?php
include("../functions.php");

header('Content-Type: application/json; charset=utf-8');

// 接收前端 JSON payload
$json_input = file_get_contents('php://input');
$data = json_decode($json_input, true);

$userId = $_SESSION['userId'] ?? 0;

if ($userId <= 0) {
    echo json_encode(['status' => 'error', 'message' => '未登入或連線逾時']);
    exit;
}

// 取得並整理參數
$b_id       = !empty($data['b_id']) ? (int)$data['b_id'] : null;
$categoryId = (int)($data['categoryId'] ?? 0);
$amount     = (float)($data['amount'] ?? 0);
$currency   = $data['currency'] ?? 'TWD';
$startDate  = $data['startDate'] ?? null;
$endDate    = $data['endDate'] ?? null;
$targetType = $data['targetType'] ?? 'month';

// 基礎檢查
if ($categoryId <= 0 || $amount < 0 || !$startDate || !$endDate) {
    echo json_encode(['status' => 'error', 'message' => '缺少必要參數或金額格式錯誤']);
    exit;
}

try {
    $db = openDB($db_server, $db_name, $db_user, $db_passwd);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($b_id) {
        // --- 編輯既有預算 ---
        // 先確認這筆 b_id 確實屬於當前使用者
        $check_sql = "SELECT `b_id` FROM `budget_configs` WHERE `b_id` = :bid AND `userId` = :uid";
        $check_stmt = $db->prepare($check_sql);
        $check_stmt->execute([':bid' => $b_id, ':uid' => $userId]);
        
        if (!$check_stmt->fetch()) {
            echo json_encode(['status' => 'error', 'message' => '找不到該筆預算或無權限修改']);
            exit;
        }

        $sql = "UPDATE `budget_configs` 
                SET `amount` = :amount, 
                    `currency` = :currency, 
                    `start_date` = :start, 
                    `end_date` = :end, 
                    `target_type` = :type,
                    `update_at` = NOW() 
                WHERE `b_id` = :bid AND `userId` = :uid";
        
        $params = [
            ':amount'   => $amount,
            ':currency' => $currency,
            ':start'    => $startDate,
            ':end'      => $endDate,
            ':type'     => $targetType,
            ':bid'      => $b_id,
            ':uid'      => $userId
        ];
        
        $msg = "預算更新成功";
    } else {
        // --- 新增預算 ---
        // (選擇性) 您也可以在這邊先檢查同一類別在同一時間區間是否已有預算，若有則直接轉為更新
        $sql = "INSERT INTO `budget_configs` 
                (`userId`, `categoryId`, `amount`, `currency`, `start_date`, `end_date`, `target_type`, `createdAt`) 
                VALUES 
                (:uid, :cid, :amount, :currency, :start, :end, :type, NOW())";
        
        $params = [
            ':uid'      => $userId,
            ':cid'      => $categoryId,
            ':amount'   => $amount,
            ':currency' => $currency,
            ':start'    => $startDate,
            ':end'      => $endDate,
            ':type'     => $targetType
        ];
        
        $msg = "預算新增成功";
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    echo json_encode([
        'status'  => 'success', 
        'message' => $msg,
        'b_id'    => $b_id ?: $db->lastInsertId() // 回傳 ID 供前端更新狀態
    ]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => '資料庫處理失敗: ' . $e->getMessage()]);
}