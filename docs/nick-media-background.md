# Đăng nick với xử lý ảnh nền

Luồng mới độc lập với `NickController::store()`. Đường đăng cũ
`/admin/games/accounts/create` và `POST /admin/games/accounts` được giữ nguyên.
Vào **Quản lý Nick → Đăng nick · Xử lý ảnh nền** để dùng luồng mới.

## Cách hoạt động

- Ảnh từ máy: tải ngay khi chọn, tối đa 3 file đồng thời, mỗi ảnh 5 MB và mỗi nick 20 ảnh.
  Ảnh tạm nằm tại `storage/app/private/nick-staging`, gắn với người đăng.
  Server giữ chỗ trong hạn mức 200 ảnh tạm bằng transaction ngắn, sau đó ghi file ngoài
  transaction. Upload đồng thời không phải chờ nhau ghi file trong lúc khóa tài khoản.
  Ảnh chỉ được gắn vào bản đăng sau khi ghi hoàn tất; ghi lỗi sẽ thu hồi chỗ và file dở.
  Chờ upload hoàn tất trước khi lưu/rời trang; worker không thể đọc file còn nằm trong trình duyệt.
- URL: request chỉ lưu danh sách. Worker dùng `SafeImageDownloader` hiện có để kiểm tra
  địa chỉ công khai, redirect, MIME, dung lượng và tải ảnh. Giới hạn 20 URL.
- Thông tin bản đăng được mã hóa ở `nick_publications.payload`. Redis chỉ chứa job với ID file.
- Bản chờ nằm riêng, chưa có trong bảng `nicks`. Khi mọi ảnh sẵn sàng, quyền đăng/thuộc tính/
  trùng tài khoản được kiểm tra lại, rồi nick, thuộc tính và liên kết media được công bố trong
  một transaction ngắn. Ảnh đại diện luôn là ảnh đầu tiên đã chọn, không phụ thuộc thứ tự tải xong.
- Mỗi ảnh tự thử tối đa 3 lượt. Trang **Theo dõi đăng nick** cho phép thử lại ảnh lỗi hoặc hủy.
  Job bị hosting ngắt được thu hồi sau 3 phút; request gửi lại cùng ID không tạo thêm nick.
- Bản ghi SQL là nguồn khôi phục nếu Redis mất job. Ảnh tạm chưa dùng hết hạn sau 24 giờ;
  dữ liệu của bản chờ/lỗi được giữ để thử lại. Hủy bản đăng sẽ xóa ảnh tạm và thông tin mật khẩu.
  Khi hoàn tất, mật khẩu chỉ còn trong nick theo cơ chế mã hóa đang dùng.

## Tối ưu xử lý nhiều ảnh

- Danh sách URL được ghi bằng một câu INSERT. Việc nhận các job cần gửi được gộp trong
  một transaction ngắn; đẩy job sang Redis sau khi nhả khóa SQL. Redis lỗi giữa chừng chỉ
  giải phóng những job chưa gửi thành công để Cron thử lại.
- Mỗi ảnh vẫn có job riêng để thử lại độc lập. Sau một ảnh thành công, chỉ kiểm tra còn
  ảnh chưa xong hay không bằng truy vấn có index; không tải toàn bộ ảnh và quét gửi lại
  job của bản đăng. Ảnh lỗi chỉ gửi lại chính nó, có thời gian chờ giữa các lần thử.
- Khi đủ ảnh, kiểm tra lại trong transaction rồi ghi thuộc tính theo lô và chuyển toàn bộ
  media sang nick bằng một UPDATE. Dữ liệu ảnh vẫn là từng file, không gộp nội dung ảnh
  vào một truy vấn SQL. Cron vẫn khôi phục các job bị mất hoặc gián đoạn.
- Trang tiến độ lấy số lượng theo trạng thái và chi tiết ảnh lỗi; không tải dữ liệu của
  mọi ảnh hay trường chứa mật khẩu để tính tiến độ.

Đo trước/sau với cùng fixture trong `NickPublicationTest::test_image_processing_query_budget`:

| Ảnh URL | Nhận bản đăng: trước → sau | Xử lý và mở bán: trước → sau | Tổng: trước → sau |
| --- | --- | --- | --- |
| 10 | 47 → 16 | 149 → 104 | 196 → 120 |
| 50 | 167 → 16 | 709 → 464 | 876 → 480 |

Đây là **số truy vấn SQL** khi gọi service trên SQLite in-memory, với ảnh PNG giả lập,
mock tải URL và queue giả lập. Không tính setup fixture, HTTP/controller, Redis thật,
polling hoặc lượt dọn dẹp của Cron; không phải số đo thời gian trên production.
Fixture 50 ảnh gọi service trực tiếp để đo chi phí xử lý; biểu mẫu và API hiện giới hạn 20 ảnh/nick.
Chạy lại phép đo sau tối ưu trong PowerShell (PHP CLI cần có trong PATH):

```powershell
$env:NICK_MEDIA_BENCHMARK = '1'
php vendor/bin/phpunit tests/Feature/NickPublicationTest.php --filter test_image_processing_query_budget
Remove-Item Env:NICK_MEDIA_BENCHMARK
```

## Triển khai trên hosting có Cron và Redis

1. Upload source, cài dependencies từ lock file nếu môi trường chưa có, build frontend bằng
   `npm run build` và upload `public/build` cùng bản source tương ứng.
2. Chạy migration mới (sao lưu DB theo quy trình triển khai của shop):

   ```sh
   php artisan migrate --path=database/migrations/2026_09_25_000001_create_nick_publications.php --force
   php artisan migrate --path=database/migrations/2026_09_26_000001_index_nick_media_file_progress.php --force
   php artisan optimize:clear
   php artisan config:cache
   ```

   Nếu đã triển khai luồng nền trước đó, vẫn cần migration ngày 26/09 để thêm index
   `(publication_id, status, position)` phục vụ kiểm tra hoàn tất và đếm tiến độ.

3. Dùng cấu hình Redis hiện tại của Laravel. Queue connection mới có tên `nick-media`,
   driver Redis, Redis connection lấy từ `REDIS_QUEUE_CONNECTION` (mặc định `default`).
   Khóa Cron dùng cache store `redis`; kiểm tra các Redis connection `default` và `cache`
   đều kết nối được. Không cần đổi `QUEUE_CONNECTION` hoặc `CACHE_STORE` của toàn ứng dụng.
4. Đặt Cron mỗi phút, thay **cả đường PHP CLI lẫn thư mục dự án** bằng đường thật của hosting:

   ```cron
   * * * * * cd /home/USER/APP && /PATH/TO/php artisan nick-media:run --slot=1 >> storage/logs/nick-media-cron.log 2>&1
   ```

   Lệnh có khóa Redis chống chồng lượt. Nếu hosting cho phép, thêm một Cron giống trên với
   `--slot=2` để xử lý 2 ảnh đồng thời; tối đa 3 slot. Mỗi slot lấy việc trong khoảng 50 giây,
   nhưng ảnh đang chạy được phép hoàn tất, nên cả lượt có thể dài hơn 1 phút. Timeout mỗi job
   là 90 giây; Redis `retry_after` là 180 giây; khóa Cron hết hạn sau 240 giây nếu process chết.
   PHP CLI Linux cần extension `pcntl` cho timeout của Laravel worker, cùng `curl`, `fileinfo`
   và client Redis đang dùng. Hosting phải cho phép PHP CLI chạy đủ lâu cho các giới hạn này.
   Không dùng HTTP request/URL Cron để thay cho worker PHP CLI.

Không cần Supervisor hay thêm lịch `schedule:run` riêng cho chức năng này; `nick-media:run`
tự khôi phục job, dọn ảnh tạm hết hạn rồi gọi worker. Nếu Cron dừng, bản đăng tiếp tục ở trạng thái chờ.
File web và worker phải dùng cùng storage, APP_KEY, cấu hình account key và cấu hình media.
Luồng công bố kiểm tra đường media không đổi khi chuyển chủ sở hữu; nếu dùng custom path generator
phụ thuộc model, cần điều chỉnh trước khi sử dụng luồng mới.

## Kiểm tra sau triển khai

- Đăng thử một nick bằng 2–3 file, một nick bằng URL. Theo dõi tiến độ và kiểm tra đúng ảnh đại diện.
- Trong lúc chờ, nick chưa xuất hiện và chưa thể mua. Hoàn tất phải có đủ ảnh trong trang chi tiết.
- Thử URL lỗi: có báo lỗi và thử lại, không tạo nick thiếu ảnh. Hủy một bản chờ rồi đăng lại.
- Mở trang cũ và đăng thử để xác nhận đường dự phòng vẫn hoạt động.
- Kiểm tra Cron log và Laravel log. Luân chuyển log Cron theo thiết lập hosting để tránh file log lớn dần.

Nếu cần quay lại cách cũ, chọn **Dùng cách đăng cũ** trước khi gửi bản đăng. Với bản đã nhận,
hãy kiểm tra danh sách xử lý và hủy bản chờ trước khi đăng lại bằng cách cũ. Không gửi lại bằng cách
cũ ngay khi mất phản hồi mạng, vì bản mới có thể đã được lưu.

Thay đổi này áp dụng cho **đăng nick mới**. Luồng sửa nick và thay bộ ảnh của nick đang tồn tại
vẫn dùng hàm cũ. Chưa thay đổi ba storefront hoặc chạy migration trên production.
