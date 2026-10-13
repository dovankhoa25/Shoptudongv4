# Giá ngọc bằng vé

`gem_prices.gift_ticket_multiplier` là hệ số vé riêng, nullable, decimal(5,2), từ 1 đến 100 và tối đa 2 chữ số thập phân. Bỏ trống dùng `multiplier` ký gửi của cùng bản giá đang hoạt động. Cột `gem_transactions.price_at_transaction` tăng độ chính xác từ decimal(10,1) lên decimal(10,2) để giữ giá vé như 12.35; response ký gửi vẫn giữ định dạng cũ, giá vé trả đủ 2 chữ số lẻ. Không thay đổi cột hệ số ký gửi hoặc giá trị các đơn cũ. Hệ số cao hơn nghĩa là cùng số tiền khách nhận nhiều ngọc hơn.

Công thức checkout giữ nguyên `(tiền / 10.000) × hệ số × 10`: ngọc ký gửi làm tròn xuống; ngọc bằng vé giữ cách làm tròn lên hiện tại và số ngọc phải là bội của 10. Bộ tính admin và checkout cùng dùng phép tính BCMath 8 chữ số thập phân. Ví dụ ký gửi 10.001đ với hệ số 10 nhận 100 ngọc, không phải 101. API kiểm tra bội của 10 cho đơn vé và giữ đủ ngọc/vé trên một bot trước khi trừ tiền; không cộng nhiều bot để xử lý một đơn. Đơn vé giới hạn theo tồn bot, ký gửi tiếp tục tối đa 20.000 ngọc mỗi đơn sau khi làm tròn xuống. `price_at_transaction` ghi lại hệ số thực dùng tại lúc tạo đơn. Các đơn đã tạo giữ nguyên số ngọc đã lưu.

API `/api/gem/servers` và resource giá admin thêm `gift_ticket_multiplier` (giá cấu hình hoặc null) và `gift_ticket_gems_per_10k` (số ngọc/10k theo giá thực dùng). `/api/server-prices` và `/api/server-prices/{id}` thêm `gem_gift_ticket_multiplier` dạng number, tự fallback giá ký gửi; null nếu server chưa có giá hoạt động. Các trường cũ giữ nguyên. Cập nhật giá tiếp tục làm mới nhóm cache công khai `public:servers` và `public:server-prices`.

Admin tạo/sửa nhận field `gift_ticket_multiplier`; gửi null hoặc chuỗi trống để trở về giá ký gửi. Sửa hoặc cập nhật hàng loạt không gửi field này sẽ giữ hệ số vé đã cấu hình. `/admin/gem-prices/api/calculate` nhận tùy chọn `delivery_method=gift_ticket`; không gửi vẫn tính ký gửi như trước.

## Triển khai

Chạy migration backend trước khi chạy source backend/frontend mới:

```powershell
php artisan migrate --force
php artisan cache:clear
php artisan config:cache
php artisan queue:restart
```

Không thêm biến môi trường cho giá vé. Đơn vé vẫn cần `NRO_TRADING_V3_ENABLED=true` và bot v3 có lease/tồn đã đồng bộ. Giá fallback hoạt động ngay với tất cả bản giá cũ vì migration để field mới null. Build lại admin và frontend theo quy trình đang dùng để hiển thị và gửi đúng giá theo cách nhận ngọc. Không rollback cột này khi bản ứng dụng mới đang chạy. Nếu rollback migration sau khi đã có đơn vé giá 2 chữ số lẻ, việc thu hẹp snapshot về 1 chữ số lẻ có thể làm tròn giá đã lưu; giữ schema mở rộng khi quay về bản ứng dụng cũ.
