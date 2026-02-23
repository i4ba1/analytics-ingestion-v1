# High-Throughput Analytics Ingestion System

A production-ready, high-performance analytics event ingestion system built with PHP 8.2, CodeIgniter 4, RabbitMQ, Redis, and PostgreSQL.

## 🎯 Overview

This system is designed to handle **10,000+ events per second** with sub-100ms response times, providing real-time event collection, processing, and metrics aggregation.

### Architecture

```
                    ┌─────────────┐
                    │   Client    │
                    └──────┬──────┘
                           │
                           ▼
                    ┌─────────────┐
                    │  API Gateway│
                    │  (CodeIgniter)│
                    └──────┬──────┘
                           │
                           ▼
                    ┌─────────────┐
                    │  RabbitMQ   │
                    │  (Events)   │
                    └──────┬──────┘
                           │
                           ▼
                    ┌─────────────┐
                    │   Workers   │
                    │  (PHP CLI)  │
                    └──────┬──────┘
                           │
                    ┌──────┴──────┐
                    ▼             ▼
              ┌─────────┐   ┌─────────┐
              │  Redis  │   │PostgreSQL│
              │ (Cache) │   │ (Store)  │
              └─────────┘   └──────────┘
```

## 🚀 Quick Start

### Prerequisites

- Docker & Docker Compose
- PHP 8.2+ (for local development)
- Composer

### Using Docker (Recommended)

```bash
# Clone and navigate to project
cd analytics-ingestion-v1

# Build and start all services
docker-compose up -d

# Initialize RabbitMQ queues
docker-compose exec rabbitmq /setup-queues.sh

# Run database migrations
docker-compose exec api php spark migrate

# Check service health
curl http://localhost:8080/v1/health
```

### Manual Installation

```bash
# Install dependencies
composer install

# Copy environment configuration
cp env .env

# Update .env with your settings

# Run migrations
php spark migrate

# Start worker
php worker.php
```

## 📡 API Endpoints

### Event Ingestion

```bash
POST /v1/events
Content-Type: application/json
X-API-Key: your-api-key

{
  "events": [
    {
      "event_id": "550e8400-e29b-41d4-a716-446655440000",
      "type": "page_view",
      "timestamp": 1639567200,
      "user_id": "user_123",
      "session_id": "session_abc",
      "properties": {
        "url": "/products/123",
        "referrer": "https://google.com",
        "user_agent": "Mozilla/5.0..."
      }
    }
  ]
}
```

**Response:**
```json
{
  "status": "accepted",
  "accepted": 1,
  "rejected": 0,
  "correlation_id": "...",
  "processing_time_ms": 45
}
```

### Metrics Queries

```bash
# Get top pages (last hour)
GET /v1/metrics/top-pages?window=1h&limit=20

# Get top users (last 24 hours)
GET /v1/metrics/top-users?window=24h&limit=20

# Get event counts (date range)
GET /v1/metrics/count?type=page_view&from=2024-01-01&to=2024-01-31

# Daily statistics
GET /v1/metrics/daily-stats?date=2024-01-15
```

### Health & Monitoring

```bash
# Health check
GET /v1/health

# Detailed health
GET /v1/health/detailed

# Prometheus metrics
GET /v1/metrics
```

## 🗄️ Database Schema

### Metrics Tables

- `metrics_event_type_daily` - Daily event counts by type
- `metrics_page_daily` - Daily page view counts
- `metrics_user_daily` - Daily user activity
- `dead_letter_queue` - Failed event tracking

## 🔧 Configuration

### Environment Variables

```bash
# Application
ENVIRONMENT=production
app.baseURL=http://localhost:8080

# Database
database.default.DNSv4=pgsql:host=postgres;dbname=analytics
database.default.username=analytics
database.default.password=analytics_password

# Redis
redis.host=redis
redis.port=6379

# RabbitMQ
rabbitmq.host=rabbitmq
rabbitmq.port=5672
rabbitmq.user=analytics
rabbitmq.password=analytics_password
rabbitmq.vhost=/analytics

# API
api.key=your-secret-api-key-change-in-production
api.rateLimit.requests=1000
api.rateLimit.window=60
```

## 📊 Monitoring

### Access Monitoring Services

- **Grafana**: http://localhost:3001 (admin/admin)
- **Prometheus**: http://localhost:9090
- **RabbitMQ Management**: http://localhost:15672 (analytics/analytics_password)

### Key Metrics

- Request rate & latency
- Queue depth
- Worker processing rate
- API error rates
- Database/Redis connection health

## 🧪 Testing

### Load Testing with k6

```bash
k6 run tests/load/ingestion.js
```

### Unit Tests

```bash
phpunit tests/
```

## 🛠️ Operations

### Queue Management

```bash
# Check queue depth
docker-compose exec api php spark queue:depth analytics.events.raw

# Purge queue (use with caution)
docker-compose exec api php spark queue:purge analytics.events.raw
```

### Worker Management

```bash
# View worker logs
docker-compose logs -f worker

# Restart workers
docker-compose restart worker
```

### Database Maintenance

```bash
# Cleanup old data (older than 90 days)
docker-compose exec api php spark db:cleanup --days=90
```

## 📈 Performance Tuning

### API Service

- Increase worker processes in Supervisor
- Enable OPcache for PHP
- Use Redis persistent connections

### Worker Service

- Scale workers horizontally: `docker-compose up --scale worker=4`
- Tune prefetch count based on message size
- Monitor consumer lag

### Database

- Add indexes for common query patterns
- Partition tables by date
- Enable query caching

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests
5. Submit a pull request

## 📝 License

MIT License - see LICENSE file for details

## 📞 Support

For issues, questions, or contributions, please open an issue on GitHub.

## 🔄 Versioning

This project follows Semantic Versioning (SemVer).

Current version: **1.0.0**
