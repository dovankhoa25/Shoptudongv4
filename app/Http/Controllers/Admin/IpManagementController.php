<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessIpBlock;
use App\Models\AdminAccessDevice;
use App\Models\LoginAttempt;
use App\Services\AdminAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\IpUtils;

class IpManagementController extends Controller
{
    public function index(Request $request, AdminAccessService $access)
    {
        $section = $request->route('section', 'overview');
        $states = match ($section) {
            'devices' => ['pending', 'approved', 'expired', 'revoked', 'all'],
            'blocks' => ['active', 'expired', 'revoked', 'all'],
            'logins' => ['all', 'success', 'failed'],
            default => ['shared', 'all'],
        };
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:191'], 'ip' => ['nullable', 'ip'],
            'days' => ['nullable', 'integer', 'in:1,7,30,90'],
            'status' => ['nullable', 'in:'.implode(',', $states)],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'in:20,50,100'],
        ]);
        $filters = ['search' => trim($data['search'] ?? ''), 'ip' => $data['ip'] ?? '',
            'days' => (int) ($data['days'] ?? 30), 'status' => $data['status'] ?? $states[0],
            'per_page' => (int) ($data['per_page'] ?? 20)];

        return Inertia::render('Admin/IpManagement/Index', [
            'section' => $section, 'filters' => $filters,
            'listing' => fn () => match ($section) {
                'devices' => $this->devices($filters),
                'blocks' => $this->blocks($filters),
                'logins' => $this->logins($filters),
                default => $this->sharedIps($filters),
            },
            'summary' => fn () => [
                'pending_devices' => AdminAccessDevice::where('status', 'pending')->count(),
                'active_blocks' => $this->activeBlocks()->count(),
            ],
            'approval_required' => fn () => $access->approvalRequired(),
            'current_ip' => $request->ip(),
            'can_view_users' => $request->user()->hasRole('super-admin') || $request->user()->can('users.view'),
        ]);
    }

    public function detail(Request $request)
    {
        $data = $request->validate(['ip' => ['required', 'ip'], 'days' => ['nullable', 'integer', 'in:1,7,30,90'],
            'page' => ['nullable', 'integer', 'min:1']]);
        $days = (int) ($data['days'] ?? 30);
        $ip = $data['ip'];
        $pairs = $this->successfulLogins($days)->where('ip_address', $ip)
            ->select('user_id')->selectRaw('COUNT(*) as successful_logins, MIN(created_at) as first_seen, MAX(created_at) as last_seen')
            ->groupBy('user_id');
        $users = DB::query()->fromSub($pairs, 'activity')->leftJoin('users', 'users.id', '=', 'activity.user_id')
            ->select('activity.*', 'users.username', 'users.status', 'users.deleted_at')
            ->orderByDesc('activity.last_seen')->orderBy('activity.user_id')->paginate(20);
        $recent = LoginAttempt::where('ip_address', $ip)->where('created_at', '>=', now()->subDays($days))
            ->with('user:id,username,status')->latest('id')->limit(10)->get($this->attemptColumns());
        $recent->each(fn ($row) => $this->sanitizeAttempt($row));

        return response()->json(['ip' => $ip, 'days' => $days, 'users' => $users, 'recent' => $recent,
            'blocks' => $this->matchingBlocks($ip, $this->activeBlocks()->get(['id', 'network', 'reason', 'expires_at']))]);
    }

    private function successfulLogins(int $days): Builder
    {
        // Failed password guesses do not establish an account/IP relationship.
        return LoginAttempt::where('is_success', true)->whereNotNull('user_id')
            ->whereNotNull('ip_address')->where('ip_address', '!=', '')
            ->where('created_at', '>=', now()->subDays($days));
    }

    private function activeBlocks(): Builder
    {
        return AccessIpBlock::whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    private function userSearch($query, string $search): void
    {
        if (preg_match('/^#?(\d+)$/', $search, $match)) {
            $query->where('users.id', (int) $match[1]);
        } else {
            $query->where('users.username', 'like', '%'.$search.'%');
        }
    }

    private function sharedIps(array $filters)
    {
        $base = $this->successfulLogins($filters['days']);
        $query = clone $base;
        if ($filters['search'] !== '') {
            $search = $filters['search'];
            if (filter_var($search, FILTER_VALIDATE_IP)) {
                $query->where('ip_address', $search);
            } else {
                // Find IPs used by a matching account, then retain every account on those IPs.
                $matching = (clone $base)->whereHas('user', fn ($q) => $this->userSearch($q, $search))->select('ip_address');
                $query->where(fn ($q) => $q->where('ip_address', 'like', $search.'%')->orWhereIn('ip_address', $matching));
            }
        }
        if ($filters['ip']) {
            $query->where('ip_address', $filters['ip']);
        }
        $listing = $query->select('ip_address')
            ->selectRaw('COUNT(DISTINCT user_id) as users_count, COUNT(*) as successful_logins, MIN(created_at) as first_seen, MAX(created_at) as last_seen')
            ->groupBy('ip_address')->when($filters['status'] === 'shared', fn ($q) => $q->havingRaw('COUNT(DISTINCT user_id) >= 2'))
            ->orderByDesc('users_count')->orderByDesc('last_seen')->orderBy('ip_address')->paginate($filters['per_page'])->withQueryString();
        $ips = $listing->getCollection()->pluck('ip_address');
        $previews = collect();
        if ($ips->isNotEmpty()) {
            $pairs = (clone $base)->whereIn('ip_address', $ips)->select('ip_address', 'user_id')
                ->selectRaw('MAX(created_at) as last_seen')->groupBy('ip_address', 'user_id');
            $ranked = DB::query()->fromSub($pairs, 'pairs')->select('pairs.*')
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY ip_address ORDER BY last_seen DESC, user_id ASC) as position');
            $previews = DB::query()->fromSub($ranked, 'ranked')->leftJoin('users', 'users.id', '=', 'ranked.user_id')
                ->where('position', '<=', 3)->orderBy('position')
                ->get(['ranked.ip_address', 'ranked.user_id', 'users.username', 'users.status', 'users.deleted_at'])->groupBy('ip_address');
        }
        $blocks = $ips->isEmpty() ? collect() : $this->activeBlocks()->get(['id', 'network', 'reason', 'expires_at']);
        $listing->getCollection()->transform(function ($row) use ($previews, $blocks) {
            $row->users = $previews->get($row->ip_address, collect())->values();
            $row->blocks = $this->matchingBlocks($row->ip_address, $blocks);

            return $row;
        });

        return $listing;
    }

    private function matchingBlocks(string $ip, $blocks): array
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return [];
        }

        return $blocks->filter(fn ($block) => IpUtils::checkIp($ip, $block->network))->values()->all();
    }

    private function devices(array $filters)
    {
        $query = AdminAccessDevice::with('user:id,username,status');
        if ($filters['search']) {
            $search = $filters['search'];
            $query->where(fn ($q) => $q->where('ip_address', 'like', $search.'%')
                ->orWhereHas('user', fn ($u) => $this->userSearch($u, $search)));
        }
        if ($filters['ip']) {
            $query->where('ip_address', $filters['ip']);
        }
        if ($filters['status'] === 'expired') {
            $query->where('status', 'approved')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '<=', now()));
        } elseif ($filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
            if ($filters['status'] === 'approved') {
                $query->where('expires_at', '>', now());
            }
        }

        return $query->orderByDesc('last_seen_at')->orderByDesc('id')->paginate($filters['per_page'])->withQueryString()
            ->through(function ($row) {
                $row->display_status = $row->status === 'approved' && (! $row->expires_at || $row->expires_at->isPast()) ? 'expired' : $row->status;

                return $row;
            });
    }

    private function blocks(array $filters)
    {
        $query = AccessIpBlock::query();
        if ($filters['search']) {
            $search = $filters['search'];
            $query->where(fn ($q) => $q->where('network', 'like', '%'.$search.'%')->orWhere('reason', 'like', '%'.$search.'%'));
        }
        if ($filters['status'] === 'active') {
            $query->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        } elseif ($filters['status'] === 'expired') {
            $query->whereNull('revoked_at')->where('expires_at', '<=', now());
        } elseif ($filters['status'] === 'revoked') {
            $query->whereNotNull('revoked_at');
        }

        return $query->latest('id')->paginate($filters['per_page'])->withQueryString()->through(function ($row) {
            $row->display_status = $row->revoked_at ? 'revoked' : ($row->expires_at?->isPast() ? 'expired' : 'active');

            return $row;
        });
    }

    private function attemptColumns(): array
    {
        return ['id', 'user_id', 'username', 'provider', 'ip_address', 'user_agent', 'is_success', 'failure_reason', 'created_at', 'meta'];
    }

    private function sanitizeAttempt($row)
    {
        $row->meta = array_intersect_key($row->meta ?? [], array_flip(['channel', 'ip_source', 'access_request_id']));

        return $row;
    }

    private function logins(array $filters)
    {
        $query = LoginAttempt::with('user:id,username,status')->where('created_at', '>=', now()->subDays($filters['days']));
        if ($filters['ip']) {
            $query->where('ip_address', $filters['ip']);
        }
        if ($filters['search']) {
            $search = $filters['search'];
            $query->where(fn ($q) => $q->where('ip_address', 'like', $search.'%')->orWhere('username', 'like', '%'.$search.'%')
                ->orWhereHas('user', fn ($u) => $this->userSearch($u, $search)));
        }
        if ($filters['status'] !== 'all') {
            $query->where('is_success', $filters['status'] === 'success');
        }

        return $query->latest('id')->paginate($filters['per_page'], $this->attemptColumns())->withQueryString()
            ->through(fn ($row) => $this->sanitizeAttempt($row));
    }
}
