<?php
	// 開啟錯誤報告，用於開發調試
	// error_reporting(E_ALL);
	// ini_set('display_errors', 1);

    include("functions.php"); // 確保這裡包含了 session_start() 和 openDB()

    // 檢查使用者是否已登入
    if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
        // 建議返回 JSON 格式的錯誤訊息，方便前端判斷
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'NoUser: Session userId not found or invalid.']);
        exit(); 
    }

    $userid = $_SESSION['userId'];

    // 從 POST 獲取 transactions 主表的數據
    $transactionTypeId = isset($_POST['transactionTypeId']) ? (int)$_POST['transactionTypeId'] : 0; // 預設值改為 0 或其他合理值
    $ps = isset($_POST['ps']) ? trim($_POST['ps']) : ''; 
    $transactionDate = isset($data['transaction_date']) && !empty($data['transaction_date']) ? $data['transaction_date'] : date('Y-m-d');//交易日期
    // transactionDate, createdAt, updatedAt 由資料庫自動設定或程式碼設定，無需從 POST 獲取

    //transactionTypeId若是5或6則是單帳戶交易須用另一api
    if (!isset($_SESSION['transactionTypeId']) && ($transactionTypeId == 5 || $transactionTypeId == 6) ) {
        // 建議返回 JSON 格式的錯誤訊息，方便前端判斷
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'use wrong api']);
        exit(); 
    }

    // 從 POST 獲取 transactions_sub 子表的數據
    //轉出帳戶
    $out_account_id = isset($_POST['out_account_id']) ? (int)$_POST['out_account_id'] : 0;
    $out_categoryId = -10;//交易類別id
    $out_currency = isset($_POST['out_currency']) ? $_POST['out_currency'] : "TWD";
    $out_amount = isset($_POST['out_amount']) ? (int)$_POST['out_amount'] : 0;
    $out_add_minus = -1;
    //轉入帳戶
    $in_account_id = isset($_POST['in_account_id']) ? (int)$_POST['in_account_id'] : 0;
    $in_categoryId = -20;//交易類別id
    $in_currency = isset($_POST['in_currency']) ? $_POST['in_currency'] : "TWD";
    $in_amount = isset($_POST['in_amount']) ? (int)$_POST['in_amount'] : 0;
    $in_add_minus = 1;
    //應付款項(信用卡)利息
    $other_account_id = isset($_POST['other_account_id']) ? (int)$_POST['other_account_id'] : -1;
    $other_categoryId = -30;//交易類別id
    $other_currency = isset($_POST['other_currency']) ? $_POST['other_currency'] : "TWD";
    $other_amount = isset($_POST['other_amount']) ? (int)$_POST['other_amount'] : 0;
    $other_add_minus = -1;
    //應付款項(信用卡)其他付款
    $other2_account_id = isset($_POST['other2_account_id']) ? (int)$_POST['other2_account_id'] : -1;
    $other2_categoryId = -40;//交易類別id
    $other2_currency = isset($_POST['other2_currency']) ? $_POST['other2_currency'] : "TWD";
    $other2_amount = isset($_POST['other2_amount']) ? (int)$_POST['other2_amount'] : 0;
    $other2_add_minus = -1;


    try {
        $db = openDB($db_server, $db_name, $db_user, $db_passwd);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Set error mode to throw exceptions
        $db->beginTransaction(); // **開始事務處理：確保兩條記錄同時成功或同時失敗**

        // 1. 插入 `transactions` 主表數據
        // 使用具名參數的預備語句，更安全且可讀性高
        $insertMainSQL = "INSERT INTO `transactions`( `userId`, `transactionTypeId`, `transactionDate`, `createdAt`, `updatedAt`, `ps`) 
                           VALUES (:userId, :transactionTypeId, :transactionDate, NOW(), NOW(), :ps)";
        // 3. Use Prepared Statements for security
		// $insertSQL = "INSERT INTO `transactions`( `userId`, `transactionTypeId`, `transactionDate`, `createdAt`, `updatedAt`, `ps`) 
        //                 VALUES ('$userid','$transactionTypeId',now(),now(),now(),'$ps')";
		
        $stmtMain = $db->prepare($insertMainSQL);
        $stmtMain->bindParam(':userId', $userid, PDO::PARAM_INT);
        $stmtMain->bindParam(':transactionTypeId', $transactionTypeId, PDO::PARAM_INT);
        $stmtMain->bindParam(':transactionDate', $transactionDate, PDO::PARAM_STR);
        $stmtMain->bindParam(':ps', $ps, PDO::PARAM_STR);
        
        $stmtMain->execute();

        // 2. 獲取剛剛插入的 `t_id`
        $t_id = $db->lastInsertId();

        // 3. 插入 `transactions_sub` 子表數據
        $insertSubSQL = "INSERT INTO `transactions_sub`( `t_id`, `account_id`,`userId`, `categoryId`, `currency`, `amount`, `add_minus`) 
                          VALUES (:t_id, :account_id, :userId, :categoryId, :currency, :amount, :add_minus)";
        
        $stmtSub = $db->prepare($insertSubSQL);
        //
        $stmtSub->bindParam(':t_id', $t_id, PDO::PARAM_INT); // 使用獲取到的 t_id
        $stmtSub->bindParam(':account_id', $out_account_id, PDO::PARAM_INT);
        $stmtSub->bindParam(':userId', $userid, PDO::PARAM_INT);
        $stmtSub->bindParam(':categoryId', $out_categoryId, PDO::PARAM_INT);
        $stmtSub->bindParam(':currency', $out_currency, PDO::PARAM_STR); // 綁定為字串
        $stmtSub->bindParam(':amount', $out_amount, PDO::PARAM_STR); // 金額用 STR 避免浮點數精度問題，DB會轉換
        $stmtSub->bindParam(':add_minus', $out_add_minus, PDO::PARAM_INT);
        
        $stmtSub->execute();
        //
        $stmtSub->bindParam(':t_id', $t_id, PDO::PARAM_INT); // 使用獲取到的 t_id
        $stmtSub->bindParam(':account_id', $in_account_id, PDO::PARAM_INT);
        $stmtSub->bindParam(':userId', $userid, PDO::PARAM_INT);
        $stmtSub->bindParam(':categoryId', $in_categoryId, PDO::PARAM_INT);
        $stmtSub->bindParam(':currency', $in_currency, PDO::PARAM_STR); // 綁定為字串
        $stmtSub->bindParam(':amount', $in_amount, PDO::PARAM_STR); // 金額用 STR 避免浮點數精度問題，DB會轉換
        $stmtSub->bindParam(':add_minus', $in_add_minus, PDO::PARAM_INT);
        
        $stmtSub->execute();
        //若是應付款項
        if($transactionTypeId == 9){
            if($other_account_id > 0){
                $stmtSub->bindParam(':t_id', $t_id, PDO::PARAM_INT); // 使用獲取到的 t_id
                $stmtSub->bindParam(':account_id', $other_account_id, PDO::PARAM_INT);
                $stmtSub->bindParam(':userId', $userid, PDO::PARAM_INT);
                $stmtSub->bindParam(':categoryId', $other_categoryId, PDO::PARAM_INT);
                $stmtSub->bindParam(':currency', $other_currency, PDO::PARAM_STR); // 綁定為字串
                $stmtSub->bindParam(':amount', $other_amount, PDO::PARAM_STR); // 金額用 STR 避免浮點數精度問題，DB會轉換
                $stmtSub->bindParam(':add_minus', $other_add_minus, PDO::PARAM_INT);
                
                $stmtSub->execute();
            }
            if($other2_account_id > 0){
                $stmtSub->bindParam(':t_id', $t_id, PDO::PARAM_INT); // 使用獲取到的 t_id
                $stmtSub->bindParam(':account_id', $other2_account_id, PDO::PARAM_INT);
                $stmtSub->bindParam(':userId', $userid, PDO::PARAM_INT);
                $stmtSub->bindParam(':categoryId', $other2_categoryId, PDO::PARAM_INT);
                $stmtSub->bindParam(':currency', $other2_currency, PDO::PARAM_STR); // 綁定為字串
                $stmtSub->bindParam(':amount', $other2_amount, PDO::PARAM_STR); // 金額用 STR 避免浮點數精度問題，DB會轉換
                $stmtSub->bindParam(':add_minus', $other2_add_minus, PDO::PARAM_INT);
                
                $stmtSub->execute();
            }
        }


        // 4.更新帳戶餘額
        $atSQL="SELECT `accountId`,`currentBalance`,`add_minus` FROM `accountTable` WHERE `userId` = '$userid' AND `accountId`= '$out_account_id' ";
        $atRes = $db -> query($atSQL);
        $atRows = $atRes -> fetchAll();
        $atCount = $atRes -> rowCount();

        $at2SQL="SELECT `accountId`,`currentBalance`,`add_minus` FROM `accountTable` WHERE `userId` = '$userid' AND `accountId`= '$in_account_id' ";
        $at2Res = $db -> query($at2SQL);
        $at2Rows = $at2Res -> fetchAll();
        $at2Count = $at2Res -> rowCount();

        if($atCount != 1 || $at2Count != 1 ){
            $db->rollBack(); // 取消前面的修改暫存
            echo json_encode([
                'status' => 'error',
                'message' => '新增失敗，沒查到指定帳戶',
                'other' => "出帳帳戶id：".$out_account_id."，入帳帳戶id：".$in_account_id,
            ]);
        }else{
            $row = $atRows[0];
            $row2 = $at2Rows[0];
            $new_out_currentBalance = (int)$row['currentBalance']+(-$out_amount*(int)$row['add_minus']);
            if($transactionTypeId == 9){
                if($other_amount>0){
                    $new_out_currentBalance = $new_out_currentBalance + (-$other_amount*(int)$row['add_minus']);
                }
                if($other2_amount>0){
                    $new_out_currentBalance = $new_out_currentBalance + (-$other2_amount*(int)$row['add_minus']);
                }
            }
            $new_in_currentBalance = (int)$row2['currentBalance']+($in_amount*(int)$row2['add_minus']);

            $updateSQL = "UPDATE `accountTable` SET `currentBalance`='$new_out_currentBalance' WHERE `accountId` = '$out_account_id' AND `userId` = '$userid';";
            $stmt = $db->prepare($updateSQL);
		    $stmt->execute();

            $update2SQL = "UPDATE `accountTable` SET `currentBalance`='$new_in_currentBalance' WHERE `accountId` = '$in_account_id' AND `userId` = '$userid';";
            $stmt = $db->prepare($update2SQL);
		    $stmt->execute();

            // 4. 如果所有插入都成功，提交事務
            $db->commit();

            // 返回成功訊息 (建議使用 JSON)
            echo json_encode([
                'status' => 'success',
                'message' => '多子項交易記錄成功新增',
                't_id' => $t_id ,
            ]);
        }
        

        // 5. 如果兩條插入都成功，提交事務
        $db->commit();

        // 返回成功訊息 (建議使用 JSON)
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'message' => '交易記錄成功新增',
            't_id' => $t_id // 可以返回新生成的 ID
        ]);

    } catch (PDOException $e) {
        // 如果任何一步出錯，回滾事務
        // 在回滾之前，先檢查是否處於活動交易狀態
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log("Database Error in addTransaction.php: " . $e->getMessage() . " --- SQLSTATE: " . $e->getCode());
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => '資料庫操作失敗: ' . $e->getMessage(),
            'sqlstate' => $e->getCode()
        ]);

    } catch (Exception $e) {
        // 在回滾之前，先檢查是否處於活動交易狀態
        if ($db->inTransaction()) {
            $db->rollBack();
        } // 即使是其他通用錯誤，也回滾
        error_log("General Error in addTransaction.php: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => '一般伺服器錯誤.']);
    } finally {
        $db = null; // 確保關閉 DB 連線
    }
?>