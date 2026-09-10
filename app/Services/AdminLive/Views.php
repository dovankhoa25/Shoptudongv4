<?php
namespace App\Services\AdminLive;

/** Only existing, read-only screens can be subscribed; clients cannot request arbitrary controllers/props. */
class Views {
    public static function resolve(string $url,string $mode='page'): ?array {
        $parts=parse_url($url);
        if(!$parts || isset($parts['host']) || isset($parts['scheme']) || isset($parts['fragment'])) return null;
        $path=rtrim($parts['path'] ?? '', '/');
        if(!str_starts_with($path,'/admin/') || str_contains($path,'..') || str_contains($path,'\\')) return null;
        if($path==='/admin/live-balance') return ['resources'=>['user_balance'],'props'=>null];
        if($path==='/admin/chat/conversations') return ['resources'=>['chat'],'props'=>null];
        if($path==='/admin/nro-shop') return ['resources'=>['nro'],'props'=>$mode==='summary' ? ['accountStats'] : ['accounts','accountStats','accountPagination','salePolicy']];
        if(preg_match('~^/admin/nro-shop/(status|listings|orders|jobs|worker-keys|accounts/\d+(/listings)?|orders/\d+/stock-check)$~',$path)) {
            $resource=match(true) {
                str_contains($path,'/orders')=>'nro:orders',
                str_contains($path,'/jobs')=>'nro:jobs',
                str_contains($path,'/listings')=>'nro:listings',
                str_contains($path,'/accounts/')=>'nro:account-detail',
                str_ends_with($path,'/status')=>'nro:status',
                default=>'nro:settings',
            };
            return ['resources'=>['nro',$resource],'props'=>null];
        }
        $list=[
            '/admin/orders'=>[['gold_order','legacy_gold_order'],['orders','stats']],
            '/admin/imports'=>[['gold_import','legacy_gold_import'],['orders','stats']],
            '/admin/gem-orders'=>[['gem_order'],['orders','stats']],
            '/admin/services/orders'=>[['service_order'],['service_orders']],
            '/admin/services/orders/receiver'=>[['service_order'],['service_orders']],
            '/admin/games/accounts/history'=>[['nick_order'],['orders','stats']],
            '/admin/withdrawals'=>[['withdrawal'],['withdrawals','stats']],
            '/admin/cards'=>[['card_recharge'],['cards']],
            '/admin/deposits'=>[['card_recharge','bank_deposit'],['cards','bankTopups','stats','cardTypes','gateways']],
            '/admin/carot-recharges'=>[['carot_recharge'],['recharges','stats','statistics']],
            '/admin/transactions'=>[['balance_transaction','bank_deposit'],['transactions']],
            '/admin/users'=>[['balance_transaction','user'],['users']],
            '/admin/dashboard'=>[['gold_order','legacy_gold_order','gold_import','legacy_gold_import','gem_order'],['servers','grandTotal']],
            '/admin/analytics'=>[['service_order','nick_order','random_order'],['analytics','availableDates']],
        ];
        if(isset($list[$path])) return ['resources'=>$list[$path][0],'props'=>$list[$path][1]];
        if(preg_match('~^(/admin/(orders|imports|gem-orders))/(\d+)$~',$path,$m)) return ['resources'=>$list[$m[1]][0],'props'=>$m[2]==='gem-orders' ? ['order','relatedOrders'] : ['order']];
        return null;
    }
}
