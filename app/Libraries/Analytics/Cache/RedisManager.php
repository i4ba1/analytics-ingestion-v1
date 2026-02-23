<?php

namespace App\Libraries\Analytics\Cache;

use App\Libraries\Analytics\Config\Config;
use Predis\Client;

/**
 * Redis Manager
 * 
 * Handles all Redis operations including rate limiting,
 * deduplication, hot metrics, and caching.
 */
class RedisManager
{
    private ?Client $client = null;
    private Config $config;
    private static ?RedisManager $instance = null;

    private function __construct()
    {
        $this->config = Config::getInstance();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get or create Redis client
     */
    public function getClient(): Client
    {
        if ($this->client === null) {
            $this->connect();
        }
        return $this->client;
    }

    /**
     * Establish connection to Redis
     */
    public function connect(): void
    {
        try {
            $parameters = [
                'scheme' => 'tcp',
                'host' => $this->config->get('redis.host', 'localhost'),
                'port' => $this->config->get('redis.port', 6379),
                'database' => $this->config->get('redis.database', 0),
            ];

            $password = $this->config->get('redis.password', '');
            if (!empty($password)) {
                $parameters['password'] = $password;
            }

            $this->client = new Client($parameters);
            
            // Test connection
            $this->client->ping();
            
            log_message('info', 'Redis connection established');
        } catch (\Exception $e) {
            log_message('error', 'Redis connection failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Close Redis connection
     */
    public function close(): void
    {
        if ($this->client !== null) {
            $this->client->disconnect();
            $this->client = null;
        }
    }

    // =================================================================
    // RATE LIMITING
    // =================================================================

    /**
     * Check rate limit
     * 
     * @param string $apiKey API key identifier
     * @param int $limit Request limit
     * @param int $window Time window in seconds
     * @return array ['allowed' => bool, 'remaining' => int, 'reset' => int]
     */
    public function checkRateLimit(string $apiKey, int $limit, int $window): array
    {
        $client = $this->getClient();
        $prefix = $this->config->get('redisKeys.prefix.rateLimit', 'rl:');
        $key = $prefix . $apiKey . ':' . date('YmdHi');
        
        $current = $client->incr($key);
        
        // Set expiry on first request
        if ($current === 1) {
            $client->expire($key, $window);
        }

        $reset = time() + $client->ttl($key);
        
        return [
            'allowed' => $current <= $limit,
            'remaining' => max(0, $limit - $current),
            'current' => $current,
            'limit' => $limit,
            'reset' => $reset,
        ];
    }

    // =================================================================
    // IDEMPOTENCY / DEDUPLICATION
    // =================================================================

    /**
     * Check if event has been processed (idempotency check)
     * 
     * @param string $eventId Event UUID
     * @return bool True if already processed
     */
    public function isEventProcessed(string $eventId): bool
    {
        $client = $this->getClient();
        $prefix = $this->config->get('redisKeys.prefix.dedupe', 'dedupe:');
        $key = $prefix . $eventId;
        
        return (bool) $client->exists($key);
    }

    /**
     * Mark event as processed
     * 
     * @param string $eventId Event UUID
     * @param int $ttl TTL in seconds (default: 24 hours)
     * @return bool
     */
    public function markEventProcessed(string $eventId, ?int $ttl = null): bool
    {
        $client = $this->getClient();
        $prefix = $this->config->get('redisKeys.prefix.dedupe', 'dedupe:');
        $key = $prefix . $eventId;
        $ttl = $ttl ?? $this->config->get('ttl.dedupe', 86400);
        
        $client->setex($key, $ttl, 1);
        
        return true;
    }

    /**
     * Check and mark event as processed (atomic operation)
     * 
     * @param string $eventId Event UUID
     * @return bool True if event was NOT processed before (first time)
     */
    public function checkAndMarkEvent(string $eventId): bool
    {
        $client = $this->getClient();
        $prefix = $this->config->get('redisKeys.prefix.dedupe', 'dedupe:');
        $key = $prefix . $eventId;
        $ttl = $this->config->get('ttl.dedupe', 86400);
        
        // SETNX returns 1 if key was set (first time), 0 if already exists
        $result = $client->setnx($key, time());
        
        if ($result === 1) {
            $client->expire($key, $ttl);
            return true; // First time processing
        }
        
        return false; // Already processed
    }

    // =================================================================
    // HOT METRICS (Sorted Sets)
    // =================================================================

    /**
     * Increment page view count in sorted set
     * 
     * @param string $url Page URL
     * @param int $hourTimestamp Hour timestamp (YYYYMMDDHH format)
     * @param float $score Score increment (default: 1)
     */
    public function incrementPageView(string $url, ?int $hourTimestamp = null, float $score = 1.0): void
    {
        $client = $this->getClient();
        $prefix = $this->config->get('redisKeys.prefix.topPages', 'z:top_pages:');
        $hourKey = $hourTimestamp ?? date('YmdH');
        $key = $prefix . $hourKey;
        
        $client->zincrby($key, $score, $url);
        $client->expire($key, $this->config->get('ttl.hotMetrics', 604800));
    }

    /**
     * Increment user activity count in sorted set
     * 
     * @param string $userId User ID
     * @param int $hourTimestamp Hour timestamp
     * @param float $score Score increment
     */
    public function incrementUserActivity(string $userId, ?int $hourTimestamp = null, float $score = 1.0): void
    {
        $client = $this->getClient();
        $prefix = $this->config->get('redisKeys.prefix.topUsers', 'z:top_users:');
        $hourKey = $hourTimestamp ?? date('YmdH');
        $key = $prefix . $hourKey;
        
        $client->zincrby($key, $score, $userId);
        $client->expire($key, $this->config->get('ttl.hotMetrics', 604800));
    }

    /**
     * Get top pages for a time window
     * 
     * @param string $window Time window (1h, 24h, etc.)
     * @param int $limit Number of results
     * @return array [['url' => string, 'count' => int], ...]
     */
    public function getTopPages(string $window = '1h', int $limit = 20): array
    {
        $client = $this->getClient();
        $prefix = $this->config->get('redisKeys.prefix.topPages', 'z:top_pages:');
        
        $keys = $this->getHourKeysForWindow($window, $prefix);
        
        if (empty($keys)) {
            return [];
        }

        // Union of all hour keys
        $unionKey = 'temp:top_pages:union:' . uniqid();
        
        if (count($keys) === 1) {
            $result = $client->zrevrange($keys[0], 0, $limit - 1, 'WITHSCORES');
        } else {
            $client->zunionstore($unionKey, $keys);
            $result = $client->zrevrange($unionKey, 0, $limit - 1, 'WITHSCORES');
            $client->del($unionKey);
        }

        $topPages = [];
        for ($i = 0; $i < count($result); $i += 2) {
            $topPages[] = [
                'url' => $result[$i],
                'count' => (int) $result[$i + 1],
            ];
        }

        return $topPages;
    }

    /**
     * Get top users for a time window
     * 
     * @param string $window Time window
     * @param int $limit Number of results
     * @return array [['user_id' => string, 'count' => int], ...]
     */
    public function getTopUsers(string $window = '1h', int $limit = 20): array
    {
        $client = $this->getClient();
        $prefix = $this->config->get('redisKeys.prefix.topUsers', 'z:top_users:');
        
        $keys = $this->getHourKeysForWindow($window, $prefix);
        
        if (empty($keys)) {
            return [];
        }

        $unionKey = 'temp:top_users:union:' . uniqid();
        
        if (count($keys) === 1) {
            $result = $client->zrevrange($keys[0], 0, $limit - 1, 'WITHSCORES');
        } else {
            $client->zunionstore($unionKey, $keys);
            $result = $client->zrevrange($unionKey, 0, $limit - 1, 'WITHSCORES');
            $client->del($unionKey);
        }

        $topUsers = [];
        for ($i = 0; $i < count($result); $i += 2) {
            $topUsers[] = [
                'user_id' => $result[$i],
                'count' => (int) $result[$i + 1],
            ];
        }

        return $topUsers;
    }

    /**
     * Get hour keys for a time window
     */
    private function getHourKeysForWindow(string $window, string $prefix): array
    {
        $hours = 0;
        
        // Parse window
        if (preg_match('/^(\d+)h$/', $window, $matches)) {
            $hours = (int) $matches[1];
        } elseif (preg_match('/^(\d+)d$/', $window, $matches)) {
            $hours = (int) $matches[1] * 24;
        } elseif ($window === '1h') {
            $hours = 1;
        }

        if ($hours <= 0) {
            return [];
        }

        $keys = [];
        for ($i = 0; $i < $hours; $i++) {
            $timestamp = strtotime("-{$i} hours");
            $hourKey = date('YmdH', $timestamp);
            $keys[] = $prefix . $hourKey;
        }

        return $keys;
    }

    // =================================================================
    // CACHING
    // =================================================================

    /**
     * Get cached value
     * 
     * @param string $key Cache key
     * @return mixed|null
     */
    public function getCache(string $key)
    {
        $client = $this->getClient();
        $prefix = $this->config->get('redisKeys.prefix.cache', 'cache:');
        $fullKey = $prefix . $key;
        
        $value = $client->get($fullKey);
        
        return $value !== null ? json_decode($value, true) : null;
    }

    /**
     * Set cached value
     * 
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int|null $ttl TTL in seconds (default from config)
     * @return bool
     */
    public function setCache(string $key, $value, ?int $ttl = null): bool
    {
        $client = $this->getClient();
        $prefix = $this->config->get('redisKeys.prefix.cache', 'cache:');
        $fullKey = $prefix . $key;
        $ttl = $ttl ?? $this->config->get('ttl.cache', 120);
        
        $jsonValue = json_encode($value);
        
        if ($ttl > 0) {
            $client->setex($fullKey, $ttl, $jsonValue);
        } else {
            $client->set($fullKey, $jsonValue);
        }
        
        return true;
    }

    /**
     * Delete cached value
     */
    public function deleteCache(string $key): bool
    {
        $client = $this->getClient();
        $prefix = $this->config->get('redisKeys.prefix.cache', 'cache:');
        $fullKey = $prefix . $key;
        
        return (bool) $client->del($fullKey);
    }

    // =================================================================
    // UTILITY
    // =================================================================

    /**
     * Check health of Redis connection
     */
    public function healthCheck(): bool
    {
        try {
            $client = $this->getClient();
            $client->ping();
            return true;
        } catch (\Exception $e) {
            log_message('error', 'Redis health check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Flush all keys (use with caution!)
     */
    public function flushAll(): void
    {
        $client = $this->getClient();
        $client->flushdb();
    }

    /**
     * Get Redis info
     */
    public function getInfo(): array
    {
        try {
            $client = $this->getClient();
            $info = $client->info();
            
            return [
                'connected' => true,
                'version' => $info['redis_version'] ?? 'unknown',
                'used_memory' => $info['used_memory_human'] ?? 'unknown',
                'connected_clients' => $info['connected_clients'] ?? 0,
                'keyspace' => $info['db0'] ?? [],
            ];
        } catch (\Exception $e) {
            return [
                'connected' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Destructor - ensure connection is closed
     */
    public function __destruct()
    {
        $this->close();
    }
}
