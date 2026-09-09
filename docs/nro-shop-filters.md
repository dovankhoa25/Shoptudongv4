# Bàn giao NRO shop — 10/09/2026

Đã cập nhật backend dùng chung, Shophhp v4, 123nick v4 và VangHHP v4. Đây là gói cập nhật chồng lên các project hiện tại, không phải bộ cài mới.

## Quản trị

- Vào `/admin/nro-shop` → Tài khoản. Mắt = xem dữ liệu; bút = sửa tin/giá; dấu cộng = tạo gói/đăng bán; ba chấm dọc = các thao tác khác. Nút có tên hỗ trợ và tooltip.
- Với acc **kho đồ**, menu ba chấm có **Tạm ẩn acc và gói đồ khỏi shop** / **Hiện lại gói đồ trên shop**. Quyền: `nro-accounts.manage`; CTV chỉ thao tác kho của mình. Admin/super admin giữ phạm vi xem toàn bộ như trước.
- Cờ `nro_accounts.shop_hidden` chỉ điều khiển việc bán mới. Danh sách công khai và URL chi tiết loại bỏ các gói của kho ẩn; API mua cũng kiểm tra lại trong transaction có khóa acc.
- Đơn đã mua, tồn giữ cho đơn, phiên nhận đồ và công việc worker được giữ nguyên. Khách vẫn yêu cầu nhận và worker vẫn nhận job từ kho ẩn. Không tắt acc/game session và không tự hoàn tiền vì thao tác ẩn.
- Hiện lại chỉ đưa các gói còn trạng thái đang bán trở lại shop. Không tự đăng lại gói đã bán, hoàn tiền hoặc tạm dừng riêng. Gói tạo mới trong kho đang ẩn cũng không công khai.
- Hai tab tài khoản/gói đồ có nhãn tạm ẩn rõ ràng; bộ lọc trạng thái tài khoản có “Kho tạm ẩn khỏi shop”. Danh sách trong admin vẫn hiển thị để quản lý.
- Cấu hình ID theo nhóm tại `/admin/nro-shop` → **Cấu hình bán & nhóm đồ**. Admin/super admin hoặc `nro-sale-policy.manage` mới sửa được. Quyền cấu hình không mở quyền xem kho của CTV khác.

API chặn bán mới ngay khi lưu ẩn thành công và làm mới namespace cache. Trang khách đã mở sẵn cập nhật theo lần tải/lọc hoặc chu kỳ 30 giây; không có cơ chế đẩy realtime mới trong bản này. Khách bấm mua trên dữ liệu cũ vẫn bị từ chối, không trừ tiền.

## Ba client

- `/ban-do-tu-dong`, đường dẫn cũ `/mua-do` và lịch sử `/profile/items`.
- Sidebar nhóm/kiểu gói; lọc chi tiết nổi bật, mở/thu gọn, tự mở khi đổi nhóm. Server, tìm kiếm, sắp xếp gọn; chọn tự lọc, không cần Áp dụng.
- Trang bị: Áo, Quần, **Găng**, Giày, Rada; lọc hành tinh, sao, sức đánh, hút máu, hút KI, vàng từ quái, HP, KI, khác.
- Nhóm mặc định: đá 220–224; sao pha lê 441–447; Ngọc Rồng 14–20. ID admin cấu hình được ưu tiên. Có chọn riêng từng vật phẩm trong các nhóm này.
- Lọc trước phân trang, 20 gói/trang; các điều kiện của combo phải khớp cùng một món. Đổi lọc về trang 1.
- Desktop tối đa 1600px, 4 cột; mobile 2 cột. Thẻ và skeleton cùng chiều cao, chữ nhỏ, vùng nội dung dài cuộn; giá/nút giữ vị trí.
- Ưu tiên SPL và chỉ số có %. Hiển thị `Ép 0/5 sao` từ option 102/107 hoặc cặp nhãn 5 SPL + 0 SPL. Một nhãn đơn không đủ xác định số đã ép: hiện `Ép ?/5 sao`. Sao đã ép/tổng ô phân biệt đặc/rỗng; chưa tự đoán màu loại SPL từ tổng chỉ số.
- Giữ popup vật phẩm, chi tiết đầy đủ, trạng thái nhiều người nhận cùng bot và thời gian nhận 30 phút của thay đổi trước.
- Giữ thương hiệu/bố cục riêng. VangHHP dùng pager mới riêng cho đồ tự động, không thay pager các dịch vụ khác.

## Kiểm thử đã thực hiện

- Backend: **70 tests, 1125 assertions** đều qua trên SQLite `:memory:`. Bao gồm snapshot, tồn/giữ đồ, mua/hoàn tiền, quyền/ownership, cùng bot, lọc, và kho ẩn vẫn nhận job giao đơn đã mua.
- Render component sao: **13 assertions** qua, gồm 0/5, 3/5, nhãn đảo thứ tự và dữ liệu thiếu; cả dạng compact/đầy đủ.
- TypeScript + production build: admin Vite và cả ba Next client đều qua.
- Browser Shophhp local: desktop 1600px có 4 cột; mobile 390px có 2 cột, thẻ 300px, không tràn ngang. Đã thử thu/mở lọc, đổi nhóm trên mobile, chọn Đá lục bảo ID 220 và popup vật phẩm.
- API local: lọc riêng 220/441/14, Găng type 2 và hút máu trả phản hồi hợp lệ. Không có gói demo hút máu thì trả 0 kết quả.
- Database MySQL local `newdb` đã chạy migration 000005 và 000006. Lô demo cũ giữ nguyên: 500 dòng vật phẩm/400 gói; không mua thật được.
- Chưa triển khai production, chưa chạy lại giao dịch game thật trong lượt này. Không thay binary QLTK ở bản bàn giao này; tiếp tục dùng bản shared-30m đã bàn giao trước.
- Vite còn cảnh báo kích thước chunk và dữ liệu Browserslist cũ, không làm build thất bại.

## Đưa lên production

1. Dùng `backend-overlay.zip` cho project Laravel hiện có. Gói có source PHP, routes, migration, `resources/nro`, source admin và toàn bộ `public/build` cùng manifest. **Không chỉ upload public/build**: catalog trong resources/nro không được Vite nhúng thay cho PHP.
2. Laravel yêu cầu PHP 8.2 trở lên theo composer.json. Sao lưu database trước triển khai. Chạy các migration NRO còn pending theo thứ tự; nếu đã dùng gói trước và đã có 000004 thì chỉ thêm 000005/000006:

   ```sh
   php artisan migrate --path=database/migrations/2026_09_10_000005_add_nro_extra_stat_filters.php --path=database/migrations/2026_09_10_000006_add_nro_shop_visibility.php --force
   php artisan optimize:clear
   ```

   Nếu chưa có 000004, chạy migration `2026_09_10_000004_add_nro_inventory_filters.php` trước. Hoàn tất migration trước khi mở bản backend mới cho snapshot/mua hàng. Chạy lại route/config cache theo quy trình hosting đang dùng sau khi clear.
3. Giải nén đúng `shophhp-v4-overlay.zip`, `123nick-v4-overlay.zip`, `vanghhp-v4-overlay.zip` vào từng client tương ứng. Giữ `.env` của từng website; kiểm tra URL API production rồi `npm run build`, khởi động lại dịch vụ Next theo quy trình đang dùng.
4. Các zip client chứa source, không chứa `.next` build local. Build local dùng môi trường local, không dùng để upload thẳng production. Không đưa lô demo hoặc database local lên production.
5. Kiểm tra nhanh bằng kho thử: ẩn → tìm/chi tiết không còn mở bán; đơn đã mua vẫn nhận được; hiện lại → chỉ gói active trở lại. Dùng tài khoản CTV kiểm tra chỉ thấy kho của mình.

`MANIFEST.json` ghi SHA-256 từng file trong từng zip; `SHA256SUMS.txt` dùng kiểm tra toàn bộ gói. Không có `.env`, mật khẩu, proxy, database dump hoặc node_modules trong gói.
