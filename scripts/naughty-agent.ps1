# PowerShell naughty agent script wrapper
param (
    [string]$Invoice = "INV-001",
    [int]$Duplicate = 3,
    [switch]$Concurrent,
    [switch]$TimeoutSimulation,
    [switch]$Recover
)

$cmd = "php artisan settlements:naughty-agent $Invoice --duplicate=$Duplicate"
if ($Concurrent) { $cmd += " --concurrent" }
if ($TimeoutSimulation) { $cmd += " --timeout-simulation" }
if ($Recover) { $cmd += " --recover" }

Write-Host "Running: $cmd" -ForegroundColor Cyan
Invoke-Expression $cmd
