<?php
namespace App\Services\AdminLive;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Arr;

/** Reuse the screen's read query and authorization, without an HTTP request or browser reload. */
class Reader {
    public function read(User $user,string $url,string $mode='page'): array {
        $view=Views::resolve($url,$mode);abort_unless($view && !$user->isLocked(),403);
        $parts=parse_url($url);parse_str($parts['query'] ?? '',$query);
        if(($parts['path'] ?? '')==='/admin/chat/conversations' && (int)($query['live_pages'] ?? 1)>1) {
            $pages=min(100,(int)$query['live_pages']);unset($query['live_pages']);$combined=null;
            for($page=1;$page<=$pages;$page++) {
                $result=$this->read($user,$parts['path'].'?'.http_build_query([...$query,'page'=>$page]),$mode);
                if($combined===null)$combined=$result;else $combined['data']=[...$combined['data'],...$result['data']];
                $combined['meta']['current_page']=$page;
                if($page>=($result['meta']['last_page'] ?? 1))break;
            }
            return $combined;
        }
        $original=app('request');$default=Auth::getDefaultDriver();$guard=Auth::guard('web');$previous=$guard->getUser();
        $factory=app(\Inertia\ResponseFactory::class);$shared=$factory->getShared();
        $request=Request::create(rtrim(config('app.url'),' /').$url,'GET');
        $request->headers->set('X-Inertia','true');$request->headers->set('Accept','application/json');
        $request->setUserResolver(fn()=>$user);$request->attributes->set('admin_live_props',$view['props']);
        if($original->hasSession()) $request->setLaravelSession($original->session());
        else $request->setLaravelSession(new \Illuminate\Session\Store('live-read',new \Illuminate\Session\ArraySessionHandler(30)));
        try {
            Auth::shouldUse('web');$guard->setUser($user);app()->instance('request',$request);$factory->flushShared();
            $router=app(Router::class);$route=clone $router->getRoutes()->match($request);$route->bind($request);$request->setRouteResolver(fn()=>$route);
            // Infrastructure for cookies/session/CSRF/assets is unnecessary for a server-side projection.
            // Auth, unlocked-user, role/permission/policy and route binding middleware are retained.
            $skip=[\Illuminate\Cookie\Middleware\EncryptCookies::class,\Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
                \Illuminate\Session\Middleware\StartSession::class,\Illuminate\View\Middleware\ShareErrorsFromSession::class,
                \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
                \App\Http\Middleware\HandleInertiaRequests::class,\Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
                \Illuminate\Routing\Middleware\ThrottleRequests::class,\Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class];
            $middleware=array_values(array_filter($router->gatherRouteMiddleware($route),fn($m)=>!in_array(explode(':',$m,2)[0],$skip)));
            $response=app(Pipeline::class)->send($request)->through($middleware)->then(function($r) use($route) {
                $response=$route->run();
                return $response instanceof \Illuminate\Contracts\Support\Responsable ? $response->toResponse($r) : $response;
            });
            abort_unless($response instanceof \Illuminate\Http\JsonResponse && $response->isSuccessful(),403);
            $data=$response->getData(true);
            return $view['props']===null ? $data : Arr::only($data['props'] ?? [],$view['props']);
        } finally {
            app()->instance('request',$original);Auth::shouldUse($default);
            if($previous) $guard->setUser($previous);else $guard->forgetUser();
            $factory->flushShared();$factory->share($shared);
        }
    }
}
