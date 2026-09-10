<?php
namespace App\Observers;
use App\Models\User;
use App\Services\UserRealtimeNotifier;
use App\Services\AdminRealtimeNotifier;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
class UserBalanceObserver implements ShouldHandleEventsAfterCommit {
    public function created(User $user): void {app(AdminRealtimeNotifier::class)->changed('user',$user->id,'created',null,'Tài khoản vừa được tạo.');}
    public function deleted(User $user): void {app(AdminRealtimeNotifier::class)->changed('user',$user->id,'deleted',null,'Tài khoản đã bị xóa.');}
    public function updated(User $user): void {
        if($user->wasChanged('balance')) {
            $notify=fn()=>app(UserRealtimeNotifier::class)->balanceChanged((int)$user->id,0,(int)$user->balance,'Số dư của bạn đã được cập nhật.');
            \Illuminate\Support\defer($notify,'user-balance:'.$user->id);
            if(app()->runningInConsole())app()->terminating($notify);
        }
        if($user->wasChanged(['balance','username','email','status','locked_until'])) app(AdminRealtimeNotifier::class)->changed('user',$user->id,'updated',null,'Thông tin tài khoản được cập nhật.');
    }
}
