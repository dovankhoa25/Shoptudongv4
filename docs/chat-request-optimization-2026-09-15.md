# Giảm request chat và OPTIONS — 15/09/2026

## Những gì đã sửa

Áp dụng cùng thay đổi trên 123nick.com v4, shophhp.net v4 và vanghhp.vn v4.

- Đồng bộ token giữa tab: sự kiện nhận từ BroadcastChannel cập nhật Redux nhưng không phát ngược lại. Test hai tab cùng đổi token tái hiện vòng lặp với code cũ ở cả ba frontend, hết lặp sau sửa. Đây là lỗi nguồn có thể làm tăng request; chưa chứng minh là nguồn của IP trong ảnh production.
- TokenWatcher lấy token ngay lúc mount, không bỏ qua lần đổi token đầu tiên; dọn listener khi unmount. Không mở BroadcastChannel trong SSR.
- Kênh chat dùng khoá số theo phiên đăng nhập trong Redux, không dùng token làm query key. Khoá giữ nguyên khi component remount, đổi ngay khi hydrate token khác, refresh token hoặc logout.
- RTK Query gộp request đang chạy theo khoá, giữ kết quả khi tạm ngừng dùng 60 giây. Phản hồi của phiên cũ về muộn bị loại bỏ; không dùng cho phiên mới.
- GET lấy kênh chat bị 429: chờ Retry-After (giây hoặc HTTP date); thiếu header hợp lệ thì chờ 60 giây. Lỗi mạng/5xx chờ 30 giây. Tự thử lại tối đa hai lần sau lỗi đầu; lỗi 401/403 không tự thử lại. Gọi lại trong thời gian chờ không phát HTTP, kể cả khi remount hoặc bấm thử lại.
- Laravel cho browser cache preflight 300 giây, cấu hình CORS_MAX_AGE trong khoảng 0–600. Expose Retry-After để frontend đọc được qua CORS.
- Admin đếm riêng OPTIONS; preflight chưa chạy router hiển thị OPTIONS /[preflight], đường dẫn không khớp khác vẫn là [unmatched]. Không lưu raw URL, query hay token.

## Cache hết hạn / xoá lúc nào

| Dữ liệu | Phạm vi | Khi hết hiệu lực |
| --- | --- | --- |
| Kết quả kênh chat RTK | Một Redux store, một phiên token | Hết 60 giây sau khi không còn subscriber. Đổi token/logout đổi khoá ngay, ngừng dùng dữ liệu cũ; entry cũ tự dọn sau 60 giây. Reload trang tạo store mới. |
| Bộ đếm khoá phiên chat | Redux, không persist | Tăng khi token/trạng thái đăng nhập thay đổi, kể cả rehydrate. Không tăng khi chỉ cập nhật số dư hoặc nhận lại cùng token. |
| Thời gian chờ lấy kênh | Một store và phiên token, ngoài vòng đời component | Hết deadline mới được gọi mạng; thành công xoá lỗi/số lần thất bại; đổi phiên thay entry. Không giữ bản đồ token không giới hạn. |
| Browser preflight | Cache CORS riêng của browser | TTL mặc định 300 giây; sửa cấu hình server không xoá ngay cache đã có trong browser. Request API thực vẫn kiểm tra token/quyền. Đây không phải cache JSON hoặc cache dữ liệu cá nhân. |
| Thống kê traffic | Ring cache hiện có | Tối đa 15 phút; dòng OPTIONS/[unmatched] đã ghi trước deploy có thể còn đến khi hết cửa sổ. Không flush cache nghiệp vụ để đổi nhãn. |

Backend vẫn xác thực từng request thật và broadcast authorization. Không thay đổi cache mua hàng, số dư, webhook hoặc hạn mức IP hiện có.

## Kiểm tra

- 18 Node tests dùng source thực của cả ba frontend: Redux/RTK Query với API giả lập, hàng đợi BroadcastChannel hai tab, token rotation/logout/hydration, response cũ, concurrent/remount, 429 và giới hạn thử lại.
- 12 Laravel tests liên quan CORS, traffic và registry frontend: 100 assertions đạt.
- TypeScript toàn bộ ba frontend và lint tất cả file frontend thay đổi đều đạt, bao gồm lượt kiểm tra lại sau sửa đồng bộ tab.
- Backend npm run build đạt (TypeScript + Vite). Chưa chạy full Next build, browser E2E hoặc load test production.

## Triển khai và đối chiếu

1. Build/deploy frontend mới trên cả ba site và backend có cấu hình CORS mới + assets admin mới.
2. Backend đặt CORS_MAX_AGE=300 (hoặc dùng mặc định), chạy php artisan config:cache theo quy trình deploy. Không cần migration mới cho riêng bản sửa này.
3. Mở phiên browser dùng bundle mới, thử hai tab đăng nhập, mở/đóng chat và chuyển trang. Kênh chat không được gọi liên tục khi token không đổi; đăng xuất phải ngừng dùng kênh cũ.
4. Đối chiếu cửa sổ 1 phút ở admin: GET /api/chat/realtime-channel, 429 và OPTIONS. Cache preflight chỉ giảm request do browser tuân theo CORS; một script cố ý vẫn có thể gửi nhiều request.
5. Muốn ngăn lưu lượng cố ý trước khi PHP chạy cần cấu hình bảo vệ ở Cloudflare/server. Bản sửa này chưa cấu hình hoặc bật rule Cloudflare và không chứng minh website chịu được một mức tải cụ thể.

Nguồn: [RTK Query cache](https://redux-toolkit.js.org/rtk-query/usage/cache-behavior), [Access-Control-Max-Age](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Access-Control-Max-Age).
