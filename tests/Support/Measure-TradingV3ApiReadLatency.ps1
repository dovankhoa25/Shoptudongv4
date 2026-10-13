param(
    [string]$OutputPath = (Join-Path $PSScriptRoot 'runtime-reports/sv6-api-latency-20261014.json'),
    [switch]$LibraryOnly
)
$ErrorActionPreference = 'Stop'
$repository = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$baseUri = [Uri]'https://api.shophhp.net/app/v3/'
$handler = [System.Net.Http.HttpClientHandler]::new()
$handler.AllowAutoRedirect = $false
$client = [System.Net.Http.HttpClient]::new($handler)
$client.Timeout = [TimeSpan]::FromSeconds(20)
$records = [System.Collections.Generic.List[object]]::new()
$clockCorrection = 0L
$requestCount = 0
$unauthorizedCount = 0
$credentialSource = $null
$capabilities = $null
$failure = $null

function Read-ToolEnvironment([string]$path) {
    $values = @{}
    if (!(Test-Path -LiteralPath $path)) { return $values }
    foreach ($line in [IO.File]::ReadAllLines($path)) {
        if ($line -match '^\s*(NRO_TRADING_V3_(?:HMAC_SECRET|TOOL_ID))\s*=\s*(.*?)\s*$') {
            $values[$matches[1]] = $matches[2].Trim('"', "'")
        }
    }
    return $values
}

function Invoke-SignedRead([string]$route, [hashtable]$credentials, [int]$round, [scriptblock]$filter = $null) {
    $script:filteredData = $null
    for ($attempt = 0; $attempt -le 1; $attempt++) {
        if ($script:requestCount -ge 12 -or $script:unauthorizedCount -ge 2) { return $false }
        $script:requestCount++
        $uri = [Uri]::new($baseUri, $route)
        if ($uri.Host -ne $baseUri.Host -or $uri.Scheme -ne 'https') { throw 'Invalid diagnostic origin.' }
        $timestamp = [DateTimeOffset]::UtcNow.AddSeconds($script:clockCorrection).ToUnixTimeSeconds().ToString()
        $nonceBytes = [byte[]]::new(16)
        $random = [Security.Cryptography.RandomNumberGenerator]::Create()
        try { $random.GetBytes($nonceBytes) } finally { $random.Dispose() }
        $nonce = [Convert]::ToHexString($nonceBytes).ToLowerInvariant()
        $bodyHash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'
        $canonical = @('GET', $uri.PathAndQuery, $credentials['NRO_TRADING_V3_TOOL_ID'], $timestamp, $nonce, $bodyHash) -join "`n"
        $hmac = [Security.Cryptography.HMACSHA256]::new([Text.Encoding]::UTF8.GetBytes($credentials['NRO_TRADING_V3_HMAC_SECRET']))
        try { $signature = [Convert]::ToHexString($hmac.ComputeHash([Text.Encoding]::UTF8.GetBytes($canonical))).ToLowerInvariant() }
        finally { $hmac.Dispose() }
        $request = [System.Net.Http.HttpRequestMessage]::new([System.Net.Http.HttpMethod]::Get, $uri)
        $request.Headers.Accept.ParseAdd('application/json')
        $request.Headers.Add('X-Tool-Id', $credentials['NRO_TRADING_V3_TOOL_ID'])
        $request.Headers.Add('X-Tool-Timestamp', $timestamp)
        $request.Headers.Add('X-Tool-Nonce', $nonce)
        $request.Headers.Add('X-Tool-Signature', $signature)
        $deadline = [Threading.CancellationTokenSource]::new([TimeSpan]::FromSeconds(20))
        $stopwatch = [Diagnostics.Stopwatch]::StartNew()
        $reply = $null
        try {
            $reply = $client.SendAsync($request, [System.Net.Http.HttpCompletionOption]::ResponseHeadersRead, $deadline.Token).GetAwaiter().GetResult()
            $bytes = $reply.Content.ReadAsByteArrayAsync($deadline.Token).GetAwaiter().GetResult()
            $stopwatch.Stop()
            $parsed = $null
            try { $parsed = [Text.Encoding]::UTF8.GetString($bytes) | ConvertFrom-Json } catch {}
            $dataCount = $null
            if ($null -ne $parsed -and $null -ne $parsed.data) {
                if ($parsed.data -is [Array]) { $dataCount = $parsed.data.Count }
                else { $dataCount = @($parsed.data.PSObject.Properties).Count }
            }
            $records.Add([pscustomobject]@{
                round = $round; route = $route; status = [int]$reply.StatusCode
                duration_ms = [Math]::Round($stopwatch.Elapsed.TotalMilliseconds, 1)
                data_count = $dataCount; response_size = $bytes.Length
            })
            if ([int]$reply.StatusCode -eq 401) {
                $script:unauthorizedCount++
                if ($attempt -eq 0 -and $null -ne $reply.Headers.Date -and $script:unauthorizedCount -lt 2) {
                    $serverDate = [DateTimeOffset]$reply.Headers.Date
                    $correction = [long][Math]::Round($serverDate.Subtract([DateTimeOffset]::UtcNow).TotalSeconds)
                    if ([Math]::Abs($correction - $script:clockCorrection) -gt 60 -and [Math]::Abs($correction) -le 600) {
                        $script:clockCorrection = $correction
                        continue
                    }
                }
                return $false
            }
            if (!$reply.IsSuccessStatusCode -or $null -eq $parsed -or !$parsed.success) {
                $script:failure = 'API rejected or returned an invalid response.'
                return $false
            }
            if ($route -eq 'config') {
                $script:capabilities = [pscustomobject]@{
                    batch_heartbeat_supported = [bool]$parsed.data.batch_heartbeat_supported
                    batch_activity_supported = [bool]$parsed.data.batch_activity_supported
                }
            }
            if ($null -ne $filter) { $script:filteredData = & $filter $parsed.data }
            return $true
        } catch {
            $stopwatch.Stop()
            $records.Add([pscustomobject]@{
                round = $round; route = $route; status = 'transport_error'
                duration_ms = [Math]::Round($stopwatch.Elapsed.TotalMilliseconds, 1)
                data_count = $null; response_size = $null
                error_type = $_.Exception.GetBaseException().GetType().Name
            })
            $script:failure = 'Transport request failed or exceeded 20 seconds.'
            return $false
        } finally {
            if ($null -ne $reply) { $reply.Dispose() }
            $request.Dispose()
            $deadline.Dispose()
        }
    }
    return $false
}

if ($LibraryOnly) { return }

try {
    $authenticated = $false
    $credentials = $null
    foreach ($name in @('.env.example', '.env.production')) {
        $candidate = Read-ToolEnvironment (Join-Path $repository $name)
        if ([string]$candidate['NRO_TRADING_V3_HMAC_SECRET'] -match 'replace|example|your_|change.?me|dummy|test-secret') { continue }
        if (([string]$candidate['NRO_TRADING_V3_HMAC_SECRET']).Length -lt 32) { continue }
        if (([string]$candidate['NRO_TRADING_V3_TOOL_ID']).Length -eq 0) { $candidate['NRO_TRADING_V3_TOOL_ID'] = 'nro-trading-v3' }
        if (Invoke-SignedRead 'config' $candidate 1) {
            $credentials = $candidate; $credentialSource = $name; $authenticated = $true; break
        }
        if ($requestCount -ge 12 -or $unauthorizedCount -ge 2 -or $null -ne $failure) { break }
    }
    if ($authenticated) {
        $okay = Invoke-SignedRead 'gem/bots' $credentials 1
        for ($round = 2; $round -le 3 -and $okay; $round++) {
            $okay = Invoke-SignedRead 'config' $credentials $round
            if ($okay) { $okay = Invoke-SignedRead 'gem/bots' $credentials $round }
        }
    } elseif ($null -eq $failure) { $failure = 'No matching authentication after bounded attempts.' }
    $report = [ordered]@{
        recorded_utc = [DateTimeOffset]::UtcNow.ToString('o')
        base_url = $baseUri.AbsoluteUri
        diagnostic = 'GET only; not a measurement of financial updates or game operations.'
        credential_source = $credentialSource
        clock_correction_seconds = $clockCorrection
        capabilities = $capabilities
        requests = $records.ToArray()
        failure = $failure
    }
    $parent = Split-Path $OutputPath -Parent
    [IO.Directory]::CreateDirectory($parent) | Out-Null
    $report | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $OutputPath -Encoding utf8
    $report | ConvertTo-Json -Depth 5
} finally { $client.Dispose(); $handler.Dispose() }
