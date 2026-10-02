<?php
declare(strict_types=1);

// =========================================================================
// 1. Автозагрузка
// =========================================================================
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    require_once __DIR__ . '/core/functions/spl.php';
}

// =========================================================================
// 2. Базовая инициализация ядра
//    ВАЖНО: порядок имеет значение!
//    Route → File → Session → Logger::setLogDir → Container
// =========================================================================

// 2.1. Базовые пути (должны быть установлены ДО создания Config/Logger)
Route::setBasePath(dirname(__DIR__));
File::setBaseDir(dirname(__DIR__));

// 2.2. Сессии (если класс Session существует)
if (class_exists('Session')) {
    Session::initSessionHandler();
}

// 2.3. Папка для логов (опционально, по умолчанию core/log/)
// Logger::setLogDir('storage/logs');

// =========================================================================
// 3. DI-контейнер
// =========================================================================
$di = new Container();
Container::setGlobal($di);

// =========================================================================
// 4. Регистрация ядра (singleton — один экземпляр на запрос)
// =========================================================================

// 4.1. Config — читает .env, один на всё приложение
$di->singleton(Config::class, fn() => new Config());

// 4.2. TimeDate — один на всё приложение (зависит от Config)
$di->singleton(TimeDate::class, fn(Container $c) => new TimeDate(
    $c->get(Config::class)
));

// 4.3. Logger — registry-singleton, но в DI регистрируем дефолтный
$di->singleton(Logger::class, fn() => Logger::getInstance('app.log'));

// 4.4. DataBase — одно соединение на запрос (зависит от Config и Logger)
$di->singleton(DataBase::class, fn(Container $c) => new DataBase(
    $c->get(Config::class),
    $c->get(Logger::class)
));
// 4.5. DbQuery — factory (новый экземпляр, т.к. может быть нужен с разными настройками)
$di->factory(DbQuery::class, fn(Container $c) => new DbQuery(
    $c->get(DataBase::class),
    $c->get(Logger::class)
));

// =========================================================================
// 5. Регистрация сервисов приложения (factory — новые экземпляры)
// =========================================================================

// Template — factory (каждый раз новый, чтобы не было пересечений переменных)
$di->factory(Template::class, fn() => new Template());
$di->factory(Map::class, fn() => new Map());
// Validator — factory
if (class_exists('Validator')) {
    $di->factory(Validator::class, fn(Container $c) => new Validator(
		$c->get(DbQuery::class)
    ));
}
// SchemaService — factory (новый экземпляр для админки)
if (class_exists('SchemaService')) {
    $di->factory(SchemaService::class, fn(Container $c) => new SchemaService(
        $c->get(DbQuery::class),
        $c->get(Logger::class),
        $c->get(Validator::class)
    ));
}

// Schema — singleton (один на запрос, зависит от SchemaService)
// ВАЖНО: регистрируем ПЕРЕД PageController!
if (class_exists('Schema')) {
    $di->singleton(Schema::class, fn(Container $c) => new Schema(
        $c->get(SchemaService::class),
        $c->get(Config::class),
        $c->get(Logger::class)
    ));
}
// Если есть PageService и PageController — регистрируем их
if (class_exists('PageService')) {
    $di->factory(PageService::class, fn(Container $c) => new PageService(
        $c->get(DbQuery::class),
        $c->get(Logger::class),
		$c->get(Config::class) 
    ));
}
if (class_exists('PageController')) {
    $di->factory(PageController::class, fn(Container $c) => new PageController(
        $c->get(PageService::class),
        $c->get(Config::class),
        class_exists('Schema') ? $c->get(Schema::class) : null,
        $c->get(Logger::class)
    ));
}
// =========================================================================
// 5,1 Панель администратора
// =========================================================================
// 🆕 PageSettingsService
if (class_exists('PageSettingsService')) {
    $di->factory(PageSettingsService::class, fn(Container $c) => new PageSettingsService(
        $c->get(DbQuery::class),
        $c->get(Logger::class),
        $c->get(Validator::class)
    ));
}
// =========================================================================
// 6. Экспорт контейнера
// =========================================================================
$GLOBALS['di'] = $di;

// =========================================================================
// 7. Настройка отображения ошибок
// =========================================================================
// Режим отладки берётся из конфигурации (APP_DEBUG в .env/config.env),
// а не захардкожен. В продакшене ошибки не выводятся в HTML,
// но пишутся в лог (error_reporting остаётся максимальным).
$appDebug = $di->get(Config::class)->isDebug();
define('APP_DEBUG', $appDebug);

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}
error_reporting(E_ALL);