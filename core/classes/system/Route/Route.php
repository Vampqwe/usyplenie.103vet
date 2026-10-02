<?php
declare(strict_types = 1);
/**
 * Класс Route — базовые пути и маршрутизация служебных URL
 */
final class Route
{
    private static ?string $basePath = null;

    /**
     * Карта служебных URL → файлы-обработчики
     * Значение — путь к файлу (относительно корня проекта).
     *
     * ВАЖНО: ключ — это ТОЧНЫЙ путь служебного URL (без начального слэша),
     * а не «окончание» URI. Раньше использовался str_ends_with, из-за чего
     * любой пользовательский slug, заканчивающийся на «robots.txt»,
     * перехватывался как служебный URL.
     *
     * Чтобы добавить новый служебный URL — просто допиши строку сюда.
     */
    private const SERVICE_ROUTES = [
        'sitemap.xml' => 'sitemap.php',
        // robots.txt отдаётся Apache как статический файл (см. .htaccess, правило !-f).
        // Динамический обработчик подключается только если реально существует robots.php:
        'robots.txt'  => 'robots.php',
        // 'favicon.ico'    => 'favicon.php',    // пример расширения
        // 'ads.txt'        => 'ads.php',
        // 'manifest.json'  => 'manifest.php',
    ];

    /**
     * Служебные пути, которые НЕЛЬЗЯ обрабатывать как обычные страницы CMS
     * (даже если соответствующий PHP-обработчик отсутствует).
     * Попытка открыть их через PageController должна давать 404, а не случайную страницу.
     */
    private const RESERVED_PATHS = [
        'core', 'vendor', 'admin', 'todo', 'result_pages', 'logs', 'log', 'tests',
    ];

    // =========================================================================
    // Базовые пути
    // =========================================================================

    public static function setBasePath(string $path): void
    {
        $realPath = realpath($path);
        if ($realPath === false) {
            throw new InvalidArgumentException("Базовый путь не существует: $path");
        }
        self::$basePath = $realPath;
    }

    public static function getBasePath(): string
    {
        if (self::$basePath !== null) {
            return self::$basePath;
        }

        if (!isset($_SERVER['DOCUMENT_ROOT'])) {
            throw new RuntimeException('DOCUMENT_ROOT не установлен и базовый путь не задан');
        }
        $docRoot = realpath($_SERVER['DOCUMENT_ROOT']);
        if ($docRoot === false) {
            throw new RuntimeException('DOCUMENT_ROOT не существует');
        }
        return $docRoot;
    }

    public static function getPathRoot(): string
    {
        return self::getBasePath();
    }

    public static function getPathCore(): string
    {
        return self::getPathRoot() . '/core/';
    }

    public static function getPathCoreDb(): string
    {
        return self::getPathCore() . 'db/';
    }

    public static function getPathCoreLog(): string
    {
        return self::getPathCore() . 'log/';
    }

    // =========================================================================
    // Служебные URL (sitemap.xml, robots.txt и т.д.)
    // =========================================================================

    /**
     * Проверяет, является ли URI служебным (sitemap.xml, robots.txt и т.д.)
     */
    public static function isServiceUrl(string $uri): bool
    {
        return self::getServiceHandler($uri) !== null;
    }

    /**
     * Возвращает путь к файлу-обработчику для служебного URL
     * или null, если URI не является служебным.
     *
     * ИСПРАВЛЕНО (пункт 3):
     *  - сравнение теперь ТОЧНОЕ (нормализованный путь === ключ карты),
     *    а не str_ends_with — раньше slug вида /foo/sitemap.xml
     *    перехватывался как служебный URL;
     *  - отсутствующий обработчик (robots.php) больше не может привести
     *    к require_once несуществующего файла: возвращаем null и
     *    отдаём URI обычной обработке (PageController → 404);
     *  - добавлен статический fallback: если в корне проекта лежит файл
     *    с точным именем служебного URL (например robots.txt), он
     *    безопасно отдаётся напрямую — это спасает на Nginx/локальном
     *    сервере PHP, где Apache-правило «существующий файл → отдать как есть» отсутствует.
     *
     * @return string|null абсолютный путь к файлу или null
     */
    public static function getServiceHandler(string $uri): ?string
    {
        // Нормализуем входной URI к тому же виду, что и ключи карты
        $path = trim(Url::normalize($uri), '/');
        if ($path === '') {
            return null;
        }

        $handler = self::SERVICE_ROUTES[$path] ?? null;
        if ($handler === null) {
            return null;
        }

        $root = self::getPathRoot();

        // 1. PHP-обработчик, если он реально существует
        $handlerPath = $root . '/' . $handler;
        if (is_file($handlerPath)) {
            return $handlerPath;
        }

        // 2. Статический файл с точным именем URL (robots.txt, favicon.ico и т.п.)
        $staticPath = $root . '/' . $path;
        if (is_file($staticPath)) {
            return $staticPath;
        }

        // 3. Ни обработчика, ни файла — НЕ считаем URI служебным
        return null;
    }

    /**
     * Отдаёт статический служебный файл (результат getServiceHandler)
     * с корректным Content-Type. Вызывается из index.php.
     */
    public static function outputStaticFile(string $filePath): void
    {
        $types = [
            'txt'  => 'text/plain; charset=UTF-8',
            'xml'  => 'application/xml; charset=UTF-8',
            'json' => 'application/json; charset=UTF-8',
            'ico'  => 'image/x-icon',
        ];
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!headers_sent()) {
            header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        }
        readfile($filePath);
    }

    /**
     * Protected-пути, которые нельзя рендерить как страницы CMS
     * (первый сегмент нормализованного пути).
     */
    public static function isReservedPath(string $normalizedPath): bool
    {
        $first = explode('/', ltrim($normalizedPath, '/'))[0] ?? '';
        return in_array(strtolower($first), self::RESERVED_PATHS, true);
    }

    /**
     * Возвращает список всех зарегистрированных служебных URL
     * (полезно для отладки или автогенерации)
     */
    public static function getServiceRoutes(): array
    {
        return self::SERVICE_ROUTES;
    }
}