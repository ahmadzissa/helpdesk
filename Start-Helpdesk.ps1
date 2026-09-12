param([int]$Port = 8000, [switch]$Build)
$ErrorActionPreference = 'Stop'
$projectPath = $PSScriptRoot
Set-Location -LiteralPath $projectPath
$phpExecutable = (Get-Command php -ErrorAction Stop).Source
if (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue) {
    throw "Port $Port is already in use. Choose another port with -Port."
}
if ($Build -or -not (Test-Path 'public/build/manifest.json')) {
    $nodeExecutable = (Get-Command node -ErrorAction Stop).Source
    $npmCli = Join-Path (Split-Path $nodeExecutable) 'node_modules/npm/bin/npm-cli.js'
    & $nodeExecutable $npmCli run build
    if ($LASTEXITCODE -ne 0) { throw 'The frontend build failed.' }
}
& $phpExecutable artisan migrate --force --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'The database migration failed.' }
$env:APP_URL = "http://127.0.0.1:$Port"
$env:APP_NAME = 'Relay'
$publicPath = Join-Path $projectPath 'public'
$routerPath = Join-Path $projectPath 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'
$artisanPath = Join-Path $projectPath 'artisan'
$logPath = Join-Path $projectPath 'storage/logs'
$server = Start-Process -FilePath $phpExecutable -ArgumentList '-S',"127.0.0.1:$Port",'-t',('"' + $publicPath + '"'),('"' + $routerPath + '"') -WorkingDirectory $publicPath -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logPath 'server.log') -RedirectStandardError (Join-Path $logPath 'server-error.log') -PassThru
$queue = Start-Process -FilePath $phpExecutable -ArgumentList ('"' + $artisanPath + '"'),'queue:work','--queue=incoming,default','--timeout=950','--tries=1','--sleep=3' -WorkingDirectory $projectPath -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logPath 'queue.log') -RedirectStandardError (Join-Path $logPath 'queue-error.log') -PassThru
$schedule = Start-Process -FilePath $phpExecutable -ArgumentList ('"' + $artisanPath + '"'),'schedule:work' -WorkingDirectory $projectPath -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logPath 'scheduler.log') -RedirectStandardError (Join-Path $logPath 'scheduler-error.log') -PassThru
@{ server = $server.Id; queue = $queue.Id; scheduler = $schedule.Id; port = $Port } | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $logPath 'local-processes.json') -Encoding utf8
Write-Host "Relay is running at http://127.0.0.1:$Port"
Write-Host "Server: $($server.Id) | Queue: $($queue.Id) | Scheduler: $($schedule.Id)"
