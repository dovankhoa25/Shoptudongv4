<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
class UserBalanceSnapshot {
    /** Canonical absolute balance; repeated notifications for one value share a revision. */
    public static function read(int $userId): array {
        return DB::transaction(function()use($userId) {
            $user=DB::table('users')->where('id',$userId)->whereNull('deleted_at')->lockForUpdate()->first(['balance']);
            if(!$user)return [];
            $old=DB::table('user_balance_realtime')->where('user_id',$userId)->first();
            $balance=(int)$user->balance;$revision=(int)($old->revision ?? 0);
            if(!$old || (int)$old->balance!==$balance) {
                $revision++;DB::table('user_balance_realtime')->updateOrInsert(['user_id'=>$userId],['balance'=>$balance,'revision'=>$revision]);
            }
            return ['balance'=>$balance,'balance_revision'=>$revision];
        },3);
    }
}
