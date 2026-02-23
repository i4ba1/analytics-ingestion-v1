<?php

namespace Analytics\Controllers;

use Analytics\Queue\RabbitMQManager;
use Analytics\Cache\RedisManager;
use Analytics\Database\DatabaseManager;
use Analytics\Logging\Logger;
use Ramsey\Uuid\Uuid;

/**
 * Health Controller
 * 
 * Handles health check and system status endpoints.
 */
class Health extends \CodeIgniter\RESTful\ResourceController
{
    private RabbitMQManager $rabbitmq;
    private RedisManager $redis;
    private DatabaseManager $db;
    private Logger $logger;

    public function __construct()
    {
        $this->rabbitmq = RabbitMQManager::getInstance();
        $this->redis = RedisManager::getInstance();
        $this->db = DatabaseManager::getInstance();
        $this->logger = Logger::getInstance();
    }

    /**
     * GET /v1/health
     * 
     * Health check endpoint
     */
    public function index()
    {
        $correlationId = $this->request->getHeaderLine('X-Correlation-ID') 
            ?: Uuid::uuid4()->toString();

        try {
            $checks = [
                'redis' => $this->redis->healthCheck(),
                'database' => $this->db->healthCheck(),
                'rabbitmq' => $this->rabbitmq->healthCheck(),
            ];

            $healthy = !in_array(false, $checks, true);
            $statusCode = $healthy ? 200 : 503;

            $response = [
                'status' => $healthy ? 'healthy' : 'unhealthy',
                'checks' => $checks,
                'timestamp' => date('Y-m-d\TH:i:s.vP'),
            ];

            $this->logger->debug('Health check', [
                'correlation_id' => $correlationId,
                'status' => $response['status'],
            ]);

            return $this->response->setStatusCode($statusCode)->setJSON($response);

        } catch (\Exception $e) {
            $this->logger->logException($e, [
                'correlation_id' => $correlationId,
            ]);

            return $this->response->setStatusCode(503)->setJSON([
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * GET /v1/health/ready
     * 
     * Readiness probe
     */
    public function ready()
    {
        try {
            // Check if service can accept traffic
            $checks = [
                'redis' => $this->redis->healthCheck(),
                'database' => $this->db->healthCheck(),
                'rabbitmq' => $this->rabbitmq->healthCheck(),
            ];

            $ready = !in_array(false, $checks, true);
            $statusCode = $ready ? 200 : 503;

            return $this->response->setStatusCode($statusCode)->setJSON([
                'ready' => $ready,
            ]);

        } catch (\Exception $e) {
            return $this->response->setStatusCode(503)->setJSON([
                'ready' => false,
            ]);
        }
    }

    /**
     * GET /v1/health/live
     * 
     * Liveness probe
     */
    public function live()
    {
        return $this->response->setJSON([
            'alive' => true,
        ]);
    }

    /**
     * GET /v1/health/detailed
     * 
     * Detailed health information
     */
    public function detailed()
    {
        try {
            $redisInfo = $this->redis->getInfo();
            $rabbitmqInfo = [
                'connected' => $this->rabbitmq->healthCheck(),
            ];

            $response = [
                'timestamp' => date('Y-m-d\TH:i:s.vP'),
                'redis' => $redisInfo,
                'rabbitmq' => $rabbitmqInfo,
                'database' => [
                    'connected' => $this->db->healthCheck(),
                ],
                'system' => [
                    'php_version' => PHP_VERSION,
                    'os' => PHP_OS,
                    'memory_usage' => memory_get_usage(true),
                    'memory_peak' => memory_get_peak_usage(true),
                ],
            ];

            return $this->response->setJSON($response);

        } catch (\Exception $e) {
            return $this->response->setStatusCode(500)->setJSON([
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * GET /v1/metrics
     * 
     * Prometheus metrics endpoint
     * Exposes metrics in Prometheus format
     */
    public function metrics()
    {
        try {
            $output = [];

            // API metrics (would need to track these during requests)
            $output[] = '# HELP analytics_http_requests_total Total HTTP requests';
            $output[] = '# TYPE analytics_http_requests_total counter';
            $output[] = 'analytics_http_requests_total{endpoint="/v1/events",status="202"} ' . ($this->getMetric('events_accepted_202') ?? 0);
            $output[] = 'analytics_http_requests_total{endpoint="/v1/events",status="400"} ' . ($this->getMetric('events_rejected_400') ?? 0);
            $output[] = 'analytics_http_requests_total{endpoint="/v1/events",status="401"} ' . ($this->getMetric('events_unauthorized_401') ?? 0);

            // RabbitMQ metrics
            $queueDepth = $this->rabbitmq->getQueueMessageCount('analytics.events.raw');
            $output[] = '# HELP analytics_queue_depth Current queue depth';
            $output[] = '# TYPE analytics_queue_depth gauge';
            $output[] = 'analytics_queue_depth{queue="analytics.events.raw"} ' . $queueDepth;

            // Redis health
            $output[] = '# HELP analytics_redis_up Redis connection status';
            $output[] = '# TYPE analytics_redis_up gauge';
            $output[] = 'analytics_redis_up ' . ($this->redis->healthCheck() ? '1' : '0');

            // Database health
            $output[] = '# HELP analytics_database_up Database connection status';
            $output[] = '# TYPE analytics_database_up gauge';
            $output[] = 'analytics_database_up ' . ($this->db->healthCheck() ? '1' : '0');

            // Processing time metrics (would need to track these)
            $output[] = '# HELP analytics_processing_time_ms Average processing time';
            $output[] = '# TYPE analytics_processing_time_ms gauge';
            $output[] = 'analytics_processing_time_ms ' . ($this->getMetric('avg_processing_time') ?? 0);

            $response = implode("\n", $output);

            return $this->response
                ->setContentType('text/plain')
                ->setBody($response);

        } catch (\Exception $e) {
            return $this->response->setStatusCode(500)->setBody(
                '# Error fetching metrics: ' . $e->getMessage()
            );
        }
    }

    /**
     * Get metric value (placeholder - would use Redis/actual metrics storage)
     */
    private function getMetric(string $key): ?int
    {
        // This would read from a metrics storage (Redis, etc.)
        // For now, return null
        return null;
    }
}
