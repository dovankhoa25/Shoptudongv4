param(
    [Parameter(Mandatory)][string]$SellingUsername,
    [Parameter(Mandatory)][string]$ImportUsername,
    [Parameter(Mandatory)][string]$AuxUsername,
    [Parameter(Mandatory)][string]$DisabledUsername,
    [Parameter(Mandatory)][string]$CustomerUsername,
    [Parameter(Mandatory)][string]$TestPassword,
    [switch]$TcpFault,
    [switch]$Preflight,
    [switch]$ResumeCollect,
    [string]$ReportDirectory='',
    [string]$Php='D:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe',
    [string]$Tool='D:\code\App dev\NRO_NATIVE_SLEEP_247\qltk\NroTradingV3\bin\protected-publish\QLTK-Ngoc-V3.exe'
)
$ErrorActionPreference='Stop'
$repoRoot=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$reportBase='D:\code\App dev\NRO_NATIVE_SLEEP_247\qltk\NroTradingV3\gold-live-results'
if(-not $ReportDirectory){$ReportDirectory=Join-Path $reportBase ((Get-Date -Format 'yyyyMMdd-HHmmss')+'-cycle-'+[Guid]::NewGuid().ToString('N').Substring(0,8))}
$ReportDirectory=[IO.Path]::GetFullPath($ReportDirectory)
if(-not $ReportDirectory.StartsWith(($reportBase+'\'),[StringComparison]::OrdinalIgnoreCase)){throw 'Gold reports must stay inside the tool gold-live-results directory.'}
if($ResumeCollect){
    if(-not (Test-Path -LiteralPath (Join-Path $ReportDirectory 'test-database-no-credentials.sqlite'))){throw 'Original gold evidence is required to resume.'}
    foreach($file in Get-ChildItem -LiteralPath (Join-Path $ReportDirectory 'state\gold') -Filter '*.json'){$state=Get-Content -LiteralPath $file.FullName -Raw|ConvertFrom-Json;if($state.intent -or $state.outbox -or $state.order -or $state.transfer_intent){throw 'Resume collector requires all customer receipts settled and no ambiguous transfer.'}}
}elseif(Test-Path -LiteralPath (Join-Path $ReportDirectory 'state')){throw 'Existing financial state must be reconciled with its original fixture, never replayed as a new test.'}
[IO.Directory]::CreateDirectory($ReportDirectory)|Out-Null
$fixtureRoot=Join-Path ([IO.Path]::GetTempPath()) ('trading-v3-gold-db-'+[Guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory($fixtureRoot)|Out-Null
$dbPath=Join-Path $fixtureRoot 'test.sqlite';[IO.File]::WriteAllBytes($dbPath,[byte[]]@())
$listener=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0);$listener.Start();$listenPort=$listener.LocalEndpoint.Port;$listener.Stop()
if($ResumeCollect){$origin=[Uri](Get-Content -LiteralPath (Join-Path $ReportDirectory 'test-origin.txt') -Raw);if(-not $origin.IsLoopback -or $origin.Scheme -ne 'http' -or $origin.AbsolutePath -ne '/app/v3/'){throw 'Original loopback origin required.'};$listenPort=$origin.Port;$portCheck=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,$listenPort);$portCheck.Start();$portCheck.Stop();Copy-Item -LiteralPath (Join-Path $ReportDirectory 'test-database-no-credentials.sqlite') -Destination $dbPath -Force}
$settings=@{
    APP_ENV='testing';APP_DEBUG='false';APP_KEY=('base64:'+([Convert]::ToBase64String([Security.Cryptography.RandomNumberGenerator]::GetBytes(32))));
    APP_CONFIG_CACHE=(Join-Path $fixtureRoot 'config.php');APP_ROUTES_CACHE=(Join-Path $fixtureRoot 'routes.php');
    DB_CONNECTION='sqlite';DB_DATABASE=$dbPath;DB_URL='null';CACHE_STORE='database';CACHE_PREFIX=('gold-runtime-'+[Guid]::NewGuid().ToString('N'));
    SESSION_DRIVER='array';QUEUE_CONNECTION='sync';BROADCAST_CONNECTION='log';LOG_CHANNEL='stderr';
    NRO_TRADING_V3_ENABLED='true';NRO_TRADING_V3_TOOL_ID='nro-trading-v3';NRO_TRADING_V3_HMAC_SECRET=([Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(32)));
    NRO_TRADING_V3_API_URL="http://127.0.0.1:$listenPort/app/v3/";NRO_GOLD_TEST_ALLOWED='true';NRO_GOLD_REPORT_DIRECTORY=$ReportDirectory;
    NRO_GOLD_FAULT_TCP=([bool]$TcpFault).ToString().ToLowerInvariant();NRO_TRADING_V3_RECOVERY_TEST=([bool]$TcpFault).ToString().ToLowerInvariant();
    NRO_GOLD_TEST_RESUME_COLLECT=([bool]$ResumeCollect).ToString().ToLowerInvariant();
    NRO_GOLD_TEST_SELL_USER=$SellingUsername;NRO_GOLD_TEST_SELL_PASSWORD=$TestPassword;
    NRO_GOLD_TEST_IMPORT_USER=$ImportUsername;NRO_GOLD_TEST_IMPORT_PASSWORD=$TestPassword;
    NRO_GOLD_TEST_AUX_USER=$AuxUsername;NRO_GOLD_TEST_AUX_PASSWORD=$TestPassword;
    NRO_GOLD_TEST_OFF_USER=$DisabledUsername;NRO_GOLD_TEST_OFF_PASSWORD=$TestPassword;
    NRO_GOLD_TEST_CUSTOMER_USER=$CustomerUsername;NRO_GOLD_TEST_CUSTOMER_PASSWORD=$TestPassword
}
$previous=@{};$phpServer=$null;$runtime=$null;$dbSanitized=$false
function Redact([string]$Text){foreach($value in @($SellingUsername,$ImportUsername,$AuxUsername,$DisabledUsername,$CustomerUsername,$TestPassword,$settings['NRO_TRADING_V3_HMAC_SECRET'])){if($value){$Text=$Text.Replace($value,'[hidden]')}};return $Text}
try{
    foreach($name in $settings.Keys){$previous[$name]=[Environment]::GetEnvironmentVariable($name,'Process');[Environment]::SetEnvironmentVariable($name,$settings[$name],'Process')}
    Push-Location $repoRoot
    try{
        & $Php artisan migrate --force --no-interaction | Out-Null
        if($LASTEXITCODE -ne 0){throw 'Isolated gold migration failed.'}
        if($ResumeCollect){& $Php tests/Support/trading-v3-gold-runtime.php restore}else{& $Php tests/Support/trading-v3-gold-runtime.php}
        if($LASTEXITCODE -ne 0){throw 'Isolated gold seed failed.'}
    }finally{Pop-Location}
    $phpServer=Start-Process -FilePath $Php -ArgumentList @('-S',"127.0.0.1:$listenPort",'-t','public','tests/Support/trading-v3-gold-router.php') -WorkingDirectory $repoRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $fixtureRoot 'php.out') -RedirectStandardError (Join-Path $fixtureRoot 'php.err')
    Start-Sleep -Milliseconds 500
    if($Preflight){
        $headers=@{'X-Test-Key'=$settings['NRO_TRADING_V3_HMAC_SECRET'];Accept='application/json'}
        foreach($kind in @('order','import')){
            $result=Invoke-RestMethod -Uri "http://127.0.0.1:$listenPort/testing-gold/order" -Method Post -Headers $headers -ContentType 'application/json' -Body (@{type=$kind;name='goldtestcustomer'}|ConvertTo-Json -Compress)
            if(-not $result.success -or $result.data.gold_bar_qty -ne 1 -or $result.data.pure_gold_qty -ne 0){throw 'Bounded gold fixture preflight failed.'}
        }
        $report=Invoke-RestMethod -Uri "http://127.0.0.1:$listenPort/testing-gold/report" -Headers $headers
        if($report.data.orders.Count -ne 2 -or $report.data.bots.Count -ne 4 -or $report.data.bots[3].status){throw 'Gold report preflight failed.'}
        Write-Output 'Gold preflight PASS: bounded localhost fixture; no game login.'
        return
    }
    [IO.File]::WriteAllText((Join-Path $ReportDirectory 'test-origin.txt'),$settings['NRO_TRADING_V3_API_URL'])
    $runtime=Start-Process -FilePath $Tool -ArgumentList '--gold-live-test' -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $fixtureRoot 'tool.out') -RedirectStandardError (Join-Path $fixtureRoot 'tool.err')
    $readLines=0
    while(-not $runtime.WaitForExit(1000)){
        $lines=@(Get-Content -LiteralPath (Join-Path $fixtureRoot 'tool.out') -ErrorAction SilentlyContinue)
        if($lines.Count -gt $readLines){$lines[$readLines..($lines.Count-1)]|ForEach-Object{Redact $_}|Write-Output;$readLines=$lines.Count}
    }
    $lines=@(Get-Content -LiteralPath (Join-Path $fixtureRoot 'tool.out'))
    if($lines.Count -gt $readLines){$lines[$readLines..($lines.Count-1)]|ForEach-Object{Redact $_}|Write-Output}
    if($runtime.ExitCode -ne 0){Get-Content -LiteralPath (Join-Path $fixtureRoot 'tool.err')|ForEach-Object{Redact $_};throw 'Gold runtime stopped; preserve the journal and original fixture, do not replay.'}
}finally{
    if($runtime -and -not $runtime.HasExited){$runtime.Kill();$runtime.WaitForExit()}
    if($phpServer -and -not $phpServer.HasExited){$phpServer.Kill();$phpServer.WaitForExit()}
    if(Test-Path -LiteralPath $dbPath){
        Push-Location $repoRoot
        try{& $Php tests/Support/trading-v3-gold-runtime.php sanitize;if($LASTEXITCODE -ne 0){throw 'Cannot archive gold DB until credentials are removed.'};$dbSanitized=$true}finally{Pop-Location}
        Copy-Item -LiteralPath $dbPath -Destination (Join-Path $ReportDirectory 'test-database-no-credentials.sqlite') -Force
        foreach($file in @('tool.out','tool.err','php.err')){if(Test-Path -LiteralPath (Join-Path $fixtureRoot $file)){$name=if($ResumeCollect){'resume-'+$file}else{$file};[IO.File]::WriteAllText((Join-Path $ReportDirectory $name),(Redact ([string](Get-Content -LiteralPath (Join-Path $fixtureRoot $file) -Raw))))}}
    }
    foreach($name in $previous.Keys){if($null -eq $previous[$name]){Remove-Item -LiteralPath ('Env:'+$name) -ErrorAction SilentlyContinue}else{[Environment]::SetEnvironmentVariable($name,$previous[$name],'Process')}}
    $resolved=[IO.Path]::GetFullPath($fixtureRoot);$tempPrefix=[IO.Path]::GetFullPath([IO.Path]::GetTempPath())
    if($dbSanitized -and $resolved.StartsWith($tempPrefix,[StringComparison]::OrdinalIgnoreCase) -and [IO.Path]::GetFileName($resolved).StartsWith('trading-v3-gold-db-')){Remove-Item -LiteralPath $resolved -Recurse -Force}
    Write-Output ('Gold test evidence: '+$ReportDirectory)
}
