<?php
namespace App\Services;

use App\Models\Setting;
use App\Support\ApiCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class NroItemGroupSettings
{
    public const KEYS = ['nro_item_groups', 'nro_item_group_overrides'];

    public static function defaults(): array
    {
        $rows = [];
        foreach (NroItemFilters::GROUPS as $key => $name) {
            $rows[] = ['key'=>$key, 'name'=>$name, 'visible'=>true, 'position'=>count($rows),
                'filterMode'=>$key === 'equipment' ? 'equipment' : (isset(NroItemFilters::ITEM_GROUP_IDS[$key]) ? 'items' : 'basic')];
        }
        return $rows;
    }

    public static function definitions(): array
    {
        $raw = Setting::get('nro_item_groups');
        $rows = $raw === null ? self::defaults() : json_decode($raw, true);
        if (!is_array($rows)) $rows = self::defaults();
        usort($rows, fn($a, $b) => ($a['position'] <=> $b['position']) ?: strcmp($a['key'], $b['key']));
        return $rows;
    }

    // Admin reads the two rows together; the revision prevents overwriting a newer edit.
    public static function read(): array
    {
        return self::present(DB::table('settings')->whereIn('key', self::KEYS)->pluck('value', 'key')->all());
    }

    private static function revision(array $raw): string
    {
        return hash('sha256', json_encode(array_map(fn($key) => $raw[$key] ?? null, self::KEYS)));
    }

    private static function present(array $raw): array
    {
        $groups = json_decode($raw['nro_item_groups'] ?? 'null', true) ?? self::defaults();
        $overrides = json_decode($raw['nro_item_group_overrides'] ?? '[]', true) ?: [];
        usort($groups, fn($a, $b) => ($a['position'] <=> $b['position']) ?: strcmp($a['key'], $b['key']));
        return ['revision'=>self::revision($raw), 'groups'=>array_map(fn($g) => [...$g,
            'builtin'=>isset(NroItemFilters::GROUPS[$g['key']]),
            'defaultIds'=>NroItemFilters::ITEM_GROUP_IDS[$g['key']] ?? [],
            'ids'=>array_values(array_map(fn($r) => (int)$r['id'], array_filter($overrides, fn($r) => $r['group'] === $g['key'])))], $groups)];
    }

    public static function save(array $input): array
    {
        $v = Validator::make($input, [
            'revision'=>'required|string|size:64', 'groups'=>'required|array|min:6|max:50',
            'groups.*.key'=>['required','string','max:64','regex:/^[a-z][a-z0-9_]*$/','distinct'],
            'groups.*.name'=>'required|string|max:60', 'groups.*.visible'=>'required|boolean',
            'groups.*.position'=>'required|integer|min:0|max:999', 'groups.*.filterMode'=>'required|in:basic,items,equipment',
            'groups.*.ids'=>'present|array|max:3000', 'groups.*.ids.*'=>'required|integer|min:0|max:100000',
        ])->validate();
        $keys = array_column($v['groups'], 'key');
        if (array_diff(array_keys(NroItemFilters::GROUPS), $keys)) {
            throw ValidationException::withMessages(['groups'=>'Nhóm mặc định chỉ có thể ẩn, không thể xóa.']);
        }
        $catalog = NroCatalog::templates(); $seen = []; $groups = []; $overrides = [];
        foreach ($v['groups'] as $i => $g) {
            $name = trim($g['name']);
            if ($name === '') throw ValidationException::withMessages(["groups.$i.name"=>'Nhập tên nhóm.']);
            foreach ($g['ids'] as $id) {
                $id = (int)$id;
                if (!isset($catalog[$id])) throw ValidationException::withMessages(["groups.$i.ids"=>"ID $id không có trong catalog."]);
                if (isset($seen[$id])) throw ValidationException::withMessages(["groups.$i.ids"=>"ID $id đã được gán vào nhóm {$seen[$id]}."]);
                $seen[$id] = $name; $overrides[] = ['id'=>$id, 'group'=>$g['key']];
            }
            $groups[] = ['key'=>$g['key'], 'name'=>$name, 'visible'=>(bool)$g['visible'], 'position'=>(int)$g['position'], 'filterMode'=>$g['filterMode']];
        }
        // Equipment controls are meaningful only when every effective template is equipment.
        $classifier = new NroItemFilters($groups, $overrides);
        foreach ($catalog as $item) {
            if ($classifier->filterMode($classifier->group($item)) === 'equipment' && !in_array((int)$item['type'], [0,1,2,3,4], true)) {
                throw ValidationException::withMessages(['groups'=>"Nhóm chứa ID {$item['id']} không phải trang bị. Chọn kiểu lọc Cơ bản hoặc Theo vật phẩm."]);
            }
        }
        $raw = ['nro_item_groups'=>json_encode($groups, JSON_UNESCAPED_UNICODE), 'nro_item_group_overrides'=>json_encode($overrides)];
        foreach ($raw as $value) if (strlen($value) > 60000) throw ValidationException::withMessages(['groups'=>'Danh sách cấu hình quá dài. Giảm số ID gán riêng; các ID mặc định không cần nhập lại.']);
        // settings has no unique key in older databases; serialize first creation as well.
        Cache::lock('nro:item-group-settings:write', 15)->block(5, function () use ($v, $raw) {
            DB::transaction(function () use ($v, $raw) {
                $current = DB::table('settings')->whereIn('key', self::KEYS)->lockForUpdate()->pluck('value', 'key')->all();
                abort_unless(hash_equals(self::revision($current), $v['revision']), 409, 'Cấu hình đã được người khác sửa. Tải lại trước khi lưu.');
                foreach ($raw as $key=>$value) Setting::set($key, $value);
                ApiCache::clearGroups(['public:nro-metadata', 'public:nro-shop:listings']);
            });
        });
        return self::present($raw);
    }
}
