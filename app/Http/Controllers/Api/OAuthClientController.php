<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserSecurityLog;
use App\Models\UserSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Http\Rules\RedirectRule;

class OAuthClientController extends Controller
{
    public function __construct(
        private readonly ClientRepository $clients,
        private readonly RedirectRule $redirectRule,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'clients' => $request->user()
                ->oauthApps()
                ->where('revoked', false)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateClient($request);

        $client = $this->clients->createAuthorizationCodeGrantClient(
            $validated['name'],
            explode(',', $validated['redirect']),
            $validated['confidential'] ?? true,
            $request->user(),
            $validated['device_flow'] ?? false,
        );
        $client->forceFill([
            'is_first_party' => $validated['is_first_party'] ?? false,
            'allows_direct_login' => $validated['allows_direct_login'] ?? false,
            'allowed_origins' => json_encode($validated['allowed_origins'] ?? []),
        ])->save();

        $this->log($request, 'oauth_client_created', $client);

        return response()->json([
            'success' => true,
            'client' => $client->append('plain_secret'),
        ], 201);
    }

    public function update(Request $request, string $clientId): JsonResponse
    {
        $client = $this->findOwnedClient($request, $clientId);
        $validated = $this->validateClient($request, false);

        $client->forceFill([
            'name' => $validated['name'],
            'redirect_uris' => explode(',', $validated['redirect']),
            'is_first_party' => $validated['is_first_party'] ?? $client->is_first_party,
            'allows_direct_login' => $validated['allows_direct_login'] ?? $client->allows_direct_login,
            'allowed_origins' => json_encode($validated['allowed_origins'] ?? json_decode($client->getRawOriginal('allowed_origins') ?: '[]', true)),
        ])->save();

        $this->log($request, 'oauth_client_updated', $client);

        return response()->json([
            'success' => true,
            'client' => $client,
        ]);
    }

    public function regenerateSecret(Request $request, string $clientId): JsonResponse
    {
        $client = $this->findOwnedClient($request, $clientId);
        $this->clients->regenerateSecret($client);
        $this->log($request, 'oauth_client_secret_regenerated', $client);

        return response()->json([
            'success' => true,
            'client' => $client->append('plain_secret'),
        ]);
    }

    public function destroy(Request $request, string $clientId): JsonResponse
    {
        $client = $this->findOwnedClient($request, $clientId);
        $this->clients->delete($client);

        UserSession::query()
            ->where('oauth_client_id', $client->id)
            ->where('is_revoked', false)
            ->update([
                'is_revoked' => true,
                'revoked_at' => now(),
                'revoked_reason' => 'oauth_client_revoked',
            ]);

        $this->log($request, 'oauth_client_revoked', $client);

        return response()->json([
            'success' => true,
            'message' => 'OAuth client revoked.',
        ]);
    }

    private function validateClient(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'redirect' => ['required', 'string', $this->redirectRule],
            'confidential' => [$creating ? 'sometimes' : 'exclude', 'boolean'],
            'device_flow' => [$creating ? 'sometimes' : 'exclude', 'boolean'],
            'is_first_party' => ['sometimes', 'boolean'],
            'allows_direct_login' => ['sometimes', 'boolean'],
            'allowed_origins' => ['sometimes', 'array'],
            'allowed_origins.*' => ['url:http,https'],
        ]);
    }

    private function findOwnedClient(Request $request, string $clientId): Client
    {
        return $request->user()
            ->oauthApps()
            ->where('revoked', false)
            ->findOrFail($clientId);
    }

    private function log(Request $request, string $event, Client $client): void
    {
        UserSecurityLog::create([
            'user_id' => $request->user()->id,
            'event' => $event,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'meta' => ['client_id' => $client->id, 'client_name' => $client->name],
        ]);
    }
}
