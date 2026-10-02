<?php
declare(strict_types=1);
/**
 * SchemaService — сервис для работы с данными Schema.org
 *
 * Отвечает за:
 *  - Получение схем из БД (один оптимизированный запрос)
 *  - CRUD-операции (для админки)
 *  - Валидацию JSON-LD
 *  - Форматирование данных
 *
 * НЕ отвечает за:
 *  - Рендеринг HTML (это делает Schema)
 */
class SchemaService
{
    private DbQuery $dbQuery;
    private Logger $logger;
    private Validator $validator;

    public function __construct(DbQuery $dbQuery, Logger $logger, Validator $validator)
    {
        $this->dbQuery   = $dbQuery;
        $this->logger    = $logger;
        $this->validator = $validator;
    }

    // =========================================================================
    // Получение схем (для фронтенда)
    // =========================================================================

    /**
     * Получает все схемы для страницы (один SQL-запрос вместо трёх)
     *
     * Объединяет:
     *  - Глобальные (route = '*')
     *  - Привязанные к page_id
     *  - Привязанные к route/slug
     *
     * @param array $pageData данные страницы из PageService
     * @return array отформатированные схемы
     */
    public function getSchemasForPage(array $pageData): array
    {
        try {
            $rows = $this->fetchSchemasForPage($pageData);
            return $this->formatSchemas($rows);
        } catch (Throwable $e) {
            $this->logger->error('Ошибка получения схем: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Получает все активные схемы (для админки)
     */
    public function getAllSchemas(): array
    {
        try {
            return $this->dbQuery->select(
                'schema_org',
                [],
                ['orderBy' => 'priority DESC, id DESC']
            );
        } catch (Throwable $e) {
            $this->logger->error('Ошибка получения списка схем: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Получает схему по ID
     */
    public function getSchemaById(int $id): ?array
    {
        try {
            $row = $this->dbQuery->find('schema_org', $id);
            return $row ?: null;
        } catch (Throwable $e) {
            $this->logger->error("Ошибка получения схемы ID=$id: " . $e->getMessage());
            return null;
        }
    }

    // =========================================================================
    // CRUD (для админки)
    // =========================================================================

    /**
     * Создаёт новую схему
     *
     * @return int ID созданной записи
     * @throws RuntimeException если валидация не прошла
     */
    public function createSchema(array $data): int
    {
        $this->validateSchemaData($data);
        $this->checkUniqueness($data);

        try {
            return $this->dbQuery->insert('schema_org', [
                'route'       => $data['route'] ?? null,
                'page_id'     => $data['page_id'] ?? null,
                'schema_type' => $data['schema_type'],
                'data'        => is_string($data['data']) ? $data['data'] : json_encode($data['data']),
                'priority'    => $data['priority'] ?? 0,
                'is_active'   => $data['is_active'] ?? 1,
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Ошибка создания схемы: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Обновляет схему
     *
     * @return int количество обновлённых записей
     */
    public function updateSchema(int $id, array $data): int
    {
        $this->validateSchemaData($data, $id);
        $this->checkUniqueness($data, $id);

        try {
            $updateData = [];
            foreach (['route', 'page_id', 'schema_type', 'data', 'priority', 'is_active'] as $field) {
                if (array_key_exists($field, $data)) {
                    $updateData[$field] = $field === 'data' && is_array($data[$field])
                        ? json_encode($data[$field])
                        : $data[$field];
                }
            }
            return $this->dbQuery->updateById('schema_org', $id, $updateData);
        } catch (Throwable $e) {
            $this->logger->error("Ошибка обновления схемы ID=$id: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Удаляет схему
     */
    public function deleteSchema(int $id): int
    {
        try {
            return $this->dbQuery->deleteById('schema_org', $id);
        } catch (Throwable $e) {
            $this->logger->error("Ошибка удаления схемы ID=$id: " . $e->getMessage());
            throw $e;
        }
    }

    // =========================================================================
    // Валидация
    // =========================================================================

    /**
     * Проверяет, является ли строка валидным JSON-LD
     */
    public function validateJsonLd(string $json): bool
    {
        if ($json === '') {
            return false;
        }
        $decoded = json_decode($json, true);
        return json_last_error() === JSON_ERROR_NONE && is_array($decoded);
    }

    /**
     * Валидирует данные схемы перед сохранением
     *
     * ИСПРАВЛЕНО:
     *  - Добавлен setData() — без него валидация не работает
     *  - required() вызывается БЕЗ второго аргумента (он — текст ошибки, а не значение)
     *  - addError() заменён на addCustomError() (публичный метод)
     *  - getAllErrorMessages() теперь существует в Validator
     *
     * @throws RuntimeException если валидация не прошла
     */
    private function validateSchemaData(array $data, ?int $excludeId = null): void
    {
        // Сбрасываем предыдущие ошибки
        $this->validator->reset();
        
        // Устанавливаем данные — это КРИТИЧНО!
        // Без этого required() будет проверять пустой $this->data
        $this->validator->setData($data);

        // schema_type обязателен (второй аргумент — НЕ передаём, используем дефолт)
        $this->validator->required('schema_type');

        // data должен быть валидным JSON
        $jsonData = $data['data'] ?? '';
        if (is_array($jsonData)) {
            $jsonData = json_encode($jsonData);
        }
        if ($jsonData !== '' && !$this->validateJsonLd($jsonData)) {
            $this->validator->addCustomError('data', 'Невалидный JSON-LD');
        }

        // priority — число
        if (isset($data['priority']) && !is_numeric($data['priority'])) {
            $this->validator->addCustomError('priority', 'Должно быть числом');
        }

        if ($this->validator->hasErrors()) {
            throw new RuntimeException(
                'Ошибка валидации схемы: ' . implode('; ', $this->validator->getAllErrorMessages())
            );
        }
    }

    /**
     * Проверяет уникальность route/page_id
     */
    private function checkUniqueness(array $data, ?int $excludeId = null): void
    {
        if (!empty($data['route']) && $data['route'] !== '*') {
            $count = $this->dbQuery->count('schema_org', [
                'route' => $data['route'],
                'id'    => ['!=' => $excludeId ?? 0],
            ]);
            if ($count > 0) {
                throw new RuntimeException("Схема с route='{$data['route']}' уже существует");
            }
        }
    }

    // =========================================================================
    // Внутренние методы
    // =========================================================================

    /**
     * Единый SQL-запрос: глобальные + по page_id + по route
     */
    private function fetchSchemasForPage(array $pageData): array
    {
        $pageId = $pageData['id'] ?? null;
        $slug   = trim($pageData['slug'] ?? '', '/');

        $orClauses = [];
        $params    = [];

        $orClauses[] = "`route` = ?";
        $params[]    = '*';

        if ($pageId !== null) {
            $orClauses[] = "`page_id` = ?";
            $params[]    = $pageId;
        }

        if ($slug !== '') {
            $orClauses[] = "`route` = ?";
            $params[]    = $slug;
        }

        $sql = "SELECT * FROM `schema_org` 
                WHERE `is_active` = 1 
                  AND (" . implode(' OR ', $orClauses) . ")
                ORDER BY `priority` DESC";

        return $this->dbQuery->query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Преобразует строки БД в массив schema.org структур
     */
    private function formatSchemas(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $data = is_string($row['data']) ? json_decode($row['data'], true) : $row['data'];
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->warning(
                    "Невалидный JSON в схеме ID {$row['id']}: " . json_last_error_msg()
                );
                continue;
            }
            $result[] = array_merge(
                ['@context' => 'https://schema.org', '@type' => $row['schema_type']],
                $data ?: []
            );
        }
        return $result;
    }
}