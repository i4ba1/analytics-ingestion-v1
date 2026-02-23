$libraryPath = "app/Libraries/Analytics"
$files = Get-ChildItem -Path $libraryPath -Recurse -Filter "*.php"

foreach ($file in $files) {
    Write-Host "Processing: $($file.FullName)" -ForegroundColor Cyan
    
    $content = Get-Content $file.FullName -Raw
    $originalContent = $content
    
    # Update namespace declarations
    $content = $content -replace 'namespace Analytics\\', 'namespace App\Libraries\Analytics\'
    $content = $content -replace 'namespace Analytics\Config', 'namespace App\Libraries\Analytics\Config'
    $content = $content -replace 'namespace Analytics\Contracts', 'namespace App\Libraries\Analytics\Contracts'
    $content = $content -replace 'namespace Analytics\Queue', 'namespace App\Libraries\Analytics\Queue'
    $content = $content -replace 'namespace Analytics\Cache', 'namespace App\Libraries\Analytics\Cache'
    $content = $content -replace 'namespace Analytics\Database', 'namespace App\Libraries\Analytics\Database'
    $content = $content -replace 'namespace Analytics\Logging', 'namespace App\Libraries\Analytics\Logging'
    
    # Update use statements
    $content = $content -replace 'use Analytics\\', 'use App\Libraries\Analytics\'
    $content = $content -replace 'use Analytics\Config\\Config', 'use App\Libraries\Analytics\Config\Config'
    $content = $content -replace 'use Analytics\Contracts\\Event', 'use App\Libraries\Analytics\Contracts\Event'
    
    if ($content -ne $originalContent) {
        Set-Content -Path $file.FullName -Value $content -NoNewline
        Write-Host "  Updated: $($file.Name)" -ForegroundColor Green
    } else {
        Write-Host "  No changes: $($file.Name)" -ForegroundColor Gray
    }
}

Write-Host "Namespace updates complete" -ForegroundColor Green
