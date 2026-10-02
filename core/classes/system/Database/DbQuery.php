<?php
declare(strict_types = 1);
/**
* Класс DbQuery — удобная обёртка над DataBase для выполнения запросов
*
* Предоставляет простой API для CRUD-операций:
*  - select / selectOne — выборка данных
*  - insert / update / delete — изменение данных
*  - count — подсчёт записей
*  - query — сырые SQL-запросы
*  - Транзакции: begin / commit / rollback
*
* Все запросы логируются через Logger.
*/
class DbQuery {
    private DataBase $db;
    private Logger $logger;
    private PDO $pdo;
    /** @var bool Включить ли логирование запросов */
    private bool $logEnabled = true;

    public function __construct(DataBase $db, Logger $logger) {
        $this->db     = $db;
        $this->logger = $logger;
        $this->pdo    = $db->getPdo();
    }

    // =========================================================================
    //  SELECT — выборка данных
    // =========================================================================

    /**
    * Выполняет SELECT-запрос и возвращает массив результатов
    *
    * @param string $table имя таблицы
    * @param array $conditions условия WHERE (ключ => значение)
    * @param array $options дополнительные опции:
    *                       - 'columns' => ['id', 'name'] — список колонок (по умолчанию *)
    *                       - 'orderBy' => 'created_at DESC'
    *                       - 'limit'   => 10
    *                       - 'offset'  => 0
    * @return array массив ассоциативных массивов
    */
    public function select(string $table, array $conditions = [], array $options = []): array {
        $columns = $options['columns'] ?? ['*'];
        $columnStr = implode(', ', array_map([$this, 'quoteIdentifier'], $columns));
        $sql = "SELECT $columnStr FROM " . $this->quoteIdentifier($table);
        $params = [];
        [$whereSql, $params] = $this->buildWhere($conditions);
        if ($whereSql !== '') {
            $sql .= " $whereSql";
        }
        if (!empty($options['orderBy'])) {
            $sql .= " ORDER BY " . $this->sanitizeOrderBy($options['orderBy']);
        }
        if (isset($options['limit'])) {
            $sql .= " LIMIT " . (int)$options['limit'];
            if (isset($options['offset'])) {
                $sql .= " OFFSET " . (int)$options['offset'];
            }
        }
        return $this->execute($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
    * Возвращает одну запись или false
    */
    public function selectOne(string $table, array $conditions = [], array $options = []): array|false {
        $options['limit'] = 1;
        $result = $this->select($table, $conditions, $options);
        return $result[0] ?? false;
    }

    /**
    * Находит запись по первичному ключу
    */
    public function find(string $table, int|string $id, string $primaryKey = 'id'): array|false {
        return $this->selectOne($table, [$primaryKey => $id]);
    }

    /**
    * Возвращает все записи из таблицы
    */
    public function findAll(string $table, array $options = []): array {
        return $this->select($table, [], $options);
    }

    /**
    * Подсчитывает количество записей
    */
    public function count(string $table, array $conditions = [], string $column = '*'): int {
        $sql = "SELECT COUNT(" . $this->quoteIdentifier($column) . ") FROM " . $this->quoteIdentifier($table);
        $params = [];
        [$whereSql, $params] = $this->buildWhere($conditions);
        if ($whereSql !== '') {
            $sql .= " $whereSql";
        }
        return (int)$this->execute($sql, $params)->fetchColumn();
    }

    /**
    * Проверяет существование записи
    */
    public function exists(string $table, array $conditions): bool {
        return $this->count($table, $conditions) > 0;
    }

    /**
    * Возвращает максимальное значение колонки
    */
    public function max(string $table, string $column, array $conditions = []): mixed {
        $sql = "SELECT MAX(" . $this->quoteIdentifier($column) . ") FROM " . $this->quoteIdentifier($table);
        $params = [];
        [$whereSql, $params] = $this->buildWhere($conditions);
        if ($whereSql !== '') {
            $sql .= " $whereSql";
        }
        return $this->execute($sql, $params)->fetchColumn();
    }

    /**
    * Возвращает минимальное значение колонки
    */
    public function min(string $table, string $column, array $conditions = []): mixed {
        $sql = "SELECT MIN(" . $this->quoteIdentifier($column) . ") FROM " . $this->quoteIdentifier($table);
        $params = [];
        [$whereSql, $params] = $this->buildWhere($conditions);
        if ($whereSql !== '') {
            $sql .= " $whereSql";
        }
        return $this->execute($sql, $params)->fetchColumn();
    }

    /**
    * Возвращает сумму значений колонки
    */
    public function sum(string $table, string $column, array $conditions = []): float {
        $sql = "SELECT SUM(" . $this->quoteIdentifier($column) . ") FROM " . $this->quoteIdentifier($table);
        $params = [];
        [$whereSql, $params] = $this->buildWhere($conditions);
        if ($whereSql !== '') {
            $sql .= " $whereSql";
        }
        return (float)$this->execute($sql, $params)->fetchColumn();
    }

    // =========================================================================
    //  INSERT — вставка данных
    // =========================================================================

    /**
    * Вставляет запись в таблицу
    *
    * @param string $table имя таблицы
    * @param array $data данные для вставки (колонка => значение)
    * @return int ID вставленной записи (lastInsertId)
    */
    public function insert(string $table, array $data): int {
        if (empty($data)) {
            throw new RuntimeException("Нельзя вставить пустую запись в таблицу $table");
        }
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $this->quoteIdentifier($table),
            implode(', ', array_map([$this, 'quoteIdentifier'], $columns)),
            implode(', ', $placeholders)
        );
        $this->execute($sql, array_values($data));
        return (int)$this->pdo->lastInsertId();
    }

    /**
    * Массовая вставка нескольких записей
    *
    * @param string $table имя таблицы
    * @param array $rows массив строк (каждая — массив [колонка => значение])
    * @return int количество вставленных записей
    */
    public function insertMany(string $table, array $rows): int {
        if (empty($rows)) {
            return 0;
        }
        $columns = array_keys($rows[0]);
        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $allPlaceholders = implode(', ', array_fill(0, count($rows), $placeholders));
        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES %s",
            $this->quoteIdentifier($table),
            implode(', ', array_map([$this, 'quoteIdentifier'], $columns)),
            $allPlaceholders
        );
        $params = [];
        foreach ($rows as $row) {
            foreach ($columns as $col) {
                $params[] = $row[$col] ?? null;
            }
        }
        $this->execute($sql, $params);
        return count($rows);
    }

    // =========================================================================
    //  UPDATE — обновление данных
    // =========================================================================

    /**
    * Обновляет записи в таблице
    *
    * @param string $table имя таблицы
    * @param array $data данные для обновления (колонка => значение)
    * @param array $conditions условия WHERE
    * @return int количество обновлённых записей
    */
    public function update(string $table, array $data, array $conditions): int {
        if (empty($data)) {
            throw new RuntimeException("Нечего обновлять в таблице $table");
        }
        if (empty($conditions)) {
            throw new RuntimeException("Обновление без условий запрещено (защита от случайного UPDATE всей таблицы)");
        }
        $setClauses = [];
        $params = [];
        foreach ($data as $column => $value) {
            $setClauses[] = $this->quoteIdentifier($column) . ' = ?';
            $params[] = $value;
        }
        [$whereSql, $whereParams] = $this->buildWhere($conditions);
        $params = array_merge($params, $whereParams);
        $sql = sprintf(
            "UPDATE %s SET %s %s",
            $this->quoteIdentifier($table),
            implode(', ', $setClauses),
            $whereSql
        );
        return $this->execute($sql, $params)->rowCount();
    }

    /**
    * Обновляет запись по первичному ключу
    */
    public function updateById(string $table, int|string $id, array $data, string $primaryKey = 'id'): int {
        return $this->update($table, $data, [$primaryKey => $id]);
    }

    /**
    * Увеличивает значение колонки на указанное число
    */
    public function increment(string $table, string $column, int|float $amount = 1, array $conditions = []): int {
        if (empty($conditions)) {
            throw new RuntimeException("Increment без условий запрещён");
        }
        $sql = sprintf(
            "UPDATE %s SET %s = %s + ?",
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($column),
            $this->quoteIdentifier($column)
        );
        $params = [$amount];
        [$whereSql, $whereParams] = $this->buildWhere($conditions);
        $sql .= " $whereSql";
        $params = array_merge($params, $whereParams);
        return $this->execute($sql, $params)->rowCount();
    }

    /**
    * Уменьшает значение колонки
    */
    public function decrement(string $table, string $column, int|float $amount = 1, array $conditions = []): int {
        return $this->increment($table, $column, -$amount, $conditions);
    }

    // =========================================================================
    //  DELETE — удаление данных
    // =========================================================================

    /**
    * Удаляет записи из таблицы
    *
    * @param string $table имя таблицы
    * @param array $conditions условия WHERE
    * @return int количество удалённых записей
    */
    public function delete(string $table, array $conditions): int {
        if (empty($conditions)) {
            throw new RuntimeException("Удаление без условий запрещено (защита от случайного DELETE всей таблицы)");
        }
        [$whereSql, $params] = $this->buildWhere($conditions);
        $sql = "DELETE FROM " . $this->quoteIdentifier($table) . " $whereSql";
        return $this->execute($sql, $params)->rowCount();
    }

    /**
    * Удаляет запись по первичному ключу
    */
    public function deleteById(string $table, int|string $id, string $primaryKey = 'id'): int {
        return $this->delete($table, [$primaryKey => $id]);
    }

    // =========================================================================
    //  Транзакции
    // =========================================================================

    /**
    * Начинает транзакцию
    */
    public function beginTransaction(): bool {
        $this->log("BEGIN TRANSACTION");
        return $this->pdo->beginTransaction();
    }

    /**
    * Подтверждает транзакцию
    */
    public function commit(): bool {
        $this->log("COMMIT");
        return $this->pdo->commit();
    }

    /**
    * Откатывает транзакцию
    */
    public function rollback(): bool {
        $this->log("ROLLBACK");
        return $this->pdo->rollBack();
    }

    /**
    * Выполняет callback внутри транзакции
    * Автоматически откатывает при исключении
    *
    * @param callable $callback функция, принимающая DbQuery
    * @return mixed результат callback
    * @throws Throwable если внутри callback произошло исключение
    */
    public function transaction(callable $callback): mixed {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Throwable $e) {
            $this->rollback();
            $this->logger->error("Транзакция откатана: " . $e->getMessage());
            throw $e;
        }
    }

    /**
    * Проверяет, активна ли транзакция
    */
    public function inTransaction(): bool {
        return $this->pdo->inTransaction();
    }

    // =========================================================================
    //  Сырые запросы
    // =========================================================================

    /**
    * Выполняет сырой SQL-запрос
    *
    * @param string $sql SQL-запрос с плейсхолдерами ? или :name
    * @param array $params параметры запроса
    * @return PDOStatement
    */
    public function query(string $sql, array $params = []): PDOStatement {
        return $this->execute($sql, $params);
    }

    /**
    * Выполняет сырой запрос и возвращает все строки
    */
    public function queryAll(string $sql, array $params = []): array {
        return $this->execute($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
    * Выполняет сырой запрос и возвращает одну строку
    */
    public function queryOne(string $sql, array $params = []): array|false {
        $result = $this->execute($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $result ?: false;
}

    /**
    * Возвращает ID последней вставленной записи
    */
    public function lastInsertId(): string {
        return $this->pdo->lastInsertId();
    }

    // =========================================================================
    //  Утилиты
    // =========================================================================

    /**
    * Включает/выключает логирование запросов
    */
    public function setLogEnabled(bool $enabled): void {
        $this->logEnabled = $enabled;
    }

    /**
    * Возвращает PDO для прямого доступа (если нужно)
    */
    public function getPdo(): PDO {
        return $this->pdo;
    }

    // =========================================================================
    //  Внутренние методы
    // =========================================================================

    /**
    * Строит WHERE-выражение из массива условий
    *
    * Поддерживает:
    *  - Простые равенства: ['status' => 'active']
    *  - NULL: ['deleted_at' => null]
    *  - IN: ['id' => [1, 2, 3]]
    *  - Операторы: ['age' => ['>' => 18]]
    *  - BETWEEN: ['created_at' => ['between' => ['2024-01-01', '2024-12-31']]]
    *  - LIKE: ['name' => ['like' => '%test%']]
    *
    * @return array [WHERE SQL, параметры]
    */
    private function buildWhere(array $conditions): array {
        if (empty($conditions)) {
            return ['', []];
        }
        $clauses = [];
        $params = [];
        foreach ($conditions as $column => $value) {
            $quotedColumn = $this->quoteIdentifier($column);
            // Массив — это оператор
            if (is_array($value)) {
                $operator = array_key_first($value);
                $operand = $value[$operator];
                switch (strtolower((string)$operator)) {
                    case 'in':
                        $placeholders = implode(', ', array_fill(0, count($operand), '?'));
                        $clauses[] = "$quotedColumn IN ($placeholders)";
                        $params = array_merge($params, $operand);
                        break;
                    case 'not in':
                        $placeholders = implode(', ', array_fill(0, count($operand), '?'));
                        $clauses[] = "$quotedColumn NOT IN ($placeholders)";
                        $params = array_merge($params, $operand);
                        break;
                    case 'between':
                        $clauses[] = "$quotedColumn BETWEEN ? AND ?";
                        $params[] = $operand[0];
                        $params[] = $operand[1];
                        break;
                    case 'like':
                        $clauses[] = "$quotedColumn LIKE ?";
                        $params[] = $operand;
                        break;
                    case 'not like':
                        $clauses[] = "$quotedColumn NOT LIKE ?";
                        $params[] = $operand;
                        break;
                    case 'is':
                        $clauses[] = "$quotedColumn IS " . ($operand === null ? 'NULL' : strtoupper((string)$operand));
                        break;
                    case 'is not':
                        $clauses[] = "$quotedColumn IS NOT " . ($operand === null ? 'NULL' : strtoupper((string)$operand));
                        break;
                    case '>':
                    case '>=':
                    case '<':
                    case '<=':
                    case '!=':
                    case '<>':
                        $clauses[] = "$quotedColumn $operator ?";
                        $params[] = $operand;
                        break;
                    default:
                        throw new RuntimeException("Неизвестный оператор: $operator");
                }
            }
            // NULL
            elseif ($value === null) {
                $clauses[] = "$quotedColumn IS NULL";
            }
            // Простое равенство
            else {
                $clauses[] = "$quotedColumn = ?";
                $params[] = $value;
            }
        }
        return ['WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
    * Оборачивает идентификатор (таблицу/колонку) в обратные кавычки.
    * Защита от SQL-инъекций через имена.
    *
    * Поддерживает:
    *  - "*"             → "*"           (не экранируем)
    *  - "table.*"       → "`table`.*"
    *  - "table.column"  → "`table`.`column`"
    *  - "column"        → "`column`"
    */
    private function quoteIdentifier(string $identifier): string {
        $identifier = trim($identifier);
        // 1. Голая звёздочка — SELECT *
        if ($identifier === '*') {
            return '*';
        }
        // 2. table.* — оставляем * как есть, экранируем только имя таблицы
        if (preg_match('/^([a-zA-Z0-9_]+)\.\*$/', $identifier, $m)) {
            return '`' . $m[1] . '`.*';
        }
        // 3. table.column — экранируем обе части
        if (preg_match('/^([a-zA-Z0-9_]+)\.([a-zA-Z0-9_]+)$/', $identifier, $m)) {
            return '`' . $m[1] . '`.`' . $m[2] . '`';
        }
        // 4. Простое имя колонки/таблицы
        if (preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) {
            return '`' . $identifier . '`';
        }
        throw new RuntimeException("Недопустимый идентификатор: $identifier");
    }

    /**
    * Очищает ORDER BY (разрешаем только безопасные конструкции)
    * Поддерживает: column [ASC|DESC], table.column [ASC|DESC], ...
    */
    private function sanitizeOrderBy(string $orderBy): string {
        // Разрешаем: имя_колонки [ASC|DESC], имя_колонки [ASC|DESC], ...
        // Добавлена точка для поддержки table.column
        if (!preg_match('/^[a-zA-Z0-9_,\s`.]+$/i', $orderBy)) {
            throw new RuntimeException("Недопустимое ORDER BY: $orderBy");
        }
        return $orderBy;
    }

    /**
    * Выполняет подготовленный запрос с логированием
    */
    private function execute(string $sql, array $params = []): PDOStatement {
        try {
            if ($this->logEnabled) {
                $this->log("SQL: $sql | Params: " . json_encode($params, JSON_UNESCAPED_UNICODE));
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            $this->logger->error("Ошибка SQL: " . $e->getMessage() . " | Query: $sql");
            throw $e;
        }
    }

    /**
    * Логирует сообщение
    */
    private function log(string $message): void {
        if ($this->logEnabled) {
            $this->logger->debug($message);
        }
    }
}