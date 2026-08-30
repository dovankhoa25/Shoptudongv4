# Dovankhoa SSO

The Laravel application is deployed at one domain:

```text
https://sso.dovankhoa.vn
```

It provides three separate surfaces:

| Surface | Purpose |
| --- | --- |
| `/login`, `/register`, `/auth/google/*` | Web session used by the SSO pages |
| `/oauth/*` | Standard Passport OAuth2 authorization code, token and device flow |
| `/api/*` | JSON API for trusted first-party frontends and authenticated accounts |

## Initial setup

Run migrations and generate Passport keys if the environment does not already
have them:

```bash
php artisan migrate
php artisan passport:keys
```

Create one confidential password grant client. This is an internal backend
credential. Never expose its secret to a browser or mobile application:

```bash
php artisan passport:client --password --name="SSO First Party API"
```

Add its generated credentials and the administrator user IDs to `.env`:

```dotenv
SSO_PASSWORD_CLIENT_ID=
SSO_PASSWORD_CLIENT_SECRET=
SSO_ADMIN_USER_IDS=1
CORS_ALLOWED_ORIGINS=https://nrocheck.vn
```

## Direct login for owned frontends

Owned sites such as `nrocheck.vn` may show their own username and password
form without redirecting to the SSO page.

Create an OAuth client policy for the frontend:

```json
{
  "name": "nrocheck.vn",
  "redirect": "https://nrocheck.vn/auth/callback",
  "confidential": false,
  "device_flow": false,
  "is_first_party": true,
  "allows_direct_login": true,
  "allowed_origins": ["https://nrocheck.vn"]
}
```

The frontend calls:

```http
POST /api/auth/login
Origin: https://nrocheck.vn
Content-Type: application/json

{
  "client_id": "<nrocheck-client-uuid>",
  "login": "player123",
  "password": "password"
}
```

The `authorization` object contains the Passport token pair:

```json
{
  "token_type": "Bearer",
  "expires_in": 3600,
  "access_token": "...",
  "refresh_token": "..."
}
```

Refresh the token pair:

```http
POST /api/auth/refresh
Origin: https://nrocheck.vn
Content-Type: application/json

{
  "client_id": "<nrocheck-client-uuid>",
  "refresh_token": "..."
}
```

Direct login is rejected unless the request origin matches the client policy.
Do not enable direct login for third-party websites.

## First-party API routes

| Method | Endpoint | Purpose |
| --- | --- | --- |
| `POST` | `/api/auth/register` | Register and return a token pair |
| `POST` | `/api/auth/login` | Direct login and return a token pair |
| `POST` | `/api/auth/refresh` | Rotate the refresh token |
| `POST` | `/api/auth/forgot-password` | Email a password reset link |
| `POST` | `/api/auth/reset-password` | Reset password with the emailed token |
| `GET` | `/api/me` | Return the current user |
| `POST` | `/api/account/logout` | Revoke the current access token |
| `PUT` | `/api/account/password` | Change password and revoke all sessions |
| `GET` | `/api/account/sessions` | List sessions |
| `DELETE` | `/api/account/sessions/{session}` | Revoke a session |

## SSO redirect flow

Sites may also show a "Continue with Dovankhoa" button. Redirect the browser:

```text
https://sso.dovankhoa.vn/oauth/authorize
  ?client_id=<id>
  &redirect_uri=https://nrocheck.vn/auth/callback
  &response_type=code
  &scope=profile:read
  &state=<random>
  &code_challenge=<pkce-challenge>
  &code_challenge_method=S256
```

The SSO web session handles username/password and Google login. Passport then
redirects the browser back to the application with an authorization code.
Exchange the code at:

```http
POST /oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=authorization_code
client_id=<id>
redirect_uri=https://nrocheck.vn/auth/callback
code=<authorization-code>
code_verifier=<pkce-verifier>
```

Use Authorization Code with PKCE for public browser and mobile clients.

## OAuth client administration

Only configured SSO administrators with the `oauth-clients:manage` scope can
manage clients:

| Method | Endpoint | Purpose |
| --- | --- | --- |
| `GET` | `/api/admin/oauth-clients` | List owned OAuth clients |
| `POST` | `/api/admin/oauth-clients` | Create a client |
| `PUT` | `/api/admin/oauth-clients/{clientId}` | Update a client |
| `POST` | `/api/admin/oauth-clients/{clientId}/regenerate-secret` | Rotate secret |
| `DELETE` | `/api/admin/oauth-clients/{clientId}` | Revoke client and sessions |

## Device flow

Clients created with `"device_flow": true` may request a device code from
`POST /oauth/device/code`. Users enter the displayed code at `/oauth/device`.
