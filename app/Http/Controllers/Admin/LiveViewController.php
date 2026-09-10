<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Services\AdminLive\{Views,Reader,Updates};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class LiveViewController extends Controller {
    public function store(Request $r) {
        $v=$r->validate(['url'=>'required|string|max:2500','mode'=>'sometimes|in:page,summary']);
        $config=Views::resolve($v['url'],$v['mode'] ?? 'page');abort_unless($config,422);
        if($config['resources']===['user_balance'])$config['resources']=['user_balance:'.$r->user()->id];
        abort_if(DB::table('admin_live_views')->where('user_id',$r->user()->id)->where('expires_at','>',now())->count()>=50,429);
        // Permission is checked before registering a channel and again on every publication.
        app(Reader::class)->read($r->user(),$v['url'],$v['mode'] ?? 'page');
        $credentials=app(\App\Services\Chat\ChatRealtimeChannel::class);
        abort_unless($credentials->currentForRequest($r,true),403);
        $hash=$credentials->webCredentialHash($r);abort_unless($hash,403);
        $id=(string)Str::uuid();DB::table('admin_live_views')->insert(['id'=>$id,'user_id'=>$r->user()->id,'url'=>$v['url'],
            'credential_hash'=>$hash,'mode'=>$v['mode'] ?? 'page','resources'=>json_encode($config['resources']),'expires_at'=>now()->addMinutes(30)]);
        return response()->json(['id'=>$id,'channel'=>'Admin.View.'.$id])->header('Cache-Control','no-store');
    }
    private function owned(Request $r,string $id): object {
        $view=DB::table('admin_live_views')->where('id',$id)->where('user_id',$r->user()->id)->where('credential_hash',app(\App\Services\Chat\ChatRealtimeChannel::class)->webCredentialHash($r))->where('expires_at','>',now())->first();abort_unless($view,404);return $view;
    }
    public function sync(Request $r,string $id,Updates $updates) {
        $view=$this->owned($r,$id);$state=$updates->snapshot($view);
        DB::table('admin_live_views')->where('id',$id)->update(['expires_at'=>now()->addMinutes(30)]);
        return response()->json($state)->header('Cache-Control','no-store');
    }
    public function renew(Request $r,string $id) {
        $this->owned($r,$id);abort_unless(app(\App\Services\Chat\ChatRealtimeChannel::class)->currentForRequest($r,true),403);DB::table('admin_live_views')->where('id',$id)->update(['expires_at'=>now()->addMinutes(30)]);return response()->noContent();
    }
    public function destroy(Request $r,string $id) {
        DB::table('admin_live_views')->where('id',$id)->where('user_id',$r->user()->id)->delete();
        // Never clear another user's view state, including for guessed UUIDs.
        return response()->noContent();
    }
}
