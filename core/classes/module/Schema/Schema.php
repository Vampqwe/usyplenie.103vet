<?php
declare(strict_types=1);
/**
 * Schema — рендеринг JSON-LD разметки Schema.org
 *
 * Отвечает ТОЛЬКО за:
 *  - Построение HTML-тега <script type="application/ld+json">
 *  - Автоматическую базовую схему по типу страницы
 *
 * НЕ отвечает за:
 *  - Запросы к БД (это делает SchemaService)
 *  - Валидацию данных (это делает SchemaService)
 */
class Schema
{
    private SchemaService $schemaService;
    private Config        $config;
    private Logger        $logger;

    public function __construct(SchemaService $schemaService, Config $config, Logger $logger)
    {
        $this->schemaService = $schemaService;
        $this->config        = $config;
        $this->logger        = $logger;
    }

    /**
     * Рендерит JSON-LD разметку для страницы
     */
    public function renderForPage(array $pageData): string
    {
        try {
            // Получаем схемы из сервиса (один SQL-запрос)
            $schemas = $this->schemaService->getSchemasForPage($pageData);

            // Добавляем автоматическую базовую схему
            $autoSchema = $this->buildAutoSchema($pageData);
            if ($autoSchema) {
                $schemas[] = $autoSchema;
            }

            if (empty($schemas)) {
                return '';
            }

            $payload = (count($schemas) === 1)
                ? $schemas[0]
                : ['@context' => 'https://schema.org', '@graph' => $schemas];

            return '<script type="application/ld+json">' . PHP_EOL
                . json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
                ) . PHP_EOL
                . '</script>';
        } catch (Throwable $e) {
            $this->logger->error('Ошибка рендера Schema.org: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Автоматическая базовая схема по типу страницы
     */
    private function buildAutoSchema(array $page): ?array
    {
        $type = $page['page_type'] ?? 'article';
        $url  = Url::build($page['slug'] ?? '', $this->config);

        $base = [
            '@context'   => 'https://schema.org',
            'url'        => $url,
            'inLanguage' => 'ru-BY',
        ];

        switch ($type) {
            case 'home':
                return array_merge($base, [
                    '@type' => 'WebPage',
                    'name'  => $page['title'] ?? 'Главная'
                ]);

            case 'article':
                return array_merge($base, [
                    '@type'         => 'MedicalWebPage',
                    'headline'      => $page['title'] ?? '',
                    'description'   => $page['description'] ?? '',
                    'datePublished' => $page['published_at'] ?? $page['created_at'] ?? date('c'),
                    'dateModified'  => $page['updated_at'] ?? date('c'),
                ]);

            case 'hub':
                return array_merge($base, [
                    '@type' => 'CollectionPage',
                    'name'  => $page['title'] ?? ''
                ]);

            case '404':
            case '500':
                return null;

            default:
                return array_merge($base, [
                    '@type' => 'WebPage',
                    'name'  => $page['title'] ?? ''
                ]);
        }
    }
}