<?php
// Pure classes only; anything touching WordPress belongs in tests/Integration.
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'MyAduan\\', 8) === 0) {
        $path = dirname(__DIR__) . '/includes/' . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});
