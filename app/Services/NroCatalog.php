<?php
namespace App\Services;

class NroCatalog
{
    private array $items;
    private array $options;
    public function __construct()
    {
        $this->items = array_column(json_decode(file_get_contents(resource_path('nro/item-templates.json')), true), null, 'id');
        $this->options = array_column(json_decode(file_get_contents(resource_path('nro/item-option-templates.json')), true), 'name', 'id');
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
