<?php

class FraudConfig
{
    private static $config;

    public static function get(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $path = __DIR__ . DIRECTORY_SEPARATOR . 'fraud_config.json';
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Fraud configuration file could not be read.');
        }

        $config = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($config)) {
            throw new RuntimeException('Fraud configuration must contain a JSON object.');
        }
        self::$config = $config;
        return self::$config;
    }

    public static function section(string $name): array
    {
        $config = self::get();
        if (!isset($config[$name]) || !is_array($config[$name])) {
            throw new RuntimeException("Fraud configuration section '{$name}' is missing.");
        }
        return $config[$name];
    }
}
