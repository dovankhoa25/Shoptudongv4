param(
    [Parameter(Mandatory)][string]$MainUsername,
    [Parameter(Mandatory)][string]$MainPassword,
    [Parameter(Mandatory)][string]$AuxUsername,
    [Parameter(Mandatory)][string]$AuxPassword,
    [Parameter(Mandatory)][string]$GameHost,
    [int]$GamePort=14445,
    [Parameter(Mandatory)][int]$MainBars,
    [Parameter(Mandatory)][int]$AuxBars,
    [Parameter(Mandatory)][int]$MainNrjBars,
    [Parameter(Mandatory)][int]$AuxNrjBars,
    [long]$MainPureGold=0,
    [long]$AuxPureGold=0,
    [switch]$Preflight,
    [string]$ReportDirectory='',
    [string]$Php='D:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe',
    [string]$Tool='D:\code\App dev\NRO_NATIVE_SLEEP_247\qltk\NroTradingV3\bin\Release\net8.0\QLTK-Ngoc-V3.exe'
)
$ErrorActionPreference='Stop'
$repoRoot=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$reportBase='D:\code\App dev\NRO_NATIVE_SLEEP_247\qltk\NroTradingV3\gold-live-results'
if(-not $ReportDirectory){$ReportDirectory=Join-Path $reportBase ((Get-Date -Format 'yyyyMMdd-HHmmss')+'-sv7-roundtrip-'+[Guid]::NewGuid().ToString('N').Substring(0,8))}
$ReportDirectory=[IO.Path]::GetFullPath($ReportDirectory)
if(-not $ReportDirectory.StartsWith(($reportBase+'\'),[StringComparison]::OrdinalIgnoreCase)){throw 'Reports must remain in the tool gold-live-results directory.'}
if((Test-Path -LiteralPath $ReportDirectory) -and @(Get-ChildItem -LiteralPath $ReportDirectory -Force).Count){throw 'Fresh evidence directory required; never replay an existing game mutation.'}
if($MainBars -ne 0 -or $AuxBars -lt 1 -or $AuxBars -gt 999 -or $MainNrjBars -lt 0 -or $MainNrjBars -gt 999 -or $AuxNrjBars -lt 0 -or $AuxNrjBars -gt 999){throw 'Observed main0 and auxiliary1..999 baselines are required.'}
if($MainUsername.Equals($AuxUsername,[StringComparison]::OrdinalIgnoreCase)){throw 'Two different accounts required.'}
[IO.Directory]::CreateDirectory($ReportDirectory)|Out-Null
$fixtureRoot=Join-Path ([IO.Path]::GetTempPath()) ('trading-v3-gold-sv7-db-'+[Guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory($fixtureRoot)|Out-Null
$dbPath=Join-Path $fixtureRoot 'test.sqlite';[IO.File]::WriteAllBytes($dbPath,[byte[]]@())
$listener=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0);$listener.Start();$listenPort=$listener.LocalEndpoint.Port;$listener.Stop()
$settings=@{
    APP_ENV='testing';APP_DEBUG='false';APP_KEY=('base64:'+([Convert]::ToBase64String([Security.Cryptography.RandomNumberGenerator]::GetBytes(32))));
    APP_CONFIG_CACHE=(Join-Path $fixtureRoot 'config.php');APP_ROUTES_CACHE=(Join-Path $fixtureRoot 'routes.php');
    DB_CONNECTION='sqlite';DB_DATABASE=$dbPath;DB_URL='null';CACHE_STORE='database';CACHE_PREFIX=('sv7-gold-'+[Guid]::NewGuid().ToString('N'));
    SESSION_DRIVER='array';QUEUE_CONNECTION='sync';BROADCAST_CONNECTION='null';LOG_CHANNEL='stderr';
    NRO_TRADING_V3_ENABLED='true';NRO_TRADING_V3_TOOL_ID='nro-trading-v3';NRO_TRADING_V3_HMAC_SECRET=([Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(32)));
    NRO_TRADING_V3_API_URL="http://127.0.0.1:$listenPort/app/v3/";NRO_GOLD_TEST_ALLOWED='true';NRO_GOLD_SV7_TEST='true';NRO_GOLD_REPORT_DIRECTORY=$ReportDirectory;
    NRO_GOLD_SV7_MAIN_USER=$MainUsername;NRO_GOLD_SV7_MAIN_PASSWORD=$MainPassword;NRO_GOLD_SV7_AUX_USER=$AuxUsername;NRO_GOLD_SV7_AUX_PASSWORD=$AuxPassword;
    NRO_GOLD_SV7_HOST=$GameHost;NRO_GOLD_SV7_PORT=[string]$GamePort;NRO_GOLD_SV7_MAIN_BARS=[string]$MainBars;NRO_GOLD_SV7_AUX_BARS=[string]$AuxBars;
    NRO_GOLD_SV7_MAIN_NRJ_BARS=[string]$MainNrjBars;NRO_GOLD_SV7_AUX_NRJ_BARS=[string]$AuxNrjBars;
    NRO_GOLD_SV7_MAIN_PURE_GOLD=[string]$MainPureGold;NRO_GOLD_SV7_AUX_PURE_GOLD=[string]$AuxPureGold
}
$previous=@{};$phpServer=$null;$runtime=$null;$dbSanitized=$false;$schemaReady=$false
function Redact([string]$Text){foreach($value in @($MainUsername,$MainPassword,$AuxUsername,$AuxPassword,$settings['NRO_TRADING_V3_HMAC_SECRET'])){if($value){$Text=$Text.Replace($value,'[hidden]')}};return $Text}
try{
    foreach($name in $settings.Keys){$previous[$name]=[Environment]::GetEnvironmentVariable($name,'Process');[Environment]::SetEnvironmentVariable($name,$settings[$name],'Process')}
    Push-Location $repoRoot
    try{
        & $Php artisan migrate --force --no-interaction | Out-Null
        if($LASTEXITCODE -ne 0){throw 'Isolated SV7 migration failed.'};$schemaReady=$true
        $seed=@(& $Php tests/Support/trading-v3-gold-sv7-runtime.php 2>&1);$seedExit=$LASTEXITCODE
        $seed|ForEach-Object{Redact ([string]$_)}|Write-Output
        if($seedExit -ne 0 -or -not ($seed|Out-String).Contains('Created isolated SV7 two-bot fixture')){throw 'SV7 fixture initialization failed; no game login started.'}
    }finally{Pop-Location}
    [IO.File]::WriteAllText((Join-Path $ReportDirectory 'test-origin.txt'),$settings['NRO_TRADING_V3_API_URL'])
    $phpServer=Start-Process -FilePath $Php -ArgumentList @('-S',"127.0.0.1:$listenPort",'-t','public','tests/Support/trading-v3-gold-sv7-router.php') -WorkingDirectory $repoRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $fixtureRoot 'php.out') -RedirectStandardError (Join-Path $fixtureRoot 'php.err')
    Start-Sleep -Milliseconds 500
    if($Preflight){
        $headers=@{'X-Test-Key'=$settings['NRO_TRADING_V3_HMAC_SECRET'];Accept='application/json'}
        $report=Invoke-RestMethod -Uri "http://127.0.0.1:$listenPort/testing-gold/report" -Headers $headers
        if(-not $report.success -or $report.data.bots.Count -ne 2 -or $report.data.bots[0].id -ne 1 -or $report.data.bots[1].id -ne 3 -or $report.data.stage -ne 'refill' -or $report.data.transfers.Count -ne 0){throw 'SV7 bounded fixture report failed.'}
        $requestPath='/app/v3/gold/bots';$timestamp=[string][DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
        $nonce=[Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(16)).ToLowerInvariant()
        $emptyHash=[Convert]::ToHexString([Security.Cryptography.SHA256]::HashData([byte[]]@())).ToLowerInvariant()
        $canonical=@('GET',$requestPath,'nro-trading-v3',$timestamp,$nonce,$emptyHash)-join "`n"
        $hmac=[Security.Cryptography.HMACSHA256]::new([Text.Encoding]::UTF8.GetBytes($settings['NRO_TRADING_V3_HMAC_SECRET']))
        try{$signature=[Convert]::ToHexString($hmac.ComputeHash([Text.Encoding]::UTF8.GetBytes($canonical))).ToLowerInvariant()}finally{$hmac.Dispose()}
        $apiHeaders=@{'X-Tool-Id'='nro-trading-v3';'X-Tool-Timestamp'=$timestamp;'X-Tool-Nonce'=$nonce;'X-Tool-Signature'=$signature;Accept='application/json'}
        $bots=@((Invoke-RestMethod -Uri "http://127.0.0.1:$listenPort$requestPath" -Headers $apiHeaders).data)
        $safeConnections=@($bots|ForEach-Object{@{id=$_.id;server_id=$_.server_id;server_login_id=$_.server_login_id;host=$_.login.ip;port=$_.login.port;status=$_.status;server_enabled=$_.server_enabled;map_id=$_.map_id;area_number=$_.area_number;coordinates=$_.coordinates}})
        [IO.File]::WriteAllText((Join-Path $ReportDirectory 'connection-check.json'),($safeConnections|ConvertTo-Json -Depth 6))
        if($bots.Count -ne 2 -or $bots[0].id -ne 1 -or $bots[1].id -ne 3 -or $bots[0].server_id -le 0 -or $bots[0].server_id -ne $bots[1].server_id){throw 'Signed gold API must return exactly IDs1/3 on the same independent business server.'}
        foreach($bot in $bots){
            if($bot.server_login_id -ne 7 -or $bot.login.id -ne 7 -or $bot.login.ip -ne $GameHost -or $bot.login.port -ne $GamePort -or -not $bot.status -or -not $bot.server_enabled -or $bot.map_id -ne 84 -or $bot.area_number -ne 41 -or $bot.coordinates -ne '652-336'){throw 'Signed gold API endpoint/login/target differs from the bounded SV7 fixture.'}
        }
        $blocked=$false
        try{Invoke-RestMethod -Uri "http://127.0.0.1:$listenPort/testing-gold/stage" -Method Post -Headers $headers -ContentType 'application/json' -Body '{"stage":"store"}'|Out-Null}catch{if([int]$_.Exception.Response.StatusCode -eq 409){$blocked=$true}else{throw}}
        if(-not $blocked){throw 'SV7 fixture must reject return before the original refill receipt.'}
        [IO.File]::WriteAllText((Join-Path $ReportDirectory 'preflight.json'),($report|ConvertTo-Json -Depth 12))
        Write-Output 'SV7 preflight PASS: signed API IDs1/3, independent business server, login7 and target84/41; premature return rejected; no game login.'
        return
    }
    $runtime=Start-Process -FilePath $Tool -ArgumentList '--gold-sv7-roundtrip' -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $fixtureRoot 'tool.out') -RedirectStandardError (Join-Path $fixtureRoot 'tool.err')
    $readLines=0;$deadline=[DateTimeOffset]::UtcNow.AddMinutes(22)
    while(-not $runtime.WaitForExit(1000)){
        $lines=@(Get-Content -LiteralPath (Join-Path $fixtureRoot 'tool.out') -ErrorAction SilentlyContinue)
        if($lines.Count -gt $readLines){$lines[$readLines..($lines.Count-1)]|ForEach-Object{Redact $_}|Write-Output;$readLines=$lines.Count}
        if([DateTimeOffset]::UtcNow -ge $deadline){throw 'Bounded SV7 runtime exceeded22minutes; preserve evidence and reconcile before any new attempt.'}
    }
    $lines=@(Get-Content -LiteralPath (Join-Path $fixtureRoot 'tool.out'))
    if($lines.Count -gt $readLines){$lines[$readLines..($lines.Count-1)]|ForEach-Object{Redact $_}|Write-Output}
    if($runtime.ExitCode -ne 0){Get-Content -LiteralPath (Join-Path $fixtureRoot 'tool.err')|ForEach-Object{Redact $_};throw 'SV7 test stopped; preserve original journal and fixture, do not replay.'}
}finally{
    if($runtime -and -not $runtime.HasExited){$runtime.Kill();$runtime.WaitForExit()}
    if($phpServer -and -not $phpServer.HasExited){$phpServer.Kill();$phpServer.WaitForExit()}
    if($schemaReady){
        Push-Location $repoRoot
        try{& $Php tests/Support/trading-v3-gold-sv7-runtime.php sanitize;if($LASTEXITCODE -ne 0){throw 'Cannot archive SV7 DB until credentials are removed.'};$dbSanitized=$true}finally{Pop-Location}
        Copy-Item -LiteralPath $dbPath -Destination (Join-Path $ReportDirectory 'test-database-no-credentials.sqlite')
        if(Test-Path -LiteralPath (Join-Path $fixtureRoot 'sv7-fixture.json')){Copy-Item -LiteralPath (Join-Path $fixtureRoot 'sv7-fixture.json') -Destination (Join-Path $ReportDirectory 'fixture-baseline.json')}
    }
    foreach($file in @('tool.out','tool.err','php.err')){if(Test-Path -LiteralPath (Join-Path $fixtureRoot $file)){[IO.File]::WriteAllText((Join-Path $ReportDirectory $file),(Redact ([string](Get-Content -LiteralPath (Join-Path $fixtureRoot $file) -Raw))))}}
    foreach($name in $previous.Keys){if($null -eq $previous[$name]){Remove-Item -LiteralPath ('Env:'+$name) -ErrorAction SilentlyContinue}else{[Environment]::SetEnvironmentVariable($name,$previous[$name],'Process')}}
    $resolved=[IO.Path]::GetFullPath($fixtureRoot);$tempPrefix=[IO.Path]::GetFullPath([IO.Path]::GetTempPath())
    if($dbSanitized -and $resolved.StartsWith($tempPrefix,[StringComparison]::OrdinalIgnoreCase) -and [IO.Path]::GetFileName($resolved).StartsWith('trading-v3-gold-sv7-db-')){Remove-Item -LiteralPath $resolved -Recurse -Force}
    Write-Output ('SV7 evidence: '+$ReportDirectory)
}
