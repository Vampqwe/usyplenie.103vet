<?php
declare(strict_types=1);
/**
 * PageService — сервис для работы со страницами
 *
 * Оптимизирован для:
 *  - Минимизации запросов к БД (один запрос на страницу)
 *  - Кэширования в памяти (повторные обращения — без БД)
 *  - Нормализации URI через класс Url (единая точка истины)
 *  - Чёткой логики поиска (home → slug → 404 → fallback)
 */
class PageService
{
    // =========================================================================
    // Константы (нет magic strings)
    // =========================================================================
    private const TYPE_HOME    = 'home';
    private const TYPE_HUB     = 'hub';
    private const TYPE_ARTICLE = 'article';
    private const TYPE_404     = '404';
    private const TYPE_500     = '500';
    private const STATUS_PUBLISHED = 'published';

    // =========================================================================
    // Зависимости
    // =========================================================================
    private DbQuery $db;
    private Logger  $logger;
    private Config  $config;

    // =========================================================================
    // Кэш в памяти
    // =========================================================================
    private array $cacheBySlug = [];
    private array $cacheById = [];
    private ?array $homePageCache = null;
    private ?array $page404Cache = null;
    private ?array $page500Cache = null;

    public function __construct(DbQuery $db, Logger $logger, Config $config)
    {
        $this->db     = $db;
        $this->logger = $logger;
        $this->config = $config;
    }

    // =========================================================================
    // ПУБЛИЧНЫЙ API
    // =========================================================================

    /**
     * Находит страницу по URI запроса
     * Нормализация через Url::normalize() — единая точка истины
     */
    public function getPageByUri(string $uri): array
    {
        $normalizedUri = Url::normalize($uri);

        if ($normalizedUri === '') {
            return $this->getHomePage();
        }

        $page = $this->getPageBySlug($normalizedUri);
        if ($page !== null) {
            return $page;
        }

        $this->logger->debug("Страница не найдена: '$normalizedUri', ищем 404");
        return $this->get404Page();
    }

    public function getPageBySlug(string $slug): ?array
    {
        $slug = Url::normalize($slug);

        if (array_key_exists($slug, $this->cacheBySlug)) {
            return $this->cacheBySlug[$slug];
        }

        $page = $this->findPublishedPage($slug);
        $this->cacheBySlug[$slug] = $page;
        return $page;
    }

    public function getPageById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        if (array_key_exists($id, $this->cacheById)) {
            return $this->cacheById[$id];
        }

        try {
            $page = $this->db->find('pages', $id);
            $result = $page ?: null;
            $this->cacheById[$id] = $result;
            return $result;
        } catch (Throwable $e) {
            $this->logger->error("Ошибка поиска страницы id=$id: " . $e->getMessage());
            return null;
        }
    }

    public function getHomePage(): array
    {
        if ($this->homePageCache !== null) {
            return $this->homePageCache;
        }

        $page = $this->findHomePage();
        $this->homePageCache = $page ?? $this->getDefault404Page();
        return $this->homePageCache;
    }

    public function get404Page(): array
    {
        if ($this->page404Cache !== null) {
            return $this->page404Cache;
        }

        $page = $this->find404Page();
        $this->page404Cache = $page ?? $this->getDefault404Page();
        return $this->page404Cache;
    }

    /**
     * Возвращает страницу 500 (с кэшированием)
     */
    public function get500Page(): array
    {
        if ($this->page500Cache !== null) {
            return $this->page500Cache;
        }

        $page = $this->find500Page();
        $this->page500Cache = $page ?? $this->getDefault500Page();
        return $this->page500Cache;
    }

    public function getParentPage(?int $parentId): ?array
    {
        if ($parentId === null) {
            return null;
        }
        return $this->getPageById($parentId);
    }

    public function getBreadcrumbs(int $pageId): array
    {
        $breadcrumbs = [];
        $currentId   = $pageId;
        $visited     = [];
        $maxDepth    = 10;

        while ($currentId !== null && !isset($visited[$currentId]) && $maxDepth-- > 0) {
            $visited[$currentId] = true;
            $page = $this->getPageById($currentId);
            if (!$page) {
                break;
            }
            array_unshift($breadcrumbs, $page);
            $currentId = $page['parent_id'] ?? null;
        }

        return $breadcrumbs;
    }

    public function clearCache(): void
    {
        $this->cacheBySlug   = [];
        $this->cacheById     = [];
        $this->homePageCache = null;
        $this->page404Cache  = null;
        $this->page500Cache  = null;
    }

    // =========================================================================
    // ПРИВАТНЫЕ МЕТОДЫ
    // =========================================================================

    private function findPublishedPage(string $slug): ?array
    {
        try {
            $page = $this->db->selectOne('pages', [
                'slug'   => $slug,
                'status' => self::STATUS_PUBLISHED
            ]);
            return $page ?: null;
        } catch (Throwable $e) {
            $this->logger->error("Ошибка поиска страницы по slug '$slug': " . $e->getMessage());
            return null;
        }
    }

    private function findHomePage(): ?array
    {
        try {
            $page = $this->db->selectOne('pages', [
                'page_type' => self::TYPE_HOME,
                'status'    => self::STATUS_PUBLISHED
            ]);
            return $page ?: null;
        } catch (Throwable $e) {
            $this->logger->error("Ошибка поиска главной страницы: " . $e->getMessage());
            return null;
        }
    }

    private function find404Page(): ?array
    {
        try {
            $page = $this->db->selectOne('pages', [
                'slug'   => '404',
                'status' => self::STATUS_PUBLISHED
            ]);
            if ($page) {
                return $page;
            }

            $page = $this->db->selectOne('pages', [
                'page_type' => self::TYPE_404,
                'status'    => self::STATUS_PUBLISHED
            ]);
            return $page ?: null;
        } catch (Throwable $e) {
            $this->logger->error("Ошибка поиска страницы 404: " . $e->getMessage());
            return null;
        }
    }

    private function find500Page(): ?array
    {
        try {
            $page = $this->db->selectOne('pages', [
                'slug'   => '500',
                'status' => self::STATUS_PUBLISHED
            ]);
            if ($page) {
                return $page;
            }

            $page = $this->db->selectOne('pages', [
                'page_type' => self::TYPE_500,
                'status'    => self::STATUS_PUBLISHED
            ]);
            return $page ?: null;
        } catch (Throwable $e) {
            $this->logger->error("Ошибка поиска страницы 500: " . $e->getMessage());
            return null;
        }
    }

    private function getDefault404Page(): array
    {
        return [
            'id'             => 0,
            'title'          => 'Страница не найдена',
            'description'    => 'Запрошенная страница не существует',
            'keywords'       => '',
            'canonical'      => '',
            'og_title'       => '404 — Не найдено',
            'og_description' => 'Запрошенная страница не существует',
            'slug'           => '404',
            'content'        => '<h1>404 — Страница не найдена</h1>'
                . '<p>Извините, но запрашиваемая вами страница не существует или была удалена.</p>',
            'page_type'      => self::TYPE_404,
            'parent_id'      => null,
            'status'         => self::STATUS_PUBLISHED,
            'sort_order'     => 0,
            'is_in_menu'     => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
            'published_at'   => null,
        ];
    }

    private function getDefault500Page(): array
    {
        return [
            'id'             => 0,
            'title'          => 'Ошибка сервера',
            'description'    => 'Внутренняя ошибка сервера',
            'keywords'       => '',
            'canonical'      => '',
            'og_title'       => '500 — Ошибка сервера',
            'og_description' => 'Внутренняя ошибка сервера',
            'slug'           => '500',
            'content'        => '<h1>500 — Внутренняя ошибка сервера</h1>'
                . '<p>Извините, на сервере произошла ошибка. Пожалуйста, попробуйте позже.</p>',
            'page_type'      => self::TYPE_500,
            'parent_id'      => null,
            'status'         => self::STATUS_PUBLISHED,
            'sort_order'     => 0,
            'is_in_menu'     => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
            'published_at'   => null,
        ];
    }
}