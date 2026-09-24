<?php
namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Shared item predicates: all attributes of a combo must match the same inventory row. */
class NroListingFilters
{
    public function normalize(Request $r, NroItemFilters $itemFilters, bool $includeHidden = false): array
    {
        $filters = $r->validate(['page' => 'sometimes|integer|min:1', 'server' => 'nullable|integer|min:1',
            'q' => 'nullable|string|max:100', 'bundle' => 'nullable|in:single,combo',
            'minPrice' => 'nullable|numeric|min:0|max:1000000000000',
            'maxPrice' => ['nullable','numeric','min:0','max:1000000000000', ...($r->filled('minPrice') ? ['gte:minPrice'] : [])],
            'sort' => 'nullable|in:newest,price_asc,price_desc',
            'group'=>['nullable','string','max:64','regex:/^[a-z][a-z0-9_]*$/'],
            'equipmentType'=>'nullable|in:0,1,2,3,4',
            'gender'=>'nullable|integer|in:0,1,2', 'minStars'=>'nullable|integer|min:1|max:9', 'stat'=>'nullable|in:'.implode(',',array_keys(\App\Services\NroItemFilters::STATS)), 'itemId'=>'nullable|integer|min:0|max:100000']);
        $clearedFilters = [];
        $clear = function(array $keys) use (&$filters, &$clearedFilters) {
            foreach ($keys as $key) if (isset($filters[$key])) { unset($filters[$key]); $clearedFilters[] = $key; }
        };
        if (!empty($filters['group']) && !in_array($filters['group'], $itemFilters->visibleKeys($includeHidden), true)) {
            // Old links survive hidden/deleted groups; never hide all stock behind a dead filter.
            $clear(['group', 'equipmentType', 'gender', 'minStars', 'stat', 'itemId', 'page']);
        } else {
            $mode = $itemFilters->filterMode($filters['group'] ?? null);
            if ($mode !== 'equipment') $clear(['equipmentType','gender','minStars','stat']);
            if ($mode !== 'items') $clear(['itemId']);
            elseif (isset($filters['itemId']) && !$itemFilters->templateIds(['group'=>$filters['group'], 'itemId'=>$filters['itemId']])) $clear(['itemId']);
            if ($clearedFilters) $clear(['page']);
        }
        if ($clearedFilters) $r->merge(['page'=>1]);
        return [$filters, $clearedFilters];
    }

    public function apply($q, array $filters, NroItemFilters $itemFilters, bool $searchItems = true): void
    {
        if (! empty($filters['server'])) $q->whereIn('account_id', DB::table('nro_accounts')->select('id')->where('server_id', (int) $filters['server']));
        $search = $searchItems ? trim((string) ($filters['q'] ?? '')) : '';
        if ($search !== '' && empty($filters['group'])) {
            // Search public item names/IDs only; internal listing notes stay private.
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $q->whereExists(function ($items) use ($search, $pattern) {
                $items->selectRaw('1')->from('item_listing_items as li')->join('nro_inventory_items as i', 'i.id', '=', 'li.inventory_item_id')
                    ->whereColumn('li.listing_id', 'item_listings.id')->where(function ($match) use ($search, $pattern) {
                        $column = DB::connection()->getQueryGrammar()->wrap('i.item_json->name');
                        $match->whereRaw($column.' LIKE ? ESCAPE \'!\'', [$pattern]);
                        if (ctype_digit($search) && strlen($search) <= 10) $match->orWhere('i.template_id', (int) $search);
                    });
            });
        }
        if (!empty($filters['group'])) {
            $ids = $itemFilters->templateIds($filters);
            $q->whereExists(function ($items) use ($filters, $ids, $itemFilters, $search) {
                $items->selectRaw('1')->from('item_listing_items as li')->join('nro_inventory_items as i','i.id','=','li.inventory_item_id')
                    ->whereColumn('li.listing_id','item_listings.id');
                $items->where(function($templates) use ($ids,$filters,$itemFilters) {
                    $templates->whereIn('i.template_id',$ids);
                    if ($filters['group'] === 'other' && !isset($filters['itemId'])) $templates->orWhereNotIn('i.template_id',$itemFilters->knownIds());
                });
                if ($search !== '') {
                    $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
                    $items->where(function ($match) use ($search, $pattern) {
                        $column = DB::connection()->getQueryGrammar()->wrap('i.item_json->name');
                        $match->whereRaw($column.' LIKE ? ESCAPE \'!\'', [$pattern]);
                        if (ctype_digit($search) && strlen($search) <= 10) $match->orWhere('i.template_id', (int)$search);
                    });
                }
                // Every item-specific condition must match the SAME item in a combo.
                if (isset($filters['minStars'])) $items->where('i.filter_stars','>=',(int)$filters['minStars']);
                if (!empty($filters['stat'])) $items->where('i.filter_'.$filters['stat'],true);
            });
        }
        if (! empty($filters['bundle'])) $q->where(function ($count) use ($filters) {
            $count->from('item_listing_items')->selectRaw('COUNT(*)')->whereColumn('listing_id', 'item_listings.id');
        }, $filters['bundle'] === 'single' ? '=' : '>', 1);
        if (isset($filters['minPrice'])) $q->where('price', '>=', (float) $filters['minPrice']);
        if (isset($filters['maxPrice'])) $q->where('price', '<=', (float) $filters['maxPrice']);
        if (($filters['sort'] ?? null) === 'price_asc') $q->orderBy('price');
        elseif (($filters['sort'] ?? null) === 'price_desc') $q->orderByDesc('price');

    }
}
