<?php
// 這個檔案將負責獲取指定“帳戶類型”下的具體帳戶列表，專供 iPhone 捷徑使用。

    include("functions.php"); // 確保引入 functions.php (包含 openDB 和 checkuserAccount)

    // 開啟錯誤報告，用於開發調試
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    header('Content-Type: application/json'); // 統一設定 JSON 輸出

    $db = null;
    $userId = 0;

    try {
        // --- 1. 獲取並驗證輸入參數 ---
        $json_input = file_get_contents('php://input');
        $data = json_decode($json_input, true);

        $userEmail = isset($data['email']) ? trim($data['email']) : '';
        $userPwd = isset($data['password']) ? $data['password'] : '';
        // 接收新的參數：帳戶類型 ID
        $accountTypeId = isset($data['accountTypeId']) ? (int)$data['accountTypeId'] : 0; 
        
        // 檢查基本參數
        if (empty($userEmail) || empty($userPwd) || $accountTypeId <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => '缺少必要的 Email, Password 或 accountTypeId 參數。']);
            exit();
        }

        // --- 2. 驗證使用者 ---
        $db = openDB($db_server, $db_name, $db_user, $db_passwd);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db_log = openDB($db_server, $db_name, $db_user, $db_passwd);
        $db_log->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $userInfo = checkuserAccount($db, $userEmail, $userPwd); 

        if (!$userInfo || !isset($userInfo['userId']) || (int)$userInfo['userId'] <= 0) {
            add_log($db_log,$userId,-1,"phone-getAccount.php","登入驗證失敗，{$userInfo}");
            http_response_code(401);
            // echo json_encode(['status' => 'error', 'message' => '使用者驗證失敗。']);
            exit();
        }

        $userId = (int)$userInfo['userId'];
        add_log($db_log,$userId,1,"phone-getAccount.php","驗證成功，使用者{$userInfo['username']}");

        // --- 3. 獲取指定類型下的帳戶列表 ---
        // 只查詢該用戶、狀態為1、且符合指定 accountTypeId 的帳戶
        $accSQL = "SELECT `accountId`, `accountName`, `accountTypeId` 
                   FROM `accountTable` 
                   WHERE `userId` = ? AND `status` = '1' AND `accountTypeId` = ?";
        
        $stmtAcc = $db->prepare($accSQL);
        $stmtAcc->execute([$userId, $accountTypeId]);
        $accRows = $stmtAcc->fetchAll(PDO::FETCH_ASSOC);

        // --- 4. 格式化輸出 ---
        $finalOutput = [];
        foreach($accRows as $acc) {
            // 輸出簡潔的 ID 和 Name 結構，供捷徑的「選擇列表」使用
            $finalOutput[] = [
                'id' => (int)$acc['accountId'],
                'name' => $acc['accountName'],
            ];
        }
        
        // 輸出 JSON
        add_log($db_log,$userId,1,"phone-getAccount.php","獲取帳戶成功");
        echo json_encode($finalOutput, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    } catch (PDOException $e) {
        // error_log("Database Error in getAccountsByType.php: " . $e->getMessage());
        add_log($db_log,$userId,-1,"phone-getAccount.php","資料庫錯誤。Database Error in getAccount.php: " . $e->getMessage());
        http_response_code(500);
        // echo json_encode(['status' => 'error', 'message' => '資料庫錯誤。']);
    } catch (Exception $e) {
        // error_log("General Error in getAccountsByType.php: " . $e->getMessage());
        add_log($db_log,$userId,-1,"phone-getAccount.php","伺服器錯誤。General Error in getAccount.php: " . $e->getMessage());
        http_response_code(500);
        // echo json_encode(['status' => 'error', 'message' => '伺服器錯誤。']);
    } finally {
        $db = null; // 關閉資料庫連接
        $db_log = null;
    }
?>