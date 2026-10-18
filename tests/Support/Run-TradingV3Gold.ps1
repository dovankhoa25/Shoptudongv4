param(
    [Parameter(Mandatory)][string]$SellingUsername,
    [Parameter(Mandatory)][string]$ImportUsername,
    [Parameter(Mandatory)][string]$AuxUsername,
    [Parameter(Mandatory)][string]$DisabledUsername,
    [Parameter(Mandatory)][string]$CustomerUsername,
    [Parameter(Mandatory)][string]$TestPassword,
    [switch]$TcpFault,
    [switch]$PresenceFault,
    [switch]$Preflight,
    [switch]$ResumeCollect,
    [switch]$ImportCancel,
    [switch]$RefillCustomer,
    [switch]$RefillResume,
    [string]$ReportDirectory='',
    [string]$Php='D:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe',
    [string]$Tool='D:\code\App dev\NRO_NATIVE_SLEEP_247\qltk\NroTradingV3\bin\protected-publish\QLTK-Ngoc-V3.exe'
)
$ErrorActionPreference='Stop'
if($ImportCancel -and ($TcpFault -or $PresenceFault -or $ResumeCollect)){throw 'Import-cancel is a separate bounded scenario; do not combine with legacy faults or resume.'}
if($RefillCustomer -and ($ImportCancel -or $TcpFault -or $PresenceFault -or $ResumeCollect -or $Preflight)){throw 'Refill-customer is a separate bounded scenario.'}
if($RefillResume -and -not $RefillCustomer){throw 'Refill resume requires the original refill-customer scenario.'}
$repoRoot=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$reportBase='D:\code\App dev\NRO_NATIVE_SLEEP_247\qltk\NroTradingV3\gold-live-results'
if(-not $ReportDirectory){$ReportDirectory=Join-Path $reportBase ((Get-Date -Format 'yyyyMMdd-HHmmss')+'-cycle-'+[Guid]::NewGuid().ToString('N').Substring(0,8))}
$ReportDirectory=[IO.Path]::GetFullPath($ReportDirectory)
if(-not $ReportDirectory.StartsWith(($reportBase+'\'),[StringComparison]::OrdinalIgnoreCase)){throw 'Gold reports must stay inside the tool gold-live-results directory.'}
if($RefillResume){
    foreach($file in @('test-database-no-credentials.sqlite','test-origin.txt','refill-customer-results.json','state\worker-id.txt')){if(-not (Test-Path -LiteralPath (Join-Path $ReportDirectory $file))){throw 'Original refill database, origin, report and worker journal are required to resume.'}}
    $originalReport=Get-Content -LiteralPath (Join-Path $ReportDirectory 'refill-customer-results.json') -Raw|ConvertFrom-Json
    if($originalReport.scenario -ne 'refill_customer' -or $originalReport.success -or @($originalReport.events|Where-Object {$_.event -eq 'customer_order_completed'}).Count -ne 2){throw 'Resume only the unfinished refill test after both original sales were completed.'}
    foreach($file in Get-ChildItem -LiteralPath (Join-Path $ReportDirectory 'state\gold') -Filter '*.json'){
        $state=Get-Content -LiteralPath $file.FullName -Raw|ConvertFrom-Json
        if($state.order -or $state.start_attempt -or $state.receipts -or $state.pending_receipts -or $state.parked_orders -or $state.transfer_intent){throw 'Refill resume requires all original customer orders settled.'}
        if(($state.intent -or $state.outbox -or $state.transfer) -and (-not $state.transfer_confirmed -or -not $state.transfer -or -not $state.intent -or $state.outbox.payload.action -ne 'receipt' -or $state.outbox.payload.step_id -ne $state.intent.id -or $state.outbox.payload.evidence.confirmed -ne $true)){throw 'Only an already confirmed original transfer receipt may remain; ambiguous game effects cannot be resumed by this fixture.'}
    }
    $archive=Join-Path $ReportDirectory ('before-refill-resume-'+(Get-Date -Format 'yyyyMMdd-HHmmss')+'-'+[Guid]::NewGuid().ToString('N').Substring(0,8))
    [IO.Directory]::CreateDirectory($archive)|Out-Null
    foreach($file in @('test-database-no-credentials.sqlite','test-origin.txt','refill-customer-results.json','tool.out','tool.err','php.err','state')){if(Test-Path -LiteralPath (Join-Path $ReportDirectory $file)){Copy-Item -LiteralPath (Join-Path $ReportDirectory $file) -Destination $archive -Recurse}}
}elseif($ResumeCollect){
    if(-not (Test-Path -LiteralPath (Join-Path $ReportDirectory 'test-database-no-credentials.sqlite'))){throw 'Original gold evidence is required to resume.'}
    foreach($file in Get-ChildItem -LiteralPath (Join-Path $ReportDirectory 'state\gold') -Filter '*.json'){$state=Get-Content -LiteralPath $file.FullName -Raw|ConvertFrom-Json;if($state.intent -or $state.outbox -or $state.order -or $state.transfer_intent){throw 'Resume collector requires all customer receipts settled and no ambiguous transfer.'}}
}elseif(Test-Path -LiteralPath (Join-Path $ReportDirectory 'state')){throw 'Existing financial state must be reconciled with its original fixture, never replayed as a new test.'}
[IO.Directory]::CreateDirectory($ReportDirectory)|Out-Null
$fixtureRoot=Join-Path ([IO.Path]::GetTempPath()) ('trading-v3-gold-db-'+[Guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory($fixtureRoot)|Out-Null
$dbPath=Join-Path $fixtureRoot 'test.sqlite';[IO.File]::WriteAllBytes($dbPath,[byte[]]@())
$listener=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0);$listener.Start();$listenPort=$listener.LocalEndpoint.Port;$listener.Stop()
if($ResumeCollect -or $RefillResume){$origin=[Uri](Get-Content -LiteralPath (Join-Path $ReportDirectory 'test-origin.txt') -Raw);if(-not $origin.IsLoopback -or $origin.Scheme -ne 'http' -or $origin.AbsolutePath -ne '/app/v3/'){throw 'Original loopback origin required.'};$listenPort=$origin.Port;$portCheck=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,$listenPort);$portCheck.Start();$portCheck.Stop();Copy-Item -LiteralPath (Join-Path $ReportDirectory 'test-database-no-credentials.sqlite') -Destination $dbPath -Force}
$settings=@{
    APP_ENV='testing';APP_DEBUG='false';APP_KEY=('base64:'+([Convert]::ToBase64String([Security.Cryptography.RandomNumberGenerator]::GetBytes(32))));
    APP_CONFIG_CACHE=(Join-Path $fixtureRoot 'config.php');APP_ROUTES_CACHE=(Join-Path $fixtureRoot 'routes.php');
    DB_CONNECTION='sqlite';DB_DATABASE=$dbPath;DB_URL='null';CACHE_STORE='database';CACHE_PREFIX=('gold-runtime-'+[Guid]::NewGuid().ToString('N'));
    SESSION_DRIVER='array';QUEUE_CONNECTION='sync';BROADCAST_CONNECTION='log';LOG_CHANNEL='stderr';
    NRO_TRADING_V3_ENABLED='true';NRO_TRADING_V3_TOOL_ID='nro-trading-v3';NRO_TRADING_V3_HMAC_SECRET=([Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(32)));
    NRO_TRADING_V3_API_URL="http://127.0.0.1:$listenPort/app/v3/";NRO_GOLD_TEST_ALLOWED='true';NRO_GOLD_REPORT_DIRECTORY=$ReportDirectory;
    NRO_GOLD_FAULT_TCP=([bool]$TcpFault).ToString().ToLowerInvariant();NRO_TRADING_V3_RECOVERY_TEST=([bool]$TcpFault).ToString().ToLowerInvariant();
    NRO_GOLD_FAULT_PRESENCE=([bool]$PresenceFault).ToString().ToLowerInvariant();
    NRO_GOLD_TEST_RESUME_COLLECT=([bool]$ResumeCollect).ToString().ToLowerInvariant();
    NRO_GOLD_TEST_IMPORT_CANCEL=([bool]$ImportCancel).ToString().ToLowerInvariant();
    NRO_GOLD_TEST_REFILL_CUSTOMER=([bool]$RefillCustomer).ToString().ToLowerInvariant();
    NRO_GOLD_TEST_REFILL_RESUME=([bool]$RefillResume).ToString().ToLowerInvariant();
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
        $seedOutput=if($RefillResume){& $Php tests/Support/trading-v3-gold-runtime.php restore-refill 2>&1}elseif($ResumeCollect){& $Php tests/Support/trading-v3-gold-runtime.php restore 2>&1}else{& $Php tests/Support/trading-v3-gold-runtime.php 2>&1}
        $seedExitCode=$LASTEXITCODE
        $seedText=$seedOutput|Out-String
        $seedOutput|ForEach-Object{Redact ([string]$_)}|Write-Output
        $expectedSeed=if($RefillResume){'Original refill fixture restored;'}elseif($ResumeCollect){'Original fixture restored;'}else{'Created isolated SV11 gold fixture;'}
        if($seedExitCode -ne 0 -or -not $seedText.Contains($expectedSeed)){throw 'Isolated gold seed/restore failed; game runtime was not started.'}
    }finally{Pop-Location}
    $phpServer=Start-Process -FilePath $Php -ArgumentList @('-S',"127.0.0.1:$listenPort",'-t','public','tests/Support/trading-v3-gold-router.php') -WorkingDirectory $repoRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $fixtureRoot 'php.out') -RedirectStandardError (Join-Path $fixtureRoot 'php.err')
    Start-Sleep -Milliseconds 500
    if($Preflight){
        $headers=@{'X-Test-Key'=$settings['NRO_TRADING_V3_HMAC_SECRET'];Accept='application/json'}
        if($ImportCancel){
            $result=Invoke-RestMethod -Uri "http://127.0.0.1:$listenPort/testing-gold/import-cancel-orders" -Method Post -Headers $headers -ContentType 'application/json' -Body (@{cancel_name='goldtestcustomer';next_name='goldtestauxiliary'}|ConvertTo-Json -Compress)
            if(-not $result.success -or $result.data.cancel_order_id -le 0 -or $result.data.next_order_id -le $result.data.cancel_order_id){throw 'Bounded import-cancel fixture preflight failed.'}
        }else{foreach($kind in @('order','import')){
            $result=Invoke-RestMethod -Uri "http://127.0.0.1:$listenPort/testing-gold/order" -Method Post -Headers $headers -ContentType 'application/json' -Body (@{type=$kind;name='goldtestcustomer'}|ConvertTo-Json -Compress)
            if(-not $result.success -or $result.data.gold_bar_qty -ne 1 -or $result.data.pure_gold_qty -ne 0){throw 'Bounded gold fixture preflight failed.'}
        }}
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
        foreach($file in @('tool.out','tool.err','php.err')){if(Test-Path -LiteralPath (Join-Path $fixtureRoot $file)){$name=if($RefillResume){'refill-resume-'+$file}elseif($ResumeCollect){'resume-'+$file}else{$file};[IO.File]::WriteAllText((Join-Path $ReportDirectory $name),(Redact ([string](Get-Content -LiteralPath (Join-Path $fixtureRoot $file) -Raw))))}}
    }
    foreach($name in $previous.Keys){if($null -eq $previous[$name]){Remove-Item -LiteralPath ('Env:'+$name) -ErrorAction SilentlyContinue}else{[Environment]::SetEnvironmentVariable($name,$previous[$name],'Process')}}
    $resolved=[IO.Path]::GetFullPath($fixtureRoot);$tempPrefix=[IO.Path]::GetFullPath([IO.Path]::GetTempPath())
    if($dbSanitized -and $resolved.StartsWith($tempPrefix,[StringComparison]::OrdinalIgnoreCase) -and [IO.Path]::GetFileName($resolved).StartsWith('trading-v3-gold-db-')){Remove-Item -LiteralPath $resolved -Recurse -Force}
    Write-Output ('Gold test evidence: '+$ReportDirectory)
}
