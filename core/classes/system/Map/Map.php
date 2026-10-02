<?php
declare(strict_types = 1);

/**
 * Класс Map - обёртка над ArrayObject для работы с ассоциативными массивами
 * Предоставляет типобезопасный API для операций с данными
 */
class Map {
    
    private ArrayObject $arrayObject;

    public function __construct(array $data = []) {
        $this->arrayObject = new ArrayObject($data);
    }
    
    /**
     * Возвращает внутренний ArrayObject
     */
    public function getArrayObject(): ArrayObject {
        return $this->arrayObject;
    }

    /**
     * Заменяет содержимое массива новыми данными
     */
    public function addNewArr(array $newArray): void {
        $this->arrayObject->exchangeArray($newArray);
    }
    
    /**
     * Добавляет или обновляет значение по ключу
     * @param string|int $key ключ
     * @param mixed $val значение
     */
    public function put(string|int $key, mixed $val): void {
        $this->arrayObject->offsetSet($key, $val);
    }
    
    /**
     * Проверяет наличие ключа
     */
    public function checkKeyExists(string|int $key): bool {
        return $this->arrayObject->offsetExists($key);
    }
    
    /**
     * Получает значение по ключу
     * @param string|int $key ключ
     * @return mixed значение
     * @throws InvalidArgumentException если ключ не существует
     */
    public function getValueByKey(string|int $key): mixed {
        if (!$this->arrayObject->offsetExists($key)) {
            throw new InvalidArgumentException("Ключ '$key' не существует в Map");
        }
        return $this->arrayObject->offsetGet($key);
    }
    
    /**
     * Возвращает количество элементов
     */
    public function getCountAvailableValue(): int {
        return $this->arrayObject->count();
    }
    
    /**
     * Удаляет элемент по ключу
     */
    public function deleteValueByKey(string|int $key): void {
        $this->arrayObject->offsetUnset($key);
    }
    
    /**
     * Возвращает массив всех ключей
     */
    public function getKeys(): array {
        return array_keys($this->arrayObject->getArrayCopy());
    }
    
    /**
     * Преобразует в обычный массив
     */
    public function toArray(): array {
        return $this->arrayObject->getArrayCopy();
    }
    
    /**
     * Проверяет пустоту коллекции
     */
    public function isEmpty(): bool {
        return $this->arrayObject->count() === 0;
    }
    
    /**
     * Очищает коллекцию
     */
    public function clear(): void {
        $this->arrayObject->exchangeArray([]);
    }
    
    /**
     * Объединяет с другой Map
     */
    public function merge(Map $other): void {
        foreach ($other->toArray() as $key => $value) {
            $this->put($key, $value);
        }
    }
}
