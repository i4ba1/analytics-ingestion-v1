<?php

namespace Analytics\Controllers;

use Analytics\Config\Config;
use Analytics\Database\DatabaseManager;
use Analytics\Cache\RedisManager;
use Analytics\Logging\Logger;
use Ramsey\Uuid\Uuid;

/**
 * Metrics Controller
 * 
 * Handles metrics query endpoints with Redis caching
 * and database fallback.
 */
class Metrics extends \CodeIgniter\RESTful\ResourceController
{
    private Config $config;
    private DatabaseManager $db;
    private RedisManager $redis;
    private Logger $logger;

    public function __construct()
    {
        $this->config = Config::getInstance();
        $this->db = DatabaseManager::getInstance();
        $this->redis = RedisManager::getInstance();
        $this->logger = Logger::getInstance();
    }

    /**
     * GET /v1/metrics/top-pages
     * 
     * Get top pages for a time window
     * 
     * @queryparam window Time window (1h, 24h, 7d)
     * @queryparam limit Number of results (default: 20)
     */
    public function topPages()
    {
        $correlationId = $this->request->getHeaderLine('X-Correlation-ID') 
            ?: Uuid::uuid4()->toString();

        try {
            $window = $this->request->getVar('window') ?? '1h';
            $limit = (int) ($this->request->getVar('limit') ?? 20);

            $this->logger->logApiRequest('GET', '/v1/metrics/top-pages', [
                'correlation_id' => $correlationId,
                'window' => $window,
                'limit' => $limit,
            ]);

            // Validate window
            $validWindows = ['1h', '24h', '7d', '30d'];
            if (!in_array($window, $validWindows)) {
                return $this->response->setStatusCode(400)->setJSON([
                    'error' => 'Invalid window',
                    'allowed' => $validWindows,
                ]);
            }

            // Validate limit
            if ($limit < 1 || $limit > 100) {
                return $this->response->setStatusCode(400)->setJSON([
                    'error' => 'Invalid limit',
                    'message' => 'Limit must be between 1 and 100',
                ]);
            }

            // Try Redis first (fast)
            $cacheKey = "top_pages:{$window}:{$limit}";
            $cached = $this->redis->getCache($cacheKey);

            if ($cached !== null) {
                $this->logger->debug('Cache hit for top pages', [
                    'correlation_id' => $correlationId,
                    'cache_key' => $cacheKey,
                ]);

                return $this->response->setJSON([
                    'window' => $window,
                    'limit' => $limit,
                    'pages' => $cached,
                    'cached' => true,
                ]);
            }

            // Cache miss - query data source
            $this->logger->debug('Cache miss for top pages, querying data', [
                'correlation_id' => $correlationId,
            ]);

            $pages = [];

            // For short-term (1h), use Redis hot metrics
            if ($window === '1h' || $window === '24h') {
                $pages = $this->redis->getTopPages($window, $limit);
            } else {
                // For longer windows, query database aggregates
                $toDate = date('Y-m-d');
                $days = (int) str_replace('d', '', $window);
                $fromDate = date('Y-m-d', strtotime("-{$days} days"));

                $dbResults = $this->db->getTopPages($fromDate, $toDate, $limit);
                $pages = array_map(function($row) {
                    return [
                        'url' => $row['url'],
                        'count' => (int) $row['total_count'],
                    ];
                }, $dbResults);
            }

            // Cache the results
            $this->redis->setCache($cacheKey, $pages, 120); // 2 minute TTL

            $this->logger->logApiResponse('GET', '/v1/metrics/top-pages', 200, [
                'correlation_id' => $correlationId,
                'result_count' => count($pages),
            ]);

            return $this->response->setJSON([
                'window' => $window,
                'limit' => $limit,
                'pages' => $pages,
                'cached' => false,
            ]);

        } catch (\Exception $e) {
            $this->logger->logException($e, [
                'correlation_id' => $correlationId,
            ]);

            return $this->response->setStatusCode(500)->setJSON([
                'error' => 'Internal Server Error',
                'message' => 'Failed to retrieve top pages',
            ]);
        }
    }

    /**
     * GET /v1/metrics/top-users
     * 
     * Get top users for a time window
     * 
     * @queryparam window Time window (1h, 24h, 7d)
     * @queryparam limit Number of results (default: 20)
     */
    public function topUsers()
    {
        $correlationId = $this->request->getHeaderLine('X-Correlation-ID') 
            ?: Uuid::uuid4()->toString();

        try {
            $window = $this->request->getVar('window') ?? '1h';
            $limit = (int) ($this->request->getVar('limit') ?? 20);

            $this->logger->logApiRequest('GET', '/v1/metrics/top-users', [
                'correlation_id' => $correlationId,
                'window' => $window,
                'limit' => $limit,
            ]);

            // Validate window
            $validWindows = ['1h', '24h', '7d', '30d'];
            if (!in_array($window, $validWindows)) {
                return $this->response->setStatusCode(400)->setJSON([
                    'error' => 'Invalid window',
                    'allowed' => $validWindows,
                ]);
            }

            // Validate limit
            if ($limit < 1 || $limit > 100) {
                return $this->response->setStatusCode(400)->setJSON([
                    'error' => 'Invalid limit',
                    'message' => 'Limit must be between 1 and 100',
                ]);
            }

            // Try cache first
            $cacheKey = "top_users:{$window}:{$limit}";
            $cached = $this->redis->getCache($cacheKey);

            if ($cached !== null) {
                return $this->response->setJSON([
                    'window' => $window,
                    'limit' => $limit,
                    'users' => $cached,
                    'cached' => true,
                ]);
            }

            // Query data
            $users = [];

            if ($window === '1h' || $window === '24h') {
                $users = $this->redis->getTopUsers($window, $limit);
            } else {
                $toDate = date('Y-m-d');
                $days = (int) str_replace('d', '', $window);
                $fromDate = date('Y-m-d', strtotime("-{$days} days"));

                $dbResults = $this->db->getTopUsers($fromDate, $toDate, $limit);
                $users = array_map(function($row) {
                    return [
                        'user_id' => $row['user_id'],
                        'count' => (int) $row['total_count'],
                    ];
                }, $dbResults);
            }

            // Cache results
            $this->redis->setCache($cacheKey, $users, 120);

            return $this->response->setJSON([
                'window' => $window,
                'limit' => $limit,
                'users' => $users,
                'cached' => false,
            ]);

        } catch (\Exception $e) {
            $this->logger->logException($e);

            return $this->response->setStatusCode(500)->setJSON([
                'error' => 'Internal Server Error',
                'message' => 'Failed to retrieve top users',
            ]);
        }
    }

    /**
     * GET /v1/metrics/count
     * 
     * Get event counts for a type and date range
     * 
     * @queryparam type Event type (optional)
     * @queryparam from Start date (YYYY-MM-DD)
     * @queryparam to End date (YYYY-MM-DD)
     */
    public function count()
    {
        $correlationId = $this->request->getHeaderLine('X-Correlation-ID') 
            ?: Uuid::uuid4()->toString();

        try {
            $type = $this->request->getVar('type');
            $from = $this->request->getVar('from') ?? date('Y-m-d', strtotime('-7 days'));
            $to = $this->request->getVar('to') ?? date('Y-m-d');

            $this->logger->logApiRequest('GET', '/v1/metrics/count', [
                'correlation_id' => $correlationId,
                'type' => $type,
                'from' => $from,
                'to' => $to,
            ]);

            // Validate dates
            if (!$this->isValidDate($from) || !$this->isValidDate($to)) {
                return $this->response->setStatusCode(400)->setJSON([
                    'error' => 'Invalid date format',
                    'message' => 'Use YYYY-MM-DD format',
                ]);
            }

            // Try cache
            $cacheKey = "count:{$type}:{$from}:{$to}";
            $cached = $this->redis->getCache($cacheKey);

            if ($cached !== null) {
                return $this->response->setJSON($cached + ['cached' => true]);
            }

            // Query database
            $results = $this->db->getEventTypeCounts($type, $from, $to);
            $totalCount = $this->db->getTotalEventCount($type, $from, $to);

            $data = [
                'type' => $type ?? 'all',
                'from' => $from,
                'to' => $to,
                'total_count' => $totalCount,
                'daily_counts' => array_map(function($row) {
                    return [
                        'date' => $row['date'],
                        'event_type' => $row['event_type'],
                        'count' => (int) $row['count'],
                    ];
                }, $results),
                'cached' => false,
            ];

            // Cache results
            $this->redis->setCache($cacheKey, $data, 120);

            return $this->response->setJSON($data);

        } catch (\Exception $e) {
            $this->logger->logException($e);

            return $this->response->setStatusCode(500)->setJSON([
                'error' => 'Internal Server Error',
                'message' => 'Failed to retrieve event counts',
            ]);
        }
    }

    /**
     * GET /v1/metrics/daily-stats
     * 
     * Get daily statistics
     * 
     * @queryparam date Date (YYYY-MM-DD, default: today)
     */
    public function dailyStats()
    {
        $correlationId = $this->request->getHeaderLine('X-Correlation-ID') 
            ?: Uuid::uuid4()->toString();

        try {
            $date = $this->request->getVar('date') ?? date('Y-m-d');

            $this->logger->logApiRequest('GET', '/v1/metrics/daily-stats', [
                'correlation_id' => $correlationId,
                'date' => $date,
            ]);

            if (!$this->isValidDate($date)) {
                return $this->response->setStatusCode(400)->setJSON([
                    'error' => 'Invalid date format',
                    'message' => 'Use YYYY-MM-DD format',
                ]);
            }

            $stats = $this->db->getDailyStats($date);

            return $this->response->setJSON($stats);

        } catch (\Exception $e) {
            $this->logger->logException($e);

            return $this->response->setStatusCode(500)->setJSON([
                'error' => 'Internal Server Error',
                'message' => 'Failed to retrieve daily stats',
            ]);
        }
    }

    /**
     * Validate date format
     */
    private function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    /**
     * OPTIONS handler for CORS
     */
    public function options()
    {
        return $this->response
            ->setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS')
            ->setHeader('Access-Control-Allow-Headers', 'Content-Type, X-API-Key, X-Correlation-ID')
            ->setStatusCode(204);
    }
}
