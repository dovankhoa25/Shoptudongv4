
Hàng đợi chỉ xét 50 job đầu
- nhận tất cả giao dịch miễn là đang có job nhận 
- các jobn cùng tài khoàn này thì tài khoản này cứ online chứ không được hoàn thành r tắt rồi mở lại để gd job khác 
- cứ gd hết all đơn mới off ( nếu không có đơn chờ )
- kể cả gửi acc thì vẫn tự onl acc khách rồi qua giao dịch luôn không cần ưu tiên ai 
- nhưng nếu khi qua giao dịch mà đang trong chế độ giao dịch thì đợi giao dịch xong rồi mời gd tiếp luôn 
-- case phụ : khi có nhiều job nhưng trong đó có 1 job ( có 5 món đồ )
-- mà bot không đủ để lấy đồ ra thì đợi job khác giao dịch và báo hành trang bot đầy đợi bot 
-- nhưng nếu đủ chỗ lấy đồ thì khi job đó mời gd ( không có trong hành trang thì chạy về nhà lấy ra)
-- phải có thông tin bot về lấy v.v 

Xử lý phiên hết hạn nằm trong API nhận job
-- nếu chờ đủ time mà user chưa qua nhận thì đóng phiên job của đơn đó lại 
- nếu còn đơn khác thì vẫn cứ onl đợi đơn khác 

Journal thử gửi lại mọi loại lỗi
- Phân biệt lỗi mạng, lỗi quyền, dữ liệu không hợp lệ và đơn đã đóng; cách ly đúng job/kho cần xử lý
- gửi rõ trạng thái ra cho cả back và fontend ...

Biết thiếu hàng nhưng vẫn cho nhận lại
- Khóa nhận lại đến khi kiểm tra đủ hàng hoặc admin xử lý
- thông báo đến cả backend và client là đơn của bạn bot thiếu đồ ...
- rồi client có thể yêu cầu hoàn tiền ( và api sẽ hoàn tiền )
- các đồ vừa rồi sẽ về lại kho chưa đăng bán ( và đơn kia sẽ là hoàn tiền kèm lí do thiếu đồ ...)

Kho bị chặn đăng nhập chưa được chặn mua đồng nhất
- phần này tôi không hiểu bạn nói gì 
- nhưng nếu là lỗi login thì báo client lẫn backend ( client thì k show chi tiết chỉ cần biết lí do )
- có thể yêu cầu hoàn tiền ( nếu chặn login v.v hoặc đợi cho client admin biết thời gian login được tiếp )
- nếu không phải các ý trên hãy nói lại phần này 

1. Còn một số điểm cần thống nhất trong từng chức năng

Đăng nhập, proxy
- proxy có đó có thể sử dụng lần lượt ( không cần chờ proxy vì 1 proxy có thể cho nhiều acc và tôi thêm nhiều prx)

Lấy snapshot
- Điều kiện “đủ dữ liệu” chưa đồng nhất: một số nhánh chỉ yêu cầu túi + rương, trong khi tồn kho còn tính trang bị đang mặc. Cần cùng một chuẩn trước khi cập nhật tồn bán được.

Tự thử lại 3 lượt
- Nhánh tự tạo job quét lại hiện áp dụng cho kho đồ. Acc bán nguyên nick chưa có cùng cơ chế này. Cũng cần phân biệt 3 lần đăng nhập trong một job với 3 job lấy dữ liệu thất bại để admin hiểu.
- ok có thể làm theo bạn nói tự động onl 3 lần thôi vẫn không được ngắt luồng 
- muốn tiếp tục thì tự thao tác tay lấy dữ liệu 

Tìm và lấy đồ
Đã tìm theo ID/chỉ số rồi dùng ô hiện tại. Nhưng lần thử lại bên trong thao tác lấy rương vẫn giữ ô cũ. Nếu dữ liệu đổi, nên tìm lại món tương đương, đồng thời kiểm tra lần trước đã chuyển đồ chưa để tránh lấy dư.
- ô đồ có thể thay đổi vì nếu có đơn mua thì sau khi giao dịch hành trang sẽ tự sắp xếp lại 
- chỉ cần check đồ theo chỉ số đã có và giao dịch là được lấy đồ cũng thế 

Nhiều khách cùng bot
- thực tế không job nào cần chờ job nào giống như các dòng đầu bên trên tôi miêu tả

Đối soát
- Kiểm tra tồn kho chỉ cho biết còn khả năng giao hay khôngCần đặt cạnh lịch sử từng lượt, journal và kết quả API; không suy ra “đã giao” chỉ vì món không còn trong kho.
- ok tôi nói rõ hơn đối soát chỉ để cho admin biết là lí do đơn đó chưa được user lấy và để lâu thế 
- thực tế khi mua thì giao dịch đi chắc chắn sẽ được update 
- còn rơi vào trường hợp đăng bán và khách mua khi khách nhận đồ bot onl thiếu đồ
- hoặc thiếu món ( thì đơn này sẽ gửi lên đúng thiếu đồ và có nút yêu cầu hoàn cho user )

Sửa acc đang lỗi
- acc bị chặn đăng nhập và còn job đối soát
-- thực tế nếu làm đúng như tôi thì không thể thiếu đồ mà cần đối soát luôn 
- khi khách mua mà acc không login được ( dựa vào các case login để biết login tiếp ...) là được
- như tool hiện tại cứ đối soát xong là như này Phiên giao bị gián đoạn khi kết quả chưa được xác nhận. Shop đang đối soát; bạn không cần mua hoặc thanh toán lại.
- tôi không rõ do đâu nhưng đồ vẫn còn ở acc bạn có thể nói tôi 

Hoàn tiền
- Cơ chế chống hoàn lặp đã có. Đơn giao một phần hiện cho admin nhập số hoàn, phần còn lại trả người bán và kết thúc đơn. Quy tắc này cần chốt rõ trong nghiệp vụ.
- hiện tại tôi cũng thấy các đơn bị chia ra giao dịch nhiều lần 
- nếu full hành trang có thể cất rồi lấy từng món rồi cất các món chưa giao dịch ra và khi đủ đến giao dịch cho khách 
- các job khác khi khách nhận mà đang full hành trang và job trên cũng đang chờ nếu món có sẵn ở hành trang thì ok gd bthg rồi 
- nhưng nếu là món vừa cất thì ( báo khách chờ ) , còn đối với item cả rương đều cất mà bán hành trang thì ai giao dịch trước sẽ nhận trước ( và khi không đủ số lương job tiếp theo thì chạy về lấy ra là được )

Client
- Chưa dùng đầy đủ thông tin cần hoàn tiền/số đã hoàn. Cập nhật phụ thuộc realtime; nếu realtime lỗi lâu, thiếu cơ chế tải lại dự phòng.
- có thể bổ sung cơ chế 

Admin/QLTK
- ok có thể hiển thị rõ ràng 

----
cover chung khi api bị timeout v.v thì chờ gửi lại ( gd thành công gửi thành công lên bị timeout v.v thì gửi lại tool phải lưu đã giao dịch )
- thống nhất thông tin realtime bắn - trạng thái đơn v.v 
- kể cả đang trong giao dịch phiên bị thu hồi thì vẫn giao dịch rồi gửi hoàn thành tránh lấy đồ xong không báo hoàn thành 

qltk - không cần hiện logger trên quản lí tài khoản 
config lưu api key không nhập lại mỗi lần mở 
danh sách acc trên qltk ( chỉ hiển thị khi acc đó đang on không on thì qltk không hiện gì )
bao gồm cả job đang chờ gd hiện đầy dủ ở qltk gd xong clear bỏ 
logger thì lưu vào file txt không cần lưu ở bên dưới như hiện tại muốn xem mở file log ra xem 

bỏ ô check giao dịch đồ mặc định tool sinh ra để làm thì bật tool là auto có tất 

sau tất cả chức năng tối ưu hệ thống không làm triền miên gây lag web v.v kể cả client lẫn backend đều không poling chỗ nào cần cache cứ cache 
tối ưu hệ thống mượt mà 



# Cập nhật luồng giao đồ NRO — 11/09/2026

Đã áp dụng mã nguồn vào backend `wegamenew-backend`, tool trong `NRO_NATIVE_SLEEP_247/qltk` và client `shophhp.net v4`. Chưa đồng bộ mã ứng dụng sang 123nick hoặc vanghhp.

## Quy tắc đã áp dụng

- Kho bị chặn đăng nhập vẫn cho mua nếu gói và tồn kho hợp lệ. Khi nhận, khách thấy lý do công khai và thời gian chờ; thông tin đăng nhập không đưa ra client.
- Lỗi đăng nhập tạm thời được chờ và thử lại theo thời gian game trả về. Lịch chờ theo đường kết nối được lưu qua lần tắt/bật tool. Mỗi yêu cầu quét có tối đa ba lần thử đăng nhập; khi thất bại phải bấm lấy dữ liệu lại, không tự tạo tiếp ba job mới.
- Đơn chưa giao món nào, gặp thiếu đồ hoặc lỗi đăng nhập, có thể yêu cầu hủy. Backend chờ phiên dừng an toàn rồi hoàn đủ tiền, xử lý lặp không hoàn thêm. Tool chỉ báo kết quả, không gọi luồng hoàn tiền.
- Đã giao một phần thì cả khách lẫn admin đều không được hoàn tiền. Giữ phần chưa giao, shop bổ sung đúng món/chỉ số và cập nhật kho để khách nhận tiếp.
- Hoàn tiền không tự đưa gói cũ lên bán lại. Số đồ được giải phóng về kho chưa đăng; cần cập nhật kho trước lần bán tiếp.
- Một acc kho dùng chung nhiều đơn, mỗi lượt giao/mở rương/di chuyển được tuần tự hóa. Nhận hộ và khách tự đến cùng chia lượt; không luôn ưu tiên một chế độ.
- Lấy đồ từ rương tìm lại theo ID, option/chỉ số và nội dung trên dữ liệu mới. Ô chỉ là vị trí hiện tại để gửi thao tác game. Đồ giống hệt được cộng số lượng hoặc chọn món tương đương.
- Bot chuẩn bị túi, có thể cất món chưa cần để lấy đồ cho đơn. Gói lớn được giao từng lượt; sau mỗi lượt xác nhận số đã nhận rồi tiếp tục phần còn lại. Khách tự đến cần mời lại khi bot trở về sau chuyến lấy đồ.
- Thời gian chờ nhận vẫn 30 phút, không tính thời gian bot đi lấy đồ; giới hạn khóa/xác nhận giao dịch vẫn 20 giây mỗi bước.
- Hết giờ chỉ kết thúc phiên nhận, không xóa đơn, không giải phóng phần đồ khách chưa nhận.
- Mất kết nối trước lượt giao có thể nối lại cùng phiên, giữ người nhận và thời hạn. Lượt đang giao chưa xác nhận giữ đối soát, không tự giao lại.
- Game đã xác nhận nhưng API lỗi: lưu journal bền vững và gửi lại kết quả. Lỗi từ chối kết quả được báo lên backend; không biến thành giao lại hay tự hoàn tiền.

## Admin, client và QLTK

- Đơn giao đồ có kiểm tra kho/đối soát và nút hoàn tiền theo điều kiện an toàn. Đơn đã nhận một phần hiển thị cần bổ sung để giao đủ.
- Có thể sửa mật khẩu kho còn đơn đối soát khi phiên cũ đã hết quyền giữ và người vận hành xác nhận đã dừng game cũ. Việc sửa mật khẩu không tự chốt kết quả hoặc di chuyển tiền.
- Client Shophhp hiển thị chờ đăng nhập, lý do lỗi, yêu cầu hủy, phần đã nhận và trạng thái bot. Điểm nhận cũ bị ẩn khi chưa xác nhận bot còn online.
- Realtime làm mới khi có thay đổi, kết nối lại hoặc quay về trang. Có tín hiệu gia hạn online từ backend khi bot chờ lâu; trình duyệt không chạy vòng gọi HTTP định kỳ. Bộ đếm giây chỉ cập nhật giao diện cục bộ.
- QLTK: một acc online một dòng, bên dưới là từng phiên đang chờ/đang giao. Hoàn tất thì gỡ phiên, không tích thêm dòng acc sau mỗi lần đăng nhập lại.
- Lưu API key theo cấu hình máy vận hành; luôn bật quét và giao đồ, bỏ checkbox chế độ chỉ quét. Có nút mở thư mục log; nhật ký TXT nằm trong `history`.

## Kết quả kiểm tra

- Laravel: **101 test NRO đạt, 1.416 assertions**, dùng database kiểm thử SQLite, không chạy trên DB production.
- Tool: **99 kiểm tra offline đạt**, gồm nhận diện món, journal, quản lý phiên, cooldown đăng nhập và lịch sử TXT.
- QLTK biên dịch thành công: **0 lỗi, 0 cảnh báo**; đã kết xuất và xem bản preview với dữ liệu giả.
- TypeScript admin và Shophhp đạt.
- Vite admin build thành công. Có cảnh báo bundle lớn hơn 500 kB.
- Next.js Shophhp build thành công. Lúc build, API cấu hình cho sitemap không kết nối được (`ECONNREFUSED`), nên chưa xác minh sitemap động đầy đủ.

Các kiểm tra trên chưa chứng minh giao dịch game thật hoặc hoạt động trên hosting production. Trong lượt này không đăng nhập các acc thật, không tạo giao dịch thật, không chạy migration production và không triển khai hosting. Bản EXE debug dùng kiểm tra không phải gói phát hành đã bảo vệ.

## Khi đưa lên hosting

1. Dừng tool cũ, giữ nguyên `journal`, `history`, cấu hình và dữ liệu tài khoản đã lưu.
2. Upload đầy đủ PHP backend và migrations đang có trong checkout, không chỉ upload thư mục build. Bản này thêm `2026_09_11_000003_add_nro_recovery_state.php`; các migration NRO trước đó cũng phải có.
3. Tại thư mục backend chạy:

   ```bash
   composer dump-autoload -o
   php artisan migrate --force
   php artisan optimize:clear
   ```

4. Đảm bảo Laravel scheduler đang chạy mỗi phút. Lệnh mới `php artisan nro:recover-receipts` xử lý phiên hết hạn ngay cả khi tool tắt; đã được khai báo trong `routes/console.php`. Không dùng lệnh này để ép đơn đối soát thành đã giao.
5. Upload assets admin đã build vào `public/build` kèm `manifest.json`. Assets xác nhận build nằm tại `admin-build` cạnh báo cáo; không ghi đè file `public/build.zip` cũ của bạn.
6. Deploy client Shophhp và chạy tool bản mới sau backend. Giữ realtime hoạt động để khách nhận cập nhật. Nếu hosting dùng tiến trình PHP/queue chạy lâu, khởi động lại tiến trình đó theo cấu hình triển khai hiện có.
7. Với đơn cũ đang đối soát: gửi lại journal nếu có; nếu chưa có bằng chứng thì dùng kiểm tra kho và đối chiếu lịch sử trước khi chốt. Chỉ nhìn thấy thiếu đồ trong kho không đủ kết luận khách đã nhận.

## Tệp kiểm tra

- `backend-test.txt`: kết quả kiểm thử Laravel.
- `admin-build.txt`, `client-build.txt`: kết quả build.
- `qltk-preview.png`: preview QLTK bằng dữ liệu giả.
- `synced.json`: danh sách mã nguồn đã đồng bộ trong lần làm này.
- `before/`: bản sao trước sửa của các tệp đã thay đổi; không tự ghi đè các thay đổi riêng khác trong checkout.
