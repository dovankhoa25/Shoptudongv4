# API Auth — Tài liệu cho Frontend

Backend SSO trung tâm: `https://sso.dovankhoa.vn`
Frontend sở hữu (ví dụ nrocheck.vn) gọi trực tiếp các API bên dưới, không redirect sang trang SSO.

Mọi request auth (register/login/refresh) phải gửi kèm:
- Header `Origin: https://nrocheck.vn`
- Body `client_id: "<uuid-client-nrocheck>"` — UUID cố định, cấp bằng `passport:client --password`

Backend chỉ chấp nhận nếu client là first-party, `allows_direct_login = true`, và `Origin` khớp `allowed_origins` của client. Sai điều kiện nào cũng trả lỗi `422` với message chung: `"This client is not allowed to use direct login."`

---

## 1. Đăng ký — `POST /api/auth/register`

Rate limit: 10 request/phút.

**Request**
```json
{
  "username": "player123",
  "email": "player@example.com",
  "password": "12345678",
  "password_confirmation": "12345678",
  "client_id": "<uuid>"
}
```
`email` không bắt buộc. `password` tối thiểu 8, tối đa 72 ký tự, phải có `password_confirmation` khớp.

**Response `201`**
```json
{
  "success": true,
  "message": "Registration successful.",
  "user": { "...": "xem mục User Object" },
  "authorization": {
    "token_type": "Bearer",
    "expires_in": 3600,
    "access_token": "...",
    "refresh_token": "..."
  }
}
```

**Lỗi `422`** — validation (username/email trùng, password sai định dạng, client_id không hợp lệ):
```json
{ "message": "...", "errors": { "username": ["..."] } }
```

---

## 2. Đăng nhập — `POST /api/auth/login`

Rate limit: 10 request/phút.

**Request**
```json
{
  "login": "player123",
  "password": "12345678",
  "client_id": "<uuid>"
}
```
`login` = username hoặc email.

**Response `200`** — giống format register (không có `password_confirmation`):
```json
{
  "success": true,
  "message": "Login successful.",
  "user": { "...": "xem mục User Object" },
  "authorization": {
    "token_type": "Bearer",
    "expires_in": 3600,
    "access_token": "...",
    "refresh_token": "..."
  }
}
```

**Lỗi**
- `422` sai `client_id`/Origin hoặc sai login/password:
  ```json
  { "message": "...", "errors": { "login": ["Invalid username, email, or password."] } }
  ```
- `423` tài khoản bị khóa:
  ```json
  { "success": false, "message": "This account is locked.", "locked_reason": "...", "locked_until": "2026-08-01T00:00:00Z" }
  ```
- `403` đăng nhập bằng password bị tắt cho tài khoản này:
  ```json
  { "success": false, "message": "Password login is disabled for this account." }
  ```

---

## 3. Refresh token — `POST /api/auth/refresh`

Rate limit: 30 request/phút. Gọi khi `access_token` hết hạn (`expires_in` giây), không cần user nhập lại mật khẩu.

**Request**
```json
{ "client_id": "<uuid>", "refresh_token": "..." }
```

**Response `200`** — trả thẳng object token, **không bọc** `success`/`user`:
```json
{
  "token_type": "Bearer",
  "expires_in": 3600,
  "access_token": "...",
  "refresh_token": "..."
}
```

**Lỗi `422`**: refresh token sai/hết hạn/đã dùng, hoặc client_id sai.

---

## 4. Quên mật khẩu — `POST /api/auth/forgot-password`

Rate limit: 5 request/phút.

**Request**: `{ "email": "player@example.com" }`

**Response `200`**: `{ "success": true, "message": "We have emailed your password reset link." }`

**Lỗi `422`**: email không tồn tại hoặc gửi quá nhiều lần → `{ "message": "...", "errors": { "email": ["..."] } }`

---

## 5. Đặt lại mật khẩu — `POST /api/auth/reset-password`

Rate limit: 5 request/phút. `token` lấy từ link trong email ở bước 4.

**Request**
```json
{
  "token": "...",
  "email": "player@example.com",
  "password": "newpassword123",
  "password_confirmation": "newpassword123"
}
```

**Response `200`**: `{ "success": true, "message": "Your password has been reset." }`

Sau khi reset thành công, backend **tự thu hồi toàn bộ session cũ** — user phải đăng nhập lại trên mọi thiết bị.

**Lỗi `422`**: token sai/hết hạn.

---

## Các API dưới đây cần đăng nhập

Header bắt buộc: `Authorization: Bearer <access_token>`
Mỗi route còn gắn thêm 1 scope — access token thiếu scope sẽ bị middleware `CheckToken` chặn (`403`). Scope được cấp sẵn đầy đủ khi login/register (`profile:read profile:write sessions:read sessions:revoke`), nên frontend không cần tự xin scope.

## 6. Lấy thông tin user hiện tại — `GET /api/me`

Scope: `profile:read`

**Response `200`**
```json
{ "success": true, "user": { "...": "xem mục User Object" } }
```

---

## 7. Đăng xuất — `POST /api/account/logout`

Không yêu cầu scope riêng, chỉ cần token hợp lệ. Thu hồi `access_token` hiện tại.

**Response `200`**: `{ "success": true, "message": "Logout successful." }`

---

## 8. Đổi mật khẩu — `PUT /api/account/password`

Scope: `profile:write`

**Request**
```json
{ "current_password": "old123", "password": "new12345", "password_confirmation": "new12345" }
```

**Response `200`**
```json
{ "message": "Password updated. Please log in again.", "revoked_sessions": 3 }
```
Đổi mật khẩu xong tất cả session/token khác đều bị thu hồi — frontend phải bắt user đăng nhập lại (kể cả session vừa dùng để đổi mật khẩu).

**Lỗi `422`**: `{ "message": "The current password is incorrect." }`

---

## 9. Danh sách phiên đăng nhập — `GET /api/account/sessions`

Scope: `sessions:read`

**Response `200`**
```json
{
  "success": true,
  "sessions": [
    {
      "id": 1,
      "user_id": 10,
      "user_device_id": 2,
      "session_id": "...",
      "oauth_access_token_id": "...",
      "oauth_client_id": "...",
      "ip_address": "1.2.3.4",
      "user_agent": "Mozilla/5.0 ...",
      "last_activity_at": "2026-07-30T10:00:00Z",
      "expires_at": "2026-07-31T10:00:00Z",
      "is_revoked": false,
      "revoked_at": null,
      "revoked_reason": null,
      "device": {
        "id": 2,
        "device_id": "...",
        "device_name": "iPhone 15",
        "platform": "iOS",
        "browser": "Safari",
        "last_seen_at": "2026-07-30T10:00:00Z"
      }
    }
  ]
}
```
Sắp xếp theo `last_activity_at` mới nhất trước.

---

## 10. Thu hồi 1 phiên — `DELETE /api/account/sessions/{id}`

Scope: `sessions:revoke`. `{id}` là `sessions[].id` ở API #9.

**Response `200`**: `{ "success": true, "message": "Session revoked." }`

**Lỗi `404`**: session không tồn tại hoặc không thuộc user hiện tại.

---

## Google Login (chỉ dùng cho web session, KHÔNG phải API JSON)

Đây là flow redirect trình duyệt, không gọi được bằng `fetch`/`axios`. Chỉ áp dụng cho web app dùng session cookie của chính backend (ví dụ trang login SSO), không dành cho SPA nrocheck.vn gọi trực tiếp.

- `GET /auth/google/redirect` — redirect sang Google.
- `GET /auth/google/callback` — Google gọi lại, backend tạo session, redirect tiếp về `/dashboard` (hoặc `/oauth/authorize` nếu đang trong luồng OAuth2 SSO).

Nếu nrocheck.vn muốn có nút "Đăng nhập bằng Google", phải dùng luồng OAuth2 SSO (`/oauth/authorize` → `/oauth/token`), không dùng route này trực tiếp.

---

## User Object

Trả về ở tất cả API có field `user`. Model `User`, ẩn `password` và `remember_token`, còn lại show đầy đủ:

```json
{
  "id": 10,
  "username": "player123",
  "email": "player@example.com",
  "balance": "0",
  "avatar": null,
  "status": "active",
  "locked_until": null,
  "locked_reason": null,
  "locked_by": null,
  "email_verified_at": null,
  "created_at": "2026-07-01T00:00:00Z",
  "updated_at": "2026-07-01T00:00:00Z"
}
```
`status` có thể là: `active`, `locked`, `banned`, `pending`, `deleted`.

---

## Lưu ý bảo mật khi tích hợp frontend

- Giữ `access_token` trong bộ nhớ (JS variable/state), **không** lưu `localStorage`.
- `refresh_token` nên lưu ở cookie `HttpOnly` qua backend riêng của nrocheck nếu có; tránh lưu lâu dài ở `localStorage`.
- `client_secret` (nếu có) không bao giờ được gửi xuống frontend — chỉ nằm ở backend SSO.
- Khi nhận `401` từ bất kỳ API nào ở mục "cần đăng nhập", gọi `/api/auth/refresh`; nếu refresh cũng lỗi thì bắt user đăng nhập lại.
