# Lịch sử đăng nhập, chặn IP và duyệt thiết bị quản trị

## Cách sử dụng

- **Quản lý người dùng → Chi tiết**: xem đăng nhập thành công/thất bại, IP, kênh, nguồn IP, User-Agent, các IP đã dùng, sự kiện bảo mật và phiên API. Lịch sử đăng nhập có phân trang và lọc IP. Ô tìm người dùng cũng nhận địa chỉ IP đầy đủ đã có trong lịch sử.
- **Quản lý người dùng → Duyệt thiết bị / Chặn IP**: duyệt/thu hồi một trình duyệt tại một IP cụ thể; chặn một IP hoặc CIDR IPv4/IPv6; chọn số giờ hoặc để trống để chặn đến khi mở thủ công.
- Quyền đọc lịch sử: `users.view`. Quyền duyệt và chặn: `access-security.manage`. Migration chỉ cấp thêm quyền mới cho các role `admin`/`super-admin` đang tồn tại, không đồng bộ lại các quyền khác. Role tùy chỉnh cần được cấp quyền thích hợp.
- CIDR được chuẩn hóa, ví dụ `198.51.100.25/24` thành `198.51.100.0/24`. Không tự suy ra dải từ một IP. Từ chối `/0` và dải chứa IP quản trị đang thực hiện thao tác.

## Chính sách đăng nhập đã chọn

Mặc định `ADMIN_ACCESS_APPROVAL_REQUIRED=true`. Áp dụng cho tài khoản có vai trò/quyền quản trị, kể cả cộng tác viên có quyền và ID quản trị SSO được cấu hình. Người mua thông thường không phải xin duyệt thiết bị.

Sau khi xác minh đúng mật khẩu hoặc tài khoản Google đã liên kết, đăng nhập từ **IP mới hoặc cookie trình duyệt mới** tạo yêu cầu chờ duyệt. Chưa tạo phiên đăng nhập quản trị. Không tự đổi tài khoản sang `banned`, tránh việc một lần đăng nhập lạ khóa luôn mọi thiết bị của chủ tài khoản. Sau khi duyệt, đăng nhập lại trên đúng trình duyệt đó.

Cookie ngẫu nhiên được Laravel mã hóa, HttpOnly, host-only, SameSite=Lax; database chỉ lưu hash. Mỗi lần duyệt có hiệu lực 90 ngày. Đổi IP, xóa cookie, dùng trình duyệt khác hoặc cửa sổ riêng tư cần duyệt lại. User-Agent chỉ để đối chiếu, không phải bằng chứng xác định phần cứng và không thay thế MFA.

Mỗi yêu cầu web kiểm tra lại quyền duyệt. Đăng nhập Google và API quản lý OAuth client cũng chịu kiểm tra; một bearer token riêng lẻ không đủ mở API quản trị đó. Luồng API dành cho khách hàng vẫn dùng xác thực/scopes hiện có. Realtime quản trị lưu tham chiếu thiết bị đã duyệt và kiểm tra lại trước khi phát dữ liệu; thu hồi, hết hạn duyệt hoặc chặn IP dừng các bản cập nhật tiếp theo.

## Triển khai backend

Triển khai trong cửa sổ bảo trì hoặc qua cơ chế chuyển release của máy chủ. Sao lưu database trước migration. Không chạy code mới phục vụ HTTP trước khi migration hoàn tất.

1. Cấu hình IP tin cậy ở phần dưới; giữ `APP_ENV=production`, `SESSION_SECURE_COOKIE=true` và HTTPS.
2. Đưa source mới, dependencies hiện hành và **toàn bộ `public/build` vừa build** lên đúng thư mục ứng dụng đang chạy.
3. Chạy trên backend:

```sh
php artisan optimize:clear
php artisan migrate --force
php artisan config:cache
php artisan queue:restart
```

Migration mới: `2026_09_17_210000_create_access_security_tables.php`. Tạo bảng duyệt/chặn, mở rộng `login_attempts.username` lên 191, thêm index tra IP và tham chiếu thiết bị cho `chat_realtime_sessions`. Rollback gỡ bảng/tham chiếu/index mới, giữ độ rộng username và quyền đã cấp để tránh cắt dữ liệu hoặc thay đổi quyền ngoài ý muốn.

4. Bỏ bảo trì theo quy trình máy chủ. Admin đầu tiên nhập đúng thông tin đăng nhập để tạo yêu cầu. Từ SSH/Terminal đáng tin cậy của máy chủ:

```sh
php artisan security:admin-access list
php artisan security:admin-access approve 123
```

`123` là **ID yêu cầu được hiển thị thực tế**, không phải user ID. Đối chiếu user, IP và trình duyệt trước khi duyệt. Không duyệt toàn bộ yêu cầu. Đăng nhập lại từ cùng trình duyệt. Các yêu cầu sau có thể được admin đã có quyền duyệt trong giao diện.

Thu hồi khẩn cấp:

```sh
php artisan security:admin-access revoke 123
```

Lệnh console là đường khôi phục khi mọi admin đều đổi IP/mất cookie. Không có URL bỏ qua xét duyệt. Tài khoản đang bị khóa không được duyệt cho đến khi mở khóa tài khoản theo quy trình hiện có. `ADMIN_ACCESS_APPROVAL_REQUIRED=false` chỉ dành cho việc chủ động tắt chính sách trong cấu hình server; không cần tắt để duyệt thiết bị đầu tiên.

## IP thật qua proxy và frontend

### Truy cập trực tiếp backend qua reverse proxy/CDN

`SECURITY_TRUSTED_PROXIES` nhận danh sách địa chỉ/CIDR proxy tin cậy, ngăn cách bằng dấu phẩy. Chỉ điền proxy thực tế do hạ tầng xác minh; không dùng `*`, không tin mọi máy ngoài Internet. Proxy phải ghi đúng chuỗi `X-Forwarded-For` và giao thức gốc. Nếu không cấu hình, backend dùng IP kết nối trực tiếp; header do khách tự gửi không được tin.

Ví dụ biến cấu hình (thay placeholder bằng giá trị hạ tầng thực tế):

```dotenv
ADMIN_ACCESS_APPROVAL_REQUIRED=true
SESSION_SECURE_COOKIE=true
SECURITY_TRUSTED_PROXIES="<proxy-IP-or-CIDR>,<another-trusted-proxy>"
```

### Đăng nhập qua BFF của ba storefront Next.js

BFF là máy chủ trung gian, vì vậy không thể suy ra IP người mua từ IP kết nối backend. Đã bổ sung chuyển IP/User-Agent có chữ ký cho login/register/Google/Facebook và refresh ở cả `123nick`, `shophhp`, `vanghhp`.

- Backend và cả ba frontend: đặt cùng `SECURITY_PROXY_SECRET` là chuỗi ngẫu nhiên mạnh ít nhất 32 ký tự. Đây là biến **server**, tuyệt đối không dùng tiền tố `NEXT_PUBLIC_`.
- Mỗi frontend: đặt `SECURITY_CLIENT_IP_HEADER` thành tên header chứa **một IP duy nhất**, được ingress tin cậy ghi đè. Không chọn một header chỉ vì trình duyệt có thể gửi nó; ingress phải xóa/ghi đè giá trị từ client và chặn đường truy cập trực tiếp bỏ qua ingress.
- Chữ ký ràng buộc method, path, nội dung request, thời gian, IP và User-Agent; backend chỉ chấp nhận trong cửa sổ 60 giây. Đồng bộ đồng hồ các máy chủ.
- Nếu chưa đặt secret: giữ cách hoạt động cũ, không tin IP forward tự khai. Lịch sử có thể tiếp tục ghi IP BFF. Nếu đã đặt secret nhưng thiếu header hợp lệ, BFF trả 503 để tránh ghi nhận sai IP.
- Rebuild/redeploy hoặc restart Next.js theo cơ chế hosting sau khi cấu hình. Đối chiếu một lần đăng nhập của chính mình: nguồn `signed_storefront` và IP đúng. Header giả hoặc chữ ký sai phải không thay đổi IP backend ghi nhận.

Nguồn IP hiển thị: `peer` (kết nối trực tiếp), `trusted_proxy`, `signed_storefront`. Các bản ghi cũ không tự đổi thành IP thật và không đủ căn cứ chặn cả dải IP máy chủ trung gian.

## Phạm vi chặn và kiểm chứng

### Cache danh sách IP — bổ sung 19/09/2026

`AccessIpBlockCache` dùng cache store mặc định của Laravel, hoặc `ACCESS_IP_BLOCK_CACHE_STORE` nếu khai báo riêng. Với Redis production có thể đặt:

```dotenv
ACCESS_IP_BLOCK_CACHE_STORE=redis
ACCESS_IP_BLOCK_CACHE_TTL=60
```

Không đổi `CACHE_STORE`, Redis DB/prefix hoặc cache nghiệp vụ khác chỉ để bật cache IP. Sau khi đưa source mới lên, chạy `php artisan config:cache`, `php artisan queue:restart` và `php artisan security:ip-block-cache:clear`. Bản bổ sung cache này không cần migration mới hoặc build frontend; bản chức năng bảo mật ban đầu vẫn cần migration/asset như phần trên.

- Namespace riêng: `access-security:ip-blocks:v1:{DB-scope}:generation`, `:rules`, `:build`; Laravel tiếp tục gắn `CACHE_PREFIX`/`REDIS_PREFIX` hiện có. DB-scope là hash từ tên connection, driver, host, port và tên database. Các node cùng ứng dụng phải dùng cấu hình DB/store/prefix nhất quán. Các ứng dụng độc lập vẫn nên có `CACHE_PREFIX` riêng cho toàn bộ cache hiện có.
- Chỉ cache `network` và `expires_at`, kể cả danh sách rỗng. Một lần kiểm tra khi cache nóng đọc cặp generation/snapshot qua `many` (MGET với Redis đơn), không query bảng `access_ip_blocks`. Không tạo key theo từng IP khách; số key của cơ chế có giới hạn. Vẫn so khớp CIDR trong PHP, chi phí tăng theo số dải đang chặn.
- Cả middleware HTTP và kiểm tra realtime dùng chung `AccessIpBlock::blocks`, nên cùng đọc cache này. Trang quản lý/lịch sử vẫn đọc DB có phân trang; không cache quyền duyệt thiết bị, số dư hoặc lịch sử người dùng.
- Tạo/sửa/mở chặn/xóa qua Eloquent đều tự đổi generation sau commit. Admin ghi dữ liệu và audit trong cùng transaction; rollback không làm mất hiệu lực cache cũ. Request đang dựng snapshot cũ không thể làm snapshot đó hợp lệ lại sau đổi generation.
- Mỗi lần kiểm tra vẫn so `expires_at` với thời gian hiện tại. Hết giờ chặn không phải đợi TTL cache. Snapshot TTL mặc định 60 giây; build lock 10 giây lấy không chờ, bận thì đọc DB thay vì giữ request trong hàng đợi.
- Không cache kết quả đọc trong transaction, tránh đưa dữ liệu chưa commit hoặc snapshot cũ của transaction vào Redis. Cache lạnh/fallback đọc DB primary để không nạp lại dữ liệu replica chậm.
- Redis đọc lỗi: kiểm tra trực tiếp DB, không coi danh sách chặn là rỗng. Nếu DB cũng lỗi thì request báo lỗi, không tự cho qua.
- Redis invalidation lỗi sau commit: API trả 503 và nói rõ dữ liệu đã lưu nhưng cache chưa đồng bộ; audit đã được giữ. Khi Redis phục hồi, snapshot cũ có thể còn đến hết TTL, vì vậy chạy lệnh xóa riêng ở dưới trước khi coi thao tác đã đồng bộ.

```sh
php artisan security:ip-block-cache:clear
```

Lệnh chỉ tác động generation/snapshot IP block, không gọi `Cache::flush`, `FLUSHDB`, quét wildcard, xóa cache public, realtime, thống kê, CORS, queue hoặc session. Cần chạy sau khi sửa `access_ip_blocks` bằng phpMyAdmin/SQL trực tiếp vì các thao tác đó không phát model events; nếu không chạy, snapshot cũ tồn tại tối đa TTL cấu hình. Các đường ghi hiện tại trong ứng dụng đã đối chiếu đều đi qua model events.

Đã kiểm thử cache nóng/lạnh/rỗng, hết hạn, CRUD, transaction/rollback, producer cũ, lock bận, mất generation, Redis lỗi, phục hồi và cách ly namespace. Có kiểm tra Redis local riêng bằng Predis, không dùng Redis production và không thêm dependency vào dự án. Khi triển khai cần xác nhận driver Redis của PHP và cache store production thực sự hoạt động; fallback DB giúp giữ kiểm tra bảo mật nhưng không có lợi ích giảm SQL khi Redis hỏng.

Chặn tại Laravel áp dụng cho HTTP web/API người dùng, kể cả các request dùng phiên/token đã có. Ngoại lệ là logout, health check, webhook thanh toán và worker/app nội bộ vốn dùng khóa/chữ ký riêng. Không coi IP ban là thay thế cho xác thực webhook/worker. HTML/static cache ở CDN hoặc Next.js có thể vẫn tải được; cần quy tắc ở tầng CDN/ingress nếu muốn chặn cả các tài nguyên đó. Ban IP không phải ban tài khoản: chuyển sang mạng khác vẫn phải qua các quy tắc tài khoản và xác thực thông thường.

Trước khi đưa vào sử dụng: kiểm tra IP thật, tạo/duyệt yêu cầu đầu tiên, thử IP/trình duyệt thứ hai vẫn bị chặn, thu hồi một thiết bị thử nghiệm, thử CIDR không chứa IP quản trị và mở lại. Kiểm tra login, chat, upload ảnh hợp lệ và webhook/worker hoạt động sau deploy. Những bước này chưa được chạy trên production trong lần sửa source này.

Ảnh chat/avatar mới phải giải mã được bằng PHP GD, tối đa 20 triệu điểm ảnh và trong ngân sách bộ nhớ. File PNG chỉ có header như mẫu 33 byte bị từ chối. Máy chủ cần extension GD hỗ trợ các định dạng ảnh đang cho phép. Quy tắc không tự quét/xóa file cũ; chưa thực hiện xóa thư mục chat.

Các test `AccessSecurityTest` bật chính sách duyệt thực sự, kiểm tra đăng nhập/Google/API admin, proxy giả mạo, cookie, CIDR, lịch sử, upload và realtime. `FinancialTransitionSecurityTest` kiểm tra dữ liệu thay đổi giữa hai lần đọc cho mua vàng/ngọc và duyệt rút tiền. Bộ test nền đặt chính sách duyệt tắt để giữ fixtures cũ độc lập; điều này không thay đổi mặc định production. SQLite và mô phỏng xen kẽ không chứng minh khóa đồng thời trên MySQL hoặc hành vi production.
