<?php
declare(strict_types=1);
/**
* Класс Template — шаблонизатор с поддержкой кэширования
*
* Режимы рендеринга:
*  - render()      — str_replace (безопасный, без выполнения PHP)
*  - renderPhp()   — include + ob_start (гибкий, с выполнением PHP)
*
* Кэширование:
*  - Автоматическая инвалидация при изменении файла шаблона
*  - Поддержка TTL (время жизни)
*  - Учёт переменных в ключе кэша
*  - Хранение в core/cache/templates/
*/
class Template
{
    // =========================================================================
    // Свойства
    // =========================================================================
    private ?File $file = null;
    private Map $map;
    private Map $escapedMap;

    // Настройки кэша
    private bool $cacheEnabled = false;
    private ?int $cacheTtl = null;
    private string $cacheDir = 'core/cache/templates';
    private bool $cacheByVariables = true;

    // =========================================================================
    // Конструктор
    // =========================================================================
    public function __construct()
    {
        $this->map = new Map();
        $this->escapedMap = new Map();
    }

    // =========================================================================
    // Работа с файлом шаблона
    // =========================================================================
    public function addTplFile(string $tplFile): void
    {
        $this->file = new File($tplFile);
        if (!$this->file->existsFile()) {
            throw new FileException("Шаблон не найден: $tplFile");
        }
    }

    public function readTplFile(): string
    {
        if ($this->file === null) {
            throw new FileException("Файл шаблона не установлен");
        }
        $content = $this->file->readFile();
        return $content !== null ? $content : '';
    }

    // =========================================================================
    // Работа с переменными
    // =========================================================================
    public function assign(string $key, mixed $value): void
    {
        $this->map->put($key, $value);
    }

    public function assignEscaped(
        string $key,
        mixed $value,
        int $flags = ENT_QUOTES | ENT_HTML5,
        string $encoding = 'UTF-8'
    ): void {
        $stringValue = is_array($value) ? '' : (string)$value;
        $escapedValue = htmlspecialchars($stringValue, $flags, $encoding);
        $this->escapedMap->put($key, $escapedValue);
    }

    public function assignArray(array $data, bool $escape = false): void
    {
        foreach ($data as $key => $value) {
            if ($escape) {
                $this->assignEscaped((string)$key, $value);
            } else {
                $this->assign((string)$key, $value);
            }
        }
    }

    public function clearAssignments(): void
    {
        $this->map = new Map();
        $this->escapedMap = new Map();
    }

    // =========================================================================
    // Настройки кэша
    // =========================================================================
    public function enableCache(?int $ttl = null): self
    {
        $this->cacheEnabled = true;
        $this->cacheTtl = $ttl;
        return $this;
    }

    public function disableCache(): self
    {
        $this->cacheEnabled = false;
        $this->cacheTtl = null;
        return $this;
    }

    public function setCacheDir(string $dir): self
    {
        $this->cacheDir = trim($dir, '/');
        return $this;
    }

    public function setCacheByVariables(bool $enabled): self
    {
        $this->cacheByVariables = $enabled;
        return $this;
    }

    public function clearCache(): int
    {
        $cachePath = Route::getPathRoot() . '/' . $this->cacheDir;
        if (!is_dir($cachePath)) {
            return 0;
        }
        $deleted = 0;
        $files = glob($cachePath . '/*.html');
        if ($files === false) {
            return 0;
        }
        foreach ($files as $file) {
            if (is_file($file) && unlink($file)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    // =========================================================================
    // Рендеринг
    // =========================================================================

    /**
     * Приводит значение к строке для подстановки в шаблон
     *
     * ИСПРАВЛЕНО (пункт 1):
     *  - В режиме APP_DEBUG=true бросает RuntimeException с понятным сообщением
     *    (разработчик сразу видит, что в шаблон попал массив/объект)
     *  - В режиме APP_DEBUG=false (продакшен) тихо возвращает ''
     *    (не ломаем работу сайта)
     *
     * @param mixed $value значение для преобразования
     * @return string строковое представление
     * @throws RuntimeException если передан массив/объект/ресурс в режиме отладки
     */
    private function toString(mixed $value): string
    {
        if (is_array($value) || is_object($value) || is_resource($value)) {
            // В режиме отладки — бросаем исключение с понятным сообщением
            if (defined('APP_DEBUG') && APP_DEBUG) {
                $type = get_debug_type($value);
                throw new RuntimeException(
                    "Template: нельзя преобразовать $type в строку. "
                    . "Используйте renderPhp() для работы с массивами/объектами, "
                    . "либо передавайте скалярное значение."
                );
            }
            // В продакшене — тихо возвращаем пустую строку (не ломаем сайт)
            return '';
        }
        return (string)$value;
    }

    /**
     * Рендерит шаблон через str_replace (безопасный режим)
     * Поддерживает плейсхолдеры {key} и {{key}}
     *
     * @throws FileException если файл шаблона не установлен
     * @throws RuntimeException если передан массив/объект в режиме отладки
     */
    public function render(): string
    {
        // Проверяем кэш
        if ($this->cacheEnabled) {
            $cached = $this->getFromCache();
            if ($cached !== null) {
                return $cached;
            }
        }

        $content = $this->readTplFile();

        // Сначала подставляем экранированные переменные (уже строки)
        foreach ($this->escapedMap->getArrayObject() as $key => $value) {
            $strValue = $this->toString($value);
            $content = str_replace('{{' . $key . '}}', $strValue, $content);
            $content = str_replace('{' . $key . '}', $strValue, $content);
        }

        // Затем обычные переменные (приводим к строке безопасно)
        foreach ($this->map->getArrayObject() as $key => $value) {
            $strValue = $this->toString($value);
            $content = str_replace('{{' . $key . '}}', $strValue, $content);
            $content = str_replace('{' . $key . '}', $strValue, $content);
        }

        // Сохраняем в кэш
        if ($this->cacheEnabled) {
            $this->saveToCache($content);
        }

        return $content;
    }

    /**
     * Рендерит шаблон через include + ob_start (гибкий режим с PHP)
     * Переменные доступны в шаблоне как $key (включая массивы!)
     *
     * @throws FileException если файл шаблона не установлен
     * @throws RuntimeException если произошла ошибка при рендеринге
     */
    public function renderPhp(): string
    {
        if ($this->file === null) {
            throw new FileException("Файл шаблона не установлен");
        }

        // Проверяем кэш
        if ($this->cacheEnabled) {
            $cached = $this->getFromCache();
            if ($cached !== null) {
                return $cached;
            }
        }

        $filePath = $this->file->getFile();

        // Извлекаем все переменные (как есть — массивы остаются массивами!)
        $variables = [];
        foreach ($this->map->getArrayObject() as $key => $value) {
            $variables[$key] = $value;
        }
        foreach ($this->escapedMap->getArrayObject() as $key => $value) {
            $variables[$key] = $value;
        }

        ob_start();
        try {
            extract($variables, EXTR_SKIP);
            include $filePath;
            $output = ob_get_clean();
            $output = $output !== false ? $output : '';
        } catch (Throwable $e) {
            ob_end_clean();
            throw new RuntimeException(
                "Ошибка рендеринга шаблона $filePath: " . $e->getMessage()
            );
        }

        // Сохраняем в кэш
        if ($this->cacheEnabled) {
            $this->saveToCache($output);
        }

        return $output;
    }

    public function display(): void
    {
        echo $this->render();
    }

    public function displayPhp(): void
    {
        echo $this->renderPhp();
    }

    // =========================================================================
    // Внутренние методы кэширования (private)
    // =========================================================================

    /**
     * Вычисляет уникальный ключ кэша
     * Учитывает: путь к шаблону + переменные (если включено)
     *
     * ВАЖНО: здесь toString() вызывается БЕЗ строгой проверки,
     * чтобы не ломать кэш при наличии массивов (они просто превращаются в '')
     */
    private function getCacheKey(): string
    {
        if ($this->file === null) {
            throw new FileException("Файл шаблона не установлен");
        }

        $parts = [$this->file->getFile()];

        if ($this->cacheByVariables) {
            $vars = [];
            foreach ($this->map->getArrayObject() as $k => $v) {
                $vars[$k] = $this->toStringSafe($v);
            }
            foreach ($this->escapedMap->getArrayObject() as $k => $v) {
                $vars[$k] = $this->toStringSafe($v);
            }
            ksort($vars);
            $parts[] = serialize($vars);
        }

        return md5(implode('|', $parts));
    }

    /**
     * Безопасная версия toString() — всегда возвращает строку, никогда не бросает исключение
     * Используется только во внутренних методах (кэш), где нельзя ломать работу
     */
    private function toStringSafe(mixed $value): string
    {
        if (is_array($value) || is_object($value) || is_resource($value)) {
            return '';
        }
        return (string)$value;
    }

    private function getCachePath(): string
    {
        return Route::getPathRoot() . '/' . $this->cacheDir . '/' . $this->getCacheKey() . '.html';
    }

    private function getFromCache(): ?string
    {
        $cachePath = $this->getCachePath();
        if (!file_exists($cachePath)) {
            return null;
        }

        $templateMtime = filemtime($this->file->getFile());
        $cacheMtime = filemtime($cachePath);

        if ($cacheMtime < $templateMtime) {
            @unlink($cachePath);
            return null;
        }

        if ($this->cacheTtl !== null) {
            $age = time() - $cacheMtime;
            if ($age > $this->cacheTtl) {
                @unlink($cachePath);
                return null;
            }
        }

        $content = file_get_contents($cachePath);
        return $content !== false ? $content : null;
    }

    private function saveToCache(string $content): void
    {
        $cachePath = $this->getCachePath();
        $cacheDir = dirname($cachePath);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        file_put_contents($cachePath, $content, LOCK_EX);
    }
}