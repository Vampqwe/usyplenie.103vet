<?php
declare(strict_types = 1);
class User extends AccountManagementSystem {

	private File $File;

    private $login;

    public function __construct () {
		
		$this->File = new File();
    }
	//создает пользователя в базе данных
	public function createUser () {
	}
	
	public function getUserLoginFromDb () {
	}
}