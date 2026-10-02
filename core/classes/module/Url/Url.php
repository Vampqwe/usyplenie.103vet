<?php
declare(strict_types=1);
/**
 * Url — работа с URL-адресами сайта
 * 
 * Единая точка истины:
 *  - Домен берётся из Config (SITE_URL)
 *  - Путь нормализуется автоматически
 *  - Полный URL строится динамически: SITE_URL + путь
 * 
 * Примеры использования:
 *  $url = new Url('/sobaki/simptomy', $config);
 *  echo $url->absolute(); // https://usyplenie.103vet.by/sobaki/simptomy
 * 
 *  $url = new Url('/', $config);
 *  echo $url->absolute(); // https://usyplenie.103vet.by/
 */
final class Url
{
    private Config $config;
    private string $path;
    
    /** Максимальная длина пути (защита от атак) */
    private const MAX_LENGTH = 255;
    
    public function __construct(string $path, Config $config)
    {
        $this->config = $config;
        $this->path = self::normalize($path);
    }
    
    // =========================================================================
    // Нормализация и валидация
    // =========================================================================
    
    /**
     * Нормализует путь:
     *  - Убирает query string и fragment
     *  - Декодирует URL-encoded символы
     *  - Убирает двойные слэши
     *  - Убирает начальный и конечный слэш
     *  - Ограничивает длину
     */
    public static function normalize(string $path): string
    {
        // 1. Убираем query string и fragment
        $path = parse_url($path, PHP_URL_PATH) ?? $path;
        
        // 2. Декодируем URL-encoded символы
        $path = urldecode($path);
        
        // 3. Убираем двойные слэши
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        
        // 4. Убираем начальный и конечный слэш
        $path = trim($path, '/');
        
        // 5. Защита от слишком длинных путей
        if (strlen($path) > self::MAX_LENGTH) {
            $path = substr($path, 0, self::MAX_LENGTH);
        }
        
        return $path;
    }
    
    /**
     * Проверяет валидность пути
     * Допускает: латиницу, цифры, дефис, слэш, подчёркивание
     */
    public static function isValid(string $path): bool
    {
        $normalized = self::normalize($path);
        
        // Пустой путь — это корень, валиден
        if ($normalized === '') {
            return true;
        }
        
        // Проверяем допустимые символы
        return (bool)preg_match('/^[a-zA-Z0-9\-_\/]+$/', $normalized);
    }
    
    // =========================================================================
    // Построение URL
    // =========================================================================
    
    /**
     * Возвращает полный абсолютный URL
     * Формат: SITE_URL + путь
     * 
     * Пример:
     *  SITE_URL = 'https://usyplenie.103vet.by'
     *  path = 'sobaki/simptomy'
     *  → 'https://usyplenie.103vet.by/sobaki/simptomy'
     */
    public function absolute(): string
    {
        $baseUrl = rtrim($this->config->getEnv('SITE_URL', 'https://example.com'), '/');
        
        if ($this->path === '') {
            return $baseUrl . '/';
        }
        
        return $baseUrl . '/' . $this->path;
    }
    
    /**
     * Возвращает canonical URL (без query/fragment)
     * Для данного класса идентичен absolute()
     */
    public function canonical(): string
    {
        return $this->absolute();
    }
    
    /**
     * Возвращает только путь (без домена)
     * 
     * Пример:
     *  path = 'sobaki/simptomy'
     *  → '/sobaki/simptomy'
     */
    public function path(): string
    {
        if ($this->path === '') {
            return '/';
        }
        return '/' . $this->path;
    }
    
    // =========================================================================
    // Работа с сегментами пути
    // =========================================================================
    
    /**
     * Проверяет, является ли путь корневым ('/')
     */
    public function isRoot(): bool
    {
        return $this->path === '';
    }
    
    /**
     * Возвращает последний сегмент пути (slug)
     * 
     * Пример:
     *  path = 'sobaki/simptomy'
     *  → 'simptomy'
     */
    public function slug(): string
    {
        if ($this->path === '') {
            return '';
        }
        $parts = explode('/', $this->path);
        return end($parts);
    }
    
    /**
     * Возвращает родительский путь
     * 
     * Пример:
     *  path = 'sobaki/simptomy'
     *  → 'sobaki'
     */
    public function parent(): string
    {
        if ($this->path === '') {
            return '';
        }
        $parts = explode('/', $this->path);
        array_pop($parts);
        return implode('/', $parts);
    }
    
    /**
     * Возвращает все сегменты пути
     * 
     * Пример:
     *  path = 'sobaki/simptomy'
     *  → ['sobaki', 'simptomy']
     */
    public function segments(): array
    {
        if ($this->path === '') {
            return [];
        }
        return explode('/', $this->path);
    }
    
    // =========================================================================
    // Геттеры
    // =========================================================================
    
    /**
     * Возвращает нормализованный путь (без начального слэша)
     */
    public function getPath(): string
    {
        return $this->path;
    }
    
    /**
     * Возвращает базовый URL из конфига
     */
    public function getBaseUrl(): string
    {
        return rtrim($this->config->getEnv('SITE_URL', 'https://example.com'), '/');
    }
    
    public function __toString(): string
    {
        return $this->absolute();
    }
    
    // =========================================================================
    // Статические утилиты (без создания экземпляра)
    // =========================================================================
    
 /**
 * Строит полный URL из пути (короткая версия)
 */
public static function build(string $path, Config $config): string
{
    return (new self($path, $config))->absolute();
}

/**
 * Строит canonical URL из пути
 * ИСПРАВЛЕНО: переименован из canonical() в buildCanonical()
 */
public static function buildCanonical(string $path, Config $config): string
{
    return (new self($path, $config))->canonical();
}
}