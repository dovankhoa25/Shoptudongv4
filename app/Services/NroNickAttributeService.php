<?php
namespace App\Services;

use App\Models\Category;
use App\Models\Nick;
use App\Models\NroAccount;
use App\Models\NroAccountSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NroNickAttributeService
{
    private function key(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($value)));
    }

    private function serverKey(string $value): string
    {
        return preg_replace('/^(vutrugop|vutru|server|sever)|sao$/', '', $this->key($value));
    }

    private function costumeKey(string $value): string
    {
        return $this->key(preg_replace('/^(cai trang|ct)\s+/i', '', Str::ascii(trim($value))));
    }

    public function preview(NroAccount $account, Category $category, ?Nick $nick = null): array
    {
        $snapshot = $account->latest_snapshot_id ? NroAccountSnapshot::where('account_id', $account->id)->findOrFail($account->latest_snapshot_id) : null;
        $data = $snapshot?->data_json ?? [];
        $server = DB::table('servers')->where('id', $account->server_id)->first();
        $gender = data_get($data, 'character.gender');
        $planet = is_numeric($gender) ? ([0 => 'Trái Đất', 1 => 'Namec', 2 => 'Xayda'][(int) $gender] ?? null) : null;
        $costumes = collect(['equipped', 'bag', 'chest', 'collectionChest'])
            ->flatMap(fn ($location) => $data[$location] ?? [])
            ->filter(fn ($item) => ($item['type'] ?? null) === 5 && ($item['quantity'] ?? 0) > 0)
            ->pluck('name')->filter()->map(fn ($name) => $this->costumeKey($name))->unique()->all();
        $existing = $nick?->attributes()->get()->mapWithKeys(fn ($a) => [$a->id => (int) $a->pivot->attribute_option_id])->all() ?? [];
        $fields = $category->attributes()->where('attributes.status', 1)
            ->with(['options' => fn ($q) => $q->where('status', 1)])->get()->map(function ($attribute) use ($planet, $server, $costumes, $existing) {
                $kind = $this->key($attribute->name);
                $matches = $attribute->options->filter(function ($option) use ($kind, $planet, $server, $costumes) {
                    return match ($kind) {
                        'hanhtinh' => $planet && $this->key($option->option_value) === $this->key($planet),
                        'server', 'sever' => $server && in_array($this->serverKey($option->option_value), [$this->serverKey($server->name), $this->serverKey($server->name_view ?? '')], true),
                        'dangki', 'dangky' => $this->key($option->option_value) === 'ao',
                        'caitrang' => in_array($this->costumeKey($option->option_value), $costumes, true),
                        default => false,
                    };
                })->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
                $old = $existing[$attribute->id] ?? null;
                if (!$attribute->options->contains('id', $old)) $old = null;
                return ['id' => $attribute->id, 'name' => $attribute->name,
                    'options' => $attribute->options->map(fn ($o) => ['id' => $o->id, 'label' => $o->option_value])->values()->all(),
                    'suggestedIds' => $matches, 'selectedId' => $old ?? (count($matches) === 1 ? $matches[0] : null),
                    'autoFill' => in_array($kind, ['hanhtinh', 'server', 'sever', 'caitrang', 'dangki', 'dangky']),
                    'source' => $old !== null ? 'existing' : (count($matches) === 1 ? (in_array($kind, ['dangki', 'dangky']) ? 'default' : 'snapshot') : 'manual')];
            })->values()->all();
        return ['snapshotId' => $snapshot?->id, 'fields' => $fields];
    }

    /** Called in the publishing transaction so searchable rows and display cache stay together. */
    public function sync(Nick $nick, array $preview, ?array $selections): void
    {
        $fields = collect($preview['fields'])->keyBy('id');
        $selections ??= $fields->mapWithKeys(fn ($field) => [$field['id'] => $field['selectedId']])->all();
        $rows = []; $cache = [];
        foreach ($selections as $attributeId => $optionId) {
            $field = $fields->get($attributeId);
            if (!$field) throw ValidationException::withMessages(['attributeSelections' => 'Thuộc tính không thuộc danh mục hoặc đã bị tắt.']);
            if ($optionId === null || $optionId === '') continue;
            $option = collect($field['options'])->firstWhere('id', (int) $optionId);
            if (!$option) throw ValidationException::withMessages(['attributeSelections' => 'Giá trị không thuộc thuộc tính hoặc đã bị tắt.']);
            $rows[$attributeId] = ['attribute_option_id' => $option['id']];
            $cache[$field['name']] = $option['label'];
        }
        $nick->attributes()->sync($rows);
        $nick->forceFill(['attribute_cache_json' => json_encode((object) $cache, JSON_UNESCAPED_UNICODE)])->save();
    }
}
