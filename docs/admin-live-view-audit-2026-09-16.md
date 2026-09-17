# Rà soát admin, realtime và chi phí request — 16/09/2026

## Kết luận

Đã sửa các lỗi tải dữ liệu phụ thuộc live view và các nguồn tạo request/truy vấn thừa xác định được trong checkout này. Khi `/admin/live-views` bị từ chối, các bảng NRO có thể đọc dữ liệu qua endpoint GET hiện hữu, vẫn áp dụng đăng nhập, quyền và phạm vi dữ liệu. Nếu chính GET cũng bị chặn, giao diện báo lỗi thay vì coi đó là danh sách trống.

Nguồn phát sinh **HTML 403 trên hosting chưa được xác định**. Không tìm thấy tiêu đề `Truy cập của bạn đã bị chặn` trong source ứng dụng đã tìm. Các trường hợp thiếu quyền/thu hồi realtime được kiểm thử local trả JSON 403. Bản sửa giảm ảnh hưởng của lỗi này, không thay thế việc kiểm tra request tương ứng trong log hosting/Cloudflare.

## Phạm vi rà soát

- Kiểm kê 657 file trong `app`, `routes`, `config`, `resources/js`; tìm các đường gọi HTTP, cache/invalidation, broadcast, middleware, layout và hook realtime.
- Đọc sâu đường NRO orders/listings/jobs/worker keys/stock check, chat, admin live view, shared props, cache public, traffic monitor và middleware quyền.
- Chạy TypeScript/Vite và bộ test backend hiện có; kiểm tra riêng các tình huống lỗi mạng, quyền, phản hồi đến sai thứ tự và vòng đời subscription.
- Đây là rà soát source theo luồng và kiểm thử tự động; chưa phải chứng nhận mọi dòng code không có lỗi, kiểm thử tải hay kiểm thử giao diện trên hosting. Các checkout Next.js riêng không được sửa trong lượt này.

## Danh sách đã sửa

| Vấn đề | Thay đổi | Nơi sửa chính |
| --- | --- | --- |
| Bảng NRO chờ đăng ký live view → socket auth → sync; một bước lỗi thì trống | GET độc lập khi mở tab/đổi bộ lọc; realtime cập nhật tiếp sau khi GET thành công | `Realtime/useLiveResource.ts`, `NroShop/usePagedTab.ts` |
| Lỗi tải dữ liệu bị hiển thị như “No data” | Thông báo lỗi HTTP/quyền và nút làm mới; không coi HTML 200 là dữ liệu JSON hợp lệ | `Realtime/LiveDataNotice.tsx`, Orders/Listings/Jobs/WorkerKeys, WarehouseListingsModal, OrderStockCheckModal |
| Phản hồi cũ ghi đè bộ lọc/tài khoản mới | Hủy request cũ; kiểm tra số thứ tự request và khóa user/credential/URL; live snapshot mới thắng GET cũ | `useLiveResource.ts` |
| Các trang dựng lại khung admin, kéo theo đăng ký số dư/chat lặp | Chuyển 14 trang còn dùng layout bên trong component sang persistent layout của Inertia | NRO, Traffic, Bots/Show, GemBots/Create/Edit/Show, Categories/Create/Edit, GemOrders/Show, Orders/Show, Spins/Create/Edit, SpinTickets/Create/Edit |
| Ẩn/hiện tab trình duyệt ngắn gây DELETE → POST → auth → sync lặp | Giữ subscription tối đa 30 giây khi ẩn; dọn khi ẩn lâu; đổi trang/bộ lọc vẫn dọn ngay | `useLiveView.ts` |
| Reconnect có thể gọi lại sau lỗi quyền/giới hạn | Ngừng retry tự động sau 401/403; tuân thủ Retry-After khi 429; lỗi tạm thời chờ ít nhất 30 giây trước lần thử được kích hoạt tiếp | `useLiveView.ts` |
| Callback socket cũ tác động view mới, lease còn khi khởi tạo socket lỗi | Kiểm tra thế hệ subscription, đổi credential thì tạo lại view, dọn lease khi socket auth/setup thất bại | `useLiveView.ts` |
| Chat bubble đã bị ẩn vì thiếu quyền nhưng vẫn đăng ký API | Kiểm tra quyền trước khi bật subscription | `ChatBubble.tsx` |
| Danh sách chat admin có thể chờ mãi sau lỗi live view | Khi live báo lỗi, đọc HTTP một lần cho mỗi bộ lọc/user/credential; có nút làm mới, giữ assignment/view/period; không lặp GET khi tải thêm trang | `ChatWorkspace.tsx` |
| Refresh và một số thao tác NRO phụ thuộc hoàn toàn broadcast | Khi live không hoạt động, nút refresh dùng HTTP/Inertia reload; làm mới bảng sau đối soát/tạo/thu hồi key; cập nhật trang sau thao tác thành công nếu mất live | `AdminRealtimeProvider.tsx`, `NroShop/Index.tsx`, JobsTab, WorkerKeysTab |
| Middleware Inertia dựng avatar, quyền, kênh chat và khóa hàng số dư ngay cả với JSON | Chuyển shared `auth` thành closure; chỉ tính khi response Inertia thực sự cần prop này | `HandleInertiaRequests.php` |

### Chi phí request sau sửa

- Tab NRO thêm một GET ban đầu để dữ liệu không phụ thuộc socket. Khi live hoạt động bình thường, vẫn cần đăng ký, auth và sync; **không phải mọi lần mở tab đều giảm tổng số request**.
- Phần giảm nằm ở việc giữ khung admin, không nối lại vì chuyển tab ngắn, không tự lặp sau 403/429 và không dựng shared props trong JSON response.
- Dữ liệu không được polling định kỳ. Lease realtime vẫn gia hạn mỗi 15 phút. Một tab ẩn dưới 30 giây vẫn có thể nhận broadcast.
- Người dùng đã thiếu quyền vẫn bị GET từ chối. Không đổi middleware quyền, limiter, giá, tồn kho, mua/hoàn tiền hay cách xác định IP.

## Cache và invalidation đã đối chiếu

- `ApiCache` dùng store mặc định `cache.default`; lưu JSON đã dựng và generation cho từng nhóm. `clearGroup(s)` trong transaction chạy sau commit; rollback không làm mới generation. Cache cũ được nhận biết bằng generation, không cần flush toàn bộ Redis.
- `PublicCacheObserver` bao phủ các model liên quan; bulk SQL và pivot cần invalidation ở nơi ghi. Đã đối chiếu các controller category/service/attribute/field, NRO shop và command liên quan với [bản đồ cache](cache-invalidation-map.md).
- Middleware shared props sau sửa không cache số dư/quyền. Trang cần auth vẫn đọc giá trị hiện hành; JSON nghiệp vụ tự áp dụng logic đọc/quyền của controller như trước.
- State của live view vẫn có TTL 3.600 giây; lease view 30 phút. GET fallback đọc endpoint nghiệp vụ, không lấy dữ liệu riêng tư từ cache public.
- `TRAFFIC_MONITOR_STORE` vẫn độc lập với `CACHE_STORE`, mặc định `file` trong source. Production dùng Redis chưa đủ để suy ra monitor cũng đang dùng Redis. Khi tắt ghi nhận, monitor bỏ qua bucket/lock thống kê nhưng vẫn kiểm tra file công tắc nhỏ.
- Không đổi TTL hay xóa cache production trong lượt này. Cơ chế frontend cache webhook và lịch chạy đã có được giữ nguyên.

## Việc còn cần xử lý, theo ưu tiên

| Ưu tiên | Phát hiện / giới hạn | Hướng xử lý tiếp |
| --- | --- | --- |
| P1 | HTML 403 production chưa truy được nơi phát sinh | Đối chiếu thời gian, method, URL, mã request với Cloudflare và hosting; chỉ điều chỉnh rule đã xác định chặn nhầm. GET cũng bị chặn thì fallback không khôi phục được dữ liệu |
| P1 | Code hiện tại không ngăn người biết origin IP gửi request trực tiếp tới hosting | Cần nhà cung cấp hosting xác nhận cách chỉ cho phép lưu lượng Cloudflare tới vhost/origin; chưa áp rule `.htaccess` hay firewall trong lượt này |
| P2 | `LiveViewController::store` dựng dữ liệu để kiểm tra quyền rồi bỏ đi; `/sync` dựng lại | Tách kiểm tra quyền khỏi dựng dữ liệu hoặc thiết kế snapshot lúc đăng ký, kèm kiểm thử quyền thay đổi và sự kiện xảy ra giữa đăng ký/subscription. Không bỏ kiểm tra quyền để giảm một lần đọc |
| P2 | `AdminLive/Updates::flush` duyệt tất cả view còn hạn; refresh dùng lock chờ tối đa 5 giây và broadcast đồng bộ | Đo số view, thời gian query/lock/broadcast; cân nhắc coalescing/job theo view với thứ tự revision và thu hồi quyền. Đổi `QUEUE_CONNECTION` riêng lẻ không làm `ShouldBroadcastNow` chạy nền |
| P2 | `ApiCache::remember` có lock chờ tối đa 5 giây khi cache lạnh | Đo cache hit rate, p95/p99 và truy vấn chậm trước khi thay TTL/lock; tránh đưa dữ liệu mua hàng/quyền vào cache stale |
| P2 | Bundle app còn khoảng 674 kB minified, 214 kB gzip | Phân tích dependency/chunk, tách phần chỉ cần khi mở màn hình; chưa có số đo thiết bị người dùng để khẳng định mức chậm |
| P2 | Số dư, badge chat và chi tiết acc vẫn có thể giữ giá trị cũ khi realtime gián đoạn; chat fallback kích hoạt khi hook đã báo offline/denied | Cần UX trạng thái cập nhật cuối và kiểm thử ngắt socket lâu/không nhận callback; các quyết định tài chính ở backend vẫn đọc dữ liệu thật |
| P2 | Suite còn 3 lỗi có trước bản vá | Coverage test chưa mô tả các route kiểm tra quyền động; test login chờ `/admin/dashboard` thay vì `/admin`; fixture mua ngọc 3.000 thấp hơn min 10.000. Đối chiếu quy tắc rồi cập nhật test phù hợp |
| P3 | Một số test chat tự thử kết nối Ably bên ngoài | Fake transport ở test thích hợp; tách bài test tích hợp thực. Lỗi mạng sandbox không chứng minh dịch vụ production lỗi |

## Kiểm chứng

- `npm run build`: TypeScript và Vite thành công; còn cảnh báo chunk lớn và Browserslist cũ.
- `node --test tests/frontend/*.test.cjs`: 28 test qua, gồm 16 test mới cho lỗi live/HTTP và chat fallback. Dùng HTTP/socket giả lập và callback/hook thật; không phải kiểm thử trình duyệt đầy đủ.
- `InertiaSharedPropsTest`: 3 test / 32 assertions qua; JSON và partial reload bỏ auth không query balance/media/realtime lease, trang Inertia đầy đủ vẫn có auth/số dư.
- `AdminLiveViewTest` thêm kiểm thử đúng payload `/admin/nro-shop/orders?page=1`, GET/live trả cùng đơn, quyền GET không bị bỏ qua, thu hồi credential realtime vẫn cho phép HTTP read nếu phiên web/quyền còn hợp lệ.
- `php artisan test --compact`: **401 test, 398 qua, 3 lỗi có trước, 4.933 assertions**. Ba lỗi còn lại là `AdminPermissionCoverageTest`, redirect của `AuthenticationTest`, fixture mua ngọc của `GoldTradingApiTest` đã nêu trên. Các test thêm mới đều qua. Một số test phát lỗi kết nối Ably trong môi trường sandbox; không dùng kết quả này để suy ra tình trạng Ably production.
- `git diff --check`: qua.
- Chưa đo Redis/MySQL/CloudLinux production, chưa kiểm thử tải hoặc giao dịch game thực, chưa kiểm thử trình duyệt trên hosting.

## Triển khai

1. Đưa source thay đổi và **toàn bộ thư mục `public/build` mới** lên cùng bản release. Build đã tạo local; không dùng `public/build.zip` cũ. Có thể build lại bằng `npm run build` trong pipeline đang dùng.
2. Bản vá này không thêm migration/config/env mới; không cần flush Redis để áp dụng phần giao diện. Nếu migration quyền traffic ở bản trước chưa triển khai thì xử lý riêng với đủ enum và migration tương ứng.
3. Sau deploy, tải lại trình duyệt để nhận asset mới. Thử tab Đơn giao đồ, phân trang/bộ lọc, tạo/thu hồi key, xem kho, chat; thử quyền hạn chế và phiên hết hạn.
4. DevTools: kiểm tra GET bảng trả JSON có dữ liệu; live bị 403 phải có cảnh báo và nút làm mới. Khi chuyển giữa các trang admin dùng Inertia, subscription số dư không nên được tạo lại nếu user/credential không đổi. Trang/bộ lọc mới vẫn có subscription riêng.
5. Nếu GET cũng trả HTML 403, cần sửa rule hosting/edge đã xác định; sửa source không tự gỡ chặn bên ngoài PHP.

**Chưa deploy lên hosting hoặc đổi Cloudflare trong lượt này.**
