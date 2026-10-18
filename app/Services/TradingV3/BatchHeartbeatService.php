<?php

namespace App\Services\TradingV3;

/** Retired wire contract only. Current tools obtain bot configuration and orders directly. */
class BatchHeartbeatService
{
    public function renew(array $data): array
    {
        // No bot queries, row locks, worker metadata writes, cache invalidation or cron policy.
        // `granted` is retained only as an acknowledgement field for older binaries.
        $ack=fn(int $id)=>['id'=>$id,'granted'=>true,'code'=>'compatibility','lease_until'=>null];
        return [
            'single_tool_mode'=>true,'purpose'=>'compatibility','server_time'=>now()->toIso8601String(),
            'bot_lease_required'=>false,'heartbeat_required'=>false,
            'renewal_interval_seconds'=>0,'lease_seconds'=>0,
            'gem_bots'=>array_map($ack,$this->sortedIds($data['gem_bot_ids']??[])),
            'gold_bots'=>array_map($ack,$this->sortedIds($data['gold_bot_ids']??[])),
        ];
    }

    private function sortedIds(array $ids): array
    {
        $ids=array_values(array_unique(array_map('intval',$ids)));sort($ids,SORT_NUMERIC);return $ids;
    }
}
