<?php
 	$session_timeout = 1800; // 設定會話過期時間為 30 分鐘（1800 秒）

	ini_set('session.gc_maxlifetime', $session_timeout);
	session_set_cookie_params($session_timeout);

	// 檢查 Session 是否已經啟動，避免重複呼叫 session_start()
	// 這是 PHP 推薦的做法，避免在腳本生命週期中多次嘗試啟動 Session。
	if (session_status() == PHP_SESSION_NONE) {
		session_start();
	}

	// 包含設定檔和參數檔
	include("config.php");
	// include_once("parameter.php");
	// 統一 Session 管理邏輯（現在它只是自動運行，無需單獨呼叫 set_session()）
	// 確保 last_activity 的更新和過期檢查邏輯
	if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
		// 會話已過期
		session_unset();    // 移除 Session 變數
		session_destroy();  // 銷毀 Session
		// 對於 AJAX 請求，通常不應該直接導向 (header("Location: ..."))
		// 而是回傳一個特定的狀態碼或 JSON 訊息給前端，讓前端處理導向。
		// 例如：
		header('Content-Type: application/json'); // 確保回傳 JSON
		echo json_encode(['status' => 'session_expired', 'message' => '會話已過期，請重新登入。']);
		exit(); // 終止腳本執行
	}

	// 更新最後活動時間
	$_SESSION['last_activity'] = time();

	function reset_session(){
		session_destroy();
		if (3600 == 0) {
			$expire = ini_get('session.gc_maxlifetime');
		} else {
			ini_set('session.gc_maxlifetime', 3600);
		}
	
		if (empty($_COOKIE['PHPSESSID'])) {
			session_set_cookie_params(3600);
			session_start();
		} else {
			session_start();
			setcookie('PHPSESSID', session_id(), time() + 3600);
		}
	}

	function openDB($db_server,$db_name,$db_user,$db_passwd){
		$options = array(
			PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8',
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
			// PDO::MYSQL_ATTR_SSL_CA => '../libs/BaltimoreCyberTrustRoot.crt.pem',
			// PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
		);
		// echo "mysql:host=".$db_server.";port=5817;dbname=".$db_name.";charset=utf8 / / ".$db_user." / / ".$db_passwd."\n";
		try {
			$db = new PDO("mysql:host=".$db_server.";port=5817;dbname=".$db_name.";charset=utf8",$db_user,$db_passwd,$options);
			// $db = new PDO("mysql:host=".$db_server.";port=1637;dbname=".$db_name.";charset=utf8",$db_user,$db_passwd);
			// $db -> setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
		}
		catch(PDOException $e){
		    $db = $e;
		}
		return $db;
	}

  
?>