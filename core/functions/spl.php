<?php
declare(strict_types=1);
spl_autoload_register(function (string $className): void {
        static $classMap = null;
        if ($classMap === null) {
            $classMap = [];
            $baseDir = __DIR__ . '/classes/';
            if (!is_dir($baseDir)) {
                return;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $classFromFile = $file->getBasename('.php');
                    $classMap[$classFromFile] = $file->getPathname();
                }
            }
        }
        if (isset($classMap[$className])) {
            require_once $classMap[$className];
        }
    });