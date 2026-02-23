# Project Restructure Script - CI4 Standards
# This script restructures the project to follow CodeIgniter 4 conventions

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Restructuring to CI4 Standards" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Step 1: Move Libraries to app/
Write-Host "Step 1: Moving Libraries/Analytics to app/Libraries/..." -ForegroundColor Yellow
if (Test-Path "Libraries/Analytics") {
    if (-not (Test-Path "app/Libraries")) {
        New-Item -ItemType Directory -Path "app/Libraries" -Force | Out-Null
    }
    Move-Item -Path "Libraries/Analytics" -Destination "app/Libraries/Analytics" -Force
    Write-Host "✓ Moved Libraries/Analytics to app/Libraries/Analytics" -ForegroundColor Green
} else {
    Write-Host "✓ Libraries/Analytics already in correct location" -ForegroundColor Green
}
Write-Host ""

# Step 2: Move Worker Services to app/
Write-Host "Step 2: Moving Services/Worker to app/Services/Workers..." -ForegroundColor Yellow
if (Test-Path "Services/Worker") {
    if (-not (Test-Path "app/Services")) {
        New-Item -ItemType Directory -Path "app/Services" -Force | Out-Null
    }
    Move-Item -Path "Services/Worker" -Destination "app/Services/Workers" -Force
    Write-Host "✓ Moved Services/Worker to app/Services/Workers" -ForegroundColor Green
} else {
    Write-Host "✓ Services/Worker already in correct location" -ForegroundColor Green
}

# Move worker.php if it exists
if (Test-Path "worker.php") {
    Move-Item -Path "worker.php" -Destination "app/Services/Workers/worker.php" -Force
    Write-Host "✓ Moved worker.php to app/Services/Workers/worker.php" -ForegroundColor Green
}
Write-Host ""

# Step 3: Remove empty root-level folders
Write-Host "Step 3: Cleaning up empty root-level folders..." -ForegroundColor Yellow
if (Test-Path "Controllers") {
    if ((Get-ChildItem "Controllers" -Recurse).Count -eq 0) {
        Remove-Item "Controllers" -Force
        Write-Host "✓ Removed empty Controllers/ folder" -ForegroundColor Green
    }
}

if (Test-Path "Libraries") {
    if ((Get-ChildItem "Libraries" -Recurse).Count -eq 0) {
        Remove-Item "Libraries" -Force
        Write-Host "✓ Removed empty Libraries/ folder" -ForegroundColor Green
    }
}

if (Test-Path "Migrations") {
    if ((Get-ChildItem "Migrations" -Recurse).Count -eq 0) {
        Remove-Item "Migrations" -Force
        Write-Host "✓ Removed empty Migrations/ folder" -ForegroundColor Green
    }
}

if (Test-Path "Services") {
    if ((Get-ChildItem "Services" -Recurse).Count -eq 0) {
        Remove-Item "Services" -Force
        Write-Host "✓ Removed empty Services/ folder" -ForegroundColor Green
    }
}
Write-Host ""

# Step 4: Rename Infra to infra (lowercase is standard)
Write-Host "Step 4: Standardizing infrastructure folder name..." -ForegroundColor Yellow
if (Test-Path "Infra") {
    if (-not (Test-Path "infra")) {
        Move-Item -Path "Infra" -Destination "infra" -Force
        Write-Host "✓ Renamed Infra/ to infra/" -ForegroundColor Green
    } else {
        Write-Host "! Both Infra/ and infra/ exist, please check manually" -ForegroundColor Red
    }
} else {
    Write-Host "✓ infra/ folder already exists" -ForegroundColor Green
}
Write-Host ""

# Step 5: Update namespaces in library files
Write-Host "Step 5: Updating namespaces in library files..." -ForegroundColor Yellow
$libraryFiles = Get-ChildItem -Path "app/Libraries/Analytics" -Recurse -Filter "*.php"
foreach ($file in $libraryFiles) {
    $content = Get-Content $file.FullName -Raw
    
    # Update namespace from Analytics\* to App\Libraries\Analytics\*
    if ($content -match 'namespace Analytics\\') {
        $content = $content -replace 'namespace Analytics\\', 'namespace App\Libraries\Analytics\'
        Set-Content -Path $file.FullName -Value $content -NoNewline
        Write-Host "  Updated: $($file.Name)" -ForegroundColor Gray
    }
}
Write-Host "✓ Updated namespaces in library files" -ForegroundColor Green
Write-Host ""

# Step 6: Update namespaces in service files
Write-Host "Step 6: Updating namespaces in service files..." -ForegroundColor Yellow
if (Test-Path "app/Services/Workers/worker.php") {
    $content = Get-Content "app/Services/Workers/worker.php" -Raw
    if ($content -match 'namespace Analytics\\') {
        $content = $content -replace 'namespace Analytics\\', 'namespace App\Services\Workers\'
        Set-Content -Path "app/Services/Workers/worker.php" -Value $content -NoNewline
        Write-Host "✓ Updated namespace in worker.php" -ForegroundColor Green
    }
}
Write-Host ""

# Step 7: Update use statements in controllers
Write-Host "Step 7: Updating use statements in controllers..." -ForegroundColor Yellow
$controllers = @("Events.php", "Health.php", "Metrics.php")
foreach ($controller in $controllers) {
    $filePath = "app/Controllers/$controller"
    if (Test-Path $filePath) {
        $content = Get-Content $filePath -Raw
        
        # Update use statements
        $content = $content -replace 'use Analytics\\', 'use App\Libraries\Analytics\'
        $content = $content -replace 'use Analytics\\Config\\Config', 'use App\Libraries\Analytics\Config\Config'
        $content = $content -replace 'use Analytics\\Contracts\\Event', 'use App\Libraries\Analytics\Contracts\Event'
        $content = $content -replace 'use Analytics\\Queue\\RabbitMQManager', 'use App\Libraries\Analytics\Queue\RabbitMQManager'
        $content = $content -replace 'use Analytics\\Cache\\RedisManager', 'use App\Libraries\Analytics\Cache\RedisManager'
        $content = $content -replace 'use Analytics\\Database\\DatabaseManager', 'use App\Libraries\Analytics\Database\DatabaseManager'
        $content = $content -replace 'use Analytics\\Logging\\Logger', 'use App\Libraries\Analytics\Logging\Logger'
        
        Set-Content -Path $filePath -Value $content -NoNewline
        Write-Host "  Updated: $controller" -ForegroundColor Gray
    }
}
Write-Host "✓ Updated use statements in controllers" -ForegroundColor Green
Write-Host ""

# Step 8: Update composer.json
Write-Host "Step 8: Updating composer.json autoloader..." -ForegroundColor Yellow
$composerJson = Get-Content "composer.json" -Raw | ConvertFrom-Json

# Ensure autoload has App namespace
if ($composerJson.autoload -and $composerJson.autoload.'psr-4') {
    $composerJson.autoload.'psr-4' | Add-Member -NotePropertyName "App\\" -NotePropertyValue "app/" -Force
}

$composerJsonContent = $composerJson | ConvertTo-Json -Depth 10
Set-Content -Path "composer.json" -Value $composerJsonContent
Write-Host "✓ Updated composer.json autoloader" -ForegroundColor Green
Write-Host ""

# Summary
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Restructure Complete!" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Next steps:" -ForegroundColor Yellow
Write-Host "1. Review the changes: git status" -ForegroundColor White
Write-Host "2. Test the application: php spark serve" -ForegroundColor White
Write-Host "3. Run migrations: php spark migrate" -ForegroundColor White
Write-Host "4. Commit changes: git add . && git commit -m 'refactor: restructure to CI4 standards'" -ForegroundColor White
Write-Host ""
