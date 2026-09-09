<?php
namespace App\Services;

use App\Models\NroAccount;
use App\Models\NroAccountSnapshot;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class NroSnapshotService
{
    public function __construct(private NroCatalog $catalog) {}

    public static function cardItemPreviews(array $data): array
    {
        $previews = [];
        foreach (['equipped', 'bag', 'chest'] as $location) {
            $items = $data[$location] ?? [];
            $previews[$location] = [
                'count' => count($items),
                'items' => array_map(fn ($item) => Arr::only($item, ['templateId', 'iconId', 'name', 'quantity']), array_slice($items, 0, 6)),
            ];
        }
        return $previews;
    }

    public static function identity(array $item): array
    {
        $options = array_map(fn ($o) => ['optionId' => (int) $o['optionId'], 'param' => (int) $o['param']], $item['options'] ?? []);
        usort($options, fn ($a, $b) => [$a['optionId'], $a['param']] <=> [$b['optionId'], $b['param']]);
        return ['templateId' => (int) $item['templateId'], 'info' => $item['info'] ?? '', 'content' => $item['content'] ?? '', 'options' => $options];
    }

    public function ingest(NroAccount $account, array $payload): NroAccountSnapshot
    {
        Validator::make($payload, [
            'schemaVersion' => 'required|integer|in:1', 'catalogVersion' => 'required|string|max:50',
            'snapshot' => 'required|array', 'snapshot.capturedAt' => 'required|date',
            'snapshot.character' => 'required|array', 'snapshot.character.id' => 'required|integer|min:1',
            'snapshot.character.name' => 'required|string|max:100',
            'completeness' => 'required|array', 'completeness.bag' => 'required|boolean', 'completeness.chest' => 'required|boolean',
            'snapshot.currentTask' => 'nullable|array',
            'snapshot.currentTask.id' => 'sometimes|integer|min:-1|max:65535',
            'snapshot.currentTask.currentStep' => 'sometimes|integer|min:-1|max:255',
            'snapshot.currentTask.currentCount' => 'sometimes|integer|min:-1|max:2147483647',
            'snapshot.currentTask.name' => 'sometimes|string|max:1000',
            'snapshot.currentTask.detail' => 'sometimes|nullable|string|max:10000',
            'snapshot.currentTask.steps' => 'sometimes|array|max:255',
            'snapshot.currentTask.steps.*.name' => 'required|string|max:1000',
            'snapshot.currentTask.steps.*.detail' => 'sometimes|nullable|string|max:10000',
            'snapshot.currentTask.steps.*.objectiveType' => 'sometimes|integer|min:-128|max:255',
            'snapshot.currentTask.steps.*.mapId' => 'sometimes|integer|min:-1|max:65535',
            'snapshot.currentTask.steps.*.requiredCount' => 'sometimes|integer|min:-1|max:2147483647',
            'snapshot.currentTask' => 'nullable|array',
            'snapshot.currentTask.id' => 'sometimes|integer|min:-1|max:65535',
            'snapshot.currentTask.currentStep' => 'sometimes|integer|min:-1|max:255',
            'snapshot.currentTask.currentCount' => 'sometimes|integer|min:-1|max:2147483647',
            'snapshot.currentTask.name' => 'sometimes|string|max:1000',
            'snapshot.currentTask.detail' => 'sometimes|nullable|string|max:10000',
            'snapshot.currentTask.steps' => 'sometimes|array|max:255',
            'snapshot.currentTask.steps.*.name' => 'required|string|max:1000',
            'snapshot.currentTask.steps.*.detail' => 'sometimes|nullable|string|max:10000',
            'snapshot.currentTask.steps.*.objectiveType' => 'sometimes|integer|min:-128|max:255',
            'snapshot.currentTask.steps.*.mapId' => 'sometimes|integer|min:-1|max:65535',
            'snapshot.currentTask.steps.*.requiredCount' => 'sometimes|integer|min:-1|max:2147483647',
            ...collect(['bag', 'chest', 'equipped', 'collectionChest'])->flatMap(fn ($key) => [
                "snapshot.$key" => 'present|array|max:1000', "snapshot.$key.*.slot" => 'required|integer|min:0|max:10000',
                "snapshot.$key.*.templateId" => 'required|integer|min:0|max:100000',
                "snapshot.$key.*.quantity" => 'required|integer|min:1|max:1000000000',
                "snapshot.$key.*.options" => 'present|array|max:100',
                "snapshot.$key.*.options.*.optionId" => 'required|integer|min:0|max:100000',
                "snapshot.$key.*.options.*.param" => 'required|integer',
                "snapshot.$key.*.info" => 'nullable|string|max:4000', "snapshot.$key.*.content" => 'nullable|string|max:4000',
            ])->all(),
        ])->validate();

        // Explicit public projection: never persist arbitrary credential fields from a worker.
        $source = $payload['snapshot'];
        $data = Arr::only($source, ['capturedAt', 'disciple']);
        $skillFields = ['skillId', 'templateId', 'iconId', 'level', 'name', 'maxLevel', 'type', 'powerRequired', 'manaUse', 'cooldownMilliseconds', 'damage', 'moreInfo', 'currentExperience', 'manaUseType'];
        $data['skills'] = array_map(fn ($s) => Arr::only($s, $skillFields), $source['skills'] ?? []);
        $data['intrinsic'] = isset($source['intrinsic']) ? Arr::only($source['intrinsic'], ['iconId', 'info']) : null;
        $data['intrinsicOptions'] = array_map(fn ($o) => Arr::only($o, ['groupIndex', 'optionIndex', 'groupName', 'groupDescription', 'iconId', 'info']), $source['intrinsicOptions'] ?? []);
        $data['collectionBook'] = array_map(function ($e) {
            $entry = Arr::only($e, ['id', 'number', 'iconId', 'rank', 'amount', 'maxAmount', 'entryType', 'templateId', 'name', 'info', 'level', 'isUsed']);
            $entry['options'] = array_map(fn ($o) => Arr::only($o, ['optionId', 'param', 'activeLevel']), $e['options'] ?? []);
            return $entry;
        }, $source['collectionBook'] ?? []);
        $data['currentTask'] = isset($source['currentTask']) ? Arr::only($source['currentTask'], ['id', 'currentStep', 'name', 'detail', 'currentCount']) : null;
        if ($data['currentTask'] !== null && isset($source['currentTask']['steps'])) {
            $data['currentTask']['steps'] = array_map(fn ($step) => Arr::only($step, ['name', 'detail', 'objectiveType', 'mapId', 'requiredCount']), $source['currentTask']['steps']);
        }
        if ($data['currentTask'] !== null && isset($source['currentTask']['steps'])) {
            $data['currentTask']['steps'] = array_map(fn ($step) => Arr::only($step, ['name', 'detail', 'objectiveType', 'mapId', 'requiredCount']), $source['currentTask']['steps']);
        }
        $data['character'] = Arr::only($source['character'], ['id', 'name', 'power', 'potential', 'hp', 'mp', 'hpMax', 'mpMax', 'hpBase', 'mpBase', 'damageBase', 'damage', 'armor', 'critical', 'gender', 'gold', 'gem', 'lockedGem', 'classId', 'head', 'task']);
        $groups = [];
        foreach (['equipped', 'bag', 'chest', 'collectionChest'] as $location) {
            $data[$location] = [];
            foreach ($source[$location] as $item) {
                $identity = self::identity($item);
                $enriched = $this->catalog->item([...$identity, 'slot' => $item['slot'], 'quantity' => $item['quantity']]);
                $data[$location][] = $enriched;
                if ($location === 'collectionChest') continue;
                $hash = hash('sha256', json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $groups[$hash] ??= ['item' => $enriched, 'quantity' => 0, 'locations' => []];
                $groups[$hash]['quantity'] += $item['quantity'];
                $groups[$hash]['locations'][] = ['location' => $location, 'slot' => $item['slot'], 'quantity' => $item['quantity']];
            }
        }
        if (is_array($data['disciple'] ?? null)) {
            $data['disciple'] = Arr::only($data['disciple'], ['exists', 'hasDetails', 'name', 'power', 'potential', 'hp', 'hpMax', 'mp', 'mpMax', 'damage', 'armor', 'critical', 'skills', 'equipped']);
            $data['disciple']['equipped'] = array_map(fn ($i) => $this->catalog->item([...self::identity($i), 'slot' => $i['slot'], 'quantity' => $i['quantity']]), $data['disciple']['equipped'] ?? []);
            $data['disciple']['skills'] = array_map(fn ($s) => Arr::only($s, $skillFields), $data['disciple']['skills'] ?? []);
        }
        $summary = ['character' => Arr::only($data['character'], ['name', 'power', 'gender']), 'serverIndex' => $account->server_index, 'serverId' => $account->server_id, 'serverName' => DB::table('servers')->where('id', $account->server_id)->value('name_view'),
            'capturedAt' => $source['capturedAt'], 'highlights' => array_slice($data['equipped'], 0, 6),
            'itemPreviews' => self::cardItemPreviews($data),
            'disciple' => is_array($data['disciple'] ?? null) ? Arr::only($data['disciple'], ['exists', 'hasDetails', 'name', 'power']) : null];
        return DB::transaction(function () use ($account, $payload, $data, $summary, $groups) {
            $account = NroAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $snapshot = NroAccountSnapshot::create(['account_id' => $account->id, 'schema_version' => 1,
                'catalog_version' => $payload['catalogVersion'], 'captured_at' => $data['capturedAt'],
                'data_json' => $data, 'summary_json' => $summary, 'completeness_json' => $payload['completeness']]);
            // A partial chest response must never erase known stock or make partial inventory sellable.
            if (($payload['completeness']['bag'] ?? false) && ($payload['completeness']['chest'] ?? false)) {
                DB::table('nro_inventory_items')->where('account_id', $account->id)->update(['quantity' => 0, 'updated_at' => now()]);
                foreach ($groups as $hash => $group) {
                    DB::table('nro_inventory_items')->updateOrInsert(['account_id' => $account->id, 'fingerprint' => $hash], [
                        ...NroItemFilters::inventoryColumns($group['item']),
                        'template_id' => $group['item']['templateId'], 'item_json' => json_encode($group['item']),
                        'locations_json' => json_encode($group['locations']), 'quantity' => $group['quantity'], 'updated_at' => now(),
                    ]);
                }
            }
            $account->update(['latest_snapshot_id' => $snapshot->id, 'last_synced_at' => ($payload['completeness']['bag'] && $payload['completeness']['chest']) ? $snapshot->captured_at : null, 'character_name' => $data['character']['name']]);
            return $snapshot;
        });
    }
}
