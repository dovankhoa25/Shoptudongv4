# Ghi chú phát triển: kho dùng chung cho nhiều phiên nhận

Trạng thái: đã kiểm tra tự động, kiểm tra game thật có giới hạn và build bản bàn giao ngày 10/09/2026. Chưa triển khai production. Kết quả và phần chưa kiểm chứng được ghi bên dưới.

## Quy tắc

- Một acc kho chỉ có một session game trong một tiến trình worker. Backend gắn các job vào UUID của lần chạy, không chỉ API key.
- Nhiều đơn có thể đăng ký chờ cùng kho. Mỗi thời điểm kho chỉ chuẩn bị tồn hoặc giao một lượt. Chuẩn bị acc nhận tự động không chiếm lượt giao của kho.
- Lời mời của nhân vật đã đăng ký được chọn khi bot rảnh; kiểm tra tên và ID nhân vật gần bot. Nếu chưa có lời mời thủ công, acc nhận tự động đã sẵn sàng được chọn theo thời điểm chờ.
- Hai đơn không cùng lúc nhận bằng một nhân vật trên cùng kho. Đơn thủ công cùng tên chờ đơn trước kết thúc, chưa chạy đồng hồ. Acc nhận hộ đang online không được tạo thêm phiên login trùng; nhận xong đơn trước mới bắt đầu đơn sau. Không tự gộp tiền hoặc đồ của hai đơn.
- Mỗi lượt đọc lại slot và số lượng. Giữ quyền thao tác đến khi kiểm tra chênh lệch đồ và backend nhận tiến độ/kết quả. Đồ trong rương được lấy theo nhu cầu khi còn chỗ trong túi.
- Snapshot cuối và snapshot giữa các lượt được gửi theo thứ tự thao tác; không để kết quả cũ ghi đè kho sau lượt mới.
- Đơn hoàn tất không còn chiếm luồng; session kho chỉ offline khi hết tham chiếu. Backend giữ quyền kho đến khi tool xác nhận đã ngắt session hoặc lease hết hạn.
- Cấu hình số acc kho đồng thời nhận số nguyên dương, bỏ trần 10. Các phiên nhận cùng kho không chiếm thêm một suất acc kho.

## Các tình huống

| Tình huống | Xử lý trong mã nguồn |
|---|---|
| Nhiều khách thủ công cùng kho | Chờ độc lập, chọn lời mời hợp lệ khi kho rảnh, một lượt giao mỗi lúc |
| Khách tự nhận và tool nhận hộ | Dùng cùng hàng chờ của kho, acc nhận hộ được đăng nhập/di chuyển riêng |
| Kho khác nhau | Xử lý độc lập theo số acc kho cấu hình |
| Hai worker cùng API key | UUID lần chạy khác nhau, không được cùng sở hữu kho đang được giữ |
| Khách không khóa trong 20 giây | Gửi hủy; chỉ tạm dừng an toàn khi game đóng lượt và tồn không đổi |
| Đã khóa, không xong trong 20 giây tiếp | Gửi hủy, kiểm tra tồn như trên |
| Quá giờ nhưng đồ đã chuyển đủ | Tiếp tục xác nhận tiến độ theo chênh lệch đồ, không hoàn tiền/giao lại mù quáng |
| Game chưa xác nhận hủy hoặc tồn lệch | Ngắt session kho, giữ đơn đối soát; không chuyển sang lượt mới với kết quả chưa rõ |
| Hủy đã xác nhận, không chuyển đồ | Tạm dừng phiên 5 phút, giữ đồ/tiền của đơn; khách phải chủ động nhận lại |
| Acc nhận đầy túi | Dừng riêng phiên nhận, giữ đồ để khách dọn túi rồi nhận lại |
| Acc nhận lỗi đăng nhập | Báo vai trò acc nhận và phân loại lỗi, không đánh dấu sai mật khẩu acc kho |
| Acc kho lỗi vĩnh viễn | Chặn đăng nhập kho theo bộ phân loại hiện có; cần sửa thông tin |
| Thiếu vật phẩm trước khi giao | Snapshot đầy đủ + kiểm tra backend rồi hoàn tiền; không kết luận từ snapshot thiếu phần |
| Mất mạng API/heartbeat | Hủy session liên quan; journal giữ kết quả. Không để session game sống quá quyền lease |
| Kết quả hủy gửi lại quá muộn | Giữ đối soát và nhận journal, không tự nhập snapshot cũ hoặc kẹt gửi mãi vì trạng thái đã đổi |
| Hết thời gian chờ khách | Đồ chưa giao vẫn được giữ; khách có thể yêu cầu nhận lại |
| Đăng gói từ stack 500, chọn 300 | Chỉ còn chọn 200; không xóa 300 vật phẩm thật trong game |
| Giá thay đổi khi có người mua | Backend khóa bản ghi và từ chối sửa nếu gói đã có đơn; giá đơn đã mua giữ nguyên |

## Admin và client

- Tab gói và modal gói theo kho có sửa giá, người đăng; vẫn dùng quyền và phạm vi chủ sở hữu hiện có.
- Bộ lọc tạo gói mặc định chỉ hiện đồ được phép bán và còn số lượng. Có bộ lọc đã đăng/giữ đơn và tất cả để tra cứu.
- Danh sách đơn có người mua, nhân vật nhận, acc kho, bot, người đăng, vị trí và số phiên chờ. Tìm theo mã, người mua/người đăng hoặc acc kho.
- Shophhp v4 hiển thị bot đang giao khách khác, thời hạn từng bước và thời gian chờ nhận lại. Public API không trả tên, tài khoản hoặc mã đơn của khách khác.

## Kiểm tra ngày 10/09/2026

- Backend: 62 tests, 1018 assertions, SQLite in-memory. Có kiểm tra cùng kho nhiều đơn, chặn worker khác, cooldown riêng khách, sửa giá/phạm vi CTV và không ghi đè tồn bằng progress cũ.
- Tool: build Release thành công; 84 checks nội bộ, gồm một session cho nhiều job, hủy waiter và lưu tài khoản DPAPI.
- TypeScript admin và shophhp: kiểm tra không lỗi. Đợt này chỉ chỉnh client shophhp; chưa port phần mới sang hai client còn lại.
- Hai tài khoản được cấp đã đăng nhập đồng thời qua proxy, lấy đủ túi/rương, tới map 5 và vị trí 285/288. Đã giao thật 1 vật phẩm ID 220 và trả lại: từng lượt đều xác nhận giảm 1 ở nguồn, tăng 1 ở bên nhận. Tồn cuối A=6157, B=3824, bằng trước test. Hai phiên game đã đóng. Đây là kiểm tra core với game thật; chưa phải kiểm thử tải nhiều đơn qua toàn bộ web/worker production.

## Vận hành bổ sung

- Đăng nhập lỗi [4] chờ 5 phút trước khi thử lại; [72-4] vẫn 60 phút. Các proxy cùng host dùng chung cửa sổ chờ trong worker; lỗi ngắn không ghi đè cửa sổ dài hơn.
- Không có thời gian chờ bắt buộc 90 giây sau login. Giao diện ghi chú game đôi lúc phản hồi chậm 1–2 phút.
- Acc nhận hộ: nếu game chưa mở giao dịch sau 8 giây và bot chưa accept, trả lượt kho để phục vụ khách khác, giữ hai phiên login rồi thử mời lại sau 15 giây. Không kéo dài hạn nhận ban đầu. Khi đã accept, kết quả không rõ vẫn phải đối soát; không thử lại mù quáng.
- Dữ liệu túi đầy đủ từ phiên đăng nhập và cập nhật slot được dùng để tránh chờ request lấy lại túi mà game không phản hồi. Sau giao vẫn phải xác nhận số lượng giảm/tăng đúng.
- Tên hiển thị trong map có thể có tiền tố bang hội [TAG]. Core loại tiền tố khi đối chiếu, vẫn bắt buộc đúng ID và tên nhân vật đầy đủ; có kiểm tra từ chối tên gần giống.
- Tab đơn admin tự cập nhật mỗi 5 giây khi trang đang hiện, hiện bước khóa/hoàn tất và hạn của bước.
- QLTK hiển thị chờ khách khóa / đã khóa / kiểm tra đồ; không báo thành công chỉ vì game đóng panel.
- Tài khoản kho/quét và tài khoản người vận hành tự lưu được mã hóa theo Windows user tại saved-accounts.bin. Acc nhận của khách chỉ dùng trong phiên. Đóng cửa sổ tài khoản phải ngắt phiên trước khi cho chạy worker.

## Còn cần kiểm chứng trực tiếp

1. Mất mạng API/kill process đúng các mốc lock, progress, completion; journal muộn và đổi API key.
2. Nhiều khách thủ công và auto cùng kho, nhiều kho; trùng tên, invitation spam.
3. Race hủy/hoàn tất tại mốc 20 giây trên game thật.
4. Túi đầy, thiếu đồ, nhiều lượt, trùng template khác options, stack giữa nhiều gói.
5. Bố cục trên thiết bị thật và thao tác admin thực tế.

Thời gian tạm dừng 5 phút áp dụng cho lượt giao bị hủy/quá hạn và game đã đóng, tồn không đổi. Không tự giao lại một lượt có kết quả chưa rõ.


## Bổ sung bàn giao: 30 phút, rương và khu nhận

- Mặc định chờ nhận 30 phút, tính từ khi bot báo sẵn sàng. Migration nâng cấu hình kho lên 30 và cộng phần thời gian thiếu vào các phiên đang mở; không đặt lại từ đầu mỗi lần gọi ready.
- Kho đi lấy đồ hoặc quay lại Kame: tạm dừng đồng hồ của mọi phiên đang chờ cùng kho. Chỉ cộng đúng thời gian chuẩn bị khi bot sẵn sàng lại. Đồng hồ khóa/hoàn tất giao dịch vẫn là 20 + 20 giây.
- Tool, admin và shophhp có trạng thái về nhà/lấy rương/đến điểm giao. Vị trí cũ bị ẩn trong lúc chuẩn bị. Khi đổi khu, mọi đơn chờ nhận cùng kho được cập nhật vị trí mới; không thay tên người nhận.
- Gộp nhu cầu rương của các job đang có tại tool, trừ phần đã giao, giữ phân biệt toàn bộ options. Chỉ lấy khi còn ô túi, không xóa hoặc vứt đồ trong game.
- Mở rương nhà bằng NPC 3 theo client gốc; xác nhận vị trí cạnh NPC và panel rương thường. Rương sưu tập tương lai vẫn không phải nguồn bán tự động.
- Game thật vừa đăng nhập có lúc bỏ qua lệnh lấy rương. Tool thử ngay; nếu rương trả lại dữ liệu mới và cả túi/rương đều chưa đổi, đợi 10 giây rồi thử lại trong giới hạn 2 phút. Không thử lại khi có chênh lệch chưa rõ. Không thêm thời gian chờ cố định sau login.
- Lỗi server chưa xác nhận vị trí cạnh NPC được thử lại tối đa 2 lần trên phiên hiện tại; không áp dụng cho sai mật khẩu, mất mạng hoặc lệch tồn kho.
- Bộ đếm QLTK giữ nguyên khi cập nhật trạng thái, tạm dừng/tiếp tục cùng bước đi rương. Acc nhận tự động kiểm tra lại khu mới trước khi mời bot.

### Kiểm chứng rương trên game thật

Đã dùng một tài khoản/proxy được cấp để mở rương nhà, rút 1 vật phẩm ID 343: rương -1, túi +1; cất trả: túi về 0, rương về 1 như ban đầu. Bản thử không chờ cố định đã gặp đúng nhánh chưa phản hồi, giữ phiên rồi thử lại thành công. Có một lần đường về bị server từ chối vị trí NPC sau khi đã trả đủ đồ; bổ sung retry vị trí có giới hạn và xác nhận lại tới map 5, khu 13, tọa độ 285/288. Tất cả phiên game test đã đóng; file tài khoản/proxy tạm đã xóa.

EXE tự chứa đã publish, self-check runtime/map và chạy/dừng worker bằng stdin thành công. Backend Vite build và shophhp Next production build đều thành công. Chưa kiểm thử tải đồng thời nhiều khách trên production, chưa giả lập đủ mọi điểm mất mạng quanh lúc commit giao dịch và chưa kiểm tra giao diện trên thiết bị thật. Không coi các kiểm tra đơn lẻ là bằng chứng đã phủ hết các tình huống đó.
