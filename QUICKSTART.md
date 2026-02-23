# Quick Start Guide - Analytics Ingestion System

## Step 1: Environment Setup

```bash
# Navigate to project directory
cd C:\Users\user\PhpstormProjects\analytics-ingestion-v1

# Copy environment file
cp env.example .env

# Edit .env and update these values:
# - database credentials
# - Redis connection
# - RabbitMQ connection
# - API key
```

## Step 2: Install Dependencies

```bash
# Install Composer dependencies
composer install
```

## Step 3: Start Infrastructure Services

### Option A: Using Docker (Recommended)

```bash
# Build and start all services
docker-compose up -d

# Wait for services to be healthy (check with:)
docker-compose ps

# Initialize RabbitMQ queues
docker-compose exec rabbitmq sh -c "chmod +x /setup-queues.sh && /setup-queues.sh"

# Run database migrations
docker-compose exec api php spark migrate
```

### Option B: Manual Setup

Start the services manually:
- PostgreSQL on port 5432
- Redis on port 6379
- RabbitMQ on ports 5672 (AMQP) and 15672 (Management)

Then run:
```bash
# Create database
psql -U postgres -c "CREATE DATABASE analytics;"

# Run migrations
php spark migrate

# Start worker (in separate terminal)
php worker.php

# Start API (in separate terminal)
php spark serve
```

## Step 4: Verify Installation

```bash
# Check health endpoint
curl http://localhost:8080/v1/health

# Expected response:
# {
#   "status": "healthy",
#   "checks": {
#     "redis": true,
#     "database": true,
#     "rabbitmq": true
#   }
# }
```

## Step 5: Send Test Events

```bash
# Send a single event
curl -X POST http://localhost:8080/v1/events \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your-secret-api-key-change-in-production" \
  -d '{
    "events": [
      {
        "event_id": "550e8400-e29b-41d4-a716-446655440000",
        "type": "page_view",
        "timestamp": 1639567200,
        "user_id": "user_123",
        "session_id": "session_abc",
        "properties": {
          "url": "/products/123",
          "referrer": "https://google.com"
        }
      }
    ]
  }'

# Expected response:
# {
#   "status": "accepted",
#   "accepted": 1,
#   "rejected": 0,
#   "correlation_id": "...",
#   "processing_time_ms": 45
# }
```

## Step 6: Send Batch Events

```bash
# Send multiple events
curl -X POST http://localhost:8080/v1/events \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your-secret-api-key-change-in-production" \
  -d @- << 'EOF'
{
  "events": [
    {
      "event_id": "550e8400-e29b-41d4-a716-446655440001",
      "type": "page_view",
      "timestamp": 1639567200,
      "user_id": "user_123",
      "properties": {"url": "/home"}
    },
    {
      "event_id": "550e8400-e29b-41d4-a716-446655440002",
      "type": "page_view",
      "timestamp": 1639567201,
      "user_id": "user_456",
      "properties": {"url": "/products"}
    },
    {
      "event_id": "550e8400-e29b-41d4-a716-446655440003",
      "type": "button_click",
      "timestamp": 1639567202,
      "user_id": "user_123",
      "properties": {"button_id": "add-to-cart"}
    }
  ]
}
EOF
```

## Step 7: Query Metrics

```bash
# Get top pages (last hour)
curl http://localhost:8080/v1/metrics/top-pages?window=1h&limit=10

# Get top users
curl http://localhost:8080/v1/metrics/top-users?window=1h&limit=10

# Get event counts
curl http://localhost:8080/v1/metrics/count?type=page_view&from=2024-01-01&to=2024-12-31
```

## Step 8: Check Monitoring

```bash
# Grafana Dashboards
open http://localhost:3001
# Login: admin/admin

# Prometheus
open http://localhost:9090

# RabbitMQ Management
open http://localhost:15672
# Login: analytics/analytics_password
```

## Step 9: View Logs

```bash
# API logs
docker-compose logs -f api

# Worker logs
docker-compose logs -f worker

# All logs
docker-compose logs -f
```

## Troubleshooting

### Issue: Port Already in Use

```bash
# Check what's using the port
netstat -ano | findstr :8080

# Change port in docker-compose.yml
ports:
  - "8081:8080"  # Use 8081 instead
```

### Issue: Database Connection Failed

```bash
# Check PostgreSQL is running
docker-compose ps postgres

# View database logs
docker-compose logs postgres

# Restart database
docker-compose restart postgres
```

### Issue: RabbitMQ Connection Failed

```bash
# Check RabbitMQ is running
docker-compose ps rabbitmq

# Reinitialize queues
docker-compose exec rabbitmq /setup-queues.sh
```

### Issue: Workers Not Processing

```bash
# Check worker status
docker-compose ps worker

# View worker logs
docker-compose logs worker

# Restart workers
docker-compose restart worker

# Check queue depth
curl http://localhost:15672/api/queues
```

## Load Testing

```bash
# Install k6
choco install k6

# Run load test
k6 run --vus 10 --duration 30s tests/load/ingestion.js
```

## Next Steps

1. **Customize Configuration**: Update `.env` with production settings
2. **Add Authentication**: Implement OAuth/JWT if needed
3. **Set Up Alerts**: Configure Prometheus alerting rules
4. **Scale Workers**: Adjust replicas in `docker-compose.yml`
5. **Add Monitoring**: Integrate with your monitoring stack
6. **Deploy**: Use Docker Swarm or Kubernetes for production

## Support

For issues or questions, check:
- Logs in `writable/logs/`
- Docker logs: `docker-compose logs`
- Health endpoint: `http://localhost:8080/v1/health`
