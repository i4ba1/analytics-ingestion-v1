<?php

namespace App\Libraries\Analytics\Database;

use App\Libraries\Analytics\Config\Config;
use App\Libraries\Analytics\Logging\Logger;

/**
 * Database Manager
 * 
 * Handles database operations for daily aggregates.
 * Uses PDO for database interactions.
 */
class DatabaseManager
{
    private static ?DatabaseManager $instance = null;
    private Config $config;
    private Logger $logger;
    private ?\PDO $connection = null;

    private function __construct()
    {
        $this->config = Config::getInstance();
        $this->logger = Logger::getInstance();
    }

    public static function getInstance(): DatabaseManager
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }

    /**
     * Get database connection
     */
    public function getConnection(): \PDO
    {
        if ($this->connection === null) {
            $this->connect();
        }
        
        return $this->connection;
    }

    /**
     * Establish database connection
     */
    private function connect(): void
    {
        try {
            $dsn = $this->config->get('database.dsn');
            $username = $this->config->get('database.username');
            $password = $this->config->get('database.password');

            $this->connection = new \PDO($dsn, $username, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_PERSISTENT => false,
            ]);

            $this->logger->info('Database connection established');

        } catch (\PDOException $e) {
            $this->logger->logException($e);
            throw $e;
        }
    }

    /**
     * Health check
     */
    public function healthCheck(): bool
    {
        try {
            $this->getConnection()->query('SELECT 1');
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Database health check failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Insert or update daily event type count
     */
    public function insertEventTypeDaily(string $date, string $eventType): void
    {
        try {
            $sql = "
                INSERT INTO metrics_event_type_daily (date, event_type, count)
                VALUES (:date, :event_type, 1)
                ON DUPLICATE KEY UPDATE
                    count = count + 1,
                    updated_at = CURRENT_TIMESTAMP
            ";

            $stmt = $this->getConnection()->prepare($sql);
            $stmt->execute([
                ':date' => $date,
                ':event_type' => $eventType,
            ]);

        } catch (\PDOException $e) {
            $this->logger->error('Failed to insert event type daily', [
                'date' => $date,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Insert or update daily page count
     */
    public function insertPageDaily(string $date, string $url): void
    {
        try {
            $sql = "
                INSERT INTO metrics_page_daily (date, url, count)
                VALUES (:date, :url, 1)
                ON DUPLICATE KEY UPDATE
                    count = count + 1,
                    updated_at = CURRENT_TIMESTAMP
            ";

            $stmt = $this->getConnection()->prepare($sql);
            $stmt->execute([
                ':date' => $date,
                ':url' => substr($url, 0, 500), // Limit URL length
            ]);

        } catch (\PDOException $e) {
            $this->logger->error('Failed to insert page daily', [
                'date' => $date,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Insert or update user activity
     */
    public function insertUserActivity(string $date, ?string $userId): void
    {
        if (!$userId) {
            return;
        }

        try {
            $sql = "
                INSERT INTO metrics_user_daily (date, user_id, event_count)
                VALUES (:date, :user_id, 1)
                ON DUPLICATE KEY UPDATE
                    event_count = event_count + 1,
                    last_active_at = CURRENT_TIMESTAMP
            ";

            $stmt = $this->getConnection()->prepare($sql);
            $stmt->execute([
                ':date' => $date,
                ':user_id' => substr($userId, 0, 255),
            ]);

        } catch (\PDOException $e) {
            $this->logger->error('Failed to insert user activity', [
                'date' => $date,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get top pages for a date range
     */
    public function getTopPages(string $fromDate, string $toDate, int $limit = 20): array
    {
        try {
            $sql = "
                SELECT url, SUM(count) as total_count
                FROM metrics_page_daily
                WHERE date BETWEEN :from_date AND :to_date
                GROUP BY url
                ORDER BY total_count DESC
                LIMIT :limit
            ";

            $stmt = $this->getConnection()->prepare($sql);
            $stmt->bindValue(':from_date', $fromDate);
            $stmt->bindValue(':to_date', $toDate);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll();

        } catch (\PDOException $e) {
            $this->logger->error('Failed to get top pages', [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get top users for a date range
     */
    public function getTopUsers(string $fromDate, string $toDate, int $limit = 20): array
    {
        try {
            $sql = "
                SELECT user_id, SUM(event_count) as total_count
                FROM metrics_user_daily
                WHERE date BETWEEN :from_date AND :to_date
                GROUP BY user_id
                ORDER BY total_count DESC
                LIMIT :limit
            ";

            $stmt = $this->getConnection()->prepare($sql);
            $stmt->bindValue(':from_date', $fromDate);
            $stmt->bindValue(':to_date', $toDate);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll();

        } catch (\PDOException $e) {
            $this->logger->error('Failed to get top users', [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get event type counts for a date range
     */
    public function getEventTypeCounts(?string $eventType, string $fromDate, string $toDate): array
    {
        try {
            if ($eventType) {
                $sql = "
                    SELECT date, event_type, count
                    FROM metrics_event_type_daily
                    WHERE date BETWEEN :from_date AND :to_date
                        AND event_type = :event_type
                    ORDER BY date ASC
                ";

                $stmt = $this->getConnection()->prepare($sql);
                $stmt->bindValue(':from_date', $fromDate);
                $stmt->bindValue(':to_date', $toDate);
                $stmt->bindValue(':event_type', $eventType);
                $stmt->execute();

            } else {
                $sql = "
                    SELECT date, event_type, count
                    FROM metrics_event_type_daily
                    WHERE date BETWEEN :from_date AND :to_date
                    ORDER BY date ASC
                ";

                $stmt = $this->getConnection()->prepare($sql);
                $stmt->bindValue(':from_date', $fromDate);
                $stmt->bindValue(':to_date', $toDate);
                $stmt->execute();
            }

            return $stmt->fetchAll();

        } catch (\PDOException $e) {
            $this->logger->error('Failed to get event type counts', [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get total event count for a date range
     */
    public function getTotalEventCount(?string $eventType, string $fromDate, string $toDate): int
    {
        try {
            if ($eventType) {
                $sql = "
                    SELECT COALESCE(SUM(count), 0) as total
                    FROM metrics_event_type_daily
                    WHERE date BETWEEN :from_date AND :to_date
                        AND event_type = :event_type
                ";

                $stmt = $this->getConnection()->prepare($sql);
                $stmt->bindValue(':from_date', $fromDate);
                $stmt->bindValue(':to_date', $toDate);
                $stmt->bindValue(':event_type', $eventType);
                $stmt->execute();

            } else {
                $sql = "
                    SELECT COALESCE(SUM(count), 0) as total
                    FROM metrics_event_type_daily
                    WHERE date BETWEEN :from_date AND :to_date
                ";

                $stmt = $this->getConnection()->prepare($sql);
                $stmt->bindValue(':from_date', $fromDate);
                $stmt->bindValue(':to_date', $toDate);
                $stmt->execute();
            }

            $result = $stmt->fetch();
            return (int) ($result['total'] ?? 0);

        } catch (\PDOException $e) {
            $this->logger->error('Failed to get total event count', [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Get daily statistics for a specific date
     */
    public function getDailyStats(string $date): array
    {
        try {
            // Total events
            $sql = "
                SELECT COALESCE(SUM(count), 0) as total_events
                FROM metrics_event_type_daily
                WHERE date = :date
            ";
            $stmt = $this->getConnection()->prepare($sql);
            $stmt->bindValue(':date', $date);
            $stmt->execute();
            $totalEvents = (int) ($stmt->fetch()['total_events'] ?? 0);

            // Unique users
            $sql = "
                SELECT COUNT(*) as unique_users
                FROM metrics_user_daily
                WHERE date = :date
            ";
            $stmt = $this->getConnection()->prepare($sql);
            $stmt->bindValue(':date', $date);
            $stmt->execute();
            $uniqueUsers = (int) ($stmt->fetch()['unique_users'] ?? 0);

            // Top event types
            $sql = "
                SELECT event_type, count
                FROM metrics_event_type_daily
                WHERE date = :date
                ORDER BY count DESC
                LIMIT 5
            ";
            $stmt = $this->getConnection()->prepare($sql);
            $stmt->bindValue(':date', $date);
            $stmt->execute();
            $topEventTypes = $stmt->fetchAll();

            // Top pages
            $sql = "
                SELECT url, count
                FROM metrics_page_daily
                WHERE date = :date
                ORDER BY count DESC
                LIMIT 5
            ";
            $stmt = $this->getConnection()->prepare($sql);
            $stmt->bindValue(':date', $date);
            $stmt->execute();
            $topPages = $stmt->fetchAll();

            return [
                'date' => $date,
                'total_events' => $totalEvents,
                'unique_users' => $uniqueUsers,
                'top_event_types' => $topEventTypes,
                'top_pages' => $topPages,
            ];

        } catch (\PDOException $e) {
            $this->logger->error('Failed to get daily stats', [
                'date' => $date,
                'error' => $e->getMessage(),
            ]);
            return [
                'date' => $date,
                'total_events' => 0,
                'unique_users' => 0,
                'top_event_types' => [],
                'top_pages' => [],
            ];
        }
    }
}
