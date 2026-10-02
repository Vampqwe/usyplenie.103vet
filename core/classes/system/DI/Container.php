<?php
declare(strict_types = 1);

//use ReflectionClass;
//use ReflectionFunction;

/**
 * Легковесный DI-контейнер для VampqweEngine
 * Поддерживает singleton, factory и автоматический резолвинг через рефлексию.
 */
class Container {
    private static ?self $globalInstance = null;
    
    /** @var array<string, mixed> Кэш созданных singleton-объектов */
    private array $singletons = [];
    
    /** @var array<string, callable> Фабрики для создания новых экземпляров */
    private array $factories = [];

    /**
     * Устанавливает контейнер как глобальный
     */
    public static function setGlobal(self $container): void {
        self::$globalInstance = $container;
    }

    /**
     * Получает глобальный экземпляр контейнера
     */
    public static function getGlobal(): self {
        if (self::$globalInstance === null) {
            throw new RuntimeException("Глобальный DI-контейнер не инициализирован");
        }
        return self::$globalInstance;
    }

    /**
     * Регистрирует класс как Singleton (один экземпляр на запрос)
     */
    public function singleton(string $id, callable $resolver): void {
        $this->singletons[$id] = $resolver;
    }

    /**
     * Регистрирует класс как Factory (новый экземпляр при каждом вызове get)
     */
    public function factory(string $id, callable $resolver): void {
        $this->factories[$id] = $resolver;
    }

    /**
     * Получает экземпляр класса
     */
    public function get(string $id): mixed {
        // 1. Если singleton уже создан, возвращаем его из кэша
        if (array_key_exists($id, $this->singletons) && !is_callable($this->singletons[$id])) {
            return $this->singletons[$id];
        }

        // 2. Если singleton зарегистрирован как callable, создаём и кэшируем
        if (isset($this->singletons[$id]) && is_callable($this->singletons[$id])) {
            $this->singletons[$id] = $this->resolveCallable($this->singletons[$id]);
            return $this->singletons[$id];
        }

        // 3. Если зарегистрирована factory, создаём новый экземпляр (без кэширования)
        if (isset($this->factories[$id])) {
            return $this->resolveCallable($this->factories[$id]);
        }

        // 4. Авто-резолвинг через рефлексию (если класс не зарегистрирован явно)
        return $this->resolveClass($id);
    }

    /**
     * Выполняет callable, передавая в него Container, если он запрошен в параметрах
     */
    private function resolveCallable(callable $resolver): mixed {
        $reflection = new ReflectionFunction($resolver);
        $args = [];
        foreach ($reflection->getParameters() as $param) {
            $type = $param->getType();
            if ($type && !$type->isBuiltin() && $type->getName() === self::class) {
                $args[] = $this;
            }
        }
        return $resolver(...$args);
    }

    /**
     * Автоматически создаёт класс через рефлексию конструктора
     */
    private function resolveClass(string $className): mixed {
        if (!class_exists($className)) {
            throw new RuntimeException("Класс не найден: $className");
        }

        $reflection = new ReflectionClass($className);
        $constructor = $reflection->getConstructor();

        // Если конструктора нет, просто создаём объект
        if ($constructor === null) {
            return new $className();
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            
            // Если параметр типизирован классом, рекурсивно резолвим его
            if ($type && !$type->isBuiltin()) {
                $args[] = $this->get($type->getName());
            } 
            // Если есть значение по умолчанию, используем его
            elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } 
            // Если параметр nullable, передаём null
            elseif ($type && $type->allowsNull()) {
                $args[] = null;
            } 
            else {
                throw new RuntimeException("Не удалось разрешить зависимость для параметра: \${$param->getName()} в классе $className");
            }
        }

        return $reflection->newInstanceArgs($args);
    }
}