<?php
declare(strict_types = 1);

class FileException extends RuntimeException {
    public function __construct(string $message = 'Файловая операция не удалась', int $code = 0, ?Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}