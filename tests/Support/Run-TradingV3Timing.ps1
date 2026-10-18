param(
    [Parameter(Mandatory)][string]$BotUsername,
    [Parameter(Mandatory)][string]$BotPassword,
    [Parameter(Mandatory)][string]$CustomersCredentialPath,
    [Parameter(Mandatory)][string]$ReportDirectory,
    [ValidateSet('both','gift_ticket','gift_shop_interleave','concurrent_receipt')][string]$Methods='both',
    [ValidateRange(180,1800)][int]$MaximumSeconds=900,
    [switch]$Preflight,
    [switch]$ResumeConcurrent,
    [string]$Php='D:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe',
    [string]$ToolDLL='D:\code\App dev\NRO_NATIVE_SLEEP_247\qltk\GemV3.TimingProbe\bin\Release\net8.0\GemV3.TimingProbe.dll'
)
$ErrorActionPreference='Stop'
if($Preflight -and $Methods -ne 'concurrent_receipt'){throw 'The batch preflight applies only to concurrent_receipt; it never launches the game runtime.'}
if($ResumeConcurrent -and $Methods -ne 'concurrent_receipt'){throw 'Resume is restricted to the original concurrent_receipt fixture.'}
$repoRoot=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$reportBase=[IO.Path]::GetFullPath('D:\code\App dev\NRO_NATIVE_SLEEP_247\qltk\NroTradingV3\live-results')+[IO.Path]::DirectorySeparatorChar
$ReportDirectory=[IO.Path]::GetFullPath($ReportDirectory)
if(-not $ReportDirectory.StartsWith($reportBase,[StringComparison]::OrdinalIgnoreCase)){throw 'Timing reports must stay inside the isolated native live-results directory.'}
$resumeArchive=$null;$resumeOrigin=$null
if($ResumeConcurrent){
    foreach($required in @('concurrent-results.json','backend-report.json','test-database-no-credentials.sqlite','journal\worker-id.txt')){if(-not (Test-Path -LiteralPath (Join-Path $ReportDirectory $required) -PathType Leaf)){throw 'Original report, database and worker journal are required for resume.'}}
    $botJournals=@(Get-ChildItem -LiteralPath (Join-Path $ReportDirectory 'journal') -File -Filter 'bot-*.json')
    if($botJournals.Count -ne 1){throw 'Concurrent resume requires the original single bot journal.'}
    $originalState=Get-Content -LiteralPath $botJournals[0].FullName -Raw|ConvertFrom-Json
    $resumeOrigin=[Uri]$originalState.origin
    if(-not $resumeOrigin.IsLoopback -or $resumeOrigin.Scheme -ne 'http' -or $resumeOrigin.AbsolutePath -ne '/app/v3/'){throw 'Original loopback API origin required.'}
    $originalReport=Get-Content -LiteralPath (Join-Path $ReportDirectory 'concurrent-results.json') -Raw|ConvertFrom-Json
    if($originalReport.scenario -ne 'concurrent_receipt' -or $originalReport.success){throw 'Resume requires an unfinished original concurrent test.'}
    $workerLock=Join-Path $ReportDirectory 'journal\worker.lock'
    if(Test-Path -LiteralPath $workerLock){$lockCheck=[IO.File]::Open($workerLock,[IO.FileMode]::Open,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None);$lockCheck.Dispose()}
}elseif((Test-Path -LiteralPath $ReportDirectory) -and @(Get-ChildItem -LiteralPath $ReportDirectory -Force).Count -ne 0){throw 'Choose an empty report directory. Existing evidence must not be overwritten or replayed.'}
$CustomersCredentialPath=[IO.Path]::GetFullPath($CustomersCredentialPath)
$ToolDLL=[IO.Path]::GetFullPath($ToolDLL)
if(-not (Test-Path -LiteralPath $ToolDLL -PathType Leaf)){throw 'Build the external GemV3.TimingProbe console project before running the fixture.'}
if(-not (Test-Path -LiteralPath $CustomersCredentialPath -PathType Leaf)){throw 'The ephemeral customer credentials file is missing.'}
$customers=@(Get-Content -LiteralPath $CustomersCredentialPath -Raw | ConvertFrom-Json)
if($customers.Count -lt 1 -or $customers.Count -gt 6){throw 'Only the authorized one-to-six test customer accounts are supported.'}
foreach($customer in $customers){if(-not $customer.username -or -not $customer.password){throw 'Every customer requires an ephemeral username and password.'}}

$fixtureRoot=Join-Path ([IO.Path]::GetTempPath()) ('trading-v3-runtime-db-'+[Guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory($fixtureRoot)|Out-Null
[IO.Directory]::CreateDirectory($ReportDirectory)|Out-Null
$dbPath=Join-Path $fixtureRoot 'test.sqlite'
[IO.File]::WriteAllBytes($dbPath,[byte[]]@())
$listener=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,$(if($ResumeConcurrent){$resumeOrigin.Port}else{0}))
$listener.Start();$listenPort=$listener.LocalEndpoint.Port;$listener.Stop()
if($ResumeConcurrent){
    $resumeArchive=Join-Path $ReportDirectory ('resume-history\'+(Get-Date -Format 'yyyyMMdd-HHmmss')+'-'+[Guid]::NewGuid().ToString('N').Substring(0,8))
    [IO.Directory]::CreateDirectory($resumeArchive)|Out-Null
    foreach($source in Get-ChildItem -LiteralPath $ReportDirectory -File){Copy-Item -LiteralPath $source.FullName -Destination (Join-Path $resumeArchive $source.Name)}
    Copy-Item -LiteralPath (Join-Path $ReportDirectory 'journal') -Destination (Join-Path $resumeArchive 'journal') -Recurse
    Copy-Item -LiteralPath (Join-Path $ReportDirectory 'test-database-no-credentials.sqlite') -Destination $dbPath -Force
}
$settings=@{
    APP_ENV='testing';APP_DEBUG='false'
    APP_KEY=('base64:'+([Convert]::ToBase64String([Security.Cryptography.RandomNumberGenerator]::GetBytes(32))))
    APP_CONFIG_CACHE=(Join-Path $fixtureRoot 'config.php');APP_ROUTES_CACHE=(Join-Path $fixtureRoot 'routes.php')
    DB_CONNECTION='sqlite';DB_DATABASE=$dbPath;DB_URL='null'
    CACHE_STORE='database';CACHE_PREFIX=('timing-'+[Guid]::NewGuid().ToString('N'))
    SESSION_DRIVER='array';QUEUE_CONNECTION='sync';BROADCAST_CONNECTION='log';LOG_CHANNEL='stderr'
    NRO_TRADING_V3_ENABLED='true';NRO_TRADING_V3_TOOL_ID='nro-trading-v3'
    NRO_TRADING_V3_HMAC_SECRET=([Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(32)))
    NRO_TRADING_V3_API_URL="http://127.0.0.1:$listenPort/app/v3/"
    NRO_TRADING_V3_RENEWAL_INTERVAL_SECONDS='150'
    NRO_TRADING_V3_ACTIVITY_QUEUE_CONNECTION='database';NRO_TRADING_V3_ACTIVITY_QUEUE='trading-timing-test'
    NRO_TRADING_V3_ACTIVITY_CACHE_STORE='database'
    NRO_TRADING_V3_REPORT_DIRECTORY=$ReportDirectory
    NRO_TRADING_V3_TIMING_METHODS=$Methods
    NRO_TRADING_V3_TIMING_RESUME=([bool]$ResumeConcurrent).ToString().ToLowerInvariant()
    NRO_TRADING_V3_TIMING_SECONDS=$MaximumSeconds.ToString()
    NRO_TRADING_V3_SMOKE_SECONDS=$MaximumSeconds.ToString()
    TRADING_TEST_BOT_USERNAME=$BotUsername;TRADING_TEST_BOT_PASSWORD=$BotPassword
    TRADING_TEST_CUSTOMERS_FILE=$CustomersCredentialPath
}
$redactions=@($BotUsername,$BotPassword,$settings.NRO_TRADING_V3_HMAC_SECRET,$settings.APP_KEY)
foreach($customer in $customers){$redactions+=@([string]$customer.username,[string]$customer.password)}
$settings.TRADING_TEST_REDACTIONS_JSON=ConvertTo-Json -InputObject $redactions -Compress
$redact={param([string]$text) foreach($secret in $redactions){if($secret){$text=$text.Replace($secret,'[hidden]')}};return $text}
$previous=@{};$phpServer=$null;$runtime=$null;$failure=$null
try {
    foreach($name in $settings.Keys){$previous[$name]=[Environment]::GetEnvironmentVariable($name,'Process');[Environment]::SetEnvironmentVariable($name,[string]$settings[$name],'Process')}
    Push-Location $repoRoot
    try {
        if(-not $ResumeConcurrent){& $Php artisan migrate --force --no-interaction | Out-Null;if($LASTEXITCODE -ne 0){throw 'Isolated SQLite migration failed.'}}
        $seedOutput=if($ResumeConcurrent){& $Php tests/Support/trading-v3-timing.php restore 2>&1}else{& $Php tests/Support/trading-v3-timing.php seed 2>&1}
        [IO.File]::WriteAllText((Join-Path $fixtureRoot 'seed.log'),(& $redact ([string]($seedOutput -join [Environment]::NewLine))))
        if($LASTEXITCODE -ne 0){throw 'Isolated SV6 fixture failed.'}
    }finally{Pop-Location}
    $phpServer=Start-Process -FilePath $Php -ArgumentList @('-S',"127.0.0.1:$listenPort",'-t','public','tests/Support/trading-v3-timing-router.php') -WorkingDirectory $repoRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $fixtureRoot 'php.out') -RedirectStandardError (Join-Path $fixtureRoot 'php.err')
    $testHeaders=@{'X-Test-Key'=$settings.NRO_TRADING_V3_HMAC_SECRET;'Accept'='application/json'}
    $ready=$false
    for($attempt=0;$attempt -lt 20;$attempt++){
        try{
            $health=Invoke-RestMethod -Uri "http://127.0.0.1:$listenPort/testing/report" -Headers $testHeaders -TimeoutSec 2
            if($health.success -and $health.data.orders.Count -eq $(if($ResumeConcurrent){3}else{0})){$ready=$true;break}
        }catch{}
        if($phpServer.HasExited){throw 'The loopback PHP fixture exited before startup.'}
        Start-Sleep -Milliseconds 250
    }
    if(-not $ready){throw 'The loopback PHP fixture did not become ready.'}
    if($Preflight){
        if($ResumeConcurrent){Write-Output 'Concurrent resume preflight PASS: original three orders, journal and origin retained; no game login.';return}
        $batchBody=@{names=@('timingtestfirst','timingtestsecond')}|ConvertTo-Json -Compress
        $batch=Invoke-RestMethod -Uri "http://127.0.0.1:$listenPort/testing/orders" -Method Post -Headers $testHeaders -ContentType 'application/json' -Body $batchBody
        if(-not $batch.success -or $batch.data.Count -ne 3 -or @($batch.data|Where-Object delivery_method -eq 'consignment').Count -ne 2 -or ($batch.data|Measure-Object gem_qty -Sum).Sum -ne 70){throw 'Atomic batch limits failed.'}
        $replayBlocked=$false
        try{Invoke-RestMethod -Uri "http://127.0.0.1:$listenPort/testing/orders" -Method Post -Headers $testHeaders -ContentType 'application/json' -Body $batchBody|Out-Null}catch{if([int]$_.Exception.Response.StatusCode -eq 409){$replayBlocked=$true}else{throw}}
        if(-not $replayBlocked){throw 'The fixture accepted a duplicate batch.'}
        $batchPreflightReport=Invoke-RestMethod -Uri "http://127.0.0.1:$listenPort/testing/report" -Headers $testHeaders
        if($batchPreflightReport.data.orders.Count -ne 3 -or $batchPreflightReport.data.bounds.total_gems -ne 70){throw 'Batch preflight report mismatch.'}
        Write-Output 'Concurrent timing preflight PASS: atomic 30+30+10 batch, duplicate blocked, no game login.'
        return
    }
    $dotnet=(Get-Command dotnet -ErrorAction Stop).Source
    $runtime=Start-Process -FilePath $dotnet -ArgumentList ('"'+$ToolDLL+'"') -WorkingDirectory $repoRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $fixtureRoot 'tool.out') -RedirectStandardError (Join-Path $fixtureRoot 'tool.err')
    $watch=[Diagnostics.Stopwatch]::StartNew();$readLines=0
    while(-not $runtime.WaitForExit(1000)){
        $lines=@(Get-Content -LiteralPath (Join-Path $fixtureRoot 'tool.out') -ErrorAction SilentlyContinue)
        if($lines.Count -gt $readLines){foreach($line in $lines[$readLines..($lines.Count-1)]){& $redact ([string]$line) | Write-Output};$readLines=$lines.Count}
        if($watch.Elapsed.TotalSeconds -gt ($MaximumSeconds+30)){throw 'Timing probe exceeded its bounded deadline. Preserve the journal; do not replay game actions.'}
    }
    $lines=@(Get-Content -LiteralPath (Join-Path $fixtureRoot 'tool.out') -ErrorAction SilentlyContinue)
    if($lines.Count -gt $readLines){foreach($line in $lines[$readLines..($lines.Count-1)]){& $redact ([string]$line) | Write-Output}}
    if($runtime.ExitCode -ne 0){throw 'Timing probe stopped before the selected orders completed. Evidence is preserved; do not replay without checking the journal.'}
}catch{$failure=$_}finally{
    try {
        if($runtime -and -not $runtime.HasExited){$runtime.Kill();$runtime.WaitForExit()}
        if($phpServer -and -not $phpServer.HasExited){
            try{
                $final=Invoke-RestMethod -Uri "http://127.0.0.1:$listenPort/testing/report" -Headers $testHeaders -TimeoutSec 5
                [IO.File]::WriteAllText((Join-Path $ReportDirectory 'backend-report.json'),($final | ConvertTo-Json -Depth 35))
            }catch{[IO.File]::WriteAllText((Join-Path $ReportDirectory 'backend-report-error.txt'),'The final local report was unavailable; inspect the preserved tool journal and sanitized DB.')}
            $phpServer.Kill();$phpServer.WaitForExit()
        }
        foreach($logFile in @('tool.out','tool.err','php.out','php.err','seed.log','http-timeline.jsonl')){
            $source=Join-Path $fixtureRoot $logFile
            if(Test-Path -LiteralPath $source){$text=[string](Get-Content -LiteralPath $source -Raw);$target=Join-Path $ReportDirectory $logFile;if($ResumeConcurrent){[IO.File]::AppendAllText($target,[Environment]::NewLine+(& $redact $text))}else{[IO.File]::WriteAllText($target,(& $redact $text))}}
        }
        if(Test-Path -LiteralPath $dbPath){
            Push-Location $repoRoot
            try{& $Php tests/Support/trading-v3-timing.php sanitize;if($LASTEXITCODE -ne 0){throw 'Credential sanitization failed; an unsafe DB will not be copied.'}}finally{Pop-Location}
            Copy-Item -LiteralPath $dbPath -Destination (Join-Path $ReportDirectory 'test-database-no-credentials.sqlite')
        }
        if($failure){$errorTarget=Join-Path $ReportDirectory 'runner-error.txt';if($ResumeConcurrent){[IO.File]::AppendAllText($errorTarget,[Environment]::NewLine+(& $redact ([string]$failure)))}else{[IO.File]::WriteAllText($errorTarget,(& $redact ([string]$failure)))}}
    }catch{if(-not $failure){$failure=$_}}finally{
        if(Test-Path -LiteralPath $CustomersCredentialPath){Remove-Item -LiteralPath $CustomersCredentialPath -Force}
        foreach($name in $previous.Keys){if($null -eq $previous[$name]){Remove-Item -LiteralPath ('Env:'+$name) -ErrorAction SilentlyContinue}else{[Environment]::SetEnvironmentVariable($name,$previous[$name],'Process')}}
        $resolved=[IO.Path]::GetFullPath($fixtureRoot)
        $tempPrefix=[IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\','/')+[IO.Path]::DirectorySeparatorChar
        if(-not $resolved.StartsWith($tempPrefix,[StringComparison]::OrdinalIgnoreCase) -or -not [IO.Path]::GetFileName($resolved).StartsWith('trading-v3-runtime-db-')){throw 'Refusing to clean a path outside the generated temporary fixture.'}
        Remove-Item -LiteralPath $resolved -Recurse -Force
    }
}
if($failure){throw $failure}
Write-Output ('Timing evidence saved in '+$ReportDirectory)
