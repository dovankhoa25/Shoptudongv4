# Profile API

API dành cho trang hồ sơ người dùng. Tất cả endpoint bên dưới có tiền tố `/api`, yêu cầu OAuth2 Bearer token và chỉ đọc dữ liệu thuộc về chính user đang đăng nhập.

```http
Authorization: Bearer <access_token>
Accept: application/json
Content-Type: application/json
```

Quyền token:

- `profile:read`: xem hồ sơ và dữ liệu liên quan.
- `profile:write`: sửa hồ sơ, đổi mật khẩu.
- `sessions:read`: xem phiên đăng nhập.
- `sessions:revoke`: thu hồi phiên đăng nhập.

## Danh sách API

| Method | URL | Chức năng | Scope |
|---|---|---|---|
| GET | `/api/me` | Lấy hồ sơ, tương thích API cũ | `profile:read` |
| GET | `/api/profile` | Lấy hồ sơ | `profile:read` |
| PATCH | `/api/profile` | Cập nhật username, email, avatar | `profile:read`, `profile:write` |
| GET | `/api/profile/auth-providers` | Tài khoản/phương thức đăng nhập đã liên kết | `profile:read` |
| GET | `/api/profile/devices` | Thiết bị của user | `profile:read` |
| GET | `/api/profile/security-logs` | Nhật ký bảo mật | `profile:read` |
| GET | `/api/profile/login-attempts` | Lịch sử đăng nhập | `profile:read` |
| GET | `/api/profile/punishments` | Cảnh cáo/khóa/phạt | `profile:read` |
| GET | `/api/profile/nro-accounts` | Tài khoản NRO đã liên kết | `profile:read` |
| GET | `/api/profile/gold-wallets` | Ví vàng theo server | `profile:read` |
| GET | `/api/profile/gold-transactions` | Lịch sử mua/nhập vàng | `profile:read` |
| GET | `/api/profile/wallet-transactions` | Biến động số dư ví vàng | `profile:read` |
| GET | `/api/profile/wallet-transfers` | Lịch sử chuyển đổi ví | `profile:read` |
| GET | `/api/profile/lucky-number-bets` | Lịch sử cược số may mắn | `profile:read` |
| PUT | `/api/account/password` | Đổi mật khẩu | `profile:write` |
| GET | `/api/account/sessions` | Danh sách phiên đăng nhập | `sessions:read` |
| DELETE | `/api/account/sessions/{id}` | Thu hồi một phiên | `sessions:revoke` |
| POST | `/api/account/logout` | Đăng xuất phiên hiện tại | token hợp lệ |

Các API lịch sử hỗ trợ `?per_page=15`, nhỏ nhất `1`, lớn nhất `100`. Response phân trang có `data`, `links`, `meta` theo chuẩn Laravel Resource.

## GET hồ sơ

`GET /api/profile` trả:

```json
{
  "data": {
    "id": 12,
    "username": "nrocheck",
    "email": "user@example.com",
    "avatar": "https://cdn.example.com/avatar.png",
    "balance": "150000",
    "status": "active",
    "is_locked": false,
    "locked_until": null,
    "locked_reason": null,
    "email_verified": true,
    "email_verified_at": "2026-08-05T08:00:00.000000Z",
    "roles": ["user"],
    "created_at": "2026-08-01T08:00:00.000000Z",
    "updated_at": "2026-08-05T08:00:00.000000Z"
  }
}
```

`GET /api/me` giữ envelope cũ để không làm hỏng client đang chạy: `{ "success": true, "user": { ...các field hồ sơ như trên... } }`. Dữ liệu `user` vẫn được lọc bằng `ProfileResource`.

## PATCH cập nhật hồ sơ

`PATCH /api/profile`

Tất cả field đều không bắt buộc, nhưng request phải có ít nhất field muốn đổi:

```json
{
  "username": "ten_moi",
  "email": "new@example.com",
  "avatar": "https://cdn.example.com/new-avatar.png"
}
```

Quy tắc: `username` 3-191 ký tự và không trùng; `email` đúng định dạng và không trùng; `avatar` là URL tối đa 2048 ký tự hoặc `null`. Khi đổi email, trạng thái xác thực email được đưa về chưa xác thực. Thông tin provider mật khẩu được đồng bộ theo username/email mới.

Response `200`:

```json
{
  "message": "Cập nhật hồ sơ thành công.",
  "data": { "id": 12, "username": "ten_moi", "email": "new@example.com" }
}
```

Sai validation trả `422` với object `errors` theo chuẩn Laravel.

## Dữ liệu bảo mật và tài khoản liên kết

### Auth providers

`GET /api/profile/auth-providers`

```json
{
  "data": [{
    "id": 3,
    "provider": "google",
    "email": "user@gmail.com",
    "username": "User Name",
    "avatar": "https://example.com/avatar.jpg",
    "is_enabled": true,
    "last_login_at": "2026-08-05T08:00:00.000000Z",
    "linked_at": "2026-08-01T08:00:00.000000Z"
  }]
}
```

Không trả `provider_id` và `raw_data`.

### Devices

`GET /api/profile/devices` trả: `id`, `device_name`, `platform`, `browser`, `ip_address`, `is_trusted`, `trusted_until`, `last_seen_at`, `created_at`. Không trả fingerprint `device_id` và user-agent thô.

### Sessions

`GET /api/account/sessions` trả `success` và `data`; mỗi phần tử gồm `id`, resource `device`, `ip_address`, `user_agent`, `last_activity_at`, `expires_at`, `is_revoked`, `revoked_at`, `revoked_reason`, `created_at`. Không trả access-token ID, OAuth client ID hoặc session key.

`DELETE /api/account/sessions/25` trả:

```json
{ "success": true, "message": "Session revoked." }
```

### Security logs

`GET /api/profile/security-logs?per_page=15` trả: `id`, `event`, `ip_address`, `user_agent`, `meta`, `created_at`.

### Login attempts

`GET /api/profile/login-attempts?per_page=15` trả: `id`, `provider`, `ip_address`, `user_agent`, `is_success`, `failure_reason`, `created_at`. Không trả lại username/email đã nhập và metadata nội bộ.

### Punishments

`GET /api/profile/punishments?per_page=15` trả: `id`, `type`, `reason`, `note`, `starts_at`, `ends_at`, `is_active`, `revoked_at`, `revoked_reason`, `created_at`. Không trả ID admin xử lý.

## Dữ liệu NRO và tài chính

### NRO accounts

`GET /api/profile/nro-accounts?per_page=15` trả: `id`, `account_name`, `server`, `character_name`, `status`, `locked_reason`, `locked_until`, `created_at`, `updated_at`.

### Gold wallets

`GET /api/profile/gold-wallets` trả: `id`, `server_id`, `balance`, `locked_balance`, `available_balance`, `status`, `created_at`, `updated_at`.

### Gold transactions

`GET /api/profile/gold-transactions?per_page=15` trả: `id`, `type`, `server_id`, `character_name`, `amount_vnd`, `gold_qty`, `gold_bar_qty`, `pure_gold_qty`, `price_at_transaction`, `status`, `last_synced_at`, `created_at`. Không trả bot xử lý hoặc nguồn cập nhật nội bộ.

### Wallet transactions

`GET /api/profile/wallet-transactions?per_page=15` trả: `id`, `wallet_id`, `type`, `amount`, `balance_before`, `balance_after`, `locked_before`, `locked_after`, `reference_type`, `reference_id`, `description`, `metadata`, `created_at`. Không trả idempotency key hoặc nhân viên tạo.

### Wallet transfers

`GET /api/profile/wallet-transfers?per_page=15` trả: `id`, `transfer_code`, `from_wallet_id`, `to_wallet_id`, `amount_from`, `amount_to`, `fee_amount`, `from_gold_price`, `to_gold_price`, `exchange_rate`, `status`, `failure_reason`, `completed_at`, `cancelled_at`, `created_at`. Không trả idempotency key và ID giao dịch nội bộ.

### Lucky number bets

`GET /api/profile/lucky-number-bets?per_page=15` trả: `id`, `bet_code`, `wallet_id`, `round_id`, `market_id`, `option_id`, `selection_value`, `amount`, `payout_multiplier`, `potential_payout`, `actual_payout`, `status`, `placed_at`, `settled_at`. Không trả idempotency key hoặc ID transaction ví.

## Đổi mật khẩu và đăng xuất

`PUT /api/account/password` body:

```json
{
  "current_password": "mat-khau-hien-tai",
  "password": "mat-khau-moi",
  "password_confirmation": "mat-khau-moi"
}
```

Mật khẩu mới 8-72 ký tự. Thành công sẽ thu hồi toàn bộ session và yêu cầu đăng nhập lại.

`POST /api/account/logout` không cần body, thu hồi phiên/token hiện tại.

## Mã lỗi chung

- `401`: thiếu token, token sai hoặc hết hạn.
- `403`: token thiếu scope yêu cầu.
- `404`: resource không thuộc user hoặc không tồn tại.
- `422`: dữ liệu request không hợp lệ.
- `429`: vượt giới hạn request.

Mọi truy vấn profile lấy user từ Bearer token và bắt đầu từ quan hệ của user, không nhận `user_id` từ client. Vì vậy client không thể đổi `user_id` để đọc dữ liệu của tài khoản khác.
