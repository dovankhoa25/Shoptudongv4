# Giá ngọc bằng vé

`gem_prices.gift_ticket_multiplier` là hệ số vé riêng, nullable, decimal(5,2), từ 1 đến 100 và tối đa 2 chữ số thập phân. Bỏ trống dùng `multiplier` ký gửi của cùng bản giá đang hoạt động. Cột `gem_transactions.price_at_transaction` tăng độ chính xác từ decimal(10,1) lên decimal(10,2) để giữ giá vé như 12.35; response ký gửi vẫn giữ định dạng cũ, giá vé trả đủ 2 chữ số lẻ. Không thay đổi cột hệ số ký gửi hoặc giá trị các đơn cũ. Hệ số cao hơn nghĩa là cùng số tiền khách nhận nhiều ngọc hơn.

Ngọc ký gửi giữ công thức `floor((tiền / 10.000) × hệ số × 10)` và trừ đủ số tiền khách nhập. Ví dụ ký gửi 10.001đ với hệ số 10 nhận 100 ngọc, không phải 101; ký gửi tiếp tục tối đa 20.000 ngọc mỗi đơn sau khi làm tròn xuống.

Ngọc bằng vé lấy số vé nguyên và chỉ trừ tiền tương ứng, cùng cách báo giá với đơn thỏi vàng. Với `units = hệ số vé × 100`, tất cả phép tính BCMath còn lại dùng số nguyên:

- `ticket_qty = floor(requested_amount × units / 1.000.000)`.
- `gem_qty = ticket_qty × 10`.
- `charged_amount = ceil(ticket_qty × 1.000.000 / units)`.
- `unused_amount = requested_amount − charged_amount`.

Ví dụ khách nhập 10.000đ, hệ số vé 12,35: thực nhận 12 vé / 120 ngọc, thực trừ 9.717đ, 283đ còn lại trong số dư. 10.000đ là mốc quy đổi giá; số tiền thực trừ không bắt buộc là bội của 10.000đ. Số tiền tối thiểu vẫn xét theo tiền khách nhập; số dư chỉ cần đủ tiền thực trừ. API checkout và bộ tính admin trả thêm `requested_amount`, `charged_amount`, `unused_amount`, `ticket_qty`; checkout còn trả `balance_after` và `amount_vnd` là tiền thực trừ. Ledger lưu các trường báo giá cùng số ngọc và cách nhận ngọc để đối soát. Hủy/hoàn tiền tiếp tục dựa trên `amount_vnd`, vì vậy chỉ hoàn số tiền đã thực trừ.

Đơn vé giới hạn theo tồn khả dụng của một bot; không cộng nhiều bot để xử lý một đơn. API giữ đủ ngọc/vé trên bot trước khi trừ tiền. `price_at_transaction` ghi lại hệ số thực dùng tại lúc tạo đơn. Các đơn đã tạo giữ nguyên tiền/ngọc đã lưu, không tính lại khi đổi giá hoặc cập nhật cách làm tròn.

API `/api/gem/servers` và resource giá admin thêm `gift_ticket_multiplier` (giá cấu hình hoặc null) và `gift_ticket_gems_per_10k` (số ngọc/10k theo giá thực dùng). `/api/server-prices` và `/api/server-prices/{id}` thêm `gem_gift_ticket_multiplier` dạng number, tự fallback giá ký gửi; null nếu server chưa có giá hoạt động. Các trường cũ giữ nguyên. Cập nhật giá tiếp tục làm mới nhóm cache công khai `public:servers` và `public:server-prices`.

Admin tạo/sửa nhận field `gift_ticket_multiplier`; gửi null hoặc chuỗi trống để trở về giá ký gửi. Sửa hoặc cập nhật hàng loạt không gửi field này sẽ giữ hệ số vé đã cấu hình. `/admin/gem-prices/api/calculate` nhận tùy chọn `delivery_method=gift_ticket`; không gửi vẫn tính ký gửi như trước.

Danh sách và form giá admin dùng `gift_ticket_quote_per_10k` và `consignment_quote_per_10k` để hiển thị số vé/ngọc nguyên, tiền thực trừ và phần dư tại mốc 10.000đ. Field cũ `gift_ticket_gems_per_10k` vẫn giữ giá trị quy đổi lý thuyết để tương thích client cũ; ví dụ 12,35 vẫn là 123,5 ở field này, còn báo giá thực là 12 vé/120 ngọc. Cập nhật giá sau commit làm mới danh sách admin qua AdminLive private. Build lại `public/build` để form, bảng giá và bộ tính trên admin cùng phiên bản.

## Triển khai

Lần cập nhật lấy vé nguyên và tiền thực trừ không thêm migration hoặc biến môi trường. Frontend `vanghhp.vn v4` hiển thị “Thực trừ / Thực nhận” dưới input; modal sau tạo đơn dùng `charged_amount` và `gem_qty` từ API. Các lệnh migration bên dưới dành cho schema giá vé riêng nếu chưa được áp dụng.

Chạy migration backend trước khi chạy source backend/frontend mới:

```powershell
php artisan migrate --force
php artisan cache:clear
php artisan config:cache
php artisan queue:restart
```

Không thêm biến môi trường cho giá vé. Đơn vé vẫn cần `NRO_TRADING_V3_ENABLED=true` và bot v3 có lease/tồn đã đồng bộ. Giá fallback hoạt động ngay với tất cả bản giá cũ vì migration để field mới null. Build lại admin và frontend theo quy trình đang dùng để hiển thị và gửi đúng giá theo cách nhận ngọc. Không rollback cột này khi bản ứng dụng mới đang chạy. Nếu rollback migration sau khi đã có đơn vé giá 2 chữ số lẻ, việc thu hẹp snapshot về 1 chữ số lẻ có thể làm tròn giá đã lưu; giữ schema mở rộng khi quay về bản ứng dụng cũ.
