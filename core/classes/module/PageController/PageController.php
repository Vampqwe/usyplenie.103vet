<?php
declare(strict_types=1);
/**
 * PageController — контроллер для рендеринга страниц
 *
 * Обработка ошибок:
 *  - handleError() НЕ логирует — это делает set_exception_handler в index.php
 *  - handleError() НЕ перебрасывает исключение
 *  - Пытается показать красивую страницу ошибки (БД → файл → хардкод)
 */
class PageController
{
    private PageService $pageService;
    private Config      $config;
    private ?Schema     $schema;
    private Logger      $logger;
    private string      $templatesPath;

    public function __construct(
        PageService $pageService,
        Config $config,
        ?Schema $schema = null,
        ?Logger $logger = null
    ) {
        $this->pageService   = $pageService;
        $this->config        = $config;
        $this->schema        = $schema;
        $this->logger        = $logger ?? Logger::getInstance('app.log');
        $this->templatesPath = Route::getPathRoot() . '/templates/';
    }

    public function handle(string $uri): void
    {
        try {
            $pageData = $this->pageService->getPageByUri($uri);
            if (($pageData['page_type'] ?? '') === '404') {
                http_response_code(404);
            }
            $this->renderPage($pageData, $uri);
        } catch (Throwable $e) {
            $this->handleError($e);
        }
    }

    private function createTemplate(): Template
    {
        return Container::getGlobal()->get(Template::class);
    }

    private function renderPage(array $pageData, string $uri): void
    {
        $headHtml = $this->renderHead($pageData, $uri);

        $schemaMarkup = '';
        if ($this->schema !== null) {
            try {
                $schemaMarkup = $this->schema->renderForPage($pageData);
            } catch (Throwable $e) {
                $this->logger->warning('Ошибка генерации Schema.org: ' . $e->getMessage());
            }
        }

        $navHtml    = $this->loadNavigation();
        $footerHtml = $this->loadFooter();
        $content    = '<main class="content">' . ($pageData['content'] ?? '') . '</main>';
        $scripts    = '<script src="/assets/js/main.js" defer></script>';

        $layoutTpl = $this->createTemplate();
        $layoutTpl->addTplFile($this->templatesPath . 'layout.html');
        $layoutTpl->assignArray([
            'head-meta'  => $headHtml,
            'schema.org' => $schemaMarkup,
            'nav'        => $navHtml,
            'bloks'      => '',
            'content'    => $content,
            'footer'     => $footerHtml,
            'script'     => $scripts,
        ]);
        $layoutTpl->display();
    }

    /**
     * Рендерит head-full.html с подстановкой переменных
     * Canonical URL строится через Url::canonical() — единая точка истины
     */
    private function renderHead(array $pageData, string $uri): string
    {
        // ✅ Используем Url вместо ручной конкатенации
	$canonical = !empty($pageData['canonical'])
		? $pageData['canonical']
		: Url::buildCanonical($pageData['slug'] ?? '', $this->config);

        $ogType = ($pageData['page_type'] ?? 'article') === 'home' ? 'website' : 'article';

        $headTpl = $this->createTemplate();
        $headTpl->addTplFile($this->templatesPath . 'head-full.html');
        $headTpl->assignArray([
            'title'          => $pageData['title'] ?? 'Главная',
            'description'    => $pageData['description'] ?? '',
            'keywords'       => $pageData['keywords'] ?? '',
            'canonical'      => $canonical,
            'og:title'       => $pageData['og_title'] ?? $pageData['title'] ?? '',
            'og:description' => $pageData['og_description'] ?? $pageData['description'] ?? '',
            'og:type'        => $ogType,
        ], true);
        return $headTpl->render();
    }

    private function loadNavigation(): string
    {
        $navTpl = $this->createTemplate();
        $navTpl->addTplFile($this->templatesPath . 'nav.html');
        $navTpl->enableCache()->setCacheByVariables(false);
        return $navTpl->render();
    }

    private function loadFooter(): string
    {
        $footerTpl = $this->createTemplate();
        $footerTpl->addTplFile($this->templatesPath . 'footer.html');
        $footerTpl->enableCache()->setCacheByVariables(false);
        return $footerTpl->render();
    }

    // =========================================================================
    // Обработка ошибок (вариант B)
    // =========================================================================

    /**
     * Обработка ошибок
     *
     * Принцип:
     *  - НЕ логируем здесь — это делает set_exception_handler
     *  - НЕ перебрасываем исключение
     *  - Пытаемся показать страницу 500 из БД
     *  - Если БД недоступна — показываем статический файл или хардкод
     */
    private function handleError(Throwable $e): void
    {
        if (!headers_sent()) {
            http_response_code(500);
        }

        try {
            $this->renderErrorPage($e);
        } catch (Throwable $renderError) {
            // Каскадная ошибка — логируем и выходим
            try {
                $this->logger->error(
                    'Каскадная ошибка при рендере страницы 500: ' . $renderError->getMessage()
                    . ' | Исходная ошибка: ' . $e->getMessage()
                );
            } catch (Throwable $logError) {
                // Даже логгер упал — ничего не поделать
            }
        }
    }

    /**
     * Рендерит страницу ошибки с трёхуровневым fallback
     */
    private function renderErrorPage(Throwable $e): void
    {
        // Уровень 1: Страница 500 из БД
        $pageData = $this->tryGetErrorPageFromDb();
        if ($pageData !== null) {
            $this->renderErrorFromDb($pageData, $e);
            return;
        }

        // Уровень 2: Статический файл 500.html
        $staticFile = $this->templatesPath . '500.html';
        if (is_file($staticFile)) {
            $content = file_get_contents($staticFile);
            if ($content !== false) {
                $content = str_replace(
                    ['{{message}}', '{{error}}'],
                    [
                        APP_DEBUG ? htmlspecialchars($e->getMessage()) : 'Внутренняя ошибка сервера',
                        APP_DEBUG ? htmlspecialchars($e->getTraceAsString()) : '',
                    ],
                    $content
                );
                echo $content;
                return;
            }
        }

        // Уровень 3: Захардкоженный HTML
        $this->renderHardcodedError($e);
    }

    private function tryGetErrorPageFromDb(): ?array
    {
        try {
            return $this->pageService->get500Page();
        } catch (Throwable $e) {
            return null;
        }
    }

    private function renderErrorFromDb(array $pageData, Throwable $e): void
    {
        $layoutTpl = $this->createTemplate();
        $layoutTpl->addTplFile($this->templatesPath . 'layout.html');

        $layoutTpl->assignArray([
            'head-meta'  => $this->renderErrorHead($pageData),
            'schema.org' => '',
            'nav'        => $this->loadNavigation(),
            'bloks'      => '',
            'content'    => '<main class="content">' . ($pageData['content'] ?? '') . '</main>',
            'footer'     => $this->loadFooter(),
            'script'     => '',
        ]);
        $layoutTpl->display();
    }

    private function renderErrorHead(array $pageData): string
    {
        $headTpl = $this->createTemplate();
        $headTpl->addTplFile($this->templatesPath . 'head-full.html');
        $headTpl->assignArray([
            'title'          => $pageData['title'] ?? 'Ошибка сервера',
            'description'    => $pageData['description'] ?? 'Внутренняя ошибка сервера',
            'keywords'       => '',
            'canonical'      => Url::canonical('500', $this->config),
            'og:title'       => $pageData['og_title'] ?? $pageData['title'] ?? 'Ошибка сервера',
            'og:description' => $pageData['og_description'] ?? 'Внутренняя ошибка сервера',
            'og:type'        => 'website',
        ], true);
        return $headTpl->render();
    }

    private function renderHardcodedError(Throwable $e): void
    {
        $message = APP_DEBUG
            ? '<h1>Ошибка: ' . htmlspecialchars($e->getMessage()) . '</h1>'
              . '<p>Файл: ' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '</p>'
              . '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>'
            : '<h1>500 — Внутренняя ошибка сервера</h1>'
              . '<p>Извините, на сервере произошла ошибка. Пожалуйста, попробуйте позже.</p>';

        echo '<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ошибка сервера</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
               max-width: 800px; margin: 50px auto; padding: 20px; color: #333; }
        h1 { color: #c0392b; }
        pre { background: #f5f5f5; padding: 15px; overflow-x: auto; border-radius: 4px;
              font-size: 13px; }
    </style>
</head>
<body>' . $message . '</body>
</html>';
    }
}