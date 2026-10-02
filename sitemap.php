<?php
declare(strict_types=1);

/**
 * Динамическая генерация sitemap.xml
 * Читает опубликованные страницы из БД и формирует валидный XML
 */

require_once __DIR__ . "/vendor/autoload.php";
require_once __DIR__ . "/core/bootstrap.php";

// 1. Получаем зависимости
$db     = $di->get(DbQuery::class);
$config = $di->get(Config::class);
$logger = $di->get(Logger::class);

// 2. Устанавливаем правильный заголовок для XML
header('Content-Type: application/xml; charset=utf-8');

try {
    // 3. Базовый URL из конфига
    $baseUrl = rtrim($config->getEnv('SITE_URL', 'https://example.com'), '/');

    // 4. Запрашиваем только опубликованные страницы
    $pages = $db->select('pages', [
        'status' => 'published'
    ], [
        'orderBy' => 'sort_order ASC, id DESC'
    ]);

    // 5. Начало XML-документа
    echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;

    // 6. Генерация узлов <url>
    foreach ($pages as $page) {
        // Формируем URL (обрабатываем случай, когда slug пустой для главной)
        $slug = trim($page['slug'] ?? '', '/');
        $loc  = $slug === '' ? $baseUrl . '/' : $baseUrl . '/' . $slug;

        // Формируем дату последнего изменения (W3C формат: YYYY-MM-DDThh:mm:ss+00:00)
        $dateStr = !empty($page['updated_at']) 
            ? $page['updated_at'] 
            : (!empty($page['published_at']) ? $page['published_at'] : date('Y-m-d H:i:s'));
        
        $lastmod = date('Y-m-d\TH:i:sP', strtotime($dateStr));

        // Определяем частоту обновления и приоритет на основе типа страницы
        switch ($page['page_type']) {
            case 'home':
                $changefreq = 'daily';
                $priority   = '1.0';
                break;
            case 'hub':
                $changefreq = 'weekly';
                $priority   = '0.8';
                break;
            case 'article':
                $changefreq = 'monthly';
                $priority   = '0.6';
                break;
            default:
                $changefreq = 'monthly';
                $priority   = '0.5';
        }

        // Выводим блок URL (используем ENT_XML1 для безопасного экранирования спецсимволов в XML)
        echo '  <url>' . PHP_EOL;
        echo '    <loc>' . htmlspecialchars($loc, ENT_XML1, 'UTF-8') . '</loc>' . PHP_EOL;
        echo '    <lastmod>' . $lastmod . '</lastmod>' . PHP_EOL;
        echo '    <changefreq>' . $changefreq . '</changefreq>' . PHP_EOL;
        echo '    <priority>' . $priority . '</priority>' . PHP_EOL;
        echo '  </url>' . PHP_EOL;
    }

    // 7. Закрытие документа
    echo '</urlset>' . PHP_EOL;

} catch (Throwable $e) {
    // В случае ошибки логируем её и отдаём минимальный валидный XML или 500 ошибку
    $logger->error('Ошибка генерации sitemap.xml: ' . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
    echo '<error>Ошибка генерации карты сайта</error>' . PHP_EOL;
}