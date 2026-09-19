# Bản đồ cache và triển khai — 15/09/2026

## Phạm vi đã đối chiếu

Đối chiếu nơi đọc cache với đường ghi dữ liệu trong routes, controllers, services, models, observers và commands của Laravel; kiểm tra fetch/revalidate/RTK Query của ba frontend v4. Đây là kiểm tra source và kiểm thử local, chưa phải số đo tải production.

## Laravel: cache nào bị xóa lúc nào?

`ApiCache` lưu payload kèm phiên bản nhóm (generation). Xóa nhóm đổi generation; entry cũ có thể còn trong store nhưng không được dùng nữa. Entry có TTL, không có tác vụ làm nóng toàn bộ cache. Khi đang có transaction, đổi generation **sau commit ngoài cùng**; rollback hủy việc đổi. Một producer đọc phiên bản cũ không thể làm dữ liệu đó hợp lệ trở lại sau invalidation.

`rememberJson` dựng xong Resource, dữ liệu phân trang và Resource lồng nhau trước khi ghi cache. Key có phiên bản mới nên không đọc nhầm cache Resource cũ. Không dùng helper này cho dữ liệu/đối tượng nội bộ cần giữ kiểu.

| Nhóm / nơi đọc | TTL | Khi invalidation |
| --- | --- | --- |
| `public:catalog`: game types + danh mục | 300 giây | GameType/Category tạo, sửa, xóa; ảnh Category đổi |
| `public:catalog`: dịch vụ theo slug | 120 giây | CategoryTemplate/Service/Field CRUD; gán/bỏ gán category-service và service-field; xóa template hàng loạt |
| `public:nick`: danh sách nick/spin/random | 90 giây | Nick/Spin/SpinReward/RandomBox/RandomNick CRUD; mua/hoàn/xóa/bulk update; Category/Attribute/AttributeOption đổi; gán/bỏ gán thuộc tính; đổi thứ tự spin |
| `public:nick`: chi tiết nick / random | 120 / 60 giây | Như trên; ảnh nick/spin/reward/random box đổi; snapshot NRO tạo/sửa/xóa |
| `public:servers`: bảng server có giá | 180 giây | Server, GoldPrice, GemPrice CRUD; bulk giá có invalidation riêng |
| `public:server-prices`: popup giá + ngọc khả dụng | 120 giây | Server/GoldPrice/GemPrice CRUD; GemBot thay tồn kho, trạng thái, server hoặc bị xóa |
| `public:bots` | 180 giây | Bot tạo/xóa; tên, loại, server, map, khu, trạng thái đổi từ admin hoặc AppAuto/V2; bulk delete có invalidation riêng |
| `public:gembot` | 120 giây | GemBot tạo/xóa; thông tin public, coordinates hoặc tồn kho đổi từ admin/AppAuto/V2 |
| `public:card-types` | 600 giây | CardType CRUD |
| `public:nro-shop:listings` | 60 giây | Luồng listing/tồn kho/đơn hàng qua NroShopService, NroOrderRefund, PublishNroChanges; snapshot đổi; chính sách bán hoặc ẩn kho đổi |
| `public:nro-metadata`: server / bộ lọc | 60 / 900 giây | Server đổi; bộ lọc có key chứa hash overrides + mtime catalog; chỉ đọc file catalog khi thực sự cần dựng bộ lọc |
| `admin:analytics` | 120 giây | `stats:compute` hoàn tất; tên Category hoặc username/email User đổi; key riêng theo ngày + toàn hệ thống hoặc seller ID |
| `frontend-clients:allowed-origins:v1` | 300 giây | FrontendClientController tạo/sửa/bật/tắt client gọi registry forget |
| `internal:settings` | 3600 giây | Setting::set đổi generation sau commit và xóa key `settings` cũ; chống producer cũ ghi lại giá trị tồn tại vô hạn |
| `admin-live:state:{viewId}` | 3600 giây | Snapshot/refresh thay state; hết quyền/credential thì xóa view và state; view hết hạn bị dọn; mỗi lượt flush mới đọc lại dữ liệu |
| `access-security:ip-blocks:v1:{DB-scope}:rules` | 60 giây mặc định | AccessIpBlock saved/deleted đổi generation và xóa snapshot sau commit ngoài cùng; rollback giữ cache cũ; thời hạn từng lệnh chặn vẫn được xét ở mọi lần đọc |
| Các khóa lease, chống gửi lặp, maintenance | Tùy chức năng | Giữ quy tắc hiện có: ví dụ maintenance 15 giây, balance revision 3600 giây; không dùng làm cache dữ liệu public |

### Những điểm cần giữ khi viết thêm source

- Eloquent model events không chạy cho `DB::table(...)->update/delete`, Eloquent bulk SQL và pivot sync/attach/detach. Các đường ghi loại này đã rà phải gọi `ApiCache::clearGroup(s)` ở nơi ghi. Observer mới không thay thế yêu cầu này cho code viết sau.
- Riêng IP block: bulk SQL phải gọi `app(AccessIpBlockCache::class)->invalidate()` trong cùng luồng ghi (service chờ sau commit), hoặc chạy `php artisan security:ip-block-cache:clear` sau SQL thủ công. Không dùng `ApiCache::clearGroup` cho namespace này.
- PublicCacheObserver dùng chung cho admin, tool và command; update chỉ số vàng/credential của Bot không làm hết hạn card public. Public API chỉ select cột cần hiển thị, không lưu credential của bot vào payload cache.
- Bảng giá dùng eager load giá hiện hành + SQL SUM tồn kho. Không gọi query trong từng ServerInfoResource.
- Cache JSON chi tiết ảnh chỉ chứa URL. Trình duyệt/CDN lấy file ảnh từ URL riêng; Laravel không truy vấn bảng media cho mỗi lần tải byte ảnh.
- `attribute_cache_json` trên nick, URL ảnh bìa, snapshot NRO và bảng seller_category_stats là **dữ liệu đã lưu trong DB**, không phải các entry cache có TTL. Xóa Redis/file cache không tính lại những dữ liệu này. Nhãn thuộc tính lưu trên nick được dựng lại khi lưu nick/NRO attribute sync; đổi tên định nghĩa thuộc tính chỉ làm mới bộ lọc, không tự viết lại nhãn đã lưu trên tất cả nick cũ.
- `stats:compute` hiện tính lại dữ liệu mỗi 30 phút. Cache analytics 120 giây không biến báo cáo đó thành báo cáo realtime. Phần xóa/chèn thống kê giờ nằm trong cùng transaction.
- Thống kê đơn vàng/nhập/ngọc/rút tiền vẫn đọc DB mỗi lần, với một query tổng hợp và giữ bộ lọc/phạm vi quyền. Không cache số dư, quyết định mua/hoàn tiền, reservation hay credential.
- Backend không tự xóa cache ảnh/CDN Cloudflare hoặc dữ liệu RTK Query trên mọi trình duyệt đang mở.

## Next.js: nối invalidation với backend

Trước đây frontend có TTL độc lập và lệnh purchase gọi `/api/revalidate` không tồn tại. Đã bỏ lệnh đó; invalidation RTK Query của trình duyệt vẫn giữ.

Backend có command `frontend-cache:sync`, được scheduler chạy mỗi phút. Command so generation đã commit với lần gửi thành công cho từng frontend, gửi **chỉ nhóm thay đổi**, ký HMAC-SHA256. Không gửi HTTP trong request mua hàng hoặc heartbeat. Gửi lỗi không đánh dấu thành công và sẽ thử lại ở lượt sau. Nếu dữ liệu đổi trong lúc gửi, lượt sau vẫn phát hiện.

| Nhóm webhook | Tags Next.js hết hạn |
| --- | --- |
| catalog | game-types, categories, services, sitemap |
| nick | nick-detail, categories, sitemap |
| prices | server-prices và tag cũ Server-prices |

Các fetch chi tiết nick/server đã được gắn thêm tag chung; sitemap đã có tag. Webhook cả ba frontend kiểm tra chữ ký đúng độ dài, timestamp nguyên trong 5 phút, loại/nhóm hợp lệ và giới hạn body 8 KiB. Dùng `revalidateTag(tag, { expire: 0 })`: lần đọc kế tiếp dựng dữ liệu mới. [Tài liệu Next.js](https://nextjs.org/docs/app/api-reference/functions/revalidateTag).

Độ trễ đồng bộ dự kiến tối đa khoảng một chu kỳ scheduler cộng thời gian HTTP khi mọi dịch vụ hoạt động. Nếu chưa cấu hình, scheduler dừng hoặc webhook lỗi, frontend vẫn dựa vào TTL cũ (nick/danh mục 120 giây; dịch vụ 300; game-types 600; sitemap 3600; giá tùy fetch 60–600). RTK/client router có thể giữ dữ liệu đến khi refetch/refresh.

### Cấu hình khi deploy

Backend:
```dotenv
FRONTEND_CACHE_ORIGINS=https://123nick.com,https://shophhp.net,https://vanghhp.vn
FRONTEND_CACHE_WEBHOOK_SECRET=<chuoi-bi-mat-ngau-nhien-toi-thieu-32-ky-tu>
TRAFFIC_MONITOR_ENABLED=false
TRAFFIC_MONITOR_STORE=redis
```

Mỗi frontend: `WEBHOOK_SECRET` phải trùng secret trên backend. Không dùng biến `NEXT_PUBLIC_*` cho secret. Origins chỉ chứa domain triển khai thực tế do chủ hệ thống quản lý; bỏ domain không dùng. Ví dụ trên dùng Redis đã được cấu hình và kiểm tra; `TRAFFIC_MONITOR_STORE` độc lập với `CACHE_STORE`, mặc định vẫn là file nếu không khai báo. Nhiều máy phải dùng cùng store để có số liệu tổng và cùng file trạng thái (hoặc đổi trạng thái trên từng máy).

1. Build/deploy source cả backend và các frontend; backend đã chạy thành công `npm run build` local. Build frontend bằng lockfile/runtime đúng dự án.
2. Kiểm tra index thật, EXPLAIN và kế hoạch thêm index rồi chạy migration khi triển khai (có migration index, quyền traffic.view và traffic.manage). Chưa chạy trên DB production trong phiên làm việc này.
3. Cập nhật cấu hình môi trường, chạy `php artisan config:cache` và giữ cron Laravel `schedule:run` mỗi phút.
4. Chạy `php artisan frontend-cache:sync` để kiểm tra kết nối sau khi đã deploy endpoint mới. Chỉ HTTP thành công kèm JSON success=true mới được ghi nhận.
5. Test sửa giá/danh mục/nick; kiểm tra API trước, rồi frontend sau lượt scheduler. Giao dịch vẫn phải kiểm tra DB thật ở backend.

Nếu bỏ trống FRONTEND_CACHE_ORIGINS, command không gửi gì. Không cần queue worker mới cho cơ chế này. Không sửa .env production hay gửi webhook thật trong lần triển khai source này.

## Admin → Lưu lượng & API

### Bật/tắt ghi nhận — bổ sung 16/09/2026

- Vào `/admin/traffic`, bấm **Bật ghi nhận / Tắt ghi nhận**. Quyền `traffic.manage` được migration `2026_09_16_000001_add_traffic_manage_permission` cấp cho admin/super-admin hiện hữu; enum/seeder cấp cho lần seed sau. Tài khoản chỉ có `traffic.view` được xem, không đổi công tắc.
- Mặc định tắt nếu chưa có trạng thái đã lưu và không khai báo bật trong môi trường. `.env` đang có `TRAFFIC_MONITOR_ENABLED=true` vẫn bật cho đến khi admin bấm tắt. Sau khi đổi `.env`, chạy `php artisan config:cache` theo quy trình deploy.
- Nút admin lưu `1`/`0` vào `storage/app/private/traffic-monitor.state`, ngoài thư mục public; file này ưu tiên hơn `.env` và không bị `cache:clear`/`config:cache` xóa. Giữ file khi deploy, cho PHP quyền ghi thư mục chứa nó. Có thể đổi vị trí bằng `TRAFFIC_MONITOR_STATE_PATH`; không đặt trong public. Ghi lỗi sẽ báo trên form.
- Khi tắt: request mới không bắt đầu đo thời gian, không đọc/ghi bộ đếm hoặc lấy lock thống kê trong Redis/file cache; trang admin cũng không đọc các bucket và ẩn bộ lọc/bảng. Mỗi request vẫn kiểm tra một file điều khiển nhỏ trên ổ đĩa, không cần hỏi Redis/DB để biết trạng thái. Request đang chạy khi đổi nút có thể hoàn tất lần ghi trước đó.
- Không flush cache nghiệp vụ khi bật/tắt. Dữ liệu đã ghi tự hết TTL (tối đa 17 phút lưu vật lý, cửa sổ hiển thị tối đa 15 phút); bật lại sớm có thể còn số liệu cũ. Khoảng thời gian tắt không được ghi bù.
- Build/deploy backend và assets admin, chạy migration quyền mới theo quy trình triển khai. Chưa áp dụng trên hosting trong phiên sửa source. Thay đổi này chỉ điều khiển thống kê, không đổi limiter hoặc cấu hình Cloudflare.

### Bổ sung cache kênh chat và preflight

Xem [chu kỳ cache, chống gọi lặp và kiểm tra triển khai](chat-request-optimization-2026-09-15.md). Kết quả kênh giữ 60 giây khi không còn subscriber, đổi khoá ngay khi token/logout thay đổi; lỗi 429 có thời gian chờ riêng. Preflight có TTL mặc định 300 giây, không cache nội dung API.

URL: `/admin/traffic`; quyền `traffic.view` hoặc `traffic.manage` để mở trang. Người dùng/CTV không tự có quyền. Các mô tả bên dưới áp dụng khi bật ghi nhận.

- Cửa sổ 1/5/15 phút, top IP, top endpoint, lọc đúng IP/nhóm/HTTP; chi tiết tối đa 100 nhóm request.
- Đếm HTTP 429, 401/403, 404, 5xx; trung bình/tối đa thời gian xử lý tại Laravel.
- Tách tổng OPTIONS khỏi lượt gọi khác; preflight xử lý trước router dùng nhãn [preflight]. Số này không tự xác định người gọi là browser hay bot.
- Chỉ lưu route template, method, IP, nguồn IP, status, số lượt và thời gian. Không lưu query, body, password hoặc token.
- Counter gần đúng. Lock lấy ngay; bận/lỗi thì bỏ qua để không xếp request website vào hàng chờ thống kê. Tối đa 8 × 64 nhóm mỗi phút; request vượt số nhóm được đếm overflow không có chi tiết.
- 17 slot phút xoay vòng × 8 shard, cộng lock: số key vật lý có giới hạn cả trên file store. Không tạo file mới mãi theo thời gian. Không có bảng log request hoặc insert DB mỗi request khi dùng file/Redis.
- Không phải lịch sử truy cập dài hạn; restart/clear cache có thể mất số liệu. Thống kê không ghi chính trang giám sát để tránh tự tăng lượt.
- Chỉ nhận CF-Connecting-IP nếu REMOTE_ADDR thuộc [dải IP Cloudflare chính thức](https://www.cloudflare.com/ips/). Việc này chỉ gán nhãn cho thống kê; không thay đổi proxy trust, Request::ip(), auth hoặc limiter hiện có. Nếu qua proxy nội bộ, UI hiển thị IP peer và cần kiểm tra cấu hình máy chủ thực tế.
- Không nhìn thấy request bị Cloudflare chặn trước Laravel, trang Next.js hay file tĩnh phục vụ ở tầng khác. IP gọi nhiều chưa đủ để kết luận là người spam.

## Việc còn cần số liệu/quyết định vận hành

Chưa bật thêm hạn mức chặn IP: bản vá enforcement trước đó bị kiểm duyệt tự động từ chối do nguy cơ chặn người dùng, webhook và worker hợp lệ. Các throttle có sẵn vẫn giữ. Màn giám sát giúp chọn hạn mức dựa trên traffic thật; không tự ban IP.

Ảnh gốc/thumbnail, bundle lớn, broadcast đồng bộ, slow SQL production và tải PHP worker còn cần đo trên hosting. Không coi cấu hình Redis trong source là bằng chứng Redis đang hoạt động.
