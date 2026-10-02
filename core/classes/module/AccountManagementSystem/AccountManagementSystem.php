<?php
declare(strict_types = 1);
class AccountManagementSystem {
	
	private string $inputAccMail;
	private string $inputAccPass;
	private string $inputAccPassRep;

    public function __construct (
        Config $Config,
        DataBase $DataBase,
        Logger $Logger,
        Map $Map
    ) {}
	
    public function addDataAccount () {

    }

    public function registration () {
    }

    public function authorization () {
    }

    public function authentication () {

    }
}