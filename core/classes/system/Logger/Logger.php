<?php
declare(strict_types = 1);

/**
 * Класс Logger — registry-singleton для логирования
 *
 * Один экземпляр на каждый файл лога.
 * TimeDate передаётся через DI (из глобального контейнера).
 */
class Logger {
    private static array $instances = [];
    private static ?string $logDir = null;
    private File $file;
    private TimeDate $timeDate;
    private string $fileName;

    private function __construct(string $fileLog, TimeDate $timeDate) {
        $this->fileName = $fileLog;
        $this->timeDate = $timeDate;
        $logPath = $this->resolveLogPath($fileLog);
        
        // ИСПРАВЛЕНО: сначала создаём директорию, потом File
        $logDirPath = dirname($logPath);
        if (!is_dir($logDirPath)) {
            if (!mkdir($logDirPath, 0750, true) && !is_dir($logDirPath)) {
                throw new RuntimeException("Не удалось создать директорию логов: $logDirPath");
            }
        }
        
        $this->file = new File($logPath);
    }

    private function __clone() {}

    public function __wakeup() {
        throw new RuntimeException("Нельзя десериализовать singleton Logger");
    }

    // =========================================================================
    //  Singleton / Registry API
    // =========================================================================

    public static function setLogDir(string $dir): void {
        if (strpos($dir, '/') !== 0 && strpos($dir, ':') !== 1) {
            $dir = Route::getPathRoot() . '/' . $dir;
        }
        $realPath = realpath($dir);
        if ($realPath === false) {
            if (!mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new InvalidArgumentException("Не удалось создать директорию логов: $dir");
            }
            $realPath = realpath($dir);
        }
        self::$logDir = $realPath;
    }

    public static function getLogDir(): string {
        return self::$logDir ?? Route::getPathCoreLog();
    }

    /**
     * Получает singleton-экземпляр для указанного файла
     * TimeDate берётся из глобального DI-контейнера
     */
    public static function getInstance(string $fileLog = 'app.log'): self {
        if (!isset(self::$instances[$fileLog])) {
            $timeDate = Container::getGlobal()->get(TimeDate::class);
            self::$instances[$fileLog] = new self($fileLog, $timeDate);
        }
        return self::$instances[$fileLog];
    }

    public static function hasInstance(string $fileLog = 'app.log'): bool {
        return isset(self::$instances[$fileLog]);
    }

    public static function resetInstance(?string $fileLog = null): void {
        if ($fileLog === null) {
            self::$instances = [];
        } else {
            unset(self::$instances[$fileLog]);
        }
    }

    public static function resetAll(): void {
        self::$instances = [];
        self::$logDir = null;
    }

    public static function getActiveLoggers(): array {
        return array_keys(self::$instances);
    }

    // =========================================================================
    //  Логирование
    // =========================================================================

    public function inLog(string $textLog, string $typeLog = 'main'): void {
        $sanitizedText = str_replace(["\r", "\n"], '', $textLog);
        $sanitizedType = str_replace(["\r", "\n"], '', $typeLog);
        
        // ИСПРАВЛЕНО: используем существующий метод getNow() вместо nowDateTime()
        $timestamp = $this->timeDate->getNow();
        $string = "[$sanitizedType] $timestamp ---> $sanitizedText" . PHP_EOL;
        
        $this->file->putToFile($string);
    }

    public function error(string $message): void   { $this->inLog($message, 'ERROR'); }
    public function warning(string $message): void { $this->inLog($message, 'WARNING'); }
    public function info(string $message): void    { $this->inLog($message, 'INFO'); }
    public function debug(string $message): void   { $this->inLog($message, 'DEBUG'); }

    public function writeLog(string $typeLog, string $textLog): bool {
        try {
            $this->inLog($textLog, $typeLog);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function getFileName(): string {
        return $this->fileName;
    }

    public function getLogPath(): string {
        return $this->file->getFile();
    }

    private function resolveLogPath(string $fileLog): string {
        $baseDir = self::$logDir ?? Route::getPathCoreLog();
        return rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . $fileLog;
    }
}