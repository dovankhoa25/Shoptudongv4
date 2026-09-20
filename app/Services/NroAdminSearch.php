<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;

final class NroAdminSearch
{
    /** Scope/permissions must already be applied by the controller. Tokens are ANDed. */
    public static function apply($query, string $text, string $kind): void
    {
        $table = match($kind) {'accounts'=>'nro_accounts','listings'=>'item_listings',default=>'item_orders'};
        preg_match_all('/#(?:(tk|ctv|goi|vp):([^\s]+)|(\d+)\b)/u', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $tag=$m[1] ?? ''; $value=$m[2] ?? '';
            if ($tag==='') { $query->where($table.'.id',(int)$m[3]); continue; }
            $owner=$kind==='orders'?'seller_id':'user_id';
            if ($tag==='ctv') $query->whereIn($table.'.'.$owner,DB::table('users')->select('id')->where('username',$value));
            if ($tag==='tk') {
                $accounts=DB::table('nro_accounts')->select('id');
                ctype_digit($value) ? $accounts->where('id',(int)$value) : $accounts->where('account_name',$value);
                $query->whereIn($table.'.'.($kind==='accounts'?'id':'account_id'),$accounts);
            }
            if ($tag==='goi') {
                if ($kind==='accounts') $query->whereIn('nro_accounts.id',DB::table('item_listings')->select('account_id')->where('id',(int)$value));
                else $query->where($table.'.'.($kind==='orders'?'listing_id':'id'),(int)$value);
            }
            if ($tag==='vp') {
                $inventory=DB::table('nro_inventory_items')->where('template_id',(int)$value);
                if ($kind==='accounts') $query->whereIn('nro_accounts.id',$inventory->select('account_id'));
                else $query->whereIn($table.'.id',DB::table($kind==='orders'?'item_order_items':'item_listing_items')->select($kind==='orders'?'order_id':'listing_id')->whereIn('inventory_item_id',$inventory->select('id')));
            }
        }
        $term=trim(preg_replace('/#(?:(?:tk|ctv|goi|vp):[^\s]+|\d+\b)/u','',$text));
        if ($term==='') return;
        $pattern='%'.addcslashes($term,'%_\\').'%';
        $query->where(function($q) use($kind,$table,$term,$pattern) {
            if ($kind==='accounts') $q->where('account_name','like',$pattern)->orWhere('character_name','like',$pattern);
            else $q->where('title','like',$pattern)->orWhereIn('account_id',DB::table('nro_accounts')->select('id')->where('account_name','like',$pattern)->orWhere('character_name','like',$pattern));
            $q->orWhereIn($kind==='orders'?'seller_id':'user_id',DB::table('users')->select('id')->where('username','like',$pattern));
            if ($kind==='orders') $q->orWhere('recipient_name','like',$pattern)->orWhereIn('buyer_id',DB::table('users')->select('id')->where('username','like',$pattern));
            if(ctype_digit($term) && strlen($term)<=18) $q->orWhere($table.'.id',(int)$term);
        });
    }
}
