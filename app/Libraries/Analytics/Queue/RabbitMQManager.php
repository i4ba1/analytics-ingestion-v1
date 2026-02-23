<?php

namespace App\Libraries\Analytics\Queue;

use App\Libraries\Analytics\Config\Config;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exception\AMQPException;

/**
 * RabbitMQ Manager
 * 
 * Handles all RabbitMQ operations including connection management,
 * queue topology setup, and message publishing.
 */
class RabbitMQManager
{
    private ?AMQPStreamConnection $connection = null;
    private ?AMQPChannel $channel = null;
    private Config $config;
    private static ?RabbitMQManager $instance = null;

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
     * Get or create AMQP connection
     */
    public function getConnection(): AMQPStreamConnection
    {
        if ($this->connection === null || !$this->connection->isConnected()) {
            $this->connect();
        }
        return $this->connection;
    }

    /**
     * Get or create AMQP channel
     */
    public function getChannel(): AMQPChannel
    {
        if ($this->channel === null || !$this->channel->is_open()) {
            $this->channel = $this->getConnection()->channel();
        }
        return $this->channel;
    }

    /**
     * Establish connection to RabbitMQ
     */
    public function connect(): void
    {
        try {
            $this->connection = new AMQPStreamConnection(
                $this->config->get('rabbitmq.host', 'localhost'),
                $this->config->get('rabbitmq.port', 5672),
                $this->config->get('rabbitmq.user', 'guest'),
                $this->config->get('rabbitmq.password', 'guest'),
                $this->config->get('rabbitmq.vhost', '/')
            );

            $this->channel = $this->connection->channel();
        } catch (AMQPException $e) {
            log_message('error', 'RabbitMQ connection failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Close connection and channel
     */
    public function close(): void
    {
        try {
            if ($this->channel && $this->channel->is_open()) {
                $this->channel->close();
            }
            if ($this->connection && $this->connection->isConnected()) {
                $this->connection->close();
            }
        } catch (\Exception $e) {
            log_message('error', 'Error closing RabbitMQ connection: ' . $e->getMessage());
        } finally {
            $this->channel = null;
            $this->connection = null;
        }
    }

    /**
     * Declare queue topology
     */
    public function declareTopology(): void
    {
        $channel = $this->getChannel();
        $exchange = $this->config->get('rabbitmq.exchange', 'analytics.events');
        $queueRaw = $this->config->get('rabbitmq.queue.raw', 'analytics.events.raw');
        $queueDlq = $this->config->get('rabbitmq.queue.dlq', 'analytics.events.dlq');
        $dlx = $this->config->get('rabbitmq.queue.dlx', 'analytics.events.dlx');
        $routingKey = $this->config->get('rabbitmq.routingKey', 'events.raw');

        // Declare main exchange (topic type for flexibility)
        $channel->exchange_declare(
            $exchange,
            'topic',
            false,
            true,  // durable
            false
        );

        // Declare dead-letter exchange
        $channel->exchange_declare(
            $dlx,
            'direct',
            false,
            true,  // durable
            false
        );

        // Declare raw queue with dead-letter exchange
        $channel->queue_declare(
            $queueRaw,
            false,
            true,  // durable
            false,
            false,
            false,
            new \PhpAmqpLib\Wire\AMQPTable([
                'x-dead-letter-exchange' => $dlx,
                'x-dead-letter-routing-key' => $routingKey . '.dlq'
            ])
        );

        // Declare dead-letter queue
        $channel->queue_declare(
            $queueDlq,
            false,
            true,  // durable
            false,
            false
        );

        // Bind raw queue to main exchange
        $channel->queue_bind($queueRaw, $exchange, $routingKey);

        // Bind DLQ to DLX
        $channel->queue_bind($queueDlq, $dlx, $routingKey . '.dlq');

        log_message('info', 'RabbitMQ topology declared successfully');
    }

    /**
     * Publish message to exchange
     */
    public function publish(string $message, array $options = []): bool
    {
        try {
            $channel = $this->getChannel();
            $exchange = $this->config->get('rabbitmq.exchange', 'analytics.events');
            $routingKey = $options['routing_key'] ?? $this->config->get('rabbitmq.routingKey', 'events.raw');

            // Create message with persistence
            $amqpMessage = new AMQPMessage(
                $message,
                [
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'content_type' => 'application/json',
                    'timestamp' => time(),
                ]
            );

            // Add optional headers
            if (isset($options['correlation_id'])) {
                $amqpMessage->set('correlation_id', $options['correlation_id']);
            }

            if (isset($options['headers']) && is_array($options['headers'])) {
                $amqpMessage->set('application_headers', new \PhpAmqpLib\Wire\AMQPTable($options['headers']));
            }

            $channel->basic_publish(
                $amqpMessage,
                $exchange,
                $routingKey
            );

            return true;
        } catch (AMQPException $e) {
            log_message('error', 'Failed to publish message: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Publish batch of messages
     */
    public function publishBatch(array $messages): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];

        foreach ($messages as $index => $message) {
            try {
                if ($this->publish($message)) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = "Message {$index} failed to publish";
                }
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = "Message {$index}: " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Consume messages from queue
     */
    public function consume(string $queue, callable $callback, array $options = []): void
    {
        $channel = $this->getChannel();
        $prefetchCount = $options['prefetch_count'] ?? $this->config->get('worker.prefetchCount', 100);

        // Set prefetch count for fair dispatch
        $channel->basic_qos(null, $prefetchCount, false);

        // Consumer callback wrapper
        $wrappedCallback = function (AMQPMessage $message) use ($callback) {
            try {
                $callback($message);
                $message->ack();
            } catch (\Exception $e) {
                log_message('error', 'Error processing message: ' . $e->getMessage());
                
                // NACK without requeue (send to DLQ)
                $message->nack(false);
            }
        };

        $channel->basic_consume(
            $queue,
            $options['consumer_tag'] ?? '',
            false,
            false, // no_ack = false (manual ack)
            false,
            false,
            $wrappedCallback
        );

        // Wait for messages
        while ($channel->is_consuming()) {
            $channel->wait();
        }
    }

    /**
     * Setup delayed retry queues
     */
    public function setupRetryQueues(): void
    {
        $channel = $this->getChannel();
        $exchange = $this->config->get('rabbitmq.exchange', 'analytics.events');
        $queueRaw = $this->config->get('rabbitmq.queue.raw', 'analytics.events.raw');

        // Retry delays: 5 seconds, 30 seconds, 5 minutes
        $retryDelays = [
            '5s' => 5000,
            '30s' => 30000,
            '5m' => 300000,
        ];

        foreach ($retryDelays as $name => $ttl) {
            $queueName = "analytics.events.retry.{$name}";

            $channel->queue_declare(
                $queueName,
                false,
                true,  // durable
                false,
                false,
                false,
                new \PhpAmqpLib\Wire\AMQPTable([
                    'x-message-ttl' => $ttl,
                    'x-dead-letter-exchange' => $exchange,
                    'x-dead-letter-routing-key' => 'events.raw'
                ])
            );

            log_message('info', "Created retry queue: {$queueName} with TTL {$ttl}ms");
        }
    }

    /**
     * Purge queue (useful for testing)
     */
    public function purgeQueue(string $queue): int
    {
        try {
            $channel = $this->getChannel();
            return $channel->queue_purge($queue);
        } catch (AMQPException $e) {
            log_message('error', "Failed to purge queue {$queue}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get queue message count
     */
    public function getQueueMessageCount(string $queue): int
    {
        try {
            $channel = $this->getChannel();
            $info = $channel->queue_declare($queue, true);
            return $info[1] ?? 0; // Message count
        } catch (AMQPException $e) {
            log_message('error', "Failed to get queue count: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Check health of RabbitMQ connection
     */
    public function healthCheck(): bool
    {
        try {
            $connection = $this->getConnection();
            $channel = $this->getChannel();
            
            return $connection->isConnected() && $channel->is_open();
        } catch (\Exception $e) {
            log_message('error', 'RabbitMQ health check failed: ' . $e->getMessage());
            return false;
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
