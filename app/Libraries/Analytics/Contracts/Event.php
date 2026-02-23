<?php

namespace App\Libraries\Analytics\Contracts;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\InvalidUuidStringException;
use App\Libraries\Analytics\Config\Config;

/**
 * Event Contract & Validator
 * 
 * Enforces the analytics event schema with versioning support.
 * All events must conform to this contract for processing.
 */
class Event
{
    private array $data = [];
    private array $errors = [];
    private Config $config;

    // Current schema version - MUST be bumped on breaking changes
    public const SCHEMA_VERSION = 1;

    // Required event fields
    private const REQUIRED_FIELDS = [
        'event_id',
        'type',
        'timestamp',
        'user_id',
        'properties',
    ];

    /**
     * Create event from array
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * Create event from JSON string
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid JSON: ' . json_last_error_msg());
        }

        return new self($data);
    }

    public function __construct(array $data)
    {
        $this->config = Config::getInstance();
        $this->data = $data;
        
        // Add received_at if not present
        if (!isset($this->data['received_at'])) {
            $this->data['received_at'] = time();
        }

        // Set schema version
        $this->data['schema_version'] = self::SCHEMA_VERSION;
    }

    /**
     * Validate event against contract
     */
    public function validate(): bool
    {
        $this->errors = [];

        // Check required fields
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!isset($this->data[$field])) {
                $this->errors[] = "Missing required field: {$field}";
            }
        }

        if (!empty($this->errors)) {
            return false;
        }

        // Validate event_id (UUID)
        try {
            if (!Uuid::isValid($this->data['event_id'])) {
                $this->errors[] = "Invalid event_id: must be a valid UUID";
            }
        } catch (InvalidUuidStringException $e) {
            $this->errors[] = "Invalid event_id: " . $e->getMessage();
        }

        // Validate type (allowed list)
        if (!$this->isAllowedEventType($this->data['type'])) {
            $allowed = implode(', ', $this->config->get('event.allowedTypes', []));
            $this->errors[] = "Invalid event type: {$this->data['type']}. Allowed types: {$allowed}";
        }

        // Validate timestamp
        if (!$this->isValidTimestamp($this->data['timestamp'])) {
            $this->errors[] = "Invalid timestamp: must be unix timestamp or ISO8601";
        }

        // Validate user_id
        if (empty($this->data['user_id']) || !is_string($this->data['user_id'])) {
            $this->errors[] = "Invalid user_id: must be a non-empty string";
        }

        // Validate session_id (optional)
        if (isset($this->data['session_id']) && !is_string($this->data['session_id'])) {
            $this->errors[] = "Invalid session_id: must be a string";
        }

        // Validate properties (must be object/array)
        if (!is_array($this->data['properties'])) {
            $this->errors[] = "Invalid properties: must be an object";
        }

        return empty($this->errors);
    }

    /**
     * Check if event type is allowed
     */
    private function isAllowedEventType(string $type): bool
    {
        $allowed = $this->config->get('event.allowedTypes', []);
        return in_array($type, $allowed, true);
    }

    /**
     * Validate timestamp format
     */
    private function isValidTimestamp($timestamp): bool
    {
        // Unix timestamp (integer)
        if (is_int($timestamp)) {
            // Reasonable range: 2000-01-01 to 2100-01-01
            return $timestamp >= 946684800 && $timestamp <= 4102444800;
        }

        // ISO8601 string
        if (is_string($timestamp)) {
            $datetime = \DateTime::createFromFormat(\DateTime::ATOM, $timestamp);
            return $datetime !== false;
        }

        return false;
    }

    /**
     * Get validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Check if event is valid
     */
    public function isValid(): bool
    {
        return $this->validate();
    }

    /**
     * Get event data as array
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Get event as JSON
     */
    public function toJson(): string
    {
        return json_encode($this->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Magic getter for event properties
     */
    public function __get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    /**
     * Magic isset check
     */
    public function __isset(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /**
     * Get event_id
     */
    public function getEventId(): string
    {
        return $this->data['event_id'];
    }

    /**
     * Get event type
     */
    public function getType(): string
    {
        return $this->data['type'];
    }

    /**
     * Get user_id
     */
    public function getUserId(): string
    {
        return $this->data['user_id'];
    }

    /**
     * Get timestamp (normalized to unix timestamp)
     */
    public function getTimestamp(): int
    {
        $timestamp = $this->data['timestamp'];
        
        // Convert ISO8601 to unix timestamp if needed
        if (is_string($timestamp)) {
            $datetime = \DateTime::createFromFormat(\DateTime::ATOM, $timestamp);
            return $datetime ? $datetime->getTimestamp() : 0;
        }

        return (int) $timestamp;
    }

    /**
     * Get received_at timestamp
     */
    public function getReceivedAt(): int
    {
        return (int) ($this->data['received_at'] ?? time());
    }

    /**
     * Get properties
     */
    public function getProperties(): array
    {
        return $this->data['properties'] ?? [];
    }

    /**
     * Get schema version
     */
    public function getSchemaVersion(): int
    {
        return (int) ($this->data['schema_version'] ?? self::SCHEMA_VERSION);
    }

    /**
     * Get session_id (optional)
     */
    public function getSessionId(): ?string
    {
        return $this->data['session_id'] ?? null;
    }

    /**
     * Get URL from properties (if available)
     */
    public function getUrl(): ?string
    {
        return $this->data['properties']['url'] ?? null;
    }

    /**
     * Create a batch of events from array
     */
    public static function createBatch(array $eventsData): array
    {
        $events = [];
        
        foreach ($eventsData as $eventData) {
            try {
                $event = new self($eventData);
                if ($event->validate()) {
                    $events[] = $event;
                }
            } catch (\Exception $e) {
                // Skip invalid events - they'll be counted in response
                continue;
            }
        }

        return $events;
    }

    /**
     * Validate batch of events
     * 
     * @return array ['valid' => Event[], 'invalid' => array[]]
     */
    public static function validateBatch(array $eventsData): array
    {
        $valid = [];
        $invalid = [];

        foreach ($eventsData as $index => $eventData) {
            try {
                $event = new self($eventData);
                if ($event->validate()) {
                    $valid[] = $event;
                } else {
                    $invalid[] = [
                        'index' => $index,
                        'event_id' => $eventData['event_id'] ?? 'unknown',
                        'errors' => $event->getErrors(),
                    ];
                }
            } catch (\Exception $e) {
                $invalid[] = [
                    'index' => $index,
                    'event_id' => $eventData['event_id'] ?? 'unknown',
                    'errors' => [$e->getMessage()],
                ];
            }
        }

        return [
            'valid' => $valid,
            'invalid' => $invalid,
        ];
    }
}
