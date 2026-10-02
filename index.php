<?php
declare(strict_types=1);
/**
 * Front Controller — точка входа VampqweEngine
 */
require_once __DIR__ . "/core/bootstrap.php";

$di     = Container::getGlobal();
$logger = $di->get(Logger::class);

// =========================================================================
// Нормализация URI через единый класс Url
// =========================================================================
$requestUri    = $_SERVER['REQUEST_URI'] ?? '/';
$normalizedUri = Url::normalize($requestUri);
$uri           = $normalizedUri === '' ? '/' : $normalizedUri;

// =========================================================================
// Служебные URL (sitemap.xml, robots.txt и т.д.)
// Вся логика — в классе Route
// =========================================================================
$serviceHandler = Route::getServiceHandler($uri);
if ($serviceHandler !== null) {
    require_once $serviceHandler;
    exit;
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
// =========================================================================
$controller = $di->get(PageController::class);
$controller->handle($uri);