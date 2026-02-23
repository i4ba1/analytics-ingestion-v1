# Directory Restructuring Plan

## Current Structure (Not Recommended)
```
analytics-ingestion-v1/
├── Controllers/          ← WRONG LOCATION
├── Libraries/            ← WRONG LOCATION
├── Migrations/           ← WRONG LOCATION
├── Infra/                ← OK for infrastructure
├── Services/             ← WRONG LOCATION
└── app/                  ← CI4 Standard
    ├── Controllers/
    ├── Libraries/
    └── Database/
```

## Recommended Structure (CI4 Compliant)
```
analytics-ingestion-v1/
├── app/
│   ├── Controllers/
│   │   ├── Analytics/                    ← NEW: Namespace your controllers
│   │   │   ├── Events.php
│   │   │   ├── Metrics.php
│   │   │   └── Health.php
│   │   ├── Home.php                      ← CI4 Default
│   │   └── BaseController.php
│   │
│   ├── Libraries/
│   │   └── Analytics/                    ← Your libraries here
│   │       ├── Config.php
│   │       ├── Contracts/
│   │       ├── Queue/
│   │       ├── Cache/
│   │       ├── Database/
│   │       └── Logging/
│   │
│   ├── Database/
│   │   └── Migrations/
│   │       └── 2026-02-23-CreateAnalyticsTables.php
│   │
│   └── Services/                          ← Worker services here
│       └── Workers/
│           └── AnalyticsWorker.php
│
├── infra/                                 ← Infrastructure stays at root
│   └── docker/
│       ├── Dockerfile.api
│       ├── Dockerfile.worker
│       └── ...
│
├── public/
├── writable/
├── vendor/
├── spark
├── composer.json
├── docker-compose.yml
└── README.md
```

## Migration Steps

### Step 1: Move Controllers
```bash
# From root Controllers/ to app/Controllers/Analytics/
mkdir -p app/Controllers/Analytics
mv Controllers/Events.php app/Controllers/Analytics/
mv Controllers/Metrics.php app/Controllers/Analytics/
mv Controllers/Health.php app/Controllers/Analytics/

# Update namespaces in files from:
# namespace Analytics\Controllers;
# To:
# namespace App\Controllers\Analytics;
```

### Step 2: Move Libraries
```bash
# Already in correct location! Libraries/Analytics/* → app/Libraries/Analytics/*
# Just verify autoloading in composer.json
```

### Step 3: Move Migrations
```bash
# From root Migrations/ to app/Database/Migrations/
mv Migrations/* app/Database/Migrations/
```

### Step 4: Move Worker Services
```bash
# Create app/Services/Workers/
mkdir -p app/Services/Workers
mv Services/Worker/* app/Services/Workers/
mv worker.php app/Services/Workers/analytics.php
```

### Step 5: Update Composer Autoloader
```json
{
    "autoload": {
        "psr-4": {
            "App\\": "app/",
            "Analytics\\": "app/Libraries/Analytics/"
        }
    }
}
```

### Step 6: Update Routes
```php
// app/Config/Routes.php
$routes->group('v1', ['namespace' => 'App\Controllers\Analytics'], static function ($routes) {
    $routes->post('events', 'Events::create');
    $routes->get('metrics/top-pages', 'Metrics::topPages');
    // etc...
});
```

### Step 7: Update Docker Paths
```yaml
# docker-compose.yml
volumes:
  - ./app/Libraries:/app/app/Libraries
  - ./app/Services:/app/app/Services
```

## Benefits of This Structure

✅ **CI4 Compliant** - Follows framework conventions
✅ **Autoloading Works** - PSR-4 works correctly
✅ **Easy to Understand** - Developers know where things are
✅ **Future-Proof** - Compatible with CI4 updates
✅ **Professional** - Industry-standard structure

## Alternative: Keep as-is (Not Recommended)

If you MUST keep the current structure, you need to:

1. **Update composer.json** to map namespaces:
```json
{
    "autoload-dev": {
        "psr-4": {
            "Analytics\\Controllers\\": "Controllers/",
            "Analytics\\Services\\": "Services/",
            "Analytics\\Database\\": "Migrations/"
        }
    }
}
```

2. **Update .gitignore** to exclude duplicate folders
3. **Document** why you're breaking conventions
4. **Accept** that CI4 tools won't work properly

## Recommendation

**RESTRUCTURE NOW** before it becomes harder to fix. The earlier you do it, the easier it is.
