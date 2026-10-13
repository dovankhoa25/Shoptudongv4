param(
    [string]$OutputPath = (Join-Path $PSScriptRoot 'runtime-reports/pending-gift-54481-20261014.json')
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'Measure-TradingV3ApiReadLatency.ps1') -OutputPath $OutputPath -LibraryOnly
$botWorker = $null
$bot = $null
$orders = [System.Collections.Generic.List[object]]::new()
$newWorker = [guid]::NewGuid().ToString()
$credentials = Read-ToolEnvironment (Join-Path $repository '.env.example')
$knownCorrection = [long]0
$previous = Join-Path $PSScriptRoot 'runtime-reports/sv6-api-latency-20261014.json'
if (Test-Path -LiteralPath $previous) {
    try {
        $diagnostic = Get-Content -LiteralPath $previous -Raw | ConvertFrom-Json
        if ([Math]::Abs([long]$diagnostic.clock_correction_seconds) -le 600) {
            $knownCorrection = [long]$diagnostic.clock_correction_seconds
        }
    } catch {}
}
$clockCorrection = $knownCorrection

$filterBot = {
    param($data)
    $match = @($data | Where-Object { $_.id -eq 9975 }) | Select-Object -First 1
    if ($null -eq $match) { return $null }
    $script:botWorker = [string]$match.v3_worker_id
    [pscustomobject]@{
        id = $match.id; name = $match.name; status = $match.status
        server_id = $match.server_id; server_login_id = $match.server_login_id
        server_enabled = $match.server_enabled; server_name = $match.server_name
        map_id = $match.map_id; area_number = $match.area_number; coordinates = $match.coordinates
        gem_qty = $match.gem_qty; gift_ticket_qty = $match.gift_ticket_qty
        consign_item_qty = $match.consign_item_qty; stock_version = $match.stock_version
        last_synced_at = $match.last_synced_at; has_worker = ![string]::IsNullOrEmpty($script:botWorker)
        v3_lease_until = $match.v3_lease_until
    }
}
$filterOrders = {
    param($data)
    foreach ($match in @($data)) {
        if ($null -eq $match -or $match.id -notin @(54481, 54484)) { continue }
        [pscustomobject]@{
            id = $match.id; bot_id = $match.bot_id; server_id = $match.server_id
            character_name = $match.character_name; character_id = $match.character_id
            gem_qty = $match.gem_qty; delivery_method = $match.delivery_method
            status = $match.status; delivery_phase = $match.delivery_phase
            processing_version = $match.processing_version
            has_owner = ![string]::IsNullOrEmpty([string]$match.processing_owner)
            owner_matches_bot_worker = (![string]::IsNullOrEmpty($script:botWorker) -and $match.processing_owner -eq $script:botWorker)
            delivery_data_keys = @($match.delivery_data.PSObject.Properties.Name)
            delivered_gem_qty = $match.delivered_gem_qty; bot_gem_spent_qty = $match.bot_gem_spent_qty
            tickets_used_qty = $match.tickets_used_qty; created_at = $match.created_at
            completed_at = $match.completed_at
        }
    }
}

try {
    if (([string]$credentials['NRO_TRADING_V3_HMAC_SECRET']).Length -lt 32) { throw 'Missing permitted example authentication.' }
    if (([string]$credentials['NRO_TRADING_V3_TOOL_ID']).Length -eq 0) { $credentials['NRO_TRADING_V3_TOOL_ID'] = 'nro-trading-v3' }
    if (Invoke-SignedRead 'gem/bots' $credentials 1 $filterBot) {
        $bot = $filteredData
        if (Invoke-SignedRead ('gem-transactions?worker_id=' + $newWorker + '&server_id=2') $credentials 1 $filterOrders) {
            foreach ($order in @($filteredData)) { if ($null -ne $order) { $orders.Add([pscustomobject]@{ source = 'unowned-list'; order = $order }) } }
        }
        if (![string]::IsNullOrEmpty($botWorker)) {
            if (Invoke-SignedRead ('gem-transactions?worker_id=' + $botWorker + '&server_id=2') $credentials 1 $filterOrders) {
                foreach ($order in @($filteredData)) { if ($null -ne $order) { $orders.Add([pscustomobject]@{ source = 'bot-worker-list'; order = $order }) } }
            }
            $pending = @($orders | Where-Object { $_.order.id -eq 54481 -and $_.order.status -eq 'pending' }).Count -gt 0
            if (!$pending -and $requestCount -lt 5) {
                if (Invoke-SignedRead ('gem-transactions/54481?worker_id=' + $botWorker) $credentials 1 $filterOrders) {
                    foreach ($order in @($filteredData)) { if ($null -ne $order) { $orders.Add([pscustomobject]@{ source = 'detail'; order = $order }) } }
                }
            }
            if ($requestCount -lt 5) {
                if (Invoke-SignedRead ('gem-transactions/54484?worker_id=' + $botWorker) $credentials 1 $filterOrders) {
                    foreach ($order in @($filteredData)) { if ($null -ne $order) { $orders.Add([pscustomobject]@{ source = 'detail'; order = $order }) } }
                }
            }
        }
    }
    foreach ($request in $records) { $request.route = ([string]$request.route).Split('?')[0] }
    $report = [ordered]@{
        recorded_utc = [DateTimeOffset]::UtcNow.ToString('o')
        diagnostic = 'GET only; no login, claim, lease, release, stock update or order mutation.'
        clock_correction_seconds = $clockCorrection
        bot = $bot; orders = $orders.ToArray(); requests = $records.ToArray(); failure = $failure
    }
    [IO.Directory]::CreateDirectory((Split-Path $OutputPath -Parent)) | Out-Null
    $report | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $OutputPath -Encoding utf8
    $report | ConvertTo-Json -Depth 8
} finally { $client.Dispose(); $handler.Dispose() }
