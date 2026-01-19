<?php
    include("functions.php");
    // 開啟錯誤報告，用於開發調試
	error_reporting(E_ALL);
	ini_set('display_errors', 1);
       // 檢查使用者是否已登入
    if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'NoUser: Session userId not found or invalid.']);
        exit(); // 如果沒有有效用戶，立即退出
    }

    $userid = $_SESSION['userId'];
    $db = openDB($db_server,$db_name,$db_user,$db_passwd);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    try {
        $accTypeSQL="SELECT * FROM `account_typesTable` ";
        $accTypeRes = $db -> query($accTypeSQL);
        $accTypeRows = $accTypeRes -> fetchAll();
        $accTypeCount = $accTypeRes -> rowCount();


        $useCurrency = isset($_SESSION['useCurrency']) ? $_SESSION['useCurrency'] : 'TWD';
        $accSQL="SELECT * FROM `accountTable` WHERE `userId` = $userid AND `status` = '1' ";
        $accRes = $db -> query($accSQL);
        $accRows = $accRes -> fetchAll();
        $accCount = $accRes -> rowCount();


        $arr = [];

        foreach($accTypeRows as $tp){
            $tp['acc_arr'] = [];
            $arr[] = $tp;
        }

        foreach($accRows as $acc){
            for($i = 0;$i<count($arr);$i++){
                if($acc['accountTypeId'] == $arr[$i]['accountTypes_Id']){
                    $arr[$i]['acc_arr'][] = $acc;
                }
            }
        }
        // echo json_encode($arr);
        echo json_encode([
            'status' => 'success', 
            'data' => $arr
        ]);
        
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
   
?>