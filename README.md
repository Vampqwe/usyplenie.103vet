# VampqweEngine — «Достойный уход» (usyplenie.103vet.by)

Информационный сайт ветеринарной клиники паллиативного ухода на собственном лёгком PHP-движке **VampqweEngine**. Без сторонних фреймворков: фронт-контроллер, DI-контейнер, шаблонизатор, слой БД и генерация Schema.org / sitemap.xml написаны вручную.

- **PHP:** ≥ 8.0 (в `.osp/project.ini` указан PHP-8.5)
- **БД:** MySQL 8.4 (PDO + prepared statements)
- **Сессии:** Redis (опционально, `ext-redis`)
- **Веб-сервер:** Apache с mod_rewrite (OpenServer / любой LAMP)
- **Зависимости Composer:** только `ramsey/uuid ^4.9`

---

## Содержание

1. [Архитектура](#архитектура)
2. [Структура проекта](#структура-проекта)
3. [Жизненный цикл запроса](#жизненный-цикл-запроса)
4. [Ядро (core/classes/system)](#ядро-coreclassessystem)
5. [Модули приложения (core/classes/module)](#модули-приложения-coreclassesmodule)
6. [Конфигурация](#конфигурация)
7. [Шаблоны](#шаблоны)
8. [Служебные URL (sitemap.xml, robots.txt)](#служебные-url)
9. [Установка и запуск](#установка-и-запуск)
10. [Безопасность](#безопасность)
11. [Известные проблемы](#известные-проблемы)

---

## Архитектура

```
                         ┌──────────────┐
  HTTP-запрос ──────────►│  .htaccess   │  (rewrite: всё → index.php)
                         └──────┬───────┘
                                ▼
                         ┌──────────────┐
                         │  index.php   │  Front Controller
                         │ Url::normalize│
                         │ Route::get…  │──► sitemap.php (служебные URL)
                         └──────┬───────┘
                                ▼
                         ┌──────────────┐   DI (Container, bootstrap.php)
                         │PageController│◄── Config, Logger, DataBase,
                         └──────┬───────┘    DbQuery, Template, Map,
                                ▼            Validator, SchemaService,
                         ┌──────────────┐    Schema, PageService
                         │ PageService  │──► таблица `pages` (БД)
                         └──────┬───────┘
                                ▼
                         ┌──────────────┐   layout.html ← head-full.html
                         │  Template    │   nav.html, footer.html
                         └──────┬───────┘   JSON-LD ← таблица `schema_org`
                                ▼
                            HTML-ответ
```

Принципы:

- **Единая точка входа** — все запросы идут через `index.php`.
- **DI-контейнер** (`Container`) — singleton/factory-регистрация + авто-резолвинг через рефлексию; экспорт в `$GLOBALS['di']`.
- **Слои:** system-классы (Config, Logger, File, Route, DbQuery…) не знают о прикладных; модули приложения (PageService, Schema…) собираются в `bootstrap.php`.
- **Каскадный fallback ошибок:** страница 500 из БД → статический `templates/500.html` → хардкод-HTML.
- **SEO-first:** canonical через `Url`, JSON-LD (WebPage/MedicalWebPage/CollectionPage + схемы из БД), динамический `sitemap.xml`, meta-гео теги, OG-разметка.

---

## Структура проекта

```
├── index.php                  # Front Controller
├── sitemap.php                # Динамическая генерация sitemap.xml
├── robots.txt                 # Статический файл для поисковиков
├── .htaccess                  # Apache: rewrite, защита, gzip, кэш, заголовки
├── composer.json / .lock      # ramsey/uuid + classmap автозагрузка
├── favicon.svg / -64.png / -180.png, BingSiteAuth.xml
├── *.cmd                      # Windows-скрипты (init, gitPush, gitClone, OSP)
├── .osp/project.ini           # Настройки OpenServer (домен usyplenie.103vet.test)
├── assets/js/main.js          # JS: FAQ-аккордеон, бургер-меню, дропдауны
├── css/styles.css             # Стили сайта (~840 строк)
├── templates/
│   ├── layout.html            # Каркас страницы: {head-meta} {schema.org} {nav} {content} {footer} {script}
│   ├── head-full.html         # <head>: {{title}} {{description}} {{canonical}}, OG, gtag, шрифты
│   ├── nav.html               # Шапка + меню (собака/кошка, симптомы/уход)
│   └── footer.html            # Подвал с контактами
├── core/
│   ├── bootstrap.php          # Инициализация: автозагрузка → пути → DI-регистрация
│   ├── config.env             # Рабочая конфигурация (СЕКРЕТЫ — вне git!)
│   ├── config.env.example     # Шаблон конфигурации
│   ├── functions/spl.php      # Резервный SPL-автозагрузчик (если нет vendor/)
│   ├── log/app.log            # Журнал приложения
│   ├── classes/
│   │   ├── system/            # Ядро: Config, DataBase, DbQuery, File, Logger,
│   │   │                      #       Route, TimeDate, Container, Map
│   │   └── module/            # Приложение: PageController, PageService, Template,
│   │                          #       Url, Schema, SchemaService, Helper, Validator,
│   │                          #       AccountManagementSystem (User/Account/Session — заглушки)
└── vendor/                    # Composer-зависимости (ramsey/*, symfony/polyfill, psr/log)
```

---

## Жизненный цикл запроса

1. **`core/bootstrap.php`**
   - подключает `vendor/autoload.php` (classmap по `core/classes/system/` и `core/classes/module/`) или резервный `spl.php`;
   - `Route::setBasePath()` / `File::setBaseDir()` — базовые пути;
   - `Session::initSessionHandler()` — настройки хранилища сессий (Redis);
   - создаётся `Container`, регистрируются Config → TimeDate → Logger → DataBase → DbQuery → Template/Map/Validator/SchemaService/Schema/PageService/PageController;
   - `APP_DEBUG` из конфига управляет `display_errors`.
2. **`index.php`**
   - нормализует `REQUEST_URI` через `Url::normalize()`;
   - служебные URL (`sitemap.xml`) отдаёт обработчик из `Route::SERVICE_ROUTES`;
   - глобальный `set_exception_handler` логирует необработанные исключения и ставит HTTP 500;
   - передаёт URI в `PageController::handle()`.
3. **`PageController`**
   - `PageService::getPageByUri()`: home (пустой URI) → поиск по `slug+status=published` → 404;
   - рендер `head-full.html` (escape-подстановка `{{key}}`), JSON-LD от `Schema`, `nav/footer` (с кэшем), сборка `layout.html`.

---

## Ядро (core/classes/system)

| Класс | Назначение |
|---|---|
| `Config` | Парсит `.env` / `config.env` (приоритет: `.env` > корневой `config.env` > `core/config.env`), `getEnv/setEnv/isDebug/saveEnv`, нормализация bool |
| `DataBase` | PDO-соединение (ERRMODE_EXCEPTION, NATIVE prepares), DSN из конфига; singleton в DI |
| `DbQuery` | Query-builder: select/selectOne/find/insert/update/delete/count/max/min/sum, `buildWhere` (IN/BETWEEN/LIKE/операторы/NULL), транзакции, лог SQL |
| `File` | Безопасные файловые операции с sandbox (`baseDir`, запрет `..`), чтение/запись/удаление |
| `Logger` | Registry-singleton по файлам логов, уровни error/warning/info/debug, директория `core/log/` |
| `Route` | Базовые пути проекта + карта служебных URL (`SERVICE_ROUTES`) |
| `TimeDate` | Часовой пояс и форматы из Config, `getNow/getDate/getTime` |
| `Container` | Микроскопический DI: `singleton()`, `factory()`, `get()`, авто-резолвинг конструкторов |
| `Map` | Обёртка над `ArrayObject` (типобезопасный put/get/merge) |
| `*Exception` | `ConfigException`, `FileException`, `ContainerException` (наследники RuntimeException) |

## Модули приложения (core/classes/module)

| Класс | Назначение |
|---|---|
| `PageController` | Рендеринг страниц, head/canonical/OG, каскадный fallback 500 |
| `PageService` | Данные страниц из таблицы `pages` + in-memory кэш (по slug/id/home/404/500), хлебные крошки |
| `Template` | Шаблонизатор: `render()` (str_replace `{key}`/`{{key}}`), `renderPhp()` (include), файловый кэш с TTL и инвалидацией по mtime |
| `Url` | Нормализация путей, canonical/absolute из `SITE_URL`, сегменты, лимит 255 символов |
| `Schema` | JSON-LD `<script>`: авто-схема по типу страницы + схемы из БД (`@graph`) |
| `SchemaService` | CRUD и выборка схем из таблицы `schema_org`, валидация JSON-LD |
| `Validator` | Централизованная валидация: required/email/url/regex/unique(in DB)/same/in, набор ошибок |
| `Helper` | `getUUIDv4()` (ramsey/uuid) |
| `AccountManagementSystem`, `User`, `Account`, `Session` | **Заготовки** под будущую админку/ЛК (методы пустые; реализован только `Session::initSessionHandler()`) |

Требуемая схема БД (минимум):

```sql
CREATE TABLE pages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255), description TEXT, keywords TEXT, canonical VARCHAR(255),
  og_title VARCHAR(255), og_description TEXT,
  slug VARCHAR(255) UNIQUE,          -- '' = главная
  content MEDIUMTEXT,
  page_type ENUM('home','hub','article','404','500') DEFAULT 'article',
  parent_id INT NULL,
  status ENUM('published','draft') DEFAULT 'draft',
  sort_order INT DEFAULT 0, is_in_menu TINYINT DEFAULT 0,
  created_at DATETIME, updated_at DATETIME, published_at DATETIME NULL
);

CREATE TABLE schema_org (
  id INT AUTO_INCREMENT PRIMARY KEY,
  route VARCHAR(255) NULL,           -- '*' = глобальная
  page_id INT NULL,
  schema_type VARCHAR(100) NOT NULL,
  data JSON NOT NULL,
  priority INT DEFAULT 0,
  is_active TINYINT DEFAULT 1
);
```

---

## Конфигурация

Файлы читаются в порядке возрастания приоритета: `core/config.env` → `./config.env` → `./.env` (последний перезаписывает). Ключевые переменные:

| Переменная | Пример | Описание |
|---|---|---|
| `DB_LOGIN/DB_PASSWORD/DB_HOST/DB_NAME/DB_CHARSET/DB_DRIVER` | mysql / vetMinsk_usyplenie | Подключение PDO |
| `ACTIVE_MODE` | `MODE_DB` | Режим работы (зарезервировано) |
| `DEFAULT_TIMEZONE/DATE_TPL/TIME_TPL` | Europe/Minsk | Время/форматы |
| `APP_DEBUG` | `false` | В продакшене обязательно `false` (см. `.env` override) |
| `SAVE_SESSION_*` | redis tcp 127.0.0.1:6379 | Хранилище сессий |
| `SESSION.COOKIE_*` | lifetime/httponly/secure/samesite | Параметры cookie |
| `SITE_URL/SITE_NAME` | https://usyplenie.103vet.by/ | Домен для canonical/sitemap/OG |

Быстрый старт: `cp core/config.env.example core/config.env` и заполнить креды.

---

## Шаблоны

- Плейсхолдеры вида `{key}` и `{{key}}` подставляются через `str_replace` (без выполнения PHP).
- `head-full.html` рендерится с `escape=true` (htmlspecialchars) — пользовательский контент из БД экранируется.
- `layout.html`, `nav.html`, `footer.html` — без экранирования (содержат готовый HTML блоков).
- `nav/footer` кэшируются на диске (`core/cache/templates/`), инвалидация по изменению файла шаблона.

## Служебные URL

Карта — в `Route::SERVICE_ROUTES`: `sitemap.xml → sitemap.php`. Файл `robots.php` в карте указан, но **отсутствует** — `/robots.txt` отдаётся статическим файлом напрямую Apache. Для добавления нового служебного URL достаточно дописать строку в константу.

---

## Установка и запуск

### Локально (OpenServer, Windows)
```bat
:: 1. Разместить проект в domains/usyplenie.103vet.test
:: 2. Создать конфиг
copy core\config.env.example core\config.env   :: + заполнить DB_ credentials
:: 3. Зависимости
composer install
:: 4. Настроить OpenServer-проект (домен, PHP-8.5, MySQL-8.4)
OSP.cmd usyplenie.103vet.test
```

### Продакшен (LAMP)
```bash
git clone <repo> && cd usyplenie.103vet
composer install --no-dev --optimize-autoloader
cp core/config.env.example core/config.env && $EDITOR core/config.env   # боевые креды
# .env в корне: APP_DEBUG=false
mysql -u... -p... < schema.sql        # создать таблицы pages / schema_org + наполнить
chown -R www-data:www-data core/log   # права на запись логов
```

### Git-скрипты (Windows)
- `init.cmd` — `git init/add/commit/branch/remote/push` одной командой;
- `gitPush.cmd`, `gitClone.cmd` — типовые push/clone с параметрами.

---

## Безопасность

Реализовано:
- PDO prepared statements везде; `quoteIdentifier()`/`assertIdentifier()` — whitelist `[A-Za-z0-9_]` для имён таблиц/колонок; запрет `UPDATE/DELETE` без WHERE.
- `File` — sandbox внутри baseDir, блокировка `..`.
- `.htaccess` — запрет доступа к `core/`, `vendor/`, `tests/`, скрытым файлам; защита от XSS/SQLi в query string; security headers (X-Frame-Options, X-Content-Type-Options, Referrer-Policy); hotlink protection.
- Куки сессий: HttpOnly, Secure, SameSite=strict.
- Ошибки в продакшене не выводятся (только лог), стек-трейсы — лишь при `APP_DEBUG=true`.

Рекомендуется усилить: CSP-заголовок, включённый HTTPS-redirect в `.htaccess` (сейчас закомментирован), ротация логов.

---

## Известные проблемы

Полный разбор см. в отчёте аудита (2026-10-02). Кратко:

**Критические**
- `core/config.env` со всеми БД-кредами закоммичен в git (нет `.gitignore`).
- `Route::SERVICE_ROUTES` ссылается на несуществующий `robots.php`.
- `bootstrap.php` подключает `core/functions/spl.php` по неверному пути (`core/core/...`) — fallback-автозагрузка неработоспособна.
- Схема БД (`pages`, `schema_org`) отсутствует в репозитории — проект нельзя развернуть «из коробки».

**Высокие**
- `config.env` в репозитории содержит `APP_DEBUG=true` — риск утечки стек-трейсов при деплое без переопределения.
- HTTPS-редирект закомментирован при `COOKIE_SECURE=1`.
- `ErrorDocument 404 /index.php` теряет исходный URI → все 404 получают канонical главной.

**Средние**
- Заглушки `User`/`Account`/`AccountManagementSystem` неработоспособны (`new File()` без аргумента и т.п.).
- `Session::__construct` использует `:`/`else`/`endif` вместо `{}` — fatal error при любом `new Session()`.
- `Config::normalizeValue()` превращает `0`/`false`/пустые значения в строку `"false"` (портит `COOKIE_HTTPONLY=0`, пустой Redis-auth).

**Низкие / косметика**
- Относительные пути favicons (`../favicon.svg`) в head-full.html.
- Несогласованность docblock-пути `Validator`, опечатки («не состыковки», `writenByte`, `visiter`), отсутствие unit-тестов (`autoload-dev` указывает на несуществующий `tests/`).
