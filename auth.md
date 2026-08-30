Phân Chia Đúng
Web session SSO
Nằm trong web.php và Controllers/Auth.

GET  /login
POST /login
GET  /register
POST /register
GET  /auth/google/redirect
GET  /auth/google/callback
Dùng khi website khác redirect user sang trang SSO. Login Google cũng tạo session tại đây rồi quay lại /oauth/authorize đang chờ.

OAuth2 SSO
Passport tự đăng ký, không viết thủ công trong web.php:

GET  /oauth/authorize
POST /oauth/token
GET  /oauth/device
POST /oauth/device/code
Đây là flow nút Đăng nhập bằng Dovankhoa.

API login trực tiếp
Nằm trong api.php.

POST /api/auth/register
POST /api/auth/login
POST /api/auth/refresh
POST /api/auth/forgot-password
POST /api/auth/reset-password
Dành cho frontend bạn sở hữu như nrocheck.vn. Không redirect. Login và register trả:

{
  "authorization": {
    "token_type": "Bearer",
    "expires_in": 3600,
    "access_token": "...",
    "refresh_token": "..."
  }
}
API sau đăng nhập
Đã chuyển sang prefix rõ ràng:

GET    /api/me
POST   /api/account/logout
PUT    /api/account/password
GET    /api/account/sessions
DELETE /api/account/sessions/{id}
Quản trị OAuth client
Đã chuyển sang:

/api/admin/oauth-clients/*
Chỉ user ID trong SSO_ADMIN_USER_IDS và có scope quản trị mới gọi được.

Bảo Mật Login Trực Tiếp
Đã thêm migration add_first_party_policy:

is_first_party
allows_direct_login
allowed_origins
Frontend nrocheck.vn gửi:

POST https://sso.dovankhoa.vn/api/auth/login
Origin: https://nrocheck.vn

{
  "client_id": "<uuid-client-nrocheck>",
  "login": "player123",
  "password": "password"
}
Backend chỉ chấp nhận nếu origin và client policy khớp.

Cấu Hình Cần Chạy
php artisan migrate
php artisan passport:client --password --name="SSO First Party API"
Sau đó cập nhật .env:

SSO_PASSWORD_CLIENT_ID=<id-vừa-tạo>
SSO_PASSWORD_CLIENT_SECRET=<secret-vừa-tạo>
SSO_ADMIN_USER_IDS=1
CORS_ALLOWED_ORIGINS=https://nrocheck.vn
Secret này chỉ nằm trong backend SSO, tuyệt đối không gửi xuống frontend.

Google
Logic liên kết user Google đã gom vào GoogleAuthService.php. Callback web chỉ xử lý session và redirect.

Tài Liệu
Flow frontend đầy đủ nằm tại sso-api.md.

Kiểm Tra
PHP lint: pass.
Route list: pass.
TypeScript type-check: pass.
Vite build bị ACL workspace chặn ghi file tạm.
Feature test bị chặn vì PHP CLI thiếu SQLite driver.

sso-api.md
Document · MD
Open in




3:09 AM
các luồng thực tế đang được dựng và fontend bắt như nào giải thích lại 1 lần
3:11 AM

Tổng Quan
Chỉ có một backend trung tâm:

https://sso.dovankhoa.vn
Backend này quản lý:

User và password.
Login Google.
Access token và refresh token.
OAuth client của từng website hoặc app.
Session đăng nhập trên trang SSO.
Session API, log bảo mật và thu hồi token.
Frontend có thể chọn một trong hai cách đăng nhập.

Luồng 1: Login Trực Tiếp
Dành cho website do bạn sở hữu, ví dụ nrocheck.vn.

User nhập username/password ngay tại trang Nrocheck. Không chuyển trang sang SSO.

Bước 1: Đăng nhập
Frontend gọi:

POST https://sso.dovankhoa.vn/api/auth/login
Origin: https://nrocheck.vn
Content-Type: application/json

{
  "client_id": "<uuid-client-nrocheck>",
  "login": "player123",
  "password": "12345678"
}
login có thể là username hoặc email.

Backend kiểm tra:

Client tồn tại.
is_first_party = true.
allows_direct_login = true.
Origin https://nrocheck.vn nằm trong allowed_origins.
Username và password đúng.
User không bị khóa.
Login bằng password không bị vô hiệu hóa.
Response
{
  "success": true,
  "message": "Login successful.",
  "user": {
    "id": 1,
    "username": "player123",
    "email": "player@example.com"
  },
  "authorization": {
    "token_type": "Bearer",
    "expires_in": 3600,
    "access_token": "...",
    "refresh_token": "..."
  }
}
Frontend giữ:

access_token
refresh_token
expires_in
Nên giữ access token trong memory. Refresh token nên nằm trong cookie HttpOnly thông qua backend của Nrocheck nếu có. Không nên lưu refresh token lâu dài trong localStorage.

Luồng 2: Đăng Ký Trực Tiếp
Frontend Nrocheck gọi:

POST https://sso.dovankhoa.vn/api/auth/register
Origin: https://nrocheck.vn
Content-Type: application/json

{
  "client_id": "<uuid-client-nrocheck>",
  "username": "player123",
  "email": "player@example.com",
  "password": "12345678",
  "password_confirmation": "12345678"
}
Backend:

Kiểm tra chính sách client và origin.
Tạo user.
Tạo provider password.
Ghi security log.
Cấp access token và refresh token.
Response có cùng format với login trực tiếp.

Luồng 3: Refresh Token
Khi access token hết hạn, frontend không yêu cầu user nhập lại mật khẩu.

POST https://sso.dovankhoa.vn/api/auth/refresh
Origin: https://nrocheck.vn
Content-Type: application/json

{
  "client_id": "<uuid-client-nrocheck>",
  "refresh_token": "<refresh-token-hiện-tại>"
}
Response:

{
  "token_type": "Bearer",
  "expires_in": 3600,
  "access_token": "<access-token-mới>",
  "refresh_token": "<refresh-token-mới>"
}
Passport rotate refresh token. Frontend phải thay token cũ bằng token mới.

Luồng 4: Gọi API Sau Khi Login
Frontend gửi access token:

GET https://sso.dovankhoa.vn/api/me
Authorization: Bearer <access-token>
Các API hiện có:

Method	Endpoint	Công dụng
GET	/api/me	Lấy user hiện tại
POST	/api/account/logout	Đăng xuất token hiện tại
PUT	/api/account/password	Đổi mật khẩu
GET	/api/account/sessions	Danh sách phiên đăng nhập
DELETE	/api/account/sessions/{id}	Thu hồi một phiên
Đổi hoặc reset mật khẩu sẽ thu hồi toàn bộ token cũ.

Luồng 5: Login Bằng Dovankhoa SSO
Nrocheck có thể hiển thị thêm nút:

Đăng nhập bằng Dovankhoa
Luồng này dùng khi bạn muốn tận dụng session SSO chung giữa nhiều website.

Bước 1: Tạo PKCE
Frontend tạo:

code_verifier
code_challenge = SHA256(code_verifier)
state = chuỗi ngẫu nhiên
Frontend lưu tạm code_verifier và state, ví dụ trong sessionStorage.

Bước 2: Redirect
Frontend chuyển user tới:

https://sso.dovankhoa.vn/oauth/authorize
?client_id=<uuid-client-nrocheck>
&redirect_uri=https://nrocheck.vn/auth/callback
&response_type=code
&scope=profile:read
&state=<random>
&code_challenge=<challenge>
&code_challenge_method=S256
Bước 3: Session web tại SSO
Nếu user chưa đăng nhập tại SSO:

/oauth/authorize
→ /login
→ user nhập username/password
→ quay lại /oauth/authorize
Hoặc user chọn Google:

/login
→ /auth/google/redirect
→ Google
→ /auth/google/callback
→ quay lại /oauth/authorize
Nếu user đã có session SSO, bước nhập password được bỏ qua.

Bước 4: Approve
SSO hiển thị quyền app yêu cầu:

nrocheck.vn muốn đọc profile của bạn
User chọn approve hoặc deny.

Bước 5: Callback
SSO chuyển browser về:

https://nrocheck.vn/auth/callback
?code=<authorization-code>
&state=<random>
Frontend kiểm tra state có trùng giá trị ban đầu.

Bước 6: Đổi Code Lấy Token
Frontend SPA gọi:

POST https://sso.dovankhoa.vn/oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=authorization_code
client_id=<uuid-client-nrocheck>
redirect_uri=https://nrocheck.vn/auth/callback
code=<authorization-code>
code_verifier=<code-verifier>
Response:

{
  "token_type": "Bearer",
  "expires_in": 3600,
  "access_token": "...",
  "refresh_token": "..."
}
Token này dùng giống token của login trực tiếp.

Luồng 6: Login Google
Google chỉ nằm trong trang web session của SSO:

sso.dovankhoa.vn/login
→ Continue with Google
→ Google
→ callback về sso.dovankhoa.vn
Google không phải API login trực tiếp từ nrocheck.vn.

Backend sẽ:

Tìm liên kết Google hiện có.
Hoặc liên kết theo email.
Hoặc tạo user mới.
Lưu vào user_auth_providers.
Tạo session web SSO.
Quay lại OAuth flow đang chờ.
Luồng 7: Quên Mật Khẩu
Frontend gọi:

POST https://sso.dovankhoa.vn/api/auth/forgot-password

{
  "email": "player@example.com"
}
Sau khi user nhận token qua email:

POST https://sso.dovankhoa.vn/api/auth/reset-password

{
  "email": "player@example.com",
  "token": "<token-trong-email>",
  "password": "new-password",
  "password_confirmation": "new-password"
}
Reset password thành công sẽ thu hồi toàn bộ session API cũ.

Frontend Nrocheck Cần Làm Gì?
Với form login trực tiếp
Gọi /api/auth/login.
Lưu token pair an toàn.
Gọi /api/me để lấy profile khi cần.
Khi nhận 401, gọi /api/auth/refresh.
Ghi đè token pair mới.
Nếu refresh thất bại, đưa user về màn hình login.
Với nút SSO
Sinh PKCE và state.
Redirect /oauth/authorize.
Tại callback, kiểm tra state.
Đổi code tại /oauth/token.
Lưu token pair giống luồng login trực tiếp.
Cấu Hình Client Nrocheck
OAuth client dành cho Nrocheck cần:

{
  "name": "nrocheck.vn",
  "redirect": "https://nrocheck.vn/auth/callback",
  "confidential": false,
  "device_flow": false,
  "is_first_party": true,
  "allows_direct_login": true,
  "allowed_origins": [
    "https://nrocheck.vn"
  ]
}
Như vậy Nrocheck dùng được cả:

Form username/password trực tiếp.
Nút đăng nhập qua trang SSO.
Website bên thứ ba chỉ bật OAuth redirect, không bật allows_direct_login.