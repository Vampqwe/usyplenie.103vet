<?php
declare(strict_types=1);

/**
 * Класс подключения к базе данных.
 * Использует композицию вместо наследования от PDO.
 *
 * ВАЖНО: Singleton больше не здесь — его обеспечивает DI-контейнер
 * (см. bootstrap.php: $di->singleton(DataBase::class, ...)).
 * Это даёт то же одно соединение, но с автоматической подстановкой зависимостей.
 */
class DataBase
{
    private PDO $pdo;
    private Logger $logger;

    private array $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_PERSISTENT         => false,
    ];

    /**
     * Публичный конструктор — DI-контейнер сам подставит Config и Logger.
     */
    public function __construct(Config $config, Logger $logger)
    {
        $this->logger = $logger;

        try {
            $dsn = sprintf(
                '%s:dbname=%s;host=%s;charset=%s',
                $config->getEnv('DB_DRIVER'),
                $config->getEnv('DB_NAME'),
                $config->getEnv('DB_HOST'),
                $config->getEnv('DB_CHARSET')
            );

            $user     = $config->getEnv('DB_LOGIN');
            $password = $config->getEnv('DB_PASSWORD');

            $this->pdo = new PDO($dsn, $user, $password, $this->options);
        } catch (ConfigException $e) {
            $this->logger->error("Ошибка конфигурации БД: " . $e->getMessage());
            throw $e;
        } catch (PDOException $e) {
            $this->logger->error("Ошибка подключения к БД: " . $e->getMessage());
            throw $e;
        }
    }

    /** Возвращает реальный экземпляр PDO для выполнения запросов */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /** Возвращает логгер (на случай, если нужно логировать на уровне БД) */
    public function getLogger(): Logger
    {
        return $this->logger;
    }
}