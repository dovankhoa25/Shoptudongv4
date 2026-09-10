<?php
namespace Tests\Feature;
use App\Events\UserEvent;
use App\Models\User;
use App\Services\UserBalanceSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Event};
use Tests\TestCase;
class UserBalanceRealtimeTest extends TestCase {
    use RefreshDatabase;
    public function test_balance_events_use_committed_absolute_balance_and_stable_revision(): void {
        $user=User::factory()->create(['balance'=>100]);
        $first=(new UserEvent($user->id,'update_balance','old',['balance'=>1,'amount'=>50]))->broadcastWith()['payload'];
        $this->assertSame(100,$first['balance']);$this->assertSame(1,$first['balance_revision']);
        $this->assertSame($first['balance_revision'],UserBalanceSnapshot::read($user->id)['balance_revision']);
        DB::table('users')->where('id',$user->id)->update(['balance'=>70]);
        $second=(new UserEvent($user->id,'update_balance','new',['balance'=>1000]))->broadcastWith()['payload'];
        $this->assertSame(70,$second['balance']);$this->assertGreaterThan($first['balance_revision'],$second['balance_revision']);
        $this->assertSame(70,(int)$user->fresh()->balance);
    }
    public function test_profile_response_contains_revision_and_no_other_users_balance(): void {
        $user=User::factory()->create(['balance'=>123]);User::factory()->create(['balance'=>999]);
        $resource=(new \App\Http\Resources\Profile\ProfileResource($user))->resolve();
        $this->assertSame(123,$resource['balance']);$this->assertSame(1,$resource['balance_revision']);
    }
    public function test_rollback_does_not_publish_an_uncommitted_balance(): void {
        $user=User::factory()->create(['balance'=>100]);Event::fake([UserEvent::class]);
        try {DB::transaction(function()use($user){$user->update(['balance'=>50]);throw new \RuntimeException('rollback');});}catch(\RuntimeException){}
        Event::assertNotDispatched(UserEvent::class);$this->assertSame(100,(int)$user->fresh()->balance);
    }
    public function test_direct_eloquent_balance_change_notifies_owner_after_commit(): void {
        $user=User::factory()->create(['balance'=>100]);Event::fake([UserEvent::class]);
        $user->decrement('balance',30);
        app(\Illuminate\Support\Defer\DeferredCallbackCollection::class)->invoke();
        Event::assertDispatched(UserEvent::class,fn($event)=>$event->userId===$user->id && $event->type==='update_balance');
    }
}
