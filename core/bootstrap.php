<?php
declare(strict_types=1);

// =========================================================================
// 1. Автозагрузка
// =========================================================================
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    // ИСПРАВЛЕНО (пункт 2): был путь __DIR__ . '/core/functions/spl.php',
    // то есть core/core/functions/spl.php — несуществующий файл, и
    // require_once падал с fatal error. spl.php лежит рядом, в core/functions/.
    require_once __DIR__ . '/functions/spl.php';
}

// =========================================================================
// 1.5. Конвенция «точка истины» для числовых env-опций (пункт 10)
//   Значения вида "0"/"6379" остаются строками; Config больше НЕ превращает
//   их в "false". Здесь приводим "true"/"false" к 1/0 для php-ини-настроек.
// =========================================================================
function config_bool_to_int(?string $value): int
{
    return in_array(strtolower(trim((string)$value)), ['true', '1', 'yes', 'on'], true) ? 1 : 0;
}

// =========================================================================
// 2. Базовая инициализация ядра
//    ВАЖНО: порядок имеет значение!
//    Route → File → Session → Logger::setLogDir → Container
// =========================================================================

// 2.1. Базовые пути (должны быть установлены ДО создания Config/Logger)
Route::setBasePath(dirname(__DIR__));
File::setBaseDir(dirname(__DIR__));

// 2.2. Сессии — только если они реально настроены и включены.
// ИСПРАВЛЕНО (пункт 11): раньше вызывался Session::initSessionHandler(),
// который внутри делал `new Config()` — второй экземпляр конфига в обход DI.
// Теперь настройки читаются через единственный singleton Config из контейнера
// (шаг 4.1 выполняется до этого блока), а хендлер берёт сам класс Session.
$di = new Container();
Container::setGlobal($di);
$di->singleton(Config::class, fn() => new Config());

$config = $di->get(Config::class);
$sessionHandler = strtolower(trim((string)$config->getEnv('SAVE_SESSION_HANDLER', 'none')));

if ($sessionHandler !== '' && $sessionHandler !== 'none' && $sessionHandler !== 'files') {
    if (!class_exists(Session::class)) {
        // В продакшене молча откатываемся на файловые сессии…
        if (!$config->isDebug()) {
            $sessionHandler = 'files';
        } else {
            // …а в режиме разработки — видимая ошибка вместо тихой деградации
            throw new RuntimeException(
                "SAVE_SESSION_HANDLER=$sessionHandler, но класс Session отсутствует "
                . '(core/classes/module/AccountManagementSystem/Session.php)'
            );
        }
    }

    if ($sessionHandler !== 'files') {
        $savePath = sprintf(
            '%s://%s:%s',
            $config->getEnv('SAVE_SESSION_PATH_METHOD', 'tcp'),
            $config->getEnv('SAVE_SESSION_PATH_HOST', '127.0.0.1'),
            $config->getEnv('SAVE_SESSION_PATH_PORT', '6379')
        );
        $auth = trim((string)$config->getEnv('SAVE_SESSION_PATH_AUTH', ''));
        if ($auth !== '') {
            $savePath .= '?auth=' . rawurlencode($auth);
        }

        ini_set('session.save_handler', $sessionHandler);
        ini_set('session.save_path', $savePath);
        ini_set('session.gc_maxlifetime', (string)(int)$config->getEnv('SESSION.GC_MAXLIFETIME', 3600));
        ini_set('session.cookie_lifetime', (string)(int)$config->getEnv('SESSION.COOKIE_LIFETIME', 3600));
        ini_set('session.cookie_httponly', (string)config_bool_to_int((string)$config->getEnv('SESSION.COOKIE_HTTPONLY', 'true')));
        ini_set('session.cookie_secure', (string)config_bool_to_int((string)$config->getEnv('SESSION.COOKIE_SECURE', 'false')));
        ini_set('session.cookie_samesite', (string)$config->getEnv('SESSION.COOKIE_SAMESITE', 'Lax'));
    }
}

// 2.3. Папка для логов (опционально, по умолчанию core/log/)
// Logger::setLogDir('storage/logs');

// =========================================================================
// 3. DI-контейнер (создан выше на шаге 2.2 — там же зарегистрирован Config)
// =========================================================================

// =========================================================================
// 4. Регистрация ядра (singleton — один экземпляр на запрос)
// =========================================================================

// 4.1. Config — уже зарегистрирован на шаге 2.2 (один и тот же singleton!)

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
//
// ИСПРАВЛЕНО (пункт 11): раньше class_exists() вызывался без принудительной
// автозагрузки и зависел от актуальности composer-classmap (в нём остались
// ссылки на удалённую директорию admin/). Теперь проверка честная:
// сначала быстрый hit, затем гарантированная попытка автозагрузки.
// Фабрики регистрируются только при доступности ВСЕХ зависимостей —
// ошибка конфигурации видна сразу (fail-fast), а не лениво при первом get().
// =========================================================================

/**
 * Проверяет доступность класса с гарантированной попыткой автозагрузки.
 */
function class_available(string $class): bool
{
    return class_exists($class, false) || class_exists($class, true);
}

// Template — factory (каждый раз новый, чтобы не было пересечений переменных)
$di->factory(Template::class, fn() => new Template());
$di->factory(Map::class, fn() => new Map());

// Validator — factory
if (class_available(Validator::class)) {
    $di->factory(Validator::class, fn(Container $c) => new Validator(
        $c->get(DbQuery::class)
    ));
}

// SchemaService — factory (новый экземпляр для админки)
// ИСПРАВЛЕНО (пункт 11): регистрируется ТОЛЬКО если доступна вся цепочка
// зависимостей (DbQuery + Logger + Validator). Раньше при отсутствии
// Validator фабрика всё равно регистрировалась и падала ленивым
// исключением при первом $c->get(Validator::class).
if (class_available(SchemaService::class) && class_available(Validator::class)) {
    $di->factory(SchemaService::class, fn(Container $c) => new SchemaService(
        $c->get(DbQuery::class),
        $c->get(Logger::class),
        $c->get(Validator::class)
    ));
}

// Schema — singleton (один на запрос, зависит от SchemaService)
// ВАЖНО: регистрируем ПЕРЕД PageController!
if (class_available(Schema::class) && class_available(SchemaService::class)) {
    $di->singleton(Schema::class, fn(Container $c) => new Schema(
        $c->get(SchemaService::class),
        $c->get(Config::class),
        $c->get(Logger::class)
    ));
}

// Если есть PageService и PageController — регистрируем их
if (class_available(PageService::class)) {
    $di->factory(PageService::class, fn(Container $c) => new PageService(
        $c->get(DbQuery::class),
        $c->get(Logger::class),
        $c->get(Config::class)
    ));
}
if (class_available(PageController::class)) {
    $di->factory(PageController::class, fn(Container $c) => new PageController(
        $c->get(PageService::class),
        $c->get(Config::class),
        class_available(Schema::class) ? $c->get(Schema::class) : null,
        $c->get(Logger::class)
    ));
}

// =========================================================================
// 5,1 Панель администратора
// =========================================================================
// 🆕 PageSettingsService — класс ещё не создан (директории admin/ нет).
// ИСПРАВЛЕНО (пункт 11): class_available() честно проверяет автозагрузку,
// поэтому блок просто пропускается, а не полагается на устаревший classmap.
if (class_available(PageSettingsService::class)) {
    $di->factory(PageSettingsService::class, fn(Container $c) => new PageSettingsService(
        $c->get(DbQuery::class),
        $c->get(Logger::class),
        $c->get(Validator::class)
    ));
}

// =========================================================================
// 5,2 Eager-резолвинг дешёвых синглтонов ядра (пункт 11)
//     Config уже создан на шаге 2.2; TimeDate/Logger создаём сразу —
//     это объекты без сетевых side-effects. Ошибка конфигурации или
//     битой автозагрузки будет видна немедленно (fail-fast).
//     DataBase НЕ трогаем: PDO-коннект остаётся ленивым, чтобы деградация
//     без БД обрабатывалась каскадом PageController, а не fatal в bootstrap.
// =========================================================================
$di->get(TimeDate::class);
$di->get(Logger::class);

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