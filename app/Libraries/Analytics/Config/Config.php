<?php

namespace App\Libraries\Analytics\Config;

/**
 * Centralized Configuration Manager
 * 
 * Provides unified access to environment configuration with
 * dot-notation support and validation.
 */
class Config
{
    private static ?Config $instance = null;
    private array $config = [];
    private array $defaults = [];

    private function __construct()
    {
        $this->loadDefaults();
        $this->loadFromEnv();
    }

    public static function getInstance(): Config
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get configuration value using dot notation
     */
    public function get(string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Check if configuration key exists
     */
    public function has(string $key): bool
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return false;
            }
            $value = $value[$k];
        }

        return true;
    }

    /**
     * Set configuration value
     */
    public function set(string $key, $value): void
    {
        $keys = explode('.', $key);
        $config = &$this->config;

        foreach ($keys as $k) {
            if (!isset($config[$k]) || !is_array($config[$k])) {
                $config[$k] = [];
            }
            $config = &$config[$k];
        }

        $config = $value;
    }

    /**
     * Get all configuration
     */
    public function all(): array
    {
        return $this->config;
    }

    private function loadDefaults(): void
    {
        $this->defaults = [
            'app' => [
                'baseURL' => 'http://localhost:8080/',
                'timezone' => 'UTC',
            ],
            'database' => [
                'host' => 'localhost',
                'port' => 5432,
                'name' => 'analytics',
                'user' => 'analytics',
                'password' => 'analytics_password',
            ],
            'redis' => [
                'host' => 'localhost',
                'port' => 6379,
                'password' => '',
                'database' => 0,
            ],
            'rabbitmq' => [
                'host' => 'localhost',
                'port' => 5672,
                'user' => 'analytics',
                'password' => 'analytics_password',
                'vhost' => '/analytics',
                'exchange' => 'analytics.events',
                'queue' => [
                    'raw' => 'analytics.events.raw',
                    'dlq' => 'analytics.events.dlq',
                    'dlx' => 'analytics.events.dlx',
                ],
                'routingKey' => 'events.raw',
            ],
            'api' => [
                'key' => 'your-secret-api-key',
                'rateLimit' => [
                    'requests' => 1000,
                    'window' => 60,
                ],
            ],
            'event' => [
                'maxBatchSize' => 500,
                'maxPayloadSize' => 1048576, // 1MB
                'allowedTypes' => [
                    'page_view',
                    'button_click',
                    'form_submit',
                    'user_signup',
                    'logout',
                    'session_start',
                    'session_end',
                ],
            ],
            'redisKeys' => [
                'prefix' => [
                    'dedupe' => 'dedupe:',
                    'rateLimit' => 'rl:',
                    'topPages' => 'z:top_pages:',
                    'topUsers' => 'z:top_users:',
                    'cache' => 'cache:',
                ],
            ],
            'ttl' => [
                'dedupe' => 86400,      // 24 hours
                'rateLimit' => 60,       // 1 minute
                'cache' => 120,          // 2 minutes
                'hotMetrics' => 604800,  // 7 days
            ],
            'worker' => [
                'prefetchCount' => 100,
                'maxRetries' => 3,
            ],
            'logging' => [
                'level' => 'info',
            ],
        ];

        $this->config = $this->defaults;
    }

    private function loadFromEnv(): void
    {
        // Load from CodeIgniter's environment
        if (function_exists('env')) {
            // Database
            $this->config['database']['host'] = env('database.default.DNSv4') 
                ? $this->parseHostFromDns(env('database.default.DNSv4'))
                : $this->config['database']['host'];
            $this->config['database']['name'] = env('database.default.database', $this->config['database']['name']);
            $this->config['database']['user'] = env('database.default.username', $this->config['database']['user']);
            $this->config['database']['password'] = env('database.default.password', $this->config['database']['password']);
            $this->config['database']['port'] = (int) env('database.default.port', $this->config['database']['port']);

            // Redis
            $this->config['redis']['host'] = env('redis.host', $this->config['redis']['host']);
            $this->config['redis']['port'] = (int) env('redis.port', $this->config['redis']['port']);
            $this->config['redis']['password'] = env('redis.password', $this->config['redis']['password']);
            $this->config['redis']['database'] = (int) env('redis.database', $this->config['redis']['database']);

            // RabbitMQ
            $this->config['rabbitmq']['host'] = env('rabbitmq.host', $this->config['rabbitmq']['host']);
            $this->config['rabbitmq']['port'] = (int) env('rabbitmq.port', $this->config['rabbitmq']['port']);
            $this->config['rabbitmq']['user'] = env('rabbitmq.user', $this->config['rabbitmq']['user']);
            $this->config['rabbitmq']['password'] = env('rabbitmq.password', $this->config['rabbitmq']['password']);
            $this->config['rabbitmq']['vhost'] = env('rabbitmq.vhost', $this->config['rabbitmq']['vhost']);
            $this->config['rabbitmq']['exchange'] = env('rabbitmq.exchange', $this->config['rabbitmq']['exchange']);
            $this->config['rabbitmq']['queue']['raw'] = env('rabbitmq.queue.raw', $this->config['rabbitmq']['queue']['raw']);
            $this->config['rabbitmq']['queue']['dlq'] = env('rabbitmq.queue.dlq', $this->config['rabbitmq']['queue']['dlq']);
            $this->config['rabbitmq']['queue']['dlx'] = env('rabbitmq.dlx', $this->config['rabbitmq']['queue']['dlx']);
            $this->config['rabbitmq']['routingKey'] = env('rabbitmq.routing.key', $this->config['rabbitmq']['routingKey']);

            // API
            $this->config['api']['key'] = env('api.key', $this->config['api']['key']);
            $this->config['api']['rateLimit']['requests'] = (int) env('api.rateLimit.requests', $this->config['api']['rateLimit']['requests']);
            $this->config['api']['rateLimit']['window'] = (int) env('api.rateLimit.window', $this->config['api']['rateLimit']['window']);

            // Event
            $this->config['event']['maxBatchSize'] = (int) env('event.maxBatchSize', $this->config['event']['maxBatchSize']);
            $this->config['event']['maxPayloadSize'] = (int) env('event.maxPayloadSize', $this->config['event']['maxPayloadSize']);

            $allowedTypes = env('event.allowedTypes', '');
            if ($allowedTypes) {
                $this->config['event']['allowedTypes'] = array_map('trim', explode(',', $allowedTypes));
            }

            // TTL
            $this->config['ttl']['dedupe'] = (int) env('dedupe.ttl', $this->config['ttl']['dedupe']);
            $this->config['ttl']['rateLimit'] = (int) env('rateLimit.ttl', $this->config['ttl']['rateLimit']);
            $this->config['ttl']['cache'] = (int) env('cache.ttl', $this->config['ttl']['cache']);
            $this->config['ttl']['hotMetrics'] = (int) env('hotMetrics.ttl', $this->config['ttl']['hotMetrics']);

            // Worker
            $this->config['worker']['prefetchCount'] = (int) env('worker.prefetchCount', $this->config['worker']['prefetchCount']);
            $this->config['worker']['maxRetries'] = (int) env('worker.maxRetries', $this->config['worker']['maxRetries']);
        }
    }

    private function parseHostFromDns(?string $dns): string
    {
        if (!$dns) {
            return 'localhost';
        }

        // Parse "pgsql:host=hostname;dbname=database"
        preg_match('/host=([^;]+)/', $dns, $matches);
        return $matches[1] ?? 'localhost';
    }

    /**
     * Validate API key
     */
    public function validateApiKey(string $key): bool
    {
        return hash_equals($this->config['api']['key'], $key);
    }

    /**
     * Get RabbitMQ DSN for AMQP connection
     */
    public function getRabbitMQDsn(): string
    {
        return sprintf(
            'amqp://%s:%s@%s:%d%s',
            $this->config['rabbitmq']['user'],
            $this->config['rabbitmq']['password'],
            $this->config['rabbitmq']['host'],
            $this->config['rabbitmq']['port'],
            $this->config['rabbitmq']['vhost']
        );
    }

    /**
     * Get Redis DSN
     */
    public function getRedisDsn(): string
    {
        $scheme = 'tcp';
        if (!empty($this->config['redis']['password'])) {
            return sprintf(
                '%s://%s:%s@%s:%d/%d',
                $scheme,
                $this->config['redis']['password'],
                $this->config['redis']['password'], // Using same as username
                $this->config['redis']['host'],
                $this->config['redis']['port'],
                $this->config['redis']['database']
            );
        }

        return sprintf(
            '%s://%s:%d/%d',
            $scheme,
            $this->config['redis']['host'],
            $this->config['redis']['port'],
            $this->config['redis']['database']
        );
    }
}
