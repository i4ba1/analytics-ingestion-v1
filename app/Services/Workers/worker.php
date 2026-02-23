<?php

/**
 * Analytics Worker
 * 
 * CLI entry point for consuming analytics events from RabbitMQ
 * 
 * Usage: php spark worker:start [queue]
 * 
 * @package Analytics\Worker
 */

use App\Libraries\Analytics\Config\Config;
use App\Libraries\Analytics\Queue\RabbitMQManager;
use App\Libraries\Analytics\Database\DatabaseManager;
use App\Libraries\Analytics\Cache\RedisManager;
use App\Libraries\Analytics\Logging\Logger;

require_once ROOTPATH . 'vendor/autoload.php';

/**
 * Worker Class
 * 
 * Consumes messages from RabbitMQ, validates events,
 * checks idempotency, and persists aggregated data.
 */
namespace App\Services\Workers;`n`nclass Worker
{
    private Config $config;
    private RabbitMQManager $rabbitmq;
    private DatabaseManager $db;
    private RedisManager $redis;
    private Logger $logger;
    private bool $running = true;
    private string $queue;
    private array $metrics = [
        'processed' => 0,
        'failed' => 0,
        'duplicate' => 0,
        'started_at' => null,
    ];

    public function __construct(string $queue = 'analytics.events.raw')
    {
        $this->queue = $queue;
        $this->config = Config::getInstance();
        $this->rabbitmq = RabbitMQManager::getInstance();
        $this->db = DatabaseManager::getInstance();
        $this->redis = RedisManager::getInstance();
        $this->logger = Logger::getInstance();
        $this->metrics['started_at'] = time();
    }

    /**
     * Start consuming messages
     */
    public function start(): void
    {
        $this->logger->info('Worker started', [
            'queue' => $this->queue,
            'pid' => getmypid(),
        ]);

        // Setup signal handlers for graceful shutdown
        pcntl_signal(SIGTERM, [$this, 'stop']);
        pcntl_signal(SIGINT, [$this, 'stop']);
        pcntl_signal(SIGHUP, [$this, 'stop']);

        try {
            $this->rabbitmq->consume($this->queue, [$this, 'processMessage']);
        } catch (\Exception $e) {
            $this->logger->logException($e);
            throw $e;
        } finally {
            $this->logMetrics();
        }
    }

    /**
     * Process a single message from RabbitMQ
     * 
     * @param \PhpAmqpLib\Message\AMQPMessage $message
     */
    public function processMessage(\PhpAmqpLib\Message\AMQPMessage $message): void
    {
        $startTime = microtime(true);
        $deliveryTag = $message->delivery_info['delivery_tag'] ?? null;

        try {
            // Decode message
            $body = $message->getBody();
            $eventData = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error('Invalid JSON in message', [
                    'queue' => $this->queue,
                    'delivery_tag' => $deliveryTag,
                    'error' => json_last_error_msg(),
                ]);

                // NACK without requeue (poison pill)
                $message->nack(false);
                $this->metrics['failed']++;
                return;
            }

            // Extract event metadata
            $eventId = $eventData['event_id'] ?? null;
            $correlationId = $message->get('correlation_id') ?? null;

            if (!$eventId) {
                $this->logger->error('Missing event_id', [
                    'correlation_id' => $correlationId,
                    'queue' => $this->queue,
                ]);

                $message->nack(false);
                $this->metrics['failed']++;
                return;
            }

            // Log start of processing
            $this->logger->logWorkerMessage($this->queue, $deliveryTag, [
                'correlation_id' => $correlationId,
                'event_id' => $eventId,
                'event_type' => $eventData['type'] ?? 'unknown',
            ]);

            // 1. Validate contract version
            if (!$this->validateContractVersion($eventData)) {
                $this->logger->warning('Invalid contract version', [
                    'correlation_id' => $correlationId,
                    'event_id' => $eventId,
                    'schema_version' => $eventData['schema_version'] ?? null,
                ]);

                $message->nack(false);
                $this->metrics['failed']++;
                return;
            }

            // 2. Idempotency check
            $isDuplicate = $this->redis->checkDuplicate($eventId);
            
            if ($isDuplicate) {
                $this->logger->info('Duplicate event, skipping', [
                    'correlation_id' => $correlationId,
                    'event_id' => $eventId,
                    'queue' => $this->queue,
                ]);

                $message->ack();
                $this->metrics['duplicate']++;
                return;
            }

            // 3. Mark as processing (idempotency)
            $this->redis->markProcessed($eventId);

            // 4. Aggregate data
            $this->aggregateEvent($eventData, $correlationId);

            // 5. ACK message
            $message->ack();
            $this->metrics['processed']++;

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->info('Event processed successfully', [
                'correlation_id' => $correlationId,
                'event_id' => $eventId,
                'queue' => $this->queue,
                'processing_time_ms' => $processingTime,
            ]);

        } catch (\Exception $e) {
            $this->logger->logException($e, [
                'queue' => $this->queue,
                'delivery_tag' => $deliveryTag,
            ]);

            // NACK with requeue for retry
            $message->nack(true);
            $this->metrics['failed']++;
        }
    }

    /**
     * Validate event contract version
     */
    private function validateContractVersion(array $eventData): bool
    {
        $schemaVersion = $eventData['schema_version'] ?? null;
        
        // Only support version 1 for now
        return $schemaVersion === 1;
    }

    /**
     * Aggregate event data to database and Redis
     */
    private function aggregateEvent(array $eventData, ?string $correlationId): void
    {
        $eventType = $eventData['type'] ?? 'unknown';
        $userId = $eventData['user_id'] ?? null;
        $properties = $eventData['properties'] ?? [];
        $timestamp = $eventData['timestamp'] ?? time();
        
        // Convert timestamp to date
        $date = date('Y-m-d', $timestamp);
        $hour = date('Y-m-d-H', $timestamp);

        // 1. Update daily aggregates in database
        $this->db->insertEventTypeDaily($date, $eventType);
        $this->db->insertUserActivity($date, $userId);

        // 2. Track page views
        if ($eventType === 'page_view' && isset($properties['url'])) {
            $url = $properties['url'];
            $this->db->insertPageDaily($date, $url);
            
            // Also track in Redis for hot metrics
            $this->redis->incrementPageView($hour, $url);
        }

        // 3. Track user activity in Redis
        if ($userId) {
            $this->redis->incrementUserActivity($hour, $userId);
        }

        // 4. Track event type in Redis
        $this->redis->incrementEventType($hour, $eventType);
    }

    /**
     * Stop the worker gracefully
     */
    public function stop(): void
    {
        $this->logger->info('Worker stopping...', [
            'queue' => $this->queue,
            'processed' => $this->metrics['processed'],
            'failed' => $this->metrics['failed'],
            'duplicate' => $this->metrics['duplicate'],
        ]);

        $this->running = false;
        $this->logMetrics();
    }

    /**
     * Log worker metrics
     */
    private function logMetrics(): void
    {
        $duration = time() - $this->metrics['started_at'];
        
        $this->logger->info('Worker metrics', [
            'queue' => $this->queue,
            'processed' => $this->metrics['processed'],
            'failed' => $this->metrics['failed'],
            'duplicate' => $this->metrics['duplicate'],
            'duration_seconds' => $duration,
            'rate_per_second' => $duration > 0 ? round($this->metrics['processed'] / $duration, 2) : 0,
        ]);
    }
}

// CLI Entry Point
if (php_sapi_name() === 'cli') {
    // Get queue from command line or use default
    $queue = $argv[1] ?? 'analytics.events.raw';
    
    echo "Starting Analytics Worker...\n";
    echo "Queue: {$queue}\n";
    echo "PID: " . getmypid() . "\n";

    try {
        $worker = new Worker($queue);
        $worker->start();
    } catch (\Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
