<?php
	define("userServiceType", 1);

	define("success", "0000");

	//註冊
	define("PhoneEmailAlreadyUsed", 501);//手機號與信箱皆已被註冊
	define("PhoneAlreadyUsed", 502);//手機號已被註冊
	define("EmailAlreadyUsed", 503);//信箱已被註冊
	define("CheckRepeatError", 596);//註冊失敗
	define("CheckError", 597);//註冊完成但後續檢查有錯誤
	define("RegiErrorLog", 598);//用戶註冊異常,記錄成功  '用戶註冊異常,官方已記錄錯誤,若急須使用麻煩您直接聯絡官方'
	define("RegiError", 599);//用戶註冊異常,記錄失敗

	//帳號方面
	define("userLoginErr", 1000);//後續_SESSION檢查帳號有錯時
	define("userPhoneNull", 1001);
	define("userPwdNull", 1002);
	define("userRegiRepeat", 1003);//帳號重複
	define("userNotRegi", 1004);//帳號未註冊
	define("userCheckUnknowFail", 1005);//帳號未知錯誤
	define("userTypeInsufficient", 1006);//並非可以編輯之權限
	define("userUnLogin", 1007);//未登入
	define("userBlockade", 1008);//用戶遭封鎖
	define("regiError", 1009);//用戶註冊錯誤
	define("alreadyVerifyOldPhoneOK", 1111);//更換手機號時,已經經過驗證或是可以略過驗證
	
	//雜亂
	define("deleteError", 2001);//刪除失敗
	define("updateError", 2002);//更新失敗
	define("listNumAddButError", 2003);//訂單號碼雖新增但取得時發生錯誤

	

	//
	define("LogOk", 9001);//錯誤紀錄成功
	define("LogError", 9002);//錯誤紀錄失敗
	define("curlSnsResError", 9003);//簡訊api錯誤回報

	//=========================================
	define("announcementListPage", 10);//公告單頁列表數量
	define("productListPage", 10);//產品單頁列表數量(暫時不用)
	define("shoppingCartListPage", 10);//購物車單頁列表數量
	define("historyListPage", 10);//歷史訂單單頁列表數量
	define("feedbackListPage", 10);//意見回饋單頁列表數量
	define("userListPage", 10);//用戶管理單頁列表數量
	define("whyBlockadePage", 10);//封鎖原因列表

	
?>