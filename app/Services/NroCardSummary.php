<?php
namespace App\Services;

use Illuminate\Support\Arr;

final class NroCardSummary
{
    public static function fromData(array $data): array
    {
        $groups = ['costumes' => [], 'gear' => [], 'stones' => []];
        $items = [];
        foreach (['equipped' => 'Đang mặc', 'bag' => 'Hành trang', 'chest' => 'Rương', 'collectionChest' => 'Rương sưu tập'] as $key => $label) {
            foreach ($data[$key] ?? [] as $item) $items[] = [...$item, 'location' => $label];
        }
        foreach ($data['disciple']['equipped'] ?? [] as $item) $items[] = [...$item, 'location' => 'Đệ tử'];
        foreach ($items as $item) {
            $stars = 0;
            foreach ($item['options'] ?? [] as $option) {
                if (in_array((int)$option['optionId'], [102, 107], true)) $stars = max($stars, (int)$option['param']);
            }
            $type = $item['type'] ?? null;
            $group = $type === 5 ? 'costumes' : (in_array($type, [12, 14], true) || preg_match('/^Đá\s/iu', $item['name'] ?? '') ? 'stones' : ($stars > 0 ? 'gear' : null));
            if ($group === null) continue;
            $groups[$group][] = self::item($item);
        }
        $previews = [];
        foreach ($groups as $key => $items) $previews[$key] = ['count' => count($items), 'items' => array_slice($items, 0, $key === 'costumes' ? 24 : 6)];
        return [
            'character' => Arr::only($data['character'] ?? [], ['name', 'power', 'gender', 'damage', 'gold', 'gem', 'lockedGem']),
            'disciple' => isset($data['disciple']) ? Arr::only($data['disciple'], ['exists', 'hasDetails', 'name', 'power', 'damage']) : null,
            'intrinsic' => $data['intrinsic']['info'] ?? null,
            'featuredPreviews' => $previews,
            'disciplePreview' => array_map(fn ($item) => self::item([...$item, 'location' => 'Đệ tử']), array_slice($data['disciple']['equipped'] ?? [], 0, 2)),
            'cardVersion' => 3,
        ];
    }
    private static function item(array $item): array
    {
        $filled = null; $total = 0;
        foreach ($item['options'] ?? [] as $option) {
            if ((int)$option['optionId'] === 102) $filled = max(0, (int)$option['param']);
            if ((int)$option['optionId'] === 107) $total = max(0, (int)$option['param']);
        }
        return [...Arr::only($item, ['templateId', 'iconId', 'name', 'quantity', 'location']), 'stars' => max($total, $filled ?? 0), 'filledStars' => $filled];
    }
}
