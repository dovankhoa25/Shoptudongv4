<?php
namespace App\Services;

use App\Models\Setting;

class NroItemFilters
{
    public const GROUPS = ['equipment'=>'Trang bị', 'dragon_balls'=>'Ngọc Rồng', 'upgrade_stones'=>'Đá nâng cấp',
        'crystals'=>'Sao pha lê', 'support'=>'Hỗ trợ & sự kiện', 'other'=>'Vật phẩm khác'];
    public const EQUIPMENT = ['0'=>'Áo', '1'=>'Quần', '2'=>'Găng', '3'=>'Giày', '4'=>'Rada'];
    public const ITEM_GROUP_IDS = [
        'upgrade_stones'=>[220,221,222,223,224],
        'crystals'=>[447,446,445,444,443,442,441],
        'dragon_balls'=>[14,15,16,17,18,19,20],
    ];
    public const STATS = ['damage'=>'Sức đánh', 'life_steal'=>'Hút máu', 'ki_steal'=>'Hút KI',
        'gold'=>'Vàng từ quái', 'hp'=>'HP', 'ki'=>'KI', 'other'=>'Khác'];
    // Other combat/utility stats, excluding slot counts, item level, expiry and trade metadata.
    public const OTHER_STAT_IDS = [3,4,5,10,14,15,16,17,18,19,27,28,42,43,44,45,46,47,62,78,79,80,81,88,94,197,204,206];
    private array $catalog;
    private array $overrides;
    public function __construct()
    {
        $this->catalog = array_column(json_decode(file_get_contents(resource_path('nro/item-templates.json')), true), null, 'id');
        $this->overrides = array_column(self::overrides(), 'group', 'id');
    }
    public static function overrides(): array { return json_decode(Setting::get('nro_item_group_overrides', '[]'), true) ?: []; }
    public function knownIds(): array { return array_keys($this->catalog); }
    public function group(array $template): string
    {
        if (isset($this->overrides[$template['id']])) return $this->overrides[$template['id']];
        foreach (self::ITEM_GROUP_IDS as $group=>$ids) if (in_array((int)$template['id'],$ids,true)) return $group;
        $type = (int)($template['type'] ?? -1);
        if (in_array($type,[0,1,2,3,4],true)) return 'equipment';
        if (in_array($type, [6,7,8,13,22,23,24,25,27,29,31,35,37], true)) return 'support';
        return 'other';
    }
    public function metadata(): array
    {
        $version=hash('sha256',json_encode($this->overrides).filemtime(resource_path('nro/item-templates.json')));
        return \App\Support\ApiCache::remember('public:nro-metadata','filters:'.$version,900,fn()=>$this->buildMetadata());
    }
    private function buildMetadata(): array
    {
        $options = fn ($values) => array_map(fn ($id, $label) => ['value'=>(string)$id, 'label'=>$label], array_keys($values), array_values($values));
        $itemsByGroup = [];
        foreach (self::ITEM_GROUP_IDS as $group=>$defaults) {
            $ids = array_values(array_unique([...$defaults, ...$this->templateIds(['group'=>$group])]));
            $itemsByGroup[$group] = [];
            foreach ($ids as $id) {
                $item=$this->catalog[$id] ?? null;
                if ($item && $this->group($item)===$group) $itemsByGroup[$group][]=['value'=>(string)$id,'label'=>$item['name']];
            }
        }
        return ['groups'=>$options(self::GROUPS), 'equipmentTypes'=>$options(self::EQUIPMENT), 'stats'=>$options(self::STATS), 'itemsByGroup'=>$itemsByGroup];
    }
    public function templateIds(array $filters): array
    {
        return array_keys(array_filter($this->catalog, function ($item) use ($filters) {
            if (!empty($filters['group']) && $this->group($item) !== $filters['group']) return false;
            if (isset($filters['itemId']) && (int)$item['id'] !== (int)$filters['itemId']) return false;
            if (isset($filters['equipmentType']) && (int)$item['type'] !== (int)$filters['equipmentType']) return false;
            if (isset($filters['gender']) && !in_array((int)($item['gender'] ?? -1), [(int)$filters['gender'],3], true)) return false;
            return true;
        }));
    }
    public static function inventoryColumns(array $item): array
    {
        $options = $item['options'] ?? [];
        $stars = array_filter($options, fn ($o) => in_array((int)$o['optionId'], [102,107], true) && $o['param'] >= 0 && $o['param'] <= 9);
        $has = fn ($ids) => collect($options)->contains(fn ($o) => in_array((int)$o['optionId'], $ids, true) && $o['param'] > 0);
        return ['filter_stars'=>$stars ? max(array_column($stars, 'param')) : null,
            'filter_damage'=>$has([0,49,50,147]), 'filter_hp'=>$has([2,6,22,48,77]), 'filter_ki'=>$has([2,7,23,48,103])];
    }
    public static function extraInventoryColumns(array $item): array
    {
        $options=$item['options'] ?? [];
        $has=fn($ids)=>collect($options)->contains(fn($o)=>in_array((int)($o['optionId'] ?? -1),$ids,true) && ($o['param'] ?? 0)>0);
        return ['filter_life_steal'=>$has([8,95,104]), 'filter_ki_steal'=>$has([8,96]),
            'filter_gold'=>$has([100]), 'filter_other'=>$has(self::OTHER_STAT_IDS)];
    }

}
