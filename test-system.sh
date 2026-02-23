#!/bin/bash

# Analytics Ingestion System - Test Script
# This script tests all major components of the system

set -e

BASE_URL="${BASE_URL:-http://localhost:8080}"
API_KEY="${API_KEY:-your-secret-api-key-change-in-production}"

echo "=========================================="
echo "Analytics Ingestion System - Test Suite"
echo "=========================================="
echo "Base URL: $BASE_URL"
echo ""

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Test counter
TESTS_PASSED=0
TESTS_FAILED=0

# Function to print test result
print_result() {
    if [ $1 -eq 0 ]; then
        echo -e "${GREEN}✓ PASS${NC}: $2"
        ((TESTS_PASSED++))
    else
        echo -e "${RED}✗ FAIL${NC}: $2"
        ((TESTS_FAILED++))
    fi
}

echo "1. Testing Health Check..."
HEALTH_RESPONSE=$(curl -s -w "\n%{http_code}" "$BASE_URL/v1/health" -o /tmp/health_response.txt)
HEALTH_CODE=$(tail -n1 /tmp/health_response.txt)
cat /tmp/health_response.txt | grep -q "healthy"
print_result $? "Health check endpoint"
echo ""

echo "2. Testing Database Connection..."
cat /tmp/health_response.txt | grep -q '"database":true'
print_result $? "Database connection"
echo ""

echo "3. Testing Redis Connection..."
cat /tmp/health_response.txt | grep -q '"redis":true'
print_result $? "Redis connection"
echo ""

echo "4. Testing RabbitMQ Connection..."
cat /tmp/health_response.txt | grep -q '"rabbitmq":true'
print_result $? "RabbitMQ connection"
echo ""

echo "5. Testing Event Ingestion (Single Event)..."
EVENT_RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL/v1/events" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: $API_KEY" \
  -d '{
    "events": [
      {
        "event_id": "550e8400-e29b-41d4-a716-446655440001",
        "type": "page_view",
        "timestamp": '$(date +%s)',
        "user_id": "test_user_1",
        "session_id": "test_session_1",
        "properties": {
          "url": "/test/page/1",
          "referrer": "https://example.com"
        }
      }
    ]
  }' -o /tmp/event_response.txt)

EVENT_CODE=$(tail -n1 /tmp/event_response.txt)
[ "$EVENT_CODE" = "202" ]
print_result $? "Event ingestion returns 202 Accepted"

cat /tmp/event_response.txt | grep -q "accepted"
print_result $? "Event ingestion response contains 'accepted'"
echo ""

echo "6. Testing Event Ingestion (Batch Events)..."
BATCH_RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL/v1/events" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: $API_KEY" \
  -d '{
    "events": [
      {
        "event_id": "550e8400-e29b-41d4-a716-446655440002",
        "type": "page_view",
        "timestamp": '$(date +%s)',
        "user_id": "test_user_2",
        "properties": {"url": "/page2"}
      },
      {
        "event_id": "550e8400-e29b-41d4-a716-446655440003",
        "type": "button_click",
        "timestamp": '$(date +%s)',
        "user_id": "test_user_1",
        "properties": {"button_id": "test-button"}
      }
    ]
  }' -o /tmp/batch_response.txt)

BATCH_CODE=$(tail -n1 /tmp/batch_response.txt)
[ "$BATCH_CODE" = "202" ]
print_result $? "Batch event ingestion"
echo ""

echo "7. Testing Invalid API Key..."
UNAUTH_RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL/v1/events" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: invalid-key" \
  -d '{"events": []}' -o /tmp/unauth_response.txt)

UNAUTH_CODE=$(tail -n1 /tmp/unauth_response.txt)
[ "$UNAUTH_CODE" = "401" ]
print_result $? "Invalid API key returns 401"
echo ""

echo "8. Testing Invalid Event Validation..."
INVALID_RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL/v1/events" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: $API_KEY" \
  -d '{
    "events": [
      {
        "event_id": "invalid-id",
        "type": "invalid_type",
        "timestamp": '$(date +%s)',
        "user_id": "test_user"
      }
    ]
  }' -o /tmp/invalid_response.txt)

cat /tmp/invalid_response.txt | grep -q "rejected"
print_result $? "Invalid events are rejected"
echo ""

echo "9. Testing Metrics Endpoints..."
METRICS_RESPONSE=$(curl -s "$BASE_URL/v1/metrics/top-pages?window=1h&limit=10")
echo "$METRICS_RESPONSE" | grep -q "pages"
print_result $? "Top pages endpoint"

METRICS_RESPONSE=$(curl -s "$BASE_URL/v1/metrics/top-users?window=1h&limit=10")
echo "$METRICS_RESPONSE" | grep -q "users"
print_result $? "Top users endpoint"

METRICS_RESPONSE=$(curl -s "$BASE_URL/v1/metrics/count?from=2024-01-01&to=2024-12-31")
echo "$METRICS_RESPONSE" | grep -q "total_count"
print_result $? "Event counts endpoint"
echo ""

echo "10. Testing Rate Limiting..."
echo "Sending 5 rapid requests..."
for i in {1..5}; do
  curl -s -X POST "$BASE_URL/v1/events" \
    -H "Content-Type: application/json" \
    -H "X-API-Key: $API_KEY" \
    -d '{"events": [{"event_id": "rate_limit_test_'$i'", "type": "page_view", "timestamp": '$(date +%s)', "user_id": "test"}]' \
    > /dev/null
done
echo "Rate limiting test completed (check if any requests were blocked)"
echo ""

# Summary
echo "=========================================="
echo "Test Summary"
echo "=========================================="
echo -e "${GREEN}Passed: $TESTS_PASSED${NC}"
echo -e "${RED}Failed: $TESTS_FAILED${NC}"
echo ""

if [ $TESTS_FAILED -eq 0 ]; then
    echo -e "${GREEN}All tests passed!${NC}"
    exit 0
else
    echo -e "${RED}Some tests failed!${NC}"
    exit 1
fi
