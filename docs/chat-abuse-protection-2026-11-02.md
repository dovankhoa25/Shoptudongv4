# Chat and health ingress protection — 2026-11-02

## Findings

- `/up` is Laravel's public health check, not a chat endpoint. It has no web/API middleware group, so the existing chat limiter and IP-block middleware did not protect it.
- Chat already requires authentication, unlocked accounts, conversation authorization, and API token scopes. Conversation resolution locks the customer row and reuses an existing general/order chat. Message retries with `client_message_id` reuse the existing message.
- A single chat request budget covered reads, sends, resolve, and read receipts. Reducing that shared budget to 10/minute can break ordinary chat loading. It also did not reject unauthenticated request bursts before authentication.
- Opening an unchanged chat still updated participant join timestamps and broadcast an inbox update. A caller could repeatedly trigger writes and realtime fan-out without sending a message.
- Storefront realtime channel recovery already has bounded retries, handles `Retry-After`, and does not automatically retry 401/403. The one-second widget timer updates local visibility, not API polling.

## Implemented limits

| Operation | Default | Identity |
| --- | --- | --- |
| `/up` requests | 60/minute | Trusted/resolved client IP |
| Combined chat and broadcasting ingress | 600/minute | Trusted/resolved client IP |
| Combined chat reads and writes | 100/minute | User across API/web/admin |
| Resolve/open conversation | 1/minute | User across API/web/admin |
| Customer message sends | 10/minute | User across conversations/API/web |
| Agent message sends | 90/minute | User across conversations/API/web/admin |
| Message requests containing image uploads | 6/minute | User across conversations/API/web/admin |

Action-specific limits also consume the shared 100-request chat budget. Uploads also consume the send budget. Retried requests count toward request limits, while existing message idempotency continues to prevent duplicate inserts/attachments/broadcasts. Limits expire normally and return HTTP 429 with `Retry-After`.

`ThrottlePublicIngress` runs after trusted client IP resolution and CORS, before route authentication, session middleware and model binding. It covers API, customer web, admin chat, signed chat media, and broadcasting authentication. It uses fixed categories rather than raw paths as cache keys. CORS preflight remains handled before this middleware.

Health requests and requests rejected by the ingress limiter skip application traffic metric writes. The health diagnostic handler is retained. Accepted chat traffic retains its existing metric setting. Rate limiting still uses the application's configured Laravel limiter/cache store; it does not make PHP boot or cache operations free.

Resolving an unchanged conversation no longer rewrites active participant timestamps or broadcasts an inbox update. Creation, reopening, attaching an order, and actual agent reassignment continue to notify. Participant rejoining still restores its role/join state.

## Deployment and hosting

1. Upload the changed backend files, including the new global middleware and `config/chat.php`; refresh Laravel's route/config caches using the project's normal deployment process. No database migration is required.
2. Keep a working cache store shared by PHP workers. Redis is suitable when available. A database cache performs database operations even for throttled traffic. Verify the actual hosting cache configuration before changing it; do not assume Redis is installed.
3. Optional environment overrides: `CHAT_INGRESS_PER_MINUTE`, `CHAT_RESOLVE_PER_MINUTE`, `CHAT_SEND_PER_MINUTE`, `CHAT_AGENT_SEND_PER_MINUTE`, `CHAT_UPLOAD_PER_MINUTE`. Health defaults to 60/minute per IP and combined chat to 100/minute per user in `AppServiceProvider`; the former read/write environment overrides are no longer used.
4. Verify `SECURITY_TRUSTED_PROXIES` and authenticated storefront forwarding against the real proxy topology. Never trust arbitrary `X-Forwarded-For` or use `*` to identify clients. An incorrect proxy setup can make unrelated users share one IP budget.
5. Ask hosting/WAF to rate-limit `/up` and chat API bursts before PHP. Keep room for shared office/mobile IPs. Application throttling limits expensive downstream work but cannot absorb a distributed flood or protect cPanel ports.
6. Machine-to-machine API requests cannot complete browser JavaScript challenges. Review the observed international challenge failures for legitimate frontend servers; do not broadly allow a country or disable API authentication/rate limits.

After release, verify ordinary chat open/send/read, staff replies, uploads, reconnect, and 429 recovery. Observe WAF logs, request rates and server resource metrics. This local change has not been deployed or load-tested on hosting.

## Local validation

`ChatAbuseProtectionTest` covers health quota/no session/no DB queries with an in-memory limiter, unauthenticated ingress rejection before database authentication, ignored untrusted forwarding headers, independent IP budgets, CORS on 429, preflight, the combined 100-request read/write budget, separate send quotas, shared user quotas across API/web, expiry, upload isolation, and unchanged resolve without duplicate writes/broadcasts.

The existing chat suite covers ownership/scopes, message idempotency, attachments, reactions, tips, outbound chats, session/token revocation, and realtime authorization/recovery. Tests use SQLite in memory, an array cache, a temporary application key, and fresh isolated storage/test OAuth keys rather than production credentials or data.

Before the shared-budget adjustment, validation passed: 165 tests, 1,705 assertions (`Chat|ApiRateLimitIsolation|FinancialWebhookIngress|CorsPreflight|AccessIpBlockCache`), including the default one-per-minute resolve quota, continued reading/sending, independent user budgets, and expiry. PHP syntax checks passed for all changed PHP files. The shared chat budget was subsequently set to 100/minute at the user's request.

After restoring the changes and retaining the shared 100/minute chat budget, validation passed: 178 tests, 1,878 assertions (`Chat|ApiRateLimitIsolation|FinancialWebhookIngress|CorsPreflight|AccessIpBlockCache|OriginIpErrorResponse|GoldTransactionErrorResponse|ApiAuthenticationTest`). The shared-budget test verifies that one send plus 99 reads exhausts the account budget, other accounts remain independent, and access recovers after expiry.
