<?php

namespace Analytics\Logging;

/**
 * Structured JSON Logger
 * 
 * Provides JSON-structured logging with correlation tracking
 * for distributed tracing and observability.
 */
class Logger
{
    private static ?Logger $instance = null;
    private string $logPath;
    private string $minLevel = 'info';
    private array $logLevels = [
        'debug' => 0,
        'info' => 1,
        'notice' => 2,
        'warning' => 3,
        'error' => 4,
        'critical' => 5,
        'alert' => 6,
        'emergency' => 7,
    ];

    private function __construct()
    {
        $this->logPath = WRITEPATH . 'logs/analytics.log';
        
        // Ensure log directory exists
        if (!is_dir(dirname($this->logPath))) {
            mkdir(dirname($this->logPath), 0755, true);
        }
    }

    public static function getInstance(): Logger
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }

    /**
     * Log a debug message
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * Log an info message
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * Log a notice message
     */
    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    /**
     * Log a warning message
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * Log an error message
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * Log a critical message
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    /**
     * Log an API request
     */
    public function logApiRequest(string $method, string $endpoint, array $context = []): void
    {
        $this->info('API Request', array_merge([
            'type' => 'api_request',
            'method' => $method,
            'endpoint' => $endpoint,
            'timestamp' => microtime(true),
        ], $context));
    }

    /**
     * Log an API response
     */
    public function logApiResponse(string $method, string $endpoint, int $statusCode, array $context = []): void
    {
        $this->info('API Response', array_merge([
            'type' => 'api_response',
            'method' => $method,
            'endpoint' => $endpoint,
            'status_code' => $statusCode,
        ], $context));
    }

    /**
     * Log a worker message
     */
    public function logWorkerMessage(string $queue, ?int $deliveryTag, array $context = []): void
    {
        $this->debug('Worker processing', array_merge([
            'type' => 'worker_message',
            'queue' => $queue,
            'delivery_tag' => $deliveryTag,
        ], $context));
    }

    /**
     * Log an exception
     */
    public function logException(\Throwable $exception, array $context = []): void
    {
        $this->error('Exception caught', array_merge([
            'type' => 'exception',
            'exception_class' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ], $context));
    }

    /**
     * Core logging method
     */
    private function log(string $level, string $message, array $context = []): void
    {
        // Check if level should be logged
        if (!isset($this->logLevels[$level]) || 
            $this->logLevels[$level] < $this->logLevels[$this->minLevel]) {
            return;
        }

        $logEntry = [
            'timestamp' => date('Y-m-d\TH:i:s.vP'),
            'level' => strtoupper($level),
            'message' => $message,
            'context' => $context,
            'pid' => getmypid(),
            'hostname' => gethostname(),
        ];

        $logLine = json_encode($logEntry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($logLine === false) {
            $logLine = json_encode([
                'timestamp' => date('Y-m-d\TH:i:s.vP'),
                'level' => 'ERROR',
                'message' => 'Failed to encode log message',
                'original_message' => $message,
            ]);
        }

        // Write to log file
        file_put_contents($this->logPath, $logLine . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
