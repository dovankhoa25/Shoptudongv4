<?php
namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Str;

class NroItemFilters
{
    public const GROUPS = ['equipment'=>'Trang bị', 'dragon_balls'=>'Ngọc Rồng', 'upgrade_stones'=>'Đá nâng cấp',
        'crystals'=>'Sao pha lê', 'support'=>'Hỗ trợ & sự kiện', 'other'=>'Vật phẩm khác'];
    public const EQUIPMENT = ['0'=>'Áo', '1'=>'Quần', '2'=>'Găng', '3'=>'Giày', '4'=>'Rada', '5'=>'Cải trang & ngoại hình', '19'=>'Bông tai', '32'=>'Giáp tập luyện'];
    public const STATS = ['damage'=>'Sức đánh', 'hp'=>'HP', 'ki'=>'KI'];
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
        if (preg_match('/^ngoc rong(?: |$)/', Str::lower(Str::ascii($template['name'] ?? '')))) return 'dragon_balls';
        $type = $template['type'] ?? -1;
        if (array_key_exists($type, self::EQUIPMENT)) return 'equipment';
        if ($type === 14) return 'upgrade_stones';
        if ($type === 30) return 'crystals';
        if (in_array($type, [6,7,8,13,22,23,24,25,27,29,31,35,37], true)) return 'support';
        return 'other';
    }
    public function metadata(): array
    {
        $options = fn ($values) => array_map(fn ($id, $label) => ['value'=>(string)$id, 'label'=>$label], array_keys($values), array_values($values));
        return ['groups'=>$options(self::GROUPS), 'equipmentTypes'=>$options(self::EQUIPMENT), 'stats'=>$options(self::STATS)];
    }
    public function templateIds(array $filters): array
    {
        return array_keys(array_filter($this->catalog, function ($item) use ($filters) {
            if (!empty($filters['group']) && $this->group($item) !== $filters['group']) return false;
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
            'filter_damage'=>$has([0,49,50]), 'filter_hp'=>$has([2,6,22,48,77]), 'filter_ki'=>$has([2,7,23,48,103])];
    }
}
