<?php
declare(strict_types=1);

class Config {
    private static array $data = [];

    public static function load(string $file): void {
        if (!file_exists($file)) {
            throw new RuntimeException("Config file not found: $file");
        }
        self::$data = require $file;
    }
    public static function get(string $key, $default = null) {
        $parts = explode('.', $key);
        $v = self::$data;
        foreach ($parts as $p) {
            if (!is_array($v) || !array_key_exists($p, $v)) return $default;
            $v = $v[$p];
        }
        return $v;
    }
    public static function all(): array { return self::$data; }
}
