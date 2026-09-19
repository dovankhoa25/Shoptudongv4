# Rà soát route/controller và bảo mật truy cập

## Phạm vi và kết luận

Đã lập danh mục **412 route ứng dụng / 99 controller** từ `artisan route:list --json --except-vendor` sau thay đổi: 249 route admin, 97 API, 32 worker/app, 34 web/auth/khác. Danh mục ban đầu là 406 route; thêm 6 route quản lý bảo mật. Đây là kiểm kê route, kiểm tra ranh giới xác thực/quyền, rà các điểm xử lý dữ liệu nhạy cảm và chạy test; không đồng nghĩa từng dòng trong 99 controller đã được pentest hay production hoàn toàn an toàn.

## Phát hiện và sửa trong đợt này

| Khu vực | Vấn đề xác định từ source hoặc mẫu kiểm tra | Thay đổi |
| --- | --- | --- |
| `ChatController`, `UpdateAvatarRequest` | Mẫu PNG 33 byte chỉ có signature/IHDR vẫn được nhận dạng MIME/kích thước nhưng không giải mã được thành ảnh hoàn chỉnh. | Thêm `DecodableImage`: giải mã bằng GD, giới hạn điểm ảnh/bộ nhớ, từ chối ảnh hỏng trước khi lưu. Không kết luận mẫu này đã thực thi mã trên server. |
| `ApiOrderController::store`, `ApiGemOrderController::store` | Kiểm tra đơn pending trước transaction có thể trở nên cũ khi hai yêu cầu cùng người dùng/nhân vật chạy xen kẽ. | Khóa dòng người dùng và đọc lại đơn pending bằng locking read trong transaction, trước khi trừ tiền/tạo đơn. |
| `WithdrawalRequestController::approve`, `markPaid` | Trạng thái lấy từ model đã bind có thể cũ, ghi đè một thay đổi đã hoàn tất ở request khác. | Đọc lại và kiểm tra trạng thái dưới khóa trong transaction; thêm allowlist cột/hướng sắp xếp. Luồng reject đã có khóa được giữ lại. |
| Đăng nhập/quản trị | Chưa có quy trình duyệt đúng cặp IP + cookie trình duyệt theo chính sách người dùng chọn. | Thêm yêu cầu chờ duyệt, duyệt/thu hồi, kiểm tra từng request, khóa đường API quản trị OAuth khi chỉ có bearer token, lệnh phục hồi từ console. |
| Lịch sử/IP | IP của request qua BFF có thể là máy trung gian; User-Agent không chứng minh thiết bị. | Lịch sử có IP/kênh/nguồn/trình duyệt/kết quả; thêm chuyển IP có chữ ký giữa 3 BFF và backend, chỉ tin proxy được cấu hình rõ ràng. |
| Realtime admin | Request tổng hợp để đọc dữ liệu không có cookie/IP trình duyệt. Áp middleware HTTP trực tiếp sẽ làm hỏng subscription; bỏ mọi kiểm tra sẽ để kênh cũ tiếp tục nhận dữ liệu. | Request HTTP ngoài vẫn kiểm tra thiết bị; lease realtime lưu ID yêu cầu đã duyệt và kiểm tra lại trạng thái/hạn/IP block trước mỗi lần phát dữ liệu. Reader vẫn thực thi quyền của màn hình đích. |

## Những ranh giới đã đối chiếu

- **Admin:** route semantic permission và enum, `RequirePermission`, `UserController`, đăng nhập password/Google, quản lý OAuth client, live view và NRO admin. Các ngoại lệ không có middleware permission trực tiếp được liệt kê chính xác trong `AdminPermissionCoverageTest`: live-balance của chính người dùng; live-views kiểm tra chủ session và quyền màn hình qua Reader; NRO sale-policy/refund kiểm tra quyền/role trong controller. Không thêm quyền dashboard chung làm thay đổi quyền các màn hình này.
- **API khách hàng:** nhóm auth/scopes của profile, giao dịch, chat, NRO orders và quản lý phiên. Đối chiếu các test quyền sở hữu hiện có và kiểm tra hồi quy các luồng bị thay đổi. Kiểm tra pending/khóa của mua vàng/ngọc và chuyển trạng thái rút tiền được bổ sung riêng.
- **Upload và nội dung:** chat/avatar, quyền lấy chat media, hiển thị nội dung, các chỗ xử lý URL/ảnh từ xa. Các biện pháp chặn SVG/HTML, header phục vụ media, tải URL an toàn và escape nội dung đã có ở source từ đợt trước; phần mới bổ sung kiểm tra ảnh có giải mã được.
- **Worker/webhook:** kiểm kê các route không dùng đăng nhập người dùng; đối chiếu khóa app/worker, chữ ký callback và các test workflow hiện có. IP ban dành cho người dùng không thay thế hoặc chặn nhầm các đường thanh toán/worker này.
- **SQL/file/request sinks:** rà `whereRaw`, `orderByRaw`, tải/đọc file, HTTP ra ngoài và deserialize trong controller/service liên quan; đối chiếu binding/allowlist và `unserialize` không cho tạo object. Tìm kiếm tĩnh không phải bằng chứng loại trừ mọi SQLi/SSRF/RCE.

## Kiểm thử

- Kết quả cuối: **481/481 test backend, 5.520 assertions**. Có thông báo kết nối Ably bị từ chối trong môi trường local; kết quả này không xác nhận việc gửi realtime qua dịch vụ live. Frontend: **17/17 test mỗi storefront, tổng 51/51**. Backend và cả ba frontend build thành công.
- Test mới bật chính sách duyệt: password/Google, IP hoặc cookie mới, cookie giả/thiếu, sai mật khẩu, tài khoản bị khóa, CLI duyệt đầu tiên, duyệt/thu hồi qua HTTP, API admin, IP/CIDR IPv4/IPv6, header proxy/chữ ký giả, dữ liệu lịch sử không lộ metadata nhạy cảm, upload ảnh hỏng/hợp lệ, rate limit và realtime sau khi thu hồi/hết hạn/chặn IP.
- Test giao dịch dựng tình huống dữ liệu thay đổi sau lần đọc đầu và trước lần ghi; kiểm tra không tạo thêm đơn/trừ tiền hoặc ghi đè thanh toán. Đây là mô phỏng có chủ đích trên SQLite, chưa phải thử đồng thời bằng nhiều kết nối MySQL.
- Fixtures cũ được cập nhật theo hành vi hiện có: redirect đăng nhập tới `admin.home`, test mua ngọc khai báo mức tối thiểu 3.000 của riêng fixture, smoke test tạo schema, test lease có cột thiết bị mới. Test coverage phân biệt permission middleware và các điểm kiểm tra quyền trực tiếp đã nêu trên.
- Backend chạy TypeScript/Vite build; ba storefront chạy production build và 17 test bảo mật/session/forward-IP cho mỗi storefront. Build `vanghhp` có thông báo không kết nối được nguồn giá local trong bước prerender nhưng hoàn tất. Test/build không chứng minh dữ liệu giá live, đăng nhập live hay cấu hình hosting.

## Phần cần xác minh trên môi trường triển khai

Chưa kiểm tra từ bên ngoài production, log web server/CDN đầy đủ, WAF/rate-limit thực tế, cấu hình chạy file upload, quyền file, keys/token hiện hành hoặc xác định ai sở hữu hai IP trong dữ liệu người dùng cung cấp. Chưa kết luận chuỗi XSS được lưu trong database đã chạy trong trình duyệt admin, hay có rò rỉ dữ liệu.

IP thật phụ thuộc proxy/ingress cấu hình đúng. Lịch sử cũ không tự khôi phục IP client bị mất. Chặn ở Laravel không chặn HTML/static cache tại CDN/Next.js; IP ban cũng không thay thế khóa tài khoản khi đối tượng đổi mạng. Chưa xóa file chat, chưa triển khai production và chưa chạy migration trên database production.

Hướng dẫn migration, biến môi trường, duyệt admin đầu tiên và kiểm tra sau deploy: `docs/access-security.md`.
