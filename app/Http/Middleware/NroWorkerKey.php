<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class NroWorkerKey
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && strlen($token) >= 40, 401);
        $key = DB::table('nro_worker_keys')->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first();
        abort_unless($key, 401);
        $request->attributes->set('nro_worker_key_id', $key->id);
        if (\Illuminate\Support\Facades\Cache::add('nro:key-seen:'.$key->id,true,20)) DB::table('nro_worker_keys')->where('id',$key->id)->update(['last_used_at'=>now()]);
        return $next($request);
    }
}
