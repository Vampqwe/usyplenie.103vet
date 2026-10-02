<?php
declare(strict_types = 1);
use Ramsey\Uuid\Uuid;
/**
 * Класс Helper
 */
class Helper {



    public static function getUUIDv4 () {
        return Uuid::uuid4()->toString();
    }
}