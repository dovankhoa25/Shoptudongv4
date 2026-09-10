
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

