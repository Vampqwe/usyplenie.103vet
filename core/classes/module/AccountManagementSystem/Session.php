<?php
declare(strict_types = 1);
class Session {


    public function __construct () {
		// 1. Проверяем, есть ли уже ID в куки
		if (empty($_COOKIE['PHPSESSID'])) {
    	// 2. Если нет, генерируем наш UUID и устанавливаем его как ID сессии
    		session_id(Helper::getUUIDv4());
			session_start();
		}
		if (!isset($_SESSION['sess_user_data'])):
				$_SESSION['sess_user_data']['login'] = "Гость";
				$_SESSION['sess_user_data']['role'] = "visiter";
		else:
			return;
		endif;
    }
	
	public function setSession () {
	}
	
	public function getSession () {
		
	}

	public static function initSessionHandler () {
		$config = new Config();
		$sess_handler_path = $config->getenv('SAVE_SESSION_PATH_METHOD').'://'.
								$config->getenv('SAVE_SESSION_PATH_HOST').':'.
								$config->getenv('SAVE_SESSION_PATH_PORT');
		ini_set('session.save_handler', $config->getenv('SAVE_SESSION_HANDLER'));
        ini_set('session.save_path', $sess_handler_path);
        ini_set('session.gc_maxlifetime', $config->getenv('SESSION.GC_MAXLIFETIME'));
        ini_set('session.cookie_lifetime', $config->getenv('SESSION.COOKIE_LIFETIME'));
        ini_set('session.cookie_httponly', $config->getenv('SESSION.COOKIE_HTTPONLY'));
        ini_set('session.cookie_secure', $config->getenv('SESSION.COOKIE_SECURE'));
        ini_set('session.cookie_samesite', $config->getenv('SESSION.COOKIE_SAMESITE'));
	}
}