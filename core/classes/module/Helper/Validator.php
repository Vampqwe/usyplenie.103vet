<?php
declare(strict_types=1);
/**
 * Validator — централизованная валидация данных
 *
 * Расположение: core/classes/system/Validator/Validator.php
 * Используется: админка, фронтенд-формы, API
 *
 * Пример:
 *  $validator = $di->get(Validator::class);
 *  $validator->setData($_POST)
 *            ->required('email')
 *            ->email('email')
 *            ->unique('email', $_POST['email'] ?? '', 'users');
 *
 *  if ($validator->hasErrors()) {
 *      $errors = $validator->getErrors();
 *  }
 */
class Validator
{
    private DbQuery $db;
    private array $errors = [];
    private array $data = [];

    public function __construct(DbQuery $db)
    {
        $this->db = $db;
    }

    /**
     * Устанавливает данные для валидации
     */
    public function setData(array $data): self
    {
        $this->data = $data;
        $this->errors = [];
        return $this;
    }

    /**
     * Сбрасывает ошибки и данные (для повторного использования экземпляра)
     * Используется сервисами, которые получают Validator из DI
     */
    public function reset(): void
    {
        $this->errors = [];
        $this->data = [];
    }

    /**
     * Проверка на обязательное поле
     */
    public function required(string $field, string $errorMessage = 'Поле обязательно для заполнения'): self
    {
        if (!isset($this->data[$field]) || $this->data[$field] === '' || $this->data[$field] === null) {
            $this->addError($field, $errorMessage);
        }
        return $this;
    }

    /**
     * Проверка минимальной длины
     */
    public function minLength(string $field, int $length, string $errorMessage = ''): self
    {
        $value = $this->data[$field] ?? '';
        if (mb_strlen((string)$value) < $length) {
            $this->addError($field, $errorMessage ?: "Минимальная длина: $length символов");
        }
        return $this;
    }

    /**
     * Проверка максимальной длины
     */
    public function maxLength(string $field, int $length, string $errorMessage = ''): self
    {
        $value = $this->data[$field] ?? '';
        if (mb_strlen((string)$value) > $length) {
            $this->addError($field, $errorMessage ?: "Максимальная длина: $length символов");
        }
        return $this;
    }

    /**
     * Проверка email
     */
    public function email(string $field, string $errorMessage = 'Некорректный email'): self
    {
        $value = $this->data[$field] ?? '';
        if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->addError($field, $errorMessage);
        }
        return $this;
    }

    /**
     * Проверка URL
     */
    public function url(string $field, string $errorMessage = 'Некорректный URL'): self
    {
        $value = $this->data[$field] ?? '';
        if ($value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
            $this->addError($field, $errorMessage);
        }
        return $this;
    }

    /**
     * Проверка числового значения
     */
    public function numeric(string $field, string $errorMessage = 'Должно быть числом'): self
    {
        $value = $this->data[$field] ?? '';
        if ($value !== '' && !is_numeric($value)) {
            $this->addError($field, $errorMessage);
        }
        return $this;
    }

    /**
     * Проверка по регулярному выражению
     */
    public function regex(string $field, string $pattern, string $errorMessage = 'Неверный формат'): self
    {
        $value = $this->data[$field] ?? '';
        if ($value !== '' && !preg_match($pattern, (string)$value)) {
            $this->addError($field, $errorMessage);
        }
        return $this;
    }

    /**
     * Проверка на уникальность значения в БД
     * ИСПРАВЛЕНО: безопасная обработка имён таблиц и колонок
     */
    public function unique(
        string $field,
        mixed $value,
        string $table,
        string $errorMessage = 'Такое значение уже существует',
        string $excludeColumn = '',
        mixed $excludeValue = null
    ): self {
        if ($value === '' || $value === null) {
            return $this;
        }

        $safeTable = $this->assertIdentifier($table);
        $safeField = $this->assertIdentifier($field);

        $query = "SELECT COUNT(*) as cnt FROM $safeTable WHERE $safeField = ?";
        $params = [$value];

        if ($excludeColumn !== '' && $excludeValue !== null) {
            $safeExcludeColumn = $this->assertIdentifier($excludeColumn);
            $query .= " AND $safeExcludeColumn != ?";
            $params[] = $excludeValue;
        }

        try {
            $result = $this->db->query($query, $params)->fetch(PDO::FETCH_ASSOC);
            $count = (int)($result['cnt'] ?? 0);

            if ($count > 0) {
                $this->addError($field, $errorMessage);
            }
        } catch (\Throwable $e) {
            throw $e;
        }

        return $this;
    }

    /**
     * Проверка совпадения двух полей (например, пароль и подтверждение)
     */
    public function same(string $field, string $otherField, string $errorMessage = 'Значения не совпадают'): self
    {
        $value1 = $this->data[$field] ?? '';
        $value2 = $this->data[$otherField] ?? '';
        if ($value1 !== $value2) {
            $this->addError($field, $errorMessage);
        }
        return $this;
    }

    /**
     * Проверка значения из списка допустимых
     */
    public function in(string $field, array $allowed, string $errorMessage = 'Недопустимое значение'): self
    {
        $value = $this->data[$field] ?? '';
        if ($value !== '' && !in_array($value, $allowed, true)) {
            $this->addError($field, $errorMessage);
        }
        return $this;
    }

    /**
     * Добавляет кастомную ошибку извне (для сервисов со сложной валидацией)
     * Например, SchemaService проверяет JSON-LD, которого нет в стандартных методах
     */
    public function addCustomError(string $field, string $message): void
    {
        $this->addError($field, $message);
    }

    /**
     * Возвращает все ошибки плоским списком: ['field: сообщение', ...]
     * Удобно для вывода пользователю
     */
    public function getAllErrorMessages(): array
    {
        $messages = [];
        foreach ($this->errors as $field => $fieldErrors) {
            foreach ($fieldErrors as $error) {
                $messages[] = "$field: $error";
            }
        }
        return $messages;
    }

    /**
     * Строгая валидация идентификатора (имя таблицы или колонки)
     * Допускаются только буквы, цифры и подчёркивание
     */
    private function assertIdentifier(string $name): string
    {
        if ($name === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new InvalidArgumentException(
                "Недопустимый SQL-идентификатор: '$name'. Разрешены только буквы, цифры и подчёркивание."
            );
        }
        return '`' . $name . '`';
    }

    /**
     * Добавление ошибки
     */
    private function addError(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }
        $this->errors[$field][] = $message;
    }

    /**
     * Есть ли ошибки?
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Получить все ошибки
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Получить ошибки для конкретного поля
     */
    public function getFieldErrors(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    /**
     * Получить первую ошибку (для любого поля)
     */
    public function getFirstError(): ?string
    {
        foreach ($this->errors as $fieldErrors) {
            if (!empty($fieldErrors)) {
                return $fieldErrors[0];
            }
        }
        return null;
    }
}