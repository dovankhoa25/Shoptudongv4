<?php
namespace App\Services;

class NroCatalog
{
    private array $items;
    private array $options;
    public function __construct()
    {
        $this->items = self::templates();
        $file=resource_path('nro/item-option-templates.json');
        $this->options = \App\Support\ApiCache::remember('internal:nro-catalog','options:'.filemtime($file),86400,fn()=>array_column(json_decode(file_get_contents($file),true),'name','id'));
    }
    public static function templates(): array
    {
        $file=resource_path('nro/item-templates.json');
        return \App\Support\ApiCache::remember('internal:nro-catalog','items:'.filemtime($file),86400,fn()=>array_column(json_decode(file_get_contents($file),true),null,'id'));
    }
    public function item(array $item): array
    {
        $template = $this->items[$item['templateId']] ?? [];
        return [...$item, 'name' => $template['name'] ?? 'Vật phẩm #'.$item['templateId'],
            'iconId' => $template['iconId'] ?? null, 'type' => $template['type'] ?? null,
            'description' => $template['description'] ?? '',
            'optionLabels' => array_map(fn ($o) => str_replace('#', (string) $o['param'], $this->options[$o['optionId']] ?? 'Option '.$o['optionId'].': #'), $item['options'] ?? [])];
    }
}
