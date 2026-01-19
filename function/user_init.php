<?php
    include("functions.php");

    $userid = $_SESSION['userId'];

    //帳戶類別初始化
    $init_TypeName = ["現金","銀行","信用卡","保單"];
    $init_isAsset = [1,1,0,1];
    $init_isCash = [1,0,0,0];
    $init_isCreditCard = [0,0,1,0];
    $init_isBank = [0,1,0,0];
    $init_isPolicy = [0,0,0,1];
    $init_isSecurity = [0,0,0,0];
    $add_success = 0;//紀錄加成功的
     try {
		$db = openDB($db_server, $db_name, $db_user, $db_passwd);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Set error mode to throw exceptions

        for($i=0;$i<4;$i++){
            // 3. Use Prepared Statements for security
            $insertSQL = "INSERT INTO `account_typesTable`( `userId`, `typeName`, `isAsset`, `isCreditCard`, `isBank`, `isPolicy`, `isSecurity`) 
                            VALUES ('$userid','$init_TypeName[$i]','$init_isAsset[$i]','$init_isCreditCard[$i]','$init_isBank[$i]','$init_isPolicy[$i]','$init_isSecurity[$i]')";
            
            $stmt = $db->prepare($insertSQL);
            $stmt->execute();

            // 7. Check if the insert was successful (more reliable)
            if ($stmt->rowCount() > 0) {
                $add_success++;
            }
        }
        if($add_success>0){
            //如果沒有自己的代表是新用戶，取預設銀行帳戶類別
            $acctySQL="SELECT * FROM `account_typesTable` WHERE `userId` = '$userid' ";
            $acctyRes = $db -> query($acctySQL);
            $acctyRows = $acctyRes -> fetchAll();

            echo json_encode($acctyRows);
        }else{
            echo "新增預設帳戶類別失敗";
        }
	} catch (PDOException $e) {
        // 關鍵！在這裡輸出詳細的錯誤訊息
		error_log("Database Error in addAccount.php: " . $e->getMessage() . " --- SQLSTATE: " . $e->getCode());
		// 為了調試目的，直接輸出到響應體中 (生產環境不建議這樣做，以免暴露敏感資訊)
		echo "false_db_error: " . $e->getMessage() . " (SQLSTATE: " . $e->getCode() . ")";
		// 或者更詳細：
		// echo "false_db_error. Details: " . $e->getMessage() . 
		//      " | SQLSTATE: " . $e->getSQLSTATE() . 
		//      " | Error Code: " . $e->getCode();

	} catch (Exception $e) {
		// Catch any other general errors
		error_log("General Error in manager_addProduct.php: " . $e->getMessage());
		echo "false_general_error";
	} finally {
		// Close DB connection
		$db = null;
	}


    // //. 交易類別初始化
    // try {
	// 	$db = openDB($db_server, $db_name, $db_user, $db_passwd);
	// 	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Set error mode to throw exceptions

	// 	// 3. Use Prepared Statements for security
	// 	$insertSQL = "INSERT INTO `categories_Table`(`userId`, `transactionTypeId`, `parentCategoryId`, `categoryName`, `icon`, `color`, `status`) 
    //     VALUES ('$userid','$transactionTypeId','$parentCategoryId','$categoryName','$icon','$color','1')";
		
	// 	$stmt = $db->prepare($insertSQL);

	// 	$stmt->execute();

	// 	// 7. Check if the insert was successful (more reliable)
	// 	if ($stmt->rowCount() > 0) {
	// 		echo "0000"; // Success code
	// 	} else {
	// 		echo "false_insert".$insertSQL; // More specific error
	// 	}

	// } catch (PDOException $e) {
	// 	  // 關鍵！在這裡輸出詳細的錯誤訊息
	// 	error_log("Database Error in addAccount.php: " . $e->getMessage() . " --- SQLSTATE: " . $e->getCode());
	// 	// 為了調試目的，直接輸出到響應體中 (生產環境不建議這樣做，以免暴露敏感資訊)
	// 	echo "false_db_error: " . $e->getMessage() . " (SQLSTATE: " . $e->getCode() . ")";
	// 	// 或者更詳細：
	// 	// echo "false_db_error. Details: " . $e->getMessage() . 
	// 	//      " | SQLSTATE: " . $e->getSQLSTATE() . 
	// 	//      " | Error Code: " . $e->getCode();

	// } catch (Exception $e) {
	// 	// Catch any other general errors
	// 	error_log("General Error in manager_addProduct.php: " . $e->getMessage());
	// 	echo "false_general_error";
	// } finally {
	// 	// Close DB connection
	// 	$db = null;
	// }
?>