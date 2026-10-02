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
     * Ключ — окончание URI, значение — путь к файлу (относительно корня проекта)
     *
     * Чтобы добавить новый служебный URL — просто допиши строку сюда.
     */
    private const SERVICE_ROUTES = [
        'sitemap.xml' => 'sitemap.php',
        'robots.txt'  => 'robots.php',
        // 'favicon.ico'    => 'favicon.php',    // пример расширения
        // 'ads.txt'        => 'ads.php',
        // 'manifest.json'  => 'manifest.php',
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
     * или null, если URI не является служебным
     *
     * @return string|null абсолютный путь к файлу или null
     */
    public static function getServiceHandler(string $uri): ?string
    {
        foreach (self::SERVICE_ROUTES as $suffix => $handler) {
            if (str_ends_with($uri, $suffix)) {
                $fullPath = self::getPathRoot() . '/' . $handler;
                return is_file($fullPath) ? $fullPath : null;
            }
        }
        return null;
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