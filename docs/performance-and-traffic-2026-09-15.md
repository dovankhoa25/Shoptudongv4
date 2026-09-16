# Kết quả tối ưu và theo dõi lưu lượng — 15/09/2026

## Đã sửa trong source local

- Bổ sung 16/09: công tắc bật/tắt ghi nhận ở `/admin/traffic`, quyền `traffic.manage`, mặc định tắt khi chưa có cấu hình bật/trạng thái đã lưu. Khi tắt bỏ qua bộ đếm/cache thống kê; còn một kiểm tra file trạng thái nhỏ mỗi request. Xem hướng dẫn triển khai và thứ tự ưu tiên file/`.env` trong bản đồ cache bên dưới.
- Admin có trang **Lưu lượng & API** tại `/admin/traffic`: top IP/endpoint, lọc IP/nhóm/mã HTTP, số lần 429/401/403/404/5xx và thời gian xử lý. Phân quyền `traffic.view`, cửa sổ tối đa 15 phút, bộ đếm gần đúng có giới hạn dung lượng. Không tự khóa IP.
- Cache public dựng JSON hoàn chỉnh trước khi lưu. Danh mục nạp media theo lô; giá server dùng quan hệ giá hiện hành và tổng tồn kho bằng SQL; bot chỉ select các cột public.
- Xóa nhóm cache sau commit ngoài cùng; rollback không xóa. Observer chung bao phủ ghi model từ admin, AppAuto/V2 và command. Thêm invalidation cho pivot danh mục/dịch vụ/field/thuộc tính, xóa template và đổi thứ tự spin.
- Settings chuyển từ key forever đơn lẻ sang cache có generation và TTL, tránh producer cũ ghi lại dữ liệu hợp lệ vô hạn sau khi forget.
- Thống kê đơn vàng, nhập vàng, đơn ngọc và rút tiền: từ 9–12 truy vấn count/sum xuống 1 query tổng hợp cho mỗi bộ thống kê, giữ filters và scopes.
- Analytics cache riêng ngày/phạm vi admin/seller; job tính thống kê thay dữ liệu trong transaction và xóa cache khi hoàn tất. CORS cache hit không kiểm tra schema. NRO metadata cache hit không đọc/parse toàn bộ catalog trong constructor.
- Dashboard tổng hợp các giao dịch và biến động kho sau ngày chọn bằng SQL. Admin realtime chia sẻ một lần đọc giữa các tab cùng user/session/filter trong một lượt flush, vẫn kiểm tra quyền từng view.
- Ba frontend tải chat khi mở, giảm hiệu ứng nền khi không cần; sửa webhook invalidation, bổ sung tags còn thiếu, bỏ lời gọi purchase tới endpoint revalidate không tồn tại.
- Backend có `frontend-cache:sync` chạy qua scheduler, ký HMAC và chỉ gửi nhóm cache thay đổi; giữ lại trạng thái chưa gửi thành công để thử lại.
- Sửa nhật ký đăng nhập lấy `username` theo trường form đã validate.
- Hai migration mới: index đọc catalog/dashboard và quyền xem lưu lượng.

Bản đồ chi tiết TTL, đường ghi và invalidation, cơ chế Next.js, cùng hướng dẫn cấu hình nằm trong [cache-invalidation-map.md](cache-invalidation-map.md).

## Đã kiểm chứng

- Bổ sung công tắc 16/09: **20 test liên quan traffic/CORS/frontend registry, 187 assertions đạt**, gồm bật/tắt qua HTTP, không truy cập cache thống kê khi tắt, không flush cache nghiệp vụ, phân quyền xem/quản lý và lỗi lưu trạng thái. Đây chưa phải benchmark production.
- **47 test backend liên quan, 1.015 assertions: qua** (43 test / 866 assertions và 4 test NRO / 149 assertions).
- **3 test webhook frontend: qua**; chạy handler TypeScript thật với stub `next/cache`, kiểm tra HMAC, tags, timestamp, input lỗi và giới hạn body. Đây không phải kiểm chứng hệ thống cache của Next trên hosting.
- **52 file PHP sửa/thêm: syntax hợp lệ**; `git diff --check` không có lỗi whitespace.
- **19 file TypeScript/TSX frontend đã chạm tới: transpile không có lỗi cú pháp**; chưa thay thế full typecheck/Next build.
- **Backend `npm run build`: qua cả TypeScript và Vite**. Bundle chính vẫn khoảng 674 kB minified; có cảnh báo chunk lớn và Browserslist cũ. Không tự đổi dependencies trong lượt này.
- Test 20 danh mục: 1 query media khi cache lạnh, 0 DB query khi cache còn hiệu lực.
- Test bảng giá 12 server: 3 query khi cache lạnh, 0 DB query khi cache còn hiệu lực; sửa giá/tồn kho thấy giá trị mới.
- Kiểm thử nested transaction/commit/rollback; dữ liệu thống kê theo bộ lọc/phạm vi người dùng; quyền trang traffic; IP Cloudflare giả; giới hạn số nhóm metrics và việc xoay vòng khóa.

### Lần chạy toàn bộ bộ test

377 tests / 4.713 assertions, 374 test qua và 3 test thất bại:

1. `AdminPermissionCoverageTest::test_every_admin_route_uses_only_semantic_permission_middleware`: route POST admin/live-views không có middleware permission dạng semantic.
2. `Auth\\AuthenticationTest::test_users_can_authenticate_using_the_login_screen`: test chờ /admin/dashboard, controller chuyển /admin.
3. `GoldTradingApiTest::test_buying_gems_debits_balance_and_gem_history_is_user_scoped`: fixture mua 3.000, backend áp mức tối thiểu 10.000.

Đã đối chiếu `git show HEAD`: nhóm route live-views, đích redirect đăng nhập và logic mức tối thiểu trên có sẵn trước thay đổi này. Không sửa chúng để ép full suite xanh. Lần chạy full này diễn ra trước khi thêm SettingsCacheTest và đổi key settings; các test liên quan settings/NRO đã chạy lại sau đó và qua.

Một số test chat/realtime còn cố kết nối Ably và phát lỗi mạng trong sandbox, dù các test đó hoàn tất. Không coi lần chạy này là chứng minh Ably hoạt động.

## Trạng thái triển khai

Source đã sửa, **chưa deploy và chưa chạy migration production**. Không đổi .env production, proxy trust hay cấu hình tài khoản Cloudflare. Backend build local đã tạo assets; không đóng gói lại public/build.zip có thay đổi sẵn từ trước.

Frontend cache sync cần cấu hình origins + secret chung và cron Laravel. Nếu chưa bật, Next.js vẫn dùng TTL hiện có. Ở lượt tối ưu chat tiếp theo, dependencies frontend đã có và typecheck toàn bộ ba frontend đạt; chưa chạy full Next build hoặc Lighthouse. Xem [bản sửa request chat/OPTIONS](chat-request-optimization-2026-09-15.md).

Chưa đo p50/p95, MySQL EXPLAIN, tải CPU/RAM/PHP workers, kích thước ảnh thực tế hay xác minh giao dịch trên production. Ảnh gốc/thumbnail, bundle lớn và broadcast đồng bộ vẫn cần số đo để tối ưu tiếp.

## Hạn mức chống spam mới: chưa bật

Bản vá enforcement trước đó bị kiểm duyệt tự động từ chối vì áp giới hạn rộng và thay đổi proxy trust có thể chặn khách hợp lệ, webhook hoặc tool. Code đó đã gỡ; các limiter có sẵn vẫn giữ. Phần quan sát traffic mới không thay đổi cách chặn request.

Hạn mức từng được đề xuất (chưa áp dụng), theo IP:

| Nhóm | 10 giây | 1 phút | 10 phút |
| --- | ---: | ---: | ---: |
| Gửi đăng nhập/đăng ký/khôi phục mật khẩu | 10 | 30 | 100 |
| Web admin | 40 | 180 | 900 |
| API khách | 60 | 300 | 1500 |
| Web Laravel khác | 30 | 120 | 600 |
| Tool/worker | 150 | 900 | 6000 |
| Webhook | 60 | 300 | 2000 |

Cần đối chiếu số liệu thật và proxy topology trước khi bật; IP dùng chung hoặc request từ Next.js server có thể đại diện nhiều người. Không dùng challenge trình duyệt cho webhook/tool. Xem [dải IP chính thức Cloudflare](https://www.cloudflare.com/ips/) và bản đồ triển khai đi kèm.
