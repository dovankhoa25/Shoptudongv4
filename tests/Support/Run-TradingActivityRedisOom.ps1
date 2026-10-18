param([string]$RedisServer='D:\laragon\bin\redis\redis-x64-5.0.14.1\redis-server.exe',
    [string]$Php='D:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe')
$ErrorActionPreference='Stop'
$repoRoot=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$reportBase=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot 'runtime-reports'))
$reportRoot=Join-Path $reportBase ('activity-redis-live-'+(Get-Date -Format 'yyyyMMdd-HHmmss')+'-'+[Guid]::NewGuid().ToString('N').Substring(0,8))
[IO.Directory]::CreateDirectory($reportRoot)|Out-Null
$listener=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0);$listener.Start();$port=$listener.LocalEndpoint.Port;$listener.Stop()
if($port -lt 49152){throw 'An unused dynamic local Redis port is required.'}
$redisConfig=Join-Path $reportRoot 'redis-test.conf'
$configText=@('bind 127.0.0.1',('port '+$port),'protected-mode yes','save ""','appendonly no','maxmemory 0','maxmemory-policy noeviction',
    ('dir "'+$reportRoot.Replace('\','/')+'"'),('logfile "'+(Join-Path $reportRoot 'redis-server.log').Replace('\','/')+'"')) -join "`n"
[IO.File]::WriteAllText($redisConfig,$configText)
$redisProcess=$null;$previous=@{};$testExit=1
try {
    $redisProcess=Start-Process -FilePath $RedisServer -ArgumentList ('"'+$redisConfig+'"') -WorkingDirectory $reportRoot -WindowStyle Hidden -PassThru
    Start-Sleep -Milliseconds 700
    if($redisProcess.HasExited){throw 'The owned isolated Redis process failed to start.'}
    $environment=@{NRO_ACTIVITY_REDIS_TEST_ALLOWED='true';NRO_ACTIVITY_REDIS_TEST_PORT=[string]$port;
        NRO_ACTIVITY_REDIS_TEST_PID=[string]$redisProcess.Id;NRO_ACTIVITY_REDIS_TEST_DIRECTORY=$reportRoot}
    foreach($name in $environment.Keys){$previous[$name]=[Environment]::GetEnvironmentVariable($name,'Process');[Environment]::SetEnvironmentVariable($name,$environment[$name],'Process')}
    Push-Location $repoRoot
    try{& $Php tests/Support/trading-activity-redis-live-oom.php;$testExit=$LASTEXITCODE}finally{Pop-Location}
} finally {
    if($redisProcess -and -not $redisProcess.HasExited){$redisProcess.Kill();$redisProcess.WaitForExit()}
    foreach($name in $previous.Keys){[Environment]::SetEnvironmentVariable($name,$previous[$name],'Process')}
    Write-Output ('Isolated Redis test evidence: '+$reportRoot)
    Write-Output 'Only the owned Redis child process was stopped. No existing Redis instance was changed.'
}
if($testExit -ne 0){throw 'The isolated Redis OOM test failed.'}
