<?php
declare(strict_types = 1);

class ConfigException extends RuntimeException {
    public function __construct(string $message = 'Ошибка конфигурации', int $code = 0, ?Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}