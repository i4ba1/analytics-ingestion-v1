<?php

namespace Analytics\Controllers;

use Analytics\Config\Config;
use Analytics\Contracts\Event;
use Analytics\Queue\RabbitMQManager;
use Analytics\Cache\RedisManager;
use Analytics\Logging\Logger;
use Ramsey\Uuid\Uuid;

/**
 * Events Controller
 * 
 * Handles event ingestion endpoint with authentication,
 * rate limiting, validation, and RabbitMQ publishing.
 */
class Events extends \CodeIgniter\RESTful\ResourceController
{
    private Config $config;
    private RabbitMQManager $rabbitmq;
    private RedisManager $redis;
    private Logger $logger;

    public function __construct()
    {
        $this->config = Config::getInstance();
        $this->rabbitmq = RabbitMQManager::getInstance();
        $this->redis = RedisManager::getInstance();
        $this->logger = Logger::getInstance();
    }

    /**
     * POST /v1/events
     * 
     * Ingest batch of analytics events
     * 
     * @return \CodeIgniter\HTTP\Response
     */
    public function create()
    {
        $startTime = microtime(true);
        $correlationId = $this->request->getHeaderLine('X-Correlation-ID') 
            ?: Uuid::uuid4()->toString();

        $this->logger->logApiRequest('POST', '/v1/events', [
            'correlation_id' => $correlationId,
            'content_length' => $this->request->getHeaderLine('Content-Length'),
        ]);

        try {
            // 1. Authenticate API Key
            $apiKey = $this->request->getHeaderLine('X-API-Key');
            if (!$this->authenticate($apiKey)) {
                $this->logger->logApiResponse('POST', '/v1/events', 401, [
                    'correlation_id' => $correlationId,
                    'error' => 'Invalid API key',
                ]);

                return $this->response->setStatusCode(401)->setJSON([
                    'error' => 'Unauthorized',
                    'message' => 'Invalid API key',
                ]);
            }

            // 2. Check Rate Limit
            $rateLimitResult = $this->checkRateLimit($apiKey);
            if (!$rateLimitResult['allowed']) {
                $this->logger->logApiResponse('POST', '/v1/events', 429, [
                    'correlation_id' => $correlationId,
                    'error' => 'Rate limit exceeded',
                    'current' => $rateLimitResult['current'],
                    'limit' => $rateLimitResult['limit'],
                ]);

                return $this->response->setStatusCode(429)->setJSON([
                    'error' => 'Too Many Requests',
                    'message' => 'Rate limit exceeded',
                    'retry_after' => $rateLimitResult['reset'] - time(),
                ]);
            }

            // 3. Parse and Validate Payload
            $payload = $this->request->getJSON(true);
            
            if (!$payload || !isset($payload['events'])) {
                $this->logger->logApiResponse('POST', '/v1/events', 400, [
                    'correlation_id' => $correlationId,
                    'error' => 'Invalid payload',
                ]);

                return $this->response->setStatusCode(400)->setJSON([
                    'error' => 'Bad Request',
                    'message' => 'Missing or invalid payload',
                ]);
            }

            $events = $payload['events'];
            
            // Validate batch size
            $maxBatchSize = $this->config->get('event.maxBatchSize', 500);
            if (count($events) > $maxBatchSize) {
                return $this->response->setStatusCode(400)->setJSON([
                    'error' => 'Bad Request',
                    'message' => "Batch size exceeds maximum of {$maxBatchSize}",
                ]);
            }

            // 4. Validate Events
            $validationResult = Event::validateBatch($events);
            
            $validEvents = $validationResult['valid'];
            $invalidEvents = $validationResult['invalid'];

            // Log invalid events
            if (!empty($invalidEvents)) {
                $this->logger->warning('Some events failed validation', [
                    'correlation_id' => $correlationId,
                    'invalid_count' => count($invalidEvents),
                    'invalid_events' => $invalidEvents,
                ]);
            }

            // 5. Publish Valid Events to RabbitMQ
            $publishedCount = 0;
            $failedCount = 0;

            if (!empty($validEvents)) {
                foreach ($validEvents as $event) {
                    try {
                        // Add received_at timestamp
                        $eventData = $event->toArray();
                        
                        // Publish to RabbitMQ
                        $success = $this->rabbitmq->publish(
                            json_encode($eventData),
                            [
                                'correlation_id' => $correlationId,
                                'headers' => [
                                    'event_type' => $event->getType(),
                                    'event_id' => $event->getEventId(),
                                    'schema_version' => $event->getSchemaVersion(),
                                ],
                            ]
                        );

                        if ($success) {
                            $publishedCount++;
                        } else {
                            $failedCount++;
                        }
                    } catch (\Exception $e) {
                        $failedCount++;
                        $this->logger->error('Failed to publish event', [
                            'correlation_id' => $correlationId,
                            'event_id' => $event->getEventId(),
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            // 6. Build Response
            $response = [
                'status' => 'accepted',
                'accepted' => $publishedCount,
                'rejected' => count($invalidEvents) + $failedCount,
                'correlation_id' => $correlationId,
                'processing_time_ms' => $processingTime,
            ];

            if (!empty($invalidEvents)) {
                $response['invalid_events'] = $invalidEvents;
            }

            $statusCode = ($publishedCount > 0) ? 202 : 400;

            $this->logger->logApiResponse('POST', '/v1/events', $statusCode, [
                'correlation_id' => $correlationId,
                'accepted' => $publishedCount,
                'rejected' => $response['rejected'],
                'processing_time_ms' => $processingTime,
            ]);

            return $this->response->setStatusCode($statusCode)->setJSON($response);

        } catch (\Exception $e) {
            $this->logger->logException($e, [
                'correlation_id' => $correlationId,
            ]);

            return $this->response->setStatusCode(500)->setJSON([
                'error' => 'Internal Server Error',
                'message' => 'An error occurred while processing events',
            ]);
        }
    }

    /**
     * Authenticate API key
     */
    private function authenticate(string $apiKey): bool
    {
        $configuredKey = $this->config->get('api.key');
        
        if (empty($configuredKey)) {
            return false;
        }

        return hash_equals($configuredKey, $apiKey);
    }

    /**
     * Check rate limit for API key
     */
    private function checkRateLimit(string $apiKey): array
    {
        $limit = $this->config->get('api.rateLimit.requests', 1000);
        $window = $this->config->get('api.rateLimit.window', 60);

        return $this->redis->checkRateLimit($apiKey, $limit, $window);
    }

    /**
     * GET /v1/events
     * 
     * Get event ingestion status/info
     */
    public function index()
    {
        $apiKey = $this->request->getHeaderLine('X-API-Key');
        if (!$this->authenticate($apiKey)) {
            return $this->response->setStatusCode(401)->setJSON([
                'error' => 'Unauthorized',
            ]);
        }

        $rateLimitResult = $this->checkRateLimit($apiKey);

        return $this->response->setJSON([
            'endpoint' => '/v1/events',
            'methods' => ['POST'],
            'rate_limit' => [
                'remaining' => $rateLimitResult['remaining'],
                'limit' => $rateLimitResult['limit'],
                'reset' => $rateLimitResult['reset'],
            ],
            'max_batch_size' => $this->config->get('event.maxBatchSize'),
            'max_payload_size' => $this->config->get('event.maxPayloadSize'),
        ]);
    }

    /**
     * OPTIONS /v1/events
     * 
     * Handle CORS preflight
     */
    public function options()
    {
        return $this->response
            ->setHeader('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
            ->setHeader('Access-Control-Allow-Headers', 'Content-Type, X-API-Key, X-Correlation-ID')
            ->setStatusCode(204);
    }
}
