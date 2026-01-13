<?php
// 這個檔案將負責獲取“收入與支出”的主分類與子分類

    include("../functions.php"); // 確保引入 functions.php

    // 開啟錯誤報告，用於開發調試 (正式環境請關閉或記錄到日誌)
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    // 檢查使用者是否已登入
    if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
        // 返回 JSON 格式的錯誤訊息
        header('Content-Type: application/json');
        echo json_encode([]);
        // echo json_encode(['status' => 'error', 'message' => 'NoUser: Session userId not found or invalid.']);
        exit(); // 如果沒有有效用戶，立即退出
    }

    $userid = $_SESSION['userId'];
    $db = null; // 初始化 $db 變數

    try {
        $db = openDB($db_server, $db_name, $db_user, $db_passwd);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // 設定錯誤模式為拋出異常

        // 1. 一次性獲取所有屬於該用戶的類別 (包括主類別和子類別)
        // 為了效率，通常會一次查詢所有相關資料
        $allCategoriesSQL = "SELECT * FROM `categories_Table` WHERE `status`='1' AND `userId` = '$userid' ORDER BY `parentCategoryId` ASC, `categoryId` ASC";
        $stmt = $db->prepare($allCategoriesSQL);
        $stmt->execute();
        $rawCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $mainCategories = []; // 用來存放主類別，以 ID 作為鍵，方便快速查找
        $finalCategories = []; // 最終要輸出的巢狀結構陣列

        // 第一次遍歷：分離主類別和子類別，並初始化主類別的 sub_arr
        foreach ($rawCategories as $category) {
            // 確保 ID 相關欄位是整數，方便比較
            $category['categoryId'] = (int) $category['categoryId'];
            $category['parentCategoryId'] = (int) $category['parentCategoryId'];

            if ($category['parentCategoryId'] === 0) {
                // 這是主類別
                $category['sub_arr'] = []; // 為主類別添加一個空的 sub_arr 陣列
                $mainCategories[$category['categoryId']] = $category; // 將主類別存入以 ID 為鍵的陣列
            } else {
                // 這是子類別
                // 暫時不做任何操作，因為子類別會在第二次遍歷時被歸位
            }
        }

        // 第二次遍歷：將子類別歸屬到對應的主類別下
        foreach ($rawCategories as $category) {
            $category['categoryId'] = (int) $category['categoryId'];
            $category['parentCategoryId'] = (int) $category['parentCategoryId'];

            if ($category['parentCategoryId'] !== 0) {
                // 如果是子類別，並且它的父類別存在於我們收集的主類別中
                if (isset($mainCategories[$category['parentCategoryId']])) {
                    // 將子類別添加到對應主類別的 sub_arr 中
                    $mainCategories[$category['parentCategoryId']]['sub_arr'][] = $category;
                }
            }
        }

        // 將最終的主類別（現在已經包含了子類別）轉換為索引陣列，以便 JSON 輸出為陣列
        $finalCategories = array_values($mainCategories);

        // 設定響應頭為 JSON
        header('Content-Type: application/json');
        // 輸出 JSON，使用 JSON_PRETTY_PRINT 讓格式更易讀，JSON_UNESCAPED_UNICODE 確保中文字符正常顯示
        // echo json_encode($finalCategories, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        echo json_encode([
            'status' => 'success',
            'data' => $finalCategories
        ]);

    } catch (PDOException $e) {
        error_log("Database Error in getCategories.php: " . $e->getMessage() . " --- SQLSTATE: " . $e->getCode());
        header('Content-Type: application/json'); // 確保錯誤訊息也是 JSON 格式
        echo json_encode([]);
        // echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        error_log("General Error in getCategories.php: " . $e->getMessage());
        header('Content-Type: application/json'); // 確保錯誤訊息也是 JSON 格式
        echo json_encode([]);
        // echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred.']);
    } finally {
        $db = null; // 關閉資料庫連接
    }
?>