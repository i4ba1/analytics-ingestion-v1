# Analytics Ingestion System - Test Script (PowerShell)
# This script tests all major components of the system

$ErrorActionPreference = "Continue"

$BaseUrl = if ($env:BASE_URL) { $env:BASE_URL } else { "http://localhost:8080" }
$ApiKey = if ($env:API_KEY) { $env:API_KEY } else { "your-secret-api-key-change-in-production" }

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Analytics Ingestion System - Test Suite" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Base URL: $BaseUrl"
Write-Host ""

# Test counters
$TestsPassed = 0
$TestsFailed = 0

function Test-Component {
    param(
        [string]$Name,
        [scriptblock]$Script
    )
    
    try {
        $result = & $Script
        if ($result) {
            Write-Host "✓ PASS: $Name" -ForegroundColor Green
            $script:TestsPassed++
        } else {
            Write-Host "✗ FAIL: $Name" -ForegroundColor Red
            $script:TestsFailed++
        }
    } catch {
        Write-Host "✗ FAIL: $Name - $($_.Exception.Message)" -ForegroundColor Red
        $script:TestsFailed++
    }
}

Write-Host "1. Testing Health Check..." -ForegroundColor Yellow
try {
    $response = Invoke-RestMethod -Uri "$BaseUrl/v1/health" -Method Get
    $isHealthy = $response.status -eq "healthy"
    Test-Component "Health check endpoint" { $isHealthy }
    Test-Component "Database connection" { $response.checks.database -eq $true }
    Test-Component "Redis connection" { $response.checks.redis -eq $true }
    Test-Component "RabbitMQ connection" { $response.checks.rabbitmq -eq $true }
} catch {
    Write-Host "✗ FAIL: Health check - $($_.Exception.Message)" -ForegroundColor Red
    $TestsFailed += 4
}
Write-Host ""

Write-Host "2. Testing Event Ingestion (Single Event)..." -ForegroundColor Yellow
try {
    $timestamp = [DateTimeOffset]::Now.ToUnixTimeSeconds()
    $body = @{
        events = @(
            @{
                event_id = "550e8400-e29b-41d4-a716-446655440001"
                type = "page_view"
                timestamp = $timestamp
                user_id = "test_user_1"
                session_id = "test_session_1"
                properties = @{
                    url = "/test/page/1"
                    referrer = "https://example.com"
                }
            }
        )
    } | ConvertTo-Json -Depth 10

    $response = Invoke-RestMethod -Uri "$BaseUrl/v1/events" `
        -Method Post `
        -ContentType "application/json" `
        -Headers @{ "X-API-Key" = $ApiKey } `
        -Body $body

    Test-Component "Event ingestion returns 202" { $response.status -eq "accepted" }
    Test-Component "Event has accepted field" { $response.accepted -gt 0 }
} catch {
    Write-Host "✗ FAIL: Event ingestion - $($_.Exception.Message)" -ForegroundColor Red
    $TestsFailed += 2
}
Write-Host ""

Write-Host "3. Testing Event Ingestion (Batch Events)..." -ForegroundColor Yellow
try {
    $timestamp = [DateTimeOffset]::Now.ToUnixTimeSeconds()
    $body = @{
        events = @(
            @{
                event_id = "550e8400-e29b-41d4-a716-446655440002"
                type = "page_view"
                timestamp = $timestamp
                user_id = "test_user_2"
                properties = @{ url = "/page2" }
            },
            @{
                event_id = "550e8400-e29b-41d4-a716-446655440003"
                type = "button_click"
                timestamp = $timestamp
                user_id = "test_user_1"
                properties = @{ button_id = "test-button" }
            }
        )
    } | ConvertTo-Json -Depth 10

    $response = Invoke-RestMethod -Uri "$BaseUrl/v1/events" `
        -Method Post `
        -ContentType "application/json" `
        -Headers @{ "X-API-Key" = $ApiKey } `
        -Body $body

    Test-Component "Batch event ingestion" { $response.accepted -eq 2 }
} catch {
    Write-Host "✗ FAIL: Batch ingestion - $($_.Exception.Message)" -ForegroundColor Red
    $TestsFailed++
}
Write-Host ""

Write-Host "4. Testing Authentication..." -ForegroundColor Yellow
try {
    $body = @{ events = @() } | ConvertTo-Json
    
    try {
        $response = Invoke-RestMethod -Uri "$BaseUrl/v1/events" `
            -Method Post `
            -ContentType "application/json" `
            -Headers @{ "X-API-Key" = "invalid-key" } `
            -Body $body `
            -ErrorAction Stop
        Test-Component "Invalid API key rejected" { $false }
    } catch {
        Test-Component "Invalid API key returns error" { $_.Exception.Response.StatusCode -eq 401 }
    }
} catch {
    Write-Host "✗ FAIL: Authentication test failed" -ForegroundColor Red
    $TestsFailed++
}
Write-Host ""

Write-Host "5. Testing Event Validation..." -ForegroundColor Yellow
try {
    $body = @{
        events = @(
            @{
                event_id = "invalid-id"
                type = "invalid_type"
                timestamp = [DateTimeOffset]::Now.ToUnixTimeSeconds()
                user_id = "test_user"
            }
        )
    } | ConvertTo-Json -Depth 10

    $response = Invoke-RestMethod -Uri "$BaseUrl/v1/events" `
        -Method Post `
        -ContentType "application/json" `
        -Headers @{ "X-API-Key" = $ApiKey } `
        -Body $body

    Test-Component "Invalid events rejected" { $response.rejected -gt 0 }
} catch {
    Write-Host "✗ FAIL: Validation test - $($_.Exception.Message)" -ForegroundColor Red
    $TestsFailed++
}
Write-Host ""

Write-Host "6. Testing Metrics Endpoints..." -ForegroundColor Yellow
try {
    $response = Invoke-RestMethod -Uri "$BaseUrl/v1/metrics/top-pages?window=1h&limit=10" -Method Get
    Test-Component "Top pages endpoint" { $response.PSObject.Properties.Name -contains "pages" }

    $response = Invoke-RestMethod -Uri "$BaseUrl/v1/metrics/top-users?window=1h&limit=10" -Method Get
    Test-Component "Top users endpoint" { $response.PSObject.Properties.Name -contains "users" }

    $response = Invoke-RestMethod -Uri "$BaseUrl/v1/metrics/count?from=2024-01-01&to=2024-12-31" -Method Get
    Test-Component "Event counts endpoint" { $response.PSObject.Properties.Name -contains "total_count" }
} catch {
    Write-Host "✗ FAIL: Metrics endpoints - $($_.Exception.Message)" -ForegroundColor Red
    $TestsFailed += 3
}
Write-Host ""

Write-Host "7. Testing Rate Limiting..." -ForegroundColor Yellow
Write-Host "Sending 5 rapid requests..."
try {
    $timestamp = [DateTimeOffset]::Now.ToUnixTimeSeconds()
    for ($i = 1; $i -le 5; $i++) {
        $body = @{
            events = @(
                @{
                    event_id = "rate_limit_test_$i"
                    type = "page_view"
                    timestamp = $timestamp
                    user_id = "test_user"
                }
            )
        } | ConvertTo-Json -Depth 10

        Invoke-RestMethod -Uri "$BaseUrl/v1/events" `
            -Method Post `
            -ContentType "application/json" `
            -Headers @{ "X-API-Key" = $ApiKey } `
            -Body $body `
            -ErrorAction SilentlyContinue | Out-Null
    }
    Write-Host "Rate limiting test completed" -ForegroundColor Green
} catch {
    Write-Host "Rate limiting test completed with potential rate limit"
}
Write-Host ""

# Summary
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Test Summary" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Passed: $TestsPassed" -ForegroundColor Green
Write-Host "Failed: $TestsFailed" -ForegroundColor Red
Write-Host ""

if ($TestsFailed -eq 0) {
    Write-Host "All tests passed!" -ForegroundColor Green
    exit 0
} else {
    Write-Host "Some tests failed!" -ForegroundColor Red
    exit 1
}
