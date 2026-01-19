<?php
// 這個檔案將負責獲取“收入與支出”的主分類與子分類，專供 iPhone 捷徑使用

    // 引入 functions.php (假設其中有 openDB 和 checkuserAccount 函式)
    include("functions.php"); 

    // 開啟錯誤報告，用於開發調試
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    header('Content-Type: application/json'); // 統一設定 JSON 輸出

    $db = null;
    $userId = 0;

    // --- 1. 驗證使用者 (使用 POST 參數傳遞 Email 和 Password) ---
    // 獲取原始 JSON 輸入
    $json_input = file_get_contents('php://input');
    $data = json_decode($json_input, true);

    $userEmail = isset($data['email']) ? trim($data['email']) : '';
    $userPwd = isset($data['password']) ? $data['password'] : '';
    $transactionTypeId = isset($data['transactionTypeId']) ? $data['transactionTypeId'] : '';

    if (empty($userEmail) || empty($userPwd)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Email 或 Password 不得為空。']);
        exit();
    }

    try {
        $db = openDB($db_server, $db_name, $db_user, $db_passwd);
        $db_log = openDB($db_server, $db_name, $db_user, $db_passwd);
        $db_log->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // 2. 設定自動提交模式
        $db_log->setAttribute(PDO::ATTR_AUTOCOMMIT, true);
        $userInfo = checkuserAccount($db, $userEmail, $userPwd); // 假設此函式返回包含 userId 的陣列

        if (!$userInfo || !isset($userInfo['userId']) || (int)$userInfo['userId'] <= 0) {
            add_log($db_log,$userId,-1,"phone-getCategories.php","登入驗證失敗，{$userInfo}");
            http_response_code(401);
            // echo json_encode(['status' => 'error', 'message' => '使用者驗證失敗。']);
            exit();
        }

        $userId = (int)$userInfo['userId'];
        add_log($db_log,$userId,1,"phone-getCategories.php","驗證成功，使用者{$userInfo['username']}");

        // --- 2. 獲取所有類別資料 ---
        // 為了邏輯判斷，仍然一次獲取所有資料
        $allCategoriesSQL = "SELECT * FROM `categories_Table` WHERE `status`='1' AND `userId` = ? AND `transactionTypeId` = ? ORDER BY `parentCategoryId` ASC, `categoryId` ASC";
        $stmt = $db->prepare($allCategoriesSQL);
        $stmt->execute([$userId,$transactionTypeId]);
        $rawCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // --- 3. 處理類別結構並扁平化輸出 ---
        $parentCategoriesWithSubs = []; // 用來追蹤哪些父類別擁有子類別
        $selectableCategories = [];     // 最終可供捷徑選擇的類別 (扁平化)

        // 第一次遍歷：找出所有擁有子類別的父類別
        foreach ($rawCategories as $category) {
            if ((int)$category['parentCategoryId'] !== 0) {
                $parentCategoriesWithSubs[(int)$category['parentCategoryId']] = true;
            }
        }

        // 第二次遍歷：決定哪些類別可以被選擇，並進行扁平化
        foreach ($rawCategories as $category) {
            $catId = (int)$category['categoryId'];
            $parentId = (int)$category['parentCategoryId'];
            $catName = $category['categoryName'];
            
            $isParent = ($parentId === 0);
            $hasSub = isset($parentCategoriesWithSubs[$catId]);

            // 條件：
            // 1. 如果是子類別 (parentCategoryId != 0) -> 可選
            // 2. 如果是父類別 (parentCategoryId = 0) 且沒有子類別 (hasSub = false) -> 可選
            if (!$isParent || !$hasSub) {
                $selectableCategories[] = [
                    'id' => $catId,
                    'name' => $catName,
                    // 額外資訊：如果需要，可以加上其父類別名稱
                    // 'parentId' => $parentId
                ];
            }
        }

        add_log($db_log,$userId,1,"phone-getCategories.php","獲取交易類別成功");
        // 輸出 JSON (扁平化的可選類別列表)
        echo json_encode($selectableCategories, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    } catch (PDOException $e) {
        // error_log("Database Error in getCategoriesForShortcut.php: " . $e->getMessage() . " --- SQLSTATE: " . $e->getCode());
        add_log($db_log,$userId,-1,"phone-getCategories.php","資料庫錯誤。Database Error in getCategories.php: " . $e->getMessage() . " --- SQLSTATE: " . $e->getCode());
        http_response_code(500);
        // echo json_encode(['status' => 'error', 'message' => '資料庫錯誤。']);
    } catch (Exception $e) {
        // error_log("General Error in getCategoriesForShortcut.php: " . $e->getMessage());
        add_log($db_log,$userId,-1,"phone-getCategories.php","伺服器錯誤。General Error in getCategories.php: " . $e->getMessage());
        http_response_code(500);
        // echo json_encode(['status' => 'error', 'message' => '伺服器錯誤。']);
    } finally {
        $db = null; 
        $db_log = null;
    }
?>