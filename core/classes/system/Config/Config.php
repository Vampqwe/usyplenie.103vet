<?php
declare(strict_types = 1);

/**
 * Класс Config — работа с .env файлами
 * Прямой доступ к переменным окружения через getEnv()
 */
class Config {
    private array $envVars = [];
    private string $envPath;

    /** @var string[] Файлы в порядке возрастания приоритета (последний побеждает) */
    private const ENV_LOAD_ORDER = ['.env', 'config.env'];

    public function __construct() {
        $coreDir = rtrim(Route::getPathCore(), '/\\');
        $projectRoot = dirname($coreDir);

        // Ищем доступные конфиги: сначала базовый config.env в core/,
        // затем локальный .env в корне проекта (он имеет приоритет —
        // позволяет переопределять APP_DEBUG и прочее без правки config.env).
        $candidates = [
            $coreDir . DIRECTORY_SEPARATOR . 'config.env',
            $projectRoot . DIRECTORY_SEPARATOR . 'config.env',
        ];

        $loaded = [];
        foreach (self::ENV_LOAD_ORDER as $name) {
            if ($name === 'config.env') {
                foreach ($candidates as $path) {
                    if (file_exists($path)) {
                        $loaded[] = $path;
                    }
                }
            } else {
                $path = $projectRoot . DIRECTORY_SEPARATOR . $name;
                if (file_exists($path)) {
                    $loaded[] = $path;
                }
            }
        }

        if ($loaded === []) {
            throw new ConfigException(
                "Файл конфигурации не найден. Скопируйте core/config.env.example в core/config.env"
            );
        }

        $this->envPath = end($loaded);

        // Загружаем по порядку: более поздние файлы перезаписывают ранние значения
        foreach ($loaded as $path) {
            $this->loadEnvFile($path);
        }
    }

    private function loadEnvFile(string $path): void {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            if (strpos($line, '=') === false) {
                continue;
            }
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = $this->normalizeValue($this->unquoteValue(trim($value)));
            // Перезаписываем значение из ранее загруженного файла (приоритет у последнего)
            $this->envVars[$key] = $value;
            putenv("$key=$value");
        }
    }

    /**
     * Приводит строковые булевы значения к единому виду true/false
     */
    private function normalizeValue(string $value): string {
        $lower = strtolower($value);
        if (in_array($lower, ['true', '1', 'yes', 'on'], true)) {
            return 'true';
        }
        if (in_array($lower, ['false', '0', 'no', 'off', ''], true)) {
            return 'false';
        }
        return $value;
    }

    private function unquoteValue(string $value): string {
        $value = trim($value);
        if ((strlen($value) >= 2) &&
            (($value[0] === '"' && $value[-1] === '"') ||
             ($value[0] === "'" && $value[-1] === "'"))) {
            return substr($value, 1, -1);
        }
        return $value;
    }

    /**
     * Получает значение переменной окружения
     */
    public function getEnv(string $varName, mixed $default = null): mixed {
        if (array_key_exists($varName, $this->envVars)) {
            return $this->envVars[$varName];
        }
        $envValue = getenv($varName);
        if ($envValue !== false) {
            return $envValue;
        }
        if (isset($_ENV[$varName])) {
            return $_ENV[$varName];
        }
        if (isset($_SERVER[$varName])) {
            return $_SERVER[$varName];
        }
        return $default;
    }

    /**
     * Устанавливает значение переменной окружения
     */
    public function setEnv(string $varName, mixed $value): void {
        $stringValue = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
        $this->envVars[$varName] = $stringValue;
        putenv("$varName=$stringValue");
    }

    /**
     * Возвращает все загруженные переменные окружения
     */
    public function getAllEnv(): array {
        return $this->envVars;
    }

    /**
     * Режим отладки: APP_DEBUG из env-файлов или переменной окружения.
     * Значения true/1/on/yes (без учёта регистра) считаются включёнными.
     */
    public function isDebug(): bool {
        $value = $this->getEnv('APP_DEBUG', 'false');
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string)$value)), ['true', '1', 'yes', 'on'], true);
    }

    /**
     * Сохраняет переменные окружения в .env файл
     */
    public function saveEnv(?string $path = null): void {
        $filePath = $path ?? $this->envPath;
        $content = "# Файл конфигурации проекта\n";
        $content .= "# Сгенерировано: " . date('Y-m-d H:i:s') . "\n\n";

        foreach ($this->envVars as $key => $value) {
            $content .= "$key=$value\n";
        }

        $file = new File($filePath);
        $file->createFile('w');
        $file->putToFile($content);
        $file->closeFile();
    }
}