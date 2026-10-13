param([Parameter(Mandatory)][string]$BotUsername,[Parameter(Mandatory)][string]$BotPassword,[int]$SmokeSeconds=200,[ValidateSet('smoke','modes','orders','preflight','resume','recovery')][string]$Scenario='smoke',[string]$CustomerUsername='',[string]$CustomerPassword='',[string]$ReportDirectory='', [string]$Php='D:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe',[string]$Tool='D:\code\App dev\NRO_NATIVE_SLEEP_247\qltk\NroTradingV3\bin\protected-publish\QLTK-Ngoc-V3.exe')
$ErrorActionPreference='Stop'
$repoRoot=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$fixtureRoot=Join-Path ([IO.Path]::GetTempPath()) ('trading-v3-runtime-db-'+[Guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory($fixtureRoot)|Out-Null
$dbPath=Join-Path $fixtureRoot 'test.sqlite';[IO.File]::WriteAllBytes($dbPath,[byte[]]@())
$listener=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0);$listener.Start();$listenPort=$listener.LocalEndpoint.Port;$listener.Stop()
if($Scenario -eq 'resume') {
    $reportBase='D:\code\App dev\NRO_NATIVE_SLEEP_247\qltk\NroTradingV3\live-results\'
    if(-not $ReportDirectory -or -not [IO.Path]::GetFullPath($ReportDirectory).StartsWith($reportBase,[StringComparison]::OrdinalIgnoreCase)){throw 'Resume requires an existing isolated report directory.'}
    $journal=Get-Content -LiteralPath (Join-Path $ReportDirectory 'journal\bot-1.json') -Raw | ConvertFrom-Json
    if($journal.intent -or $journal.outbox.Count -gt 0 -or $journal.order.delivery_method -ne 'consignment' -or -not $journal.order.delivery_data.delivery_item.verified){throw 'Do not resume a purchase with ambiguous intent or unacknowledged work.'}
    $origin=[Uri]$journal.origin;if(-not $origin.IsLoopback -or $origin.Scheme -ne 'http' -or $origin.AbsolutePath -ne '/app/v3/'){throw 'Resume requires the original loopback origin.'}
    $listenPort=$origin.Port;$portCheck=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,$listenPort);$portCheck.Start();$portCheck.Stop()
    Copy-Item -LiteralPath (Join-Path $ReportDirectory 'test-database-no-credentials.sqlite') -Destination $dbPath -Force
}
$settings=@{APP_ENV='testing';APP_DEBUG='false';APP_KEY=('base64:'+([Convert]::ToBase64String([Security.Cryptography.RandomNumberGenerator]::GetBytes(32))));APP_CONFIG_CACHE=(Join-Path $fixtureRoot 'config.php');APP_ROUTES_CACHE=(Join-Path $fixtureRoot 'routes.php');DB_CONNECTION='sqlite';DB_DATABASE=$dbPath;DB_URL='null';CACHE_STORE='database';CACHE_PREFIX=('runtime-'+[Guid]::NewGuid().ToString('N'));SESSION_DRIVER='array';QUEUE_CONNECTION='sync';BROADCAST_CONNECTION='log';LOG_CHANNEL='stderr';NRO_TRADING_V3_ENABLED='true';NRO_TRADING_V3_TOOL_ID='nro-trading-v3';NRO_TRADING_V3_HMAC_SECRET=([Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(32)));NRO_TRADING_V3_API_URL="http://127.0.0.1:$listenPort/app/v3/";TRADING_TEST_BOT_USERNAME=$BotUsername;TRADING_TEST_BOT_PASSWORD=$BotPassword}
$previous=@{};$phpServer=$null;$runtime=$null;$liveSucceeded=$false
$settings['NRO_TRADING_V3_SMOKE_SECONDS']=$SmokeSeconds.ToString()
$settings['NRO_TRADING_V3_MODE_TEST']=if($Scenario -eq 'modes'){'true'}else{'false'}
$settings['NRO_TRADING_V3_RECOVERY_TEST']=if($Scenario -eq 'recovery'){'true'}else{'false'}
if($Scenario -in @('orders','resume','recovery')) {
    if(-not $CustomerUsername -or -not $CustomerPassword){throw 'Live orders require a test customer.'}
    if(-not $ReportDirectory){$ReportDirectory=Join-Path 'D:\code\App dev\NRO_NATIVE_SLEEP_247\qltk\NroTradingV3\live-results' ((Get-Date -Format 'yyyyMMdd-HHmmss')+'-'+[Guid]::NewGuid().ToString('N').Substring(0,8))}
    $settings['TRADING_TEST_CUSTOMER_USERNAME']=$CustomerUsername;$settings['TRADING_TEST_CUSTOMER_PASSWORD']=$CustomerPassword;$settings['NRO_TRADING_V3_REPORT_DIRECTORY']=$ReportDirectory
    $settings['NRO_TRADING_V3_RESUME']=if($Scenario -eq 'resume'){'true'}else{'false'}
}
try {
    foreach($name in $settings.Keys){$previous[$name]=[Environment]::GetEnvironmentVariable($name,'Process');[Environment]::SetEnvironmentVariable($name,$settings[$name],'Process')}
    Push-Location $repoRoot
    try {
        & $Php artisan migrate --force --no-interaction | Out-Null
        if($LASTEXITCODE -ne 0){throw 'Isolated migration failed.'}
        if($Scenario -eq 'resume'){& $Php tests/Support/trading-v3-runtime.php restore}else{& $Php tests/Support/trading-v3-runtime.php}
        if($LASTEXITCODE -ne 0){throw 'Isolated fixture failed.'}
    }finally{Pop-Location}
    $router=if($Scenario -in @('orders','preflight','resume','recovery')){'tests/Support/trading-v3-live-router.php'}else{'public/index.php'}
    $phpServer=Start-Process -FilePath $Php -ArgumentList @('-S',"127.0.0.1:$listenPort",'-t','public',$router) -WorkingDirectory $repoRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $fixtureRoot 'php.out') -RedirectStandardError (Join-Path $fixtureRoot 'php.err')
    Start-Sleep -Milliseconds 500
    if($Scenario -eq 'preflight') {
        $testHeaders=@{'X-Test-Key'=$settings['NRO_TRADING_V3_HMAC_SECRET'];'Accept'='application/json'}
        foreach($method in @('gift_ticket','consignment')) {
            $result=Invoke-RestMethod -Uri "http://127.0.0.1:$listenPort/testing/order" -Method Post -Headers $testHeaders -ContentType 'application/json' -Body (@{method=$method;name='testcustomer'} | ConvertTo-Json -Compress)
            if(-not $result.success -or $result.data.gem_qty -ne 30){throw ('Bounded test-order preflight failed: '+($result | ConvertTo-Json -Compress -Depth 6))}
        }
        $report=Invoke-RestMethod -Uri "http://127.0.0.1:$listenPort/testing/report" -Headers $testHeaders
        if($report.data.orders.Count -ne 2){throw 'Isolated report preflight failed.'}
        Write-Output 'Preflight PASS: local router creates only bounded test orders and returns report; no game login.'
        $liveSucceeded=$true;return
    }
    $testArgument=if($Scenario -eq 'recovery'){'--runtime-recovery-test'}elseif($Scenario -in @('orders','resume')){'--runtime-live-test'}else{'--runtime-smoke'}
    $runtime=Start-Process -FilePath $Tool -ArgumentList $testArgument -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $fixtureRoot 'tool.out') -RedirectStandardError (Join-Path $fixtureRoot 'tool.err')
    $readLines=0
    while(-not $runtime.WaitForExit(1000)) {
        $lines=@(Get-Content -LiteralPath (Join-Path $fixtureRoot 'tool.out') -ErrorAction SilentlyContinue)
        if($lines.Count -gt $readLines){$lines[$readLines..($lines.Count-1)] | Write-Output;$readLines=$lines.Count}
    }
    $lines=@(Get-Content -LiteralPath (Join-Path $fixtureRoot 'tool.out'));if($lines.Count -gt $readLines){$lines[$readLines..($lines.Count-1)] | Write-Output}
    if($runtime.ExitCode -ne 0){Get-Content -LiteralPath (Join-Path $fixtureRoot 'tool.err');throw 'Runtime test stopped; keep evidence and do not replay game actions.'}
    $liveSucceeded=$true
} finally {
    if($runtime -and -not $runtime.HasExited){$runtime.Kill();$runtime.WaitForExit()}
    if($phpServer -and -not $phpServer.HasExited){$phpServer.Kill();$phpServer.WaitForExit()}
    if($Scenario -in @('orders','resume','recovery') -and (Test-Path -LiteralPath $dbPath)) {
        Push-Location $repoRoot
        try { & $Php tests/Support/trading-v3-runtime.php sanitize; if($LASTEXITCODE -ne 0){throw 'Cannot copy a test DB until credentials are removed.'} } finally { Pop-Location }
        if(-not $ReportDirectory){throw 'Cannot preserve failure evidence without report path.'}
        New-Item -ItemType Directory -Path $ReportDirectory -Force | Out-Null
        Copy-Item -LiteralPath $dbPath -Destination (Join-Path $ReportDirectory 'test-database-no-credentials.sqlite') -Force
        foreach($logFile in @('tool.out','tool.err','php.err')){if(Test-Path -LiteralPath (Join-Path $fixtureRoot $logFile)){
            $logText=[string](Get-Content -LiteralPath (Join-Path $fixtureRoot $logFile) -Raw)
            foreach($redact in @($BotUsername,$BotPassword,$CustomerUsername,$CustomerPassword,$settings['NRO_TRADING_V3_HMAC_SECRET'])){if($redact -and -not [string]::IsNullOrEmpty($logText)){$logText=([string]$logText).Replace($redact,'[hidden]')}}
            [IO.File]::WriteAllText((Join-Path $ReportDirectory $logFile),[string]$logText)
        }}
    }
    foreach($name in $previous.Keys){if($null -eq $previous[$name]){Remove-Item -LiteralPath ('Env:'+$name) -ErrorAction SilentlyContinue}else{[Environment]::SetEnvironmentVariable($name,$previous[$name],'Process')}}
    $resolved=[IO.Path]::GetFullPath($fixtureRoot);$tempPrefix=[IO.Path]::GetFullPath([IO.Path]::GetTempPath())
    if($resolved.StartsWith($tempPrefix,[StringComparison]::OrdinalIgnoreCase) -and [IO.Path]::GetFileName($resolved).StartsWith('trading-v3-runtime-db-')){Remove-Item -LiteralPath $resolved -Recurse -Force}
}
