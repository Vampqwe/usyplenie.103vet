<?php
declare(strict_types = 1);

/**
 * Класс для работы с датой и временем
 * Config — обязательная зависимость (передаётся через DI)
 */
final class TimeDate {
    private Config $config;
    private string $dateFormat;
    private string $timeFormat;
    private ?string $cachedTimezone = null;

    /**
     * @param Config $config Конфигурация (обязательно, передаётся через DI)
     */
    public function __construct(Config $config) {
        $this->config = $config;

        $timezone = $this->config->getEnv('DEFAULT_TIMEZONE', 'UTC');
        if ($timezone === '') {
            $timezone = 'UTC';
        }
        $this->cachedTimezone = (string)$timezone;
        date_default_timezone_set($this->cachedTimezone);

        $this->dateFormat = (string)$this->config->getEnv('DATE_TPL', 'Y-m-d');
        $this->timeFormat = (string)$this->config->getEnv('TIME_TPL', 'H:i:s');
    }

    public function getDate(): string {
        return date($this->dateFormat);
    }

    public function getTime(): string {
        return date($this->timeFormat);
    }

    public function getTimeZone(): string {
        return $this->cachedTimezone ?? date_default_timezone_get();
    }

    public function getFormattedDate(?string $format = null): string {
        return date($format ?? $this->dateFormat);
    }

    public function getFormattedTime(?string $format = null): string {
        return date($format ?? $this->timeFormat);
    }

    public function getTimestamp(): int {
        return time();
    }

    public function getNow(): string {
        return date($this->dateFormat . ' ' . $this->timeFormat);
    }
}