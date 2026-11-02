<?php
namespace App\Services\AdminLive;
use App\Exceptions\ChatRealtimeValidationUnavailable;
use App\Events\AdminViewPatched;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
class Updates {
    private array $pending=[];
    private bool $scheduled=false;
    public function changed(string $resource): void {
        $this->pending[$resource]=true;
        if($this->scheduled)return;$this->scheduled=true;
        // Coalesce all writes in a request, then send after its response. CLI also flushes at termination.
        \Illuminate\Support\defer(fn()=>$this->flush(),'admin-live-updates');
        app()->terminating(fn()=>$this->flush());
    }
    public function flush(): void {
        $resources=array_keys($this->pending);$this->pending=[];$this->scheduled=false;if(!$resources)return;
        try {
            DB::table('admin_live_views')->where('expires_at','<=',now())->delete();
            // Share a projection only within this flush and the same user/session/filter.
            $projections=[];
            // Leave unrelated screens in the database instead of fetching every
            // active subscription whenever any resource changes.
            $views=DB::table('admin_live_views')->where('expires_at','>',now())
                ->where(function($query)use($resources) {
                    foreach($resources as $resource)$query->orWhereJsonContains('resources',$resource);
                })->get();
            foreach($views as $view) {
                try {$this->refresh($view,$projections);}catch(\Throwable $e){Log::warning('Admin live view update failed',['view'=>$view->id,'error'=>$e->getMessage()]);}
            }
        }catch(\Throwable $e){Log::warning('Admin live update failed',['error'=>$e->getMessage()]);}
    }
    public function snapshot(object $view): array {
        return Cache::lock('admin-live:lock:'.$view->id,30)->block(5,function()use($view) {
            $user=User::find($view->user_id);abort_unless($user && !$user->isLocked() && app(\App\Services\Chat\ChatRealtimeChannel::class)->webCredentialIsActive((int)$view->user_id,$view->credential_hash),403);
            $data=app(Reader::class)->read($user,$view->url,$view->mode);
            $previous=Cache::get('admin-live:state:'.$view->id);
            $state=['revision'=>$this->nextRevision($view->id),'data'=>$data];
            Cache::put('admin-live:state:'.$view->id,$state,3600);return $state;
        });
    }
    private function nextRevision(string $id): int {
        DB::table('admin_live_views')->where('id',$id)->increment('revision');
        return (int)DB::table('admin_live_views')->where('id',$id)->value('revision');
    }
    private function refresh(object $view,array &$projections): void {
        Cache::lock('admin-live:lock:'.$view->id,30)->block(5,function()use($view,&$projections) {
            if(!DB::table('admin_live_views')->where('id',$view->id)->where('expires_at','>',now())->exists())return;
            $user=User::find($view->user_id);
            $validation='account';
            try {
                abort_unless($user && !$user->isLocked(),403);
                $validation='session';
                abort_unless(app(\App\Services\Chat\ChatRealtimeChannel::class)->webCredentialIsActive((int)$view->user_id,$view->credential_hash),403);
                $validation='projection';
                $key=hash('sha256',json_encode([$view->user_id,$view->credential_hash,$view->url,$view->mode],JSON_THROW_ON_ERROR));
                $data=$projections[$key] ??= app(Reader::class)->read($user,$view->url,$view->mode);
            }
            catch(ChatRealtimeValidationUnavailable $e) {
                // A session-store outage is not proof that this staff session was
                // revoked. Publish nothing until validation can succeed again.
                Log::warning('Admin live session validation temporarily unavailable',[
                    'view'=>$view->id,'user_id'=>$view->user_id,
                    'cause'=>$e->getPrevious() ? $e->getPrevious()::class : null,
                ]);
                // No projection or session details leave this boundary. The
                // already subscribed tab can retry its authorized snapshot.
                broadcast(new AdminViewPatched($view->id,['viewId'=>$view->id,'retry'=>true]));
                return;
            }
            catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
                if(in_array($e->getStatusCode(),[401,403,404])) {
                    Log::notice('Admin live view authorization revoked',[
                        'view'=>$view->id,'user_id'=>$view->user_id,
                        'check'=>$validation,'status'=>$e->getStatusCode(),
                    ]);
                    broadcast(new AdminViewPatched($view->id,['revoked'=>true,'viewId'=>$view->id]));
                    DB::table('admin_live_views')->where('id',$view->id)->delete();Cache::forget('admin-live:state:'.$view->id);return;
                }throw $e;
            }
            $old=Cache::get('admin-live:state:'.$view->id);
            $ops=Delta::between($old['data'] ?? null,$data);if(!$ops)return;
            $revision=$this->nextRevision($view->id);
            Cache::put('admin-live:state:'.$view->id,['revision'=>$revision,'data'=>$data],3600);
            $chunks=str_split(json_encode($ops,JSON_THROW_ON_ERROR),4500);$batch=(string)Str::uuid();
            foreach($chunks as $part=>$chunk)broadcast(new AdminViewPatched($view->id,[
                'viewId'=>$view->id,'batch'=>$batch,'base'=>$old['revision'] ?? 0,'revision'=>$revision,
                'part'=>$part,'total'=>count($chunks),'chunk'=>base64_encode($chunk),
            ]));
        });
    }
}
