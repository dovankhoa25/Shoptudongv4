param(
    [switch]$Preflight,
    [switch]$WarehouseOnly,
    [switch]$ResumeRefill,
    [Parameter(Mandatory)][string]$Tool,
    [Parameter(Mandatory)][string]$ReportDirectory,
    [string]$Php='D:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe'
)
$ErrorActionPreference='Stop'
$repoRoot=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$ReportDirectory=[IO.Path]::GetFullPath($ReportDirectory)
$reportBase='D:\code\App dev\NRO_NATIVE_SLEEP_247\qltk\NroTradingV3\radar-live-results\'
if(-not $ReportDirectory.StartsWith($reportBase,[StringComparison]::OrdinalIgnoreCase) -or (!$ResumeRefill -and (Test-Path -LiteralPath $ReportDirectory))){throw 'Fresh radar evidence directory required.'}
if($ResumeRefill){
    if(Test-Path -LiteralPath (Join-Path $ReportDirectory 'USER-STOP-20261024.json')){throw 'The user closed this live test. Do not resume or replay its transfers.'}
    if(!$WarehouseOnly -or !(Test-Path -LiteralPath (Join-Path $ReportDirectory 'test-database-no-credentials.sqlite')) -or (Test-Path -LiteralPath (Join-Path $ReportDirectory 'refill-resume.json'))){throw 'Only one resume of the original warehouse fixture is supported.'}
    $archive=Join-Path $ReportDirectory 'before-refill-resume';if(Test-Path -LiteralPath $archive){throw 'Resume archive already exists; reconcile first.'};[IO.Directory]::CreateDirectory($archive)|Out-Null
    foreach($name in @('test-database-no-credentials.sqlite','test-origin.txt','workflow.json','backend-report.json','tool.out','tool.err','php.err','state')){if(Test-Path -LiteralPath (Join-Path $ReportDirectory $name)){Copy-Item -LiteralPath (Join-Path $ReportDirectory $name) -Destination $archive -Recurse}}
}
$credentials=@{}
foreach($name in @('NRO_RADAR_MAIN_USER','NRO_RADAR_MAIN_PASSWORD','NRO_RADAR_AUX_USER','NRO_RADAR_AUX_PASSWORD')) {
    $credentials[$name]=[Environment]::GetEnvironmentVariable($name,'Process')
    if(-not $credentials[$name]){throw 'Ephemeral test credentials required; never supply through command-line arguments.'}
}
[IO.Directory]::CreateDirectory($ReportDirectory)|Out-Null
$fixtureRoot=Join-Path ([IO.Path]::GetTempPath()) ('trading-v3-radar-db-'+[Guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory($fixtureRoot)|Out-Null
$dbPath=Join-Path $fixtureRoot 'test.sqlite';[IO.File]::WriteAllBytes($dbPath,[byte[]]@())
$listener=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0);$listener.Start();$listenPort=$listener.LocalEndpoint.Port;$listener.Stop()
if($ResumeRefill){$origin=[Uri](Get-Content -LiteralPath (Join-Path $ReportDirectory 'test-origin.txt') -Raw);if(!$origin.IsLoopback -or $origin.Scheme -ne 'http' -or $origin.AbsolutePath -ne '/app/v3/'){throw 'Original loopback origin required.'};$listenPort=$origin.Port;$portCheck=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,$listenPort);$portCheck.Start();$portCheck.Stop();Copy-Item -LiteralPath (Join-Path $ReportDirectory 'test-database-no-credentials.sqlite') -Destination $dbPath -Force}
$settings=@{
    APP_ENV='testing';APP_DEBUG='false';APP_KEY=('base64:'+([Convert]::ToBase64String([Security.Cryptography.RandomNumberGenerator]::GetBytes(32))));
    APP_CONFIG_CACHE=(Join-Path $fixtureRoot 'config.php');APP_ROUTES_CACHE=(Join-Path $fixtureRoot 'routes.php');
    DB_CONNECTION='sqlite';DB_DATABASE=$dbPath;DB_URL='null';CACHE_STORE='database';CACHE_PREFIX=('radar-runtime-'+[Guid]::NewGuid().ToString('N'));
    SESSION_DRIVER='array';QUEUE_CONNECTION='sync';BROADCAST_CONNECTION='log';LOG_CHANNEL='stderr';
    NRO_TRADING_V3_ENABLED='true';NRO_TRADING_V3_TOOL_ID='nro-trading-v3';NRO_TRADING_V3_HMAC_SECRET=([Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(32)));
    NRO_TRADING_V3_API_URL="http://127.0.0.1:$listenPort/app/v3/";NRO_RADAR_TEST_ALLOWED='true';NRO_RADAR_REPORT_DIRECTORY=$ReportDirectory;
    NRO_RADAR_WAREHOUSE_ONLY=([bool]$WarehouseOnly).ToString().ToLowerInvariant();NRO_RADAR_RESUME_REFILL=([bool]$ResumeRefill).ToString().ToLowerInvariant()
}
$previous=@{};$phpServer=$null;$runtime=$null;$dbSanitized=$false
function Redact([string]$Text){foreach($value in @($credentials.Values)+@($settings['NRO_TRADING_V3_HMAC_SECRET'])){if($value){$Text=$Text.Replace($value,'[hidden]')}};return $Text}
try {
    foreach($name in $settings.Keys){$previous[$name]=[Environment]::GetEnvironmentVariable($name,'Process');[Environment]::SetEnvironmentVariable($name,$settings[$name],'Process')}
    Push-Location $repoRoot
    try {
        & $Php artisan migrate --force --no-interaction | Out-Null
        if($LASTEXITCODE -ne 0){throw 'Isolated radar migration failed.'}
        $seed=if($ResumeRefill){& $Php tests/Support/trading-v3-radar-runtime.php restore-refill 2>&1}else{& $Php tests/Support/trading-v3-radar-runtime.php 2>&1};$code=$LASTEXITCODE
        $expected=if($ResumeRefill){'Original isolated radar warehouse restored'}else{'Isolated Super 2 radar fixture created'}
        if($code -ne 0 -or -not (($seed|Out-String).Contains($expected))){throw (Redact ($seed|Out-String))}
    } finally {Pop-Location}
    $phpServer=Start-Process -FilePath $Php -ArgumentList @('-S',"127.0.0.1:$listenPort",'-t','public','tests/Support/trading-v3-radar-router.php') -WorkingDirectory $repoRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $fixtureRoot 'php.out') -RedirectStandardError (Join-Path $fixtureRoot 'php.err')
    Start-Sleep -Milliseconds 500
    [IO.File]::WriteAllText((Join-Path $ReportDirectory 'test-origin.txt'),$settings['NRO_TRADING_V3_API_URL'])
    if($Preflight) {
        $headers=@{'X-Test-Key'=$settings['NRO_TRADING_V3_HMAC_SECRET'];Accept='application/json'}
        # Observed dummy capacity only in this disposable preflight; never used for game runs.
        Push-Location $repoRoot
        try {& $Php tests/Support/trading-v3-radar-runtime.php preflight;if($LASTEXITCODE -ne 0){throw 'Preflight inventory failed.'}}finally{Pop-Location}
        foreach($kind in @('order','import')) {
            $result=Invoke-RestMethod -Uri "http://127.0.0.1:$listenPort/testing-radar/order" -Method Post -Headers $headers -ContentType 'application/json' -Body (@{type=$kind;name='radarTestCustomer'}|ConvertTo-Json -Compress)
            if(-not $result.success -or $result.data.gold_bar_template_id -ne 281){throw 'Real radar checkout preflight failed.'}
        }
        Write-Output 'Radar preflight PASS: real sale/import checkout on disposable localhost DB; no game login.';return
    }
    $runtime=Start-Process -FilePath $Tool -ArgumentList '--radar-workflow-test' -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $fixtureRoot 'tool.out') -RedirectStandardError (Join-Path $fixtureRoot 'tool.err')
    $readLines=0
    while(-not $runtime.WaitForExit(1000)) {
        $lines=@(Get-Content -LiteralPath (Join-Path $fixtureRoot 'tool.out') -ErrorAction SilentlyContinue)
        if($lines.Count -gt $readLines){$lines[$readLines..($lines.Count-1)]|ForEach-Object{Redact $_}|Write-Output;$readLines=$lines.Count}
    }
    $lines=@(Get-Content -LiteralPath (Join-Path $fixtureRoot 'tool.out'))
    if($lines.Count -gt $readLines){$lines[$readLines..($lines.Count-1)]|ForEach-Object{Redact $_}|Write-Output}
    if($runtime.ExitCode -ne 0){throw 'Radar workflow stopped; retain original DB and journals before any further game action.'}
} finally {
    if($runtime -and -not $runtime.HasExited){$runtime.Kill();$runtime.WaitForExit()}
    if($phpServer -and -not $phpServer.HasExited){
        try {
            $snapshot=Invoke-RestMethod -Uri "http://127.0.0.1:$listenPort/testing-radar/report" -Headers @{'X-Test-Key'=$settings['NRO_TRADING_V3_HMAC_SECRET'];Accept='application/json'} -TimeoutSec 10
            [IO.File]::WriteAllText((Join-Path $ReportDirectory 'backend-report.json'),($snapshot|ConvertTo-Json -Depth 100))
        } catch {Write-Output 'Backend report unavailable; original sanitized database remains the evidence.'}
    }
    if($phpServer -and -not $phpServer.HasExited){$phpServer.Kill();$phpServer.WaitForExit()}
    try {
        Push-Location $repoRoot
        try {& $Php tests/Support/trading-v3-radar-runtime.php sanitize;if($LASTEXITCODE -ne 0){throw 'Radar database sanitization failed.'};$dbSanitized=$true}finally{Pop-Location}
        Copy-Item -LiteralPath $dbPath -Destination (Join-Path $ReportDirectory 'test-database-no-credentials.sqlite')
        foreach($name in @('tool.out','tool.err','php.err')){if(Test-Path -LiteralPath (Join-Path $fixtureRoot $name)){[IO.File]::WriteAllText((Join-Path $ReportDirectory $name),(Redact ([string](Get-Content -LiteralPath (Join-Path $fixtureRoot $name) -Raw))))}}
    } finally {
        foreach($name in $previous.Keys){[Environment]::SetEnvironmentVariable($name,$previous[$name],'Process')}
        $resolved=[IO.Path]::GetFullPath($fixtureRoot);$tempPrefix=[IO.Path]::GetFullPath([IO.Path]::GetTempPath())
        if($dbSanitized -and $resolved.StartsWith($tempPrefix,[StringComparison]::OrdinalIgnoreCase) -and [IO.Path]::GetFileName($resolved).StartsWith('trading-v3-radar-db-')){Remove-Item -LiteralPath $resolved -Recurse -Force}
        Write-Output ('Radar test evidence: '+$ReportDirectory)
    }
}
