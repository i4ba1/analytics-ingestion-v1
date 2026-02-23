#!/bin/bash

# RabbitMQ Queue Setup Script
# This script configures the RabbitMQ topology for the analytics system

set -e

echo "Waiting for RabbitMQ to start..."
sleep 5

# Enable management and plugins
rabbitmq-plugins enable rabbitmq_management
rabbitmq-plugins enable rabbitmq_federation
rabbitmq-plugins enable rabbitmq_shovel
rabbitmq-plugins enable rabbitmq_prometheus

echo "Setting up RabbitMQ queues..."

# Main exchange (topic type for flexibility)
rabbitmqadmin declare exchange name=analytics.events type=topic durable=true

# Dead-letter exchange
rabbitmqadmin declare exchange name=analytics.events.dlx type=direct durable=true

# Main queue with dead-letter exchange
rabbitmqadmin declare queue name=analytics.events.raw durable=true arguments='{"x-dead-letter-exchange":"analytics.events.dlx","x-dead-letter-routing-key":"events.raw.dlq"}'

# Dead-letter queue
rabbitmqadmin declare queue name=analytics.events.dlq durable=true

# Bind main queue to exchange
rabbitmqadmin declare binding source=analytics.events destination=analytics.events.raw routing_key=events.raw

# Bind DLQ to DLX
rabbitmqadmin declare binding source=analytics.events.dlx destination=analytics.events.dlq routing_key=events.raw.dlq

# Setup retry queues with TTL
echo "Setting up retry queues..."

# 5 second retry queue
rabbitmqadmin declare queue name=analytics.events.retry.5s durable=true arguments='{"x-message-ttl":5000,"x-dead-letter-exchange":"analytics.events","x-dead-letter-routing-key":"events.raw"}'

# 30 second retry queue
rabbitmqadmin declare queue name=analytics.events.retry.30s durable=true arguments='{"x-message-ttl":30000,"x-dead-letter-exchange":"analytics.events","x-dead-letter-routing-key":"events.raw"}'

# 5 minute retry queue
rabbitmqadmin declare queue name=analytics.events.retry.5m durable=true arguments='{"x-message-ttl":300000,"x-dead-letter-exchange":"analytics.events","x-dead-letter-routing-key":"events.raw"}'

echo "RabbitMQ topology setup complete!"
