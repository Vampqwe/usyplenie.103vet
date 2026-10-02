<?php
declare(strict_types=1);
/**
 * Front Controller — точка входа VampqweEngine
 */
require_once __DIR__ . "/core/bootstrap.php";

$di     = Container::getGlobal();
$logger = $di->get(Logger::class);

// =========================================================================
// Восстановление исходного URI (пункт 7)
//
// Проблема: ErrorDocument 404 /index.php — это ВНУТРЕННИЙ редирект Apache,
// при котором REQUEST_URI перезаписывается на "/index.php", и оригинальный
// адрес терялся → CMS всегда отдавала дефолтную 404 с неправильным canonical.
//
// Решение: Apache (модуль rewrite) передаёт оригинальный URI в переменной
// окружения REDIRECT_URL (а query — в REDIRECT_QUERY_STRING). Если она
// пустая (прямой запрос /index.php), используем PATH_INFO, затем REQUEST_URI.
// =========================================================================
$originalUri = $_SERVER['REDIRECT_URL']
    ?? ($_SERVER['PATH_INFO'] ?? ($_SERVER['REQUEST_URI'] ?? '/'));

// Восстанавливаем query string при внутреннем редиректе ErrorDocument
$queryString = '';
if (!empty($_SERVER['REDIRECT_QUERY_STRING'])) {
    $queryString = '?' . $_SERVER['REDIRECT_QUERY_STRING'];
} elseif (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '') {
    $queryString = '?' . $_SERVER['QUERY_STRING'];
}

// =========================================================================
// Нормализация URI через единый класс Url
// Url::normalize() вырезает query/fragment — вызываем ОДИН раз здесь,
// дальше по коду ходит только чистый нормализованный путь (пункт 8).
// =========================================================================
$normalizedPath = Url::normalize($originalUri);
$uri            = '/' . $normalizedPath; // '/' для корня

// Защита от прямого обращения к front controller: /index.php — не страница
if ($normalizedPath === 'index.php') {
    $uri = '/';
    $normalizedPath = '';
}

// =========================================================================
// Служебные URL (sitemap.xml, robots.txt и т.д.)
// Вся логика — в классе Route (пункт 3: точное совпадение + проверка файла)
// =========================================================================
$serviceHandler = Route::getServiceHandler($uri);
if ($serviceHandler !== null) {
    if (str_ends_with($serviceHandler, '.php')) {
        require_once $serviceHandler;
    } else {
        // Статический файл (robots.txt и т.п.) — отдать напрямую
        Route::outputStaticFile($serviceHandler);
    }
    exit;
}

// Зарезервированные пути (core/, vendor/, admin/...) — сразу 404,
// чтобы они никогда не рендерились как случайная страница CMS
if ($normalizedPath !== '' && Route::isReservedPath($normalizedPath)) {
    http_response_code(404);
    $uri = '/__not-found__'; // гарантированный slug-мишень для PageService
}

// =========================================================================
// Глобальный обработчик исключений — ТОЛЬКО логирует
// =========================================================================
set_exception_handler(function (Throwable $e) use ($logger): void {
    try {
        $logger->error('Необработанное исключение: ' . $e->getMessage()
            . ' | ' . $e->getFile() . ':' . $e->getLine());
    } catch (Throwable $logError) {
        error_log('Logger failed: ' . $logError->getMessage()
            . ' | Original: ' . $e->getMessage());
    }

    if (!headers_sent()) {
        http_response_code(500);
    }
});

// =========================================================================
// Передаём управление PageController
// (заодно сохраняем восстановленный URI — PageController сам добавит его
//  в superglobals, см. handle())
// =========================================================================
$controller = $di->get(PageController::class);
$controller->handle($uri);