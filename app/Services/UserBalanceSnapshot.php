<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
class UserBalanceSnapshot {
    /** One consistent SQL snapshot for profile reads; financial writes still use read(). */
    public static function forProfile(int $userId): array {
        // Write transactions need current locked reads, including under MySQL REPEATABLE READ.
        if (DB::transactionLevel() > 0) return self::read($userId);
        $row = DB::table('users as u')
            ->leftJoin('user_balance_realtime as r', 'r.user_id', '=', 'u.id')
            ->where('u.id', $userId)->whereNull('u.deleted_at')
            ->first(['u.balance', 'r.balance as snapshot_balance', 'r.revision']);
        if (!$row) return [];
        if ($row->revision !== null && (int)$row->balance === (int)$row->snapshot_balance) {
            return ['balance' => (int)$row->balance, 'balance_revision' => (int)$row->revision];
        }
        return self::read($userId);
    }

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
