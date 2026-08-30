<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserSecurityLog;
use App\Services\FrontendClientRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

class FrontendClientController extends Controller
{
    public function __construct(
        private readonly ClientRepository $clients,
        private readonly FrontendClientRegistry $registry,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $baseQuery = $this->frontendClientsQuery();
        $query = (clone $baseQuery)
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('id', 'like', "%{$search}%")
                        ->orWhere('allowed_origins', 'like', "%{$search}%");
                });
            })
            ->when(
                ($filters['status'] ?? null) === 'active',
                fn ($query) => $query->where('revoked', false),
            )
            ->when(
                ($filters['status'] ?? null) === 'inactive',
                fn ($query) => $query->where('revoked', true),
            )
            ->orderBy('name');

        $clients = $query->paginate(15)
            ->withQueryString()
            ->through(fn (Client $client): array => $this->serializeClient($client));

        return Inertia::render('Admin/FrontendClients/Index', [
            'clients' => $clients,
            'filters' => $filters,
            'stats' => [
                'total' => (clone $baseQuery)->count(),
                'active' => (clone $baseQuery)->where('revoked', false)->count(),
                'direct_login' => (clone $baseQuery)
                    ->where('revoked', false)
                    ->where('allows_direct_login', true)
                    ->count(),
            ],
            'can_manage' => $request->user()->hasRole('super-admin')
                || $request->user()->can('frontend-clients.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateClient($request);
        $origins = $this->normalizeOrigins($validated['allowed_origins']);
        $this->ensureOriginsAreAvailable($origins);

        $client = $this->clients->createAuthorizationCodeGrantClient(
            $validated['name'],
            $origins,
            false,
            $request->user(),
        );

        $client->forceFill([
            'is_first_party' => true,
            'allows_direct_login' => $validated['allows_direct_login'],
            'allowed_origins' => json_encode($origins, JSON_UNESCAPED_SLASHES),
            'revoked' => ! $validated['active'],
        ])->save();

        $this->registry->forget();
        $this->log($request, 'frontend_client_created', $client);

        return back()->with('success', "Đã tạo frontend client {$client->name}.");
    }

    public function update(Request $request, string $clientId): RedirectResponse
    {
        $client = $this->findFrontendClient($clientId);
        $validated = $this->validateClient($request);
        $origins = $this->normalizeOrigins($validated['allowed_origins']);
        $this->ensureOriginsAreAvailable($origins, $client->id);

        $client->forceFill([
            'name' => $validated['name'],
            'redirect_uris' => $origins,
            'allows_direct_login' => $validated['allows_direct_login'],
            'allowed_origins' => json_encode($origins, JSON_UNESCAPED_SLASHES),
            'revoked' => ! $validated['active'],
        ])->save();

        $this->registry->forget();
        $this->log($request, 'frontend_client_updated', $client);

        return back()->with('success', "Đã cập nhật frontend client {$client->name}.");
    }

    public function updateStatus(Request $request, string $clientId): RedirectResponse
    {
        $validated = $request->validate(['active' => ['required', 'boolean']]);
        $client = $this->findFrontendClient($clientId);
        $client->forceFill(['revoked' => ! $validated['active']])->save();

        $this->registry->forget();
        $this->log(
            $request,
            $validated['active'] ? 'frontend_client_enabled' : 'frontend_client_disabled',
            $client,
        );

        return back()->with(
            'success',
            $validated['active']
                ? "Đã bật frontend client {$client->name}."
                : "Đã tắt frontend client {$client->name}.",
        );
    }

    private function validateClient(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'allowed_origins' => ['required', 'array', 'min:1'],
            'allowed_origins.*' => [
                'required',
                'string',
                'max:191',
                'distinct',
                'url:http,https',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $parts = parse_url((string) $value);
                    $path = $parts['path'] ?? '';

                    if (
                        isset($parts['user'])
                        || isset($parts['pass'])
                        || isset($parts['query'])
                        || isset($parts['fragment'])
                        || ! in_array($path, ['', '/'], true)
                    ) {
                        $fail('Origin chỉ được gồm giao thức, tên miền và cổng.');
                    }
                },
            ],
            'allows_direct_login' => ['required', 'boolean'],
            'active' => ['required', 'boolean'],
        ]);
    }

    /** @param list<string> $origins
     * @return list<string>
     */
    private function normalizeOrigins(array $origins): array
    {
        return collect($origins)
            ->map(function (string $origin): string {
                $parts = parse_url($origin);
                $scheme = strtolower((string) ($parts['scheme'] ?? ''));
                $host = strtolower((string) ($parts['host'] ?? ''));
                $port = isset($parts['port']) ? ':'.$parts['port'] : '';

                return "{$scheme}://{$host}{$port}";
            })
            ->unique()
            ->values()
            ->all();
    }

    /** @param list<string> $origins */
    private function ensureOriginsAreAvailable(array $origins, ?string $ignoredClientId = null): void
    {
        $usedOrigins = $this->frontendClientsQuery()
            ->when($ignoredClientId, fn ($query) => $query->whereKeyNot($ignoredClientId))
            ->get(['allowed_origins'])
            ->flatMap(fn (Client $client): array => $this->clientOrigins($client))
            ->all();

        $duplicates = array_values(array_intersect($origins, $usedOrigins));

        if ($duplicates !== []) {
            throw ValidationException::withMessages([
                'allowed_origins' => 'Origin đã được gán cho frontend client khác: '.implode(', ', $duplicates),
            ]);
        }
    }

    private function findFrontendClient(string $clientId): Client
    {
        return $this->frontendClientsQuery()->findOrFail($clientId);
    }

    private function frontendClientsQuery()
    {
        return Passport::client()->newQuery()
            ->where('is_first_party', true)
            ->when(
                config('sso.password_client_id'),
                fn ($query, $internalClientId) => $query->whereKeyNot($internalClientId),
            );
    }

    /** @return list<string> */
    private function clientOrigins(Client $client): array
    {
        $origins = json_decode((string) $client->getRawOriginal('allowed_origins'), true);

        return is_array($origins) ? array_values($origins) : [];
    }

    private function serializeClient(Client $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'allowed_origins' => $this->clientOrigins($client),
            'allows_direct_login' => (bool) $client->allows_direct_login,
            'active' => ! (bool) $client->revoked,
            'created_at' => $client->created_at?->toISOString(),
            'updated_at' => $client->updated_at?->toISOString(),
        ];
    }

    private function log(Request $request, string $event, Client $client): void
    {
        UserSecurityLog::create([
            'user_id' => $request->user()->id,
            'event' => $event,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'meta' => [
                'client_id' => $client->id,
                'client_name' => $client->name,
                'active' => ! (bool) $client->revoked,
                'allowed_origins' => $this->clientOrigins($client),
            ],
        ]);
    }
}
