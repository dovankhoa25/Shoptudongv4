# NRO shop — phiên nhận đồ và QLTK (08/09/2026)

Phạm vi: backend wegamenew, shophhp.net v4 và tool QLTK. Chưa sao chép sang 123nick.com v4.

## Luồng hoạt động

1. Admin/CTV có quyền thêm acc chọn riêng server hiển thị từ servers và server đăng nhập từ server_game_login. Tool nhận IP/port từ bản ghi server_game_login. Không suy ra ID bằng serverIndex. Với acc cũ, admin mở Cấu hình để gán hai server trước khi quét/bán.
2. Bấm Lấy dữ liệu. Worker đăng nhập, gửi snapshot JSON đã lọc dữ liệu công khai. Kho lưu template + toàn bộ options + info/content, không dùng vị trí ô làm ID. Ảnh lấy từ public/images/nro.
3. Nick: đăng bằng ảnh như cũ hoặc bằng snapshot. Gắn vào nick cũ cần quyền quản lý nick. Đồ: chọn nhiều món và số lượng trên cùng acc kho, đăng một gói với giá toàn bộ gói.
4. Khách mua gói: trừ tiền một lần và giữ toàn bộ món trong transaction. Đơn ở awaiting_receipt, chưa tạo job giao.
5. Khách bấm Nhận đồ, chọn tự đến bằng tên nhân vật hoặc nhập acc/mật khẩu để tool nhận hộ. Mỗi yêu cầu có UUID; gửi lại không tạo phiên trùng. Chỉ một phiên chưa kết thúc trên mỗi đơn. Acc nhận tự động bị khóa sử dụng đồng thời giữa các phiên.
6. Tool nhận job, đăng nhập nguồn bằng IP/port cấu hình, tháo đồ đang mặc/lấy rương thường, chuẩn bị vật phẩm. Với nhận hộ, tool đăng nhập thêm acc nhận và đưa đến cùng map/khu.
7. Tool báo sẵn sàng cùng tên, ID nhân vật, map/khu. Backend mới bắt đầu đếm mặc định 10 phút; admin đổi 1–60 phút cho từng kho. Các lượt tiếp theo không gia hạn mốc này.
8. Giao đúng tên/ID nhân vật; đối chiếu toàn bộ chỉ số và số lượng. Nhận hộ kiểm tra lượng đồ tăng ở acc nhận và giảm ở acc giao. Có thể giao nhiều lượt, mỗi lượt tối đa 20 ô.
9. Tiến độ mỗi dòng được ghi tăng dần; chỉ giảm reservation theo số đã giao. Giao đủ mới cộng tiền CTV một lần. Hết thời gian chờ lời mời: đóng cả acc, đơn quay về awaiting_receipt; phần chưa giao giữ vô thời hạn. Nhận lại chỉ giao quantity - delivered, không thu thêm tiền.

## Mất kết nối và đối soát

Tool ghi journal trước giao. Khi chạy lại chỉ báo kết quả, không tự phát lại giao dịch. Mất heartbeat quá 3 phút được chuyển review khi worker gọi claim tiếp theo. Trong lúc toàn bộ tool offline, trạng thái web có thể chưa cập nhật sang review cho đến lần claim tiếp theo.

Lỗi đăng nhập, chuẩn bị, lỗi giao hoặc kết quả chưa chắc chắn của đơn giao chuyển review. Người có quyền đối soát phải dừng tool, chờ lease hết, kiểm tra lịch sử và nhập tổng đã giao của từng dòng. Chọn đã giao đủ để hoàn tất, hoặc cho nhận phần còn lại. Không tự hoàn tiền hay nhả món chưa nhận. Thao tác đối soát lưu người làm và ghi chú. Nếu journal của job đã bị đối soát bị từ chối, đối chiếu ID rồi chuyển file đó ra ngoài thư mục journal; không chạy lại giao dịch.

Mật khẩu acc nhận mã hóa bằng Laravel Crypt, chỉ trả trong claim đã xác thực và no-store. Xóa khỏi phiên khi hoàn tất/hết giờ/chuyển review; khi review vẫn giữ khóa acc nhận tới khi đối soát. Không đưa mật khẩu vào dữ liệu public, dữ liệu CTV, log hoặc journal.

## Phân quyền

| Quyền | Tác dụng |
| --- | --- |
| nicks.view | Danh sách/chi tiết nick trong phạm vi sở hữu |
| nicks.create | Đăng nick ảnh hoặc snapshot |
| nicks.manage | Quyền quản lý nick cũ, bao gồm sửa/xóa; vẫn tương thích |
| nro-accounts.view | Xem acc và snapshot |
| nro-accounts.manage | Thêm acc, cập nhật đăng nhập, quét dữ liệu |
| item-listings.view | Xem gói đồ |
| item-listings.manage | Đăng/tạm dừng gói từ kho thuộc mình |
| item-orders.view | Xem đơn giao đồ thuộc mình |
| item-orders.reconcile | Đối soát trong phạm vi được phép |
| nro-workers.manage | Cấp/thu hồi API key máy; key có quyền đăng nhập game |
| nro-settings.manage | Chọn hai server, map/khu và số phút chờ |

CTV không tự nhận quyền mới. Cấp riêng trong Quyền người dùng hoặc Vai trò. CTV chỉ được danh sách nick thì cấp nicks.view; chỉ đăng thì nicks.create; đăng gói cần item-listings.manage; xem đơn cần item-orders.view. Nếu CTV tự thêm/quét acc, cấp thêm nro-accounts.manage. Muốn tách quyền đăng khỏi sửa/xóa, bỏ nicks.manage cũ. Admin/super-admin hiện có được bổ sung các quyền mới bằng migration; admin được tạo sau migration cần chạy seeder hoặc được cấp quyền như hệ thống hiện tại.

## API và dữ liệu

Migration `2026_09_08_000011_track_nro_trade_in_flight.php` đã chạy local. Phiên mới ghi trade_in_flight=false; begin-round ghi true trước khi tool chấp nhận giao dịch; progress có tiến độ mới và đủ danh sách món mới xóa dấu này. Dừng/lỗi/lease hết hạn ngoài lượt giao chưa xác nhận đưa đơn về awaiting_receipt, giữ tiền/đồ, xóa thông tin nhận hộ và cho khách bấm nhận lại. Không tự mở lại khi đang có lượt chưa xác nhận, đã giao đủ chờ kết toán, hoặc phiên cũ không có dấu theo dõi. Progress gửi lại từ lượt trước không được xóa dấu của lượt sau. Không cần đổi EXE Kame: quyết định nhận lại nằm ở BE dựa trên begin-round/progress hiện có.

DTO gói đồ có stockAvailable (số gói từ tồn chưa giữ cho đơn), available (số đang mua được), unavailableReasons (lý do như cần đồng bộ, tool offline, kho đang xử lý/đối soát, đồ giữ cho đơn). BE và shop hiển thị lý do; không coi mọi available=0 là hết đồ. Đơn local #1 đã được mở lại theo xác nhận trực tiếp của người dùng rằng chưa giao dịch: giữ nguyên thanh toán và 1 vật phẩm dành cho đơn, ghi nguồn xác nhận trong result_json của job #5.

Điểm giao cấu hình riêng từng acc: mặc định map 5 (Đảo Kame), delivery_zone_mode=auto chọn khu 4–15 theo công suất, hoặc fixed dùng delivery_zone. Khu cố định mặc định 4. Migration `2026_09_08_000010_default_nro_delivery_to_kame.php` đã chạy local: đổi map cũ 24 thành 5, khu cũ 0 thành 4 cho acc NRO; giữ map/khu tùy chỉnh khác và không sửa vị trí, thời gian hay trạng thái các phiên cũ. Khi triển khai chạy riêng migration này cùng code. API claim nhận protocolVersion=2,3; delivery auto chỉ được cấp cho version 3. Tool mới gửi đúng vị trí thực tế lên ready, web dùng vị trí này khi bot sẵn sàng. Trước đó web hiện điểm nhận dự kiến từ cấu hình acc.

Tạo gói đồ: phần snapshot chỉ xem chỉ số; bảng bên dưới dùng để tích nhiều món và nhập số lượng, sau đó bấm Tạo gói để đặt tên, mô tả và giá chung. Nếu snapshot chưa xác nhận đủ bag/chest, BE hiện cảnh báo và nút yêu cầu lấy lại dữ liệu/tải lại danh sách, đồng thời chặn tạo gói cả ở API. Các món trong snapshot chưa đầy đủ không tự được coi là tồn kho bán; tồn cũ vẫn giữ để không mất dữ liệu và lượng đang giữ cho đơn.

Thêm acc kiểm tra trùng username (bỏ khoảng trắng đầu/cuối) và server đăng nhập trên toàn hệ thống. Bản ghi chưa bán, bị ngừng hoặc đã xóa mềm nhưng chưa bán vẫn chặn đăng ký trùng. Bản ghi có status=sold cho phép tạo acc mới với ID mới, mật khẩu mới và chưa có snapshot; tin bán, snapshot, đơn và tồn kho của lần cũ không chuyển sang lần mới. Lần nhập lại phải lấy dữ liệu rồi đăng một tin mới. Thêm acc và đổi server cùng khóa dòng server đăng nhập trong transaction để ngăn hai lần đăng ký trùng đồng thời.

Migration `2026_09_08_000009_allow_nro_account_repurchase.php` thay unique vĩnh viễn account_name/server bằng index tra cứu account_name/server_game_id/status. Local đã chạy riêng migration này. Khi triển khai chạy `php artisan migrate --path=database/migrations/2026_09_08_000009_allow_nro_account_repurchase.php --force` cùng code mới. Rollback từ chối nếu đã có lịch sử nhập lại trùng username/server, không xóa lịch sử để ép phục hồi unique cũ.

BE dùng bố cục snapshot gọn giống shop: hai bảng sư phụ/đệ tử cạnh nhau, kỹ năng có icon và các tab đồ có tìm kiếm. Form thêm acc chia hai cột trên desktop, một cột trên điện thoại; ô tài khoản và mật khẩu dùng cùng màu giao diện.

Tab Acc game hiển thị tin liên kết (mã nick, danh mục, giá, trạng thái), phân biệt chưa quét/chưa đăng/đang bán/đã bán và báo khi có snapshot mới chưa cập nhật vào tin. Sửa tin bán tải lại dữ liệu từ backend rồi điền sẵn mã nick, danh mục, giá, mô tả và thuộc tính; quyền nicks.create không cho sửa tin cũ. Acc đã bán bị chặn quét lại, đăng lại, đổi mật khẩu và cấu hình. Quản lý acc gom Lấy dữ liệu/Sửa mật khẩu/Cấu hình vào menu; giá và mô tả nhập ở bước Đăng bán.

Acc kho hiện tổng số gói và số gói có trạng thái active (không đồng nghĩa luôn đủ tồn hoặc có tool online). Bấm số gói mở GET /admin/nro-shop/accounts/{id}/listings, phân trang 20 gói và kiểm tra quyền item-listings.view/manage cùng phạm vi chủ acc. NRO_SHOP_FRONTEND_URL cấu hình đích của liên kết Xem tin trên shop: local mặc định http://localhost:3000, môi trường khác cần đặt URL shop thật. Backend hiện chưa phân phối danh mục theo từng website; URL này chỉ là đích xem tin, không chứng nhận tin được đăng độc quyền ở website đó.

Khi đăng nick bằng snapshot, chọn danh mục để tải thuộc tính được phép qua GET /admin/nro-shop/accounts/{id}/nick-attributes?categoryId=... (nickId tùy chọn để giữ giá trị tin đã đăng). Hành tinh được đối chiếu từ character.gender; Server/Sever dùng tên của bảng servers theo server_id, không dùng thứ tự đăng nhập; cải trang đối chiếu tên đầy đủ của món type 5 trong snapshot với lựa chọn đang bật. Chỉ gợi ý khi khớp duy nhất, nhiều cải trang cần CTV chọn một giá trị. Không suy đoán loại đăng ký Gmail/ảo hoặc tự tạo lựa chọn mới. Các chỉ số và toàn bộ đồ vẫn nằm trong snapshot để xem chi tiết.

Form cho CTV kiểm tra, sửa và bỏ chọn rồi gửi attributeSelections (map attributeId → optionId/null) và snapshotId cùng POST .../nick. Backend kiểm tra quyền danh mục, chủ nick, lựa chọn thuộc đúng thuộc tính và snapshot chưa đổi; lưu nick_attributes và attribute_cache_json trong cùng transaction. Bộ lọc attr_{id} hiện có dùng được ngay, không thêm migration. Nếu API cũ không gửi attributeSelections, giữ thuộc tính hợp lệ của nick đang liên kết và bổ sung gợi ý cho mục chưa có; gửi map rỗng để xóa toàn bộ. Nick đã đăng trước cập nhật cần mở lại form, nhập mã nick và lưu để bổ sung bộ lọc; không tự ghi đè hàng loạt thông tin CTV.

- Public GET /api/nro-shop/listings (kèm danh sách servers), /listings/{id}.
- Khách đã đăng nhập GET/POST /api/nro-shop/orders, POST /orders/{id}/receive. Mua dùng serverId; recipientName có thể để trống, chọn tại lúc nhận.
- Worker Bearer key: GET /app/nro-worker/accounts (tối đa 500 acc, không mật khẩu); POST /claim với protocolVersion=2 và types; POST /jobs/{id}/heartbeat, /ready, /begin-round, /progress, /complete.
- Bảng bổ sung: nro_delivery_sessions, worker_jobs.delivery_session_id, item_orders.server_id, cấu hình server/map/khu/wait_minutes trên nro_accounts.
- server_game_id là tham chiếu logic có kiểm tra exists. Bảng server_game_login cũ dùng signed integer không khai báo primary key trong migration gốc; không sửa cấu trúc bảng đó.
- Snapshot thiếu túi/rương không xóa tồn cũ nhưng chặn mua. Snapshot đầy đủ không hết hạn theo thời gian; vẫn chặn mua nếu không có worker giao online. Kho chưa xác nhận tồn (last_synced_at=null) được tool tự lấy lại, không quét định kỳ chỉ vì dữ liệu cũ.

## Cài local / triển khai sau

Local đã chạy riêng migration 000001, 000006, 000007 và 000008 ngày 2026_09_08. Không chạy các migration khác ngoài phạm vi. Máy mới phải chạy bốn migration này theo thứ tự và bảo toàn APP_KEY vì mật khẩu đã mã hóa phụ thuộc khóa đó.

Mở qltk/NroShopDesktop/release/QLTK-Shop.exe (một EXE tự chứa runtime/core/map), nhập URL backend và API key, chọn số luồng. Bật giao đồ để nhận cả snapshot và đơn. Danh sách acc có nút tải; bảng hiển thị từng job và hai vai trò acc giao/nhận. Dừng gửi lệnh ngắt an toàn; chờ worker đóng các session và gửi kết quả.

## Đã kiểm tra / còn xác minh

Ảnh đại diện của thẻ nick có chiều cao cố định 176px cho cả ảnh và snapshot. Snapshot dùng chữ nhỏ, ba dòng icon Mặc/Túi/Rương, tối đa 6 icon mỗi dòng và +N ô đồ còn lại; nút Xem đồ mở dữ liệu đầy đủ. Summary bổ sung itemPreviews (tên, iconId, templateId, số lượng và tổng số ô theo vị trí), không tải toàn bộ option trong danh sách. Chạy lại `php artisan nro:refresh-card-summaries` để cập nhật snapshot cũ đã có disciple nhưng chưa có itemPreviews. Sau triển khai cần làm mới cache danh mục của frontend hoặc chờ chu kỳ revalidate 120 giây.

Shophhp v4 hiển thị thông số sư phụ và đệ tử trong hai bảng nhỏ cạnh nhau từ màn hình 480px; màn hình hẹp hơn xếp dọc. Tab Đệ tử chỉ hiển thị kỹ năng và trang bị để tránh lặp thông số. Thẻ nick không có ảnh dùng tên nhân vật, server/hành tinh, sức mạnh sư phụ/đệ tử và icon đang mặc làm phần đại diện. Chạy `php artisan nro:refresh-card-summaries` khi triển khai để bổ sung tóm tắt đệ tử từ snapshot đã lưu; lệnh không đăng nhập game, không sửa dữ liệu snapshot gốc và bỏ qua bản ghi đã có trường disciple. Local đã cập nhật 2 bản ghi.

Shophhp v4: nick có nro_summary mở modal xem nhanh từ nút kính lúp/Xem đồ, tên nick hoặc nút Xem; dữ liệu đầy đủ chỉ tải khi mở modal. Nick bằng ảnh vẫn phóng to ảnh. Trang chi tiết nick snapshot có bảng thông số và các tab bên trái, giá/thuộc tính/nút mua bên phải, ảnh bổ sung có thể mở riêng. Thông số sư phụ/đệ tử hiển thị theo dòng; kỹ năng dùng iconId thật trong gói catalog kỹ năng, không dùng skillId làm ID ảnh.

Tool mới giữ iconId trong SkillSnapshot và backend cho phép trường này qua bản chiếu dữ liệu công khai. Snapshot cũ chưa có iconId cần Lấy dữ liệu bằng EXE mới, sau đó Sửa tin bán → Lưu thay đổi để gắn snapshot mới cho tin đang bán. Bản EXE cập nhật lần này nằm ở qltk/NroShopDesktop/release/QLTK-Shop-skills.exe vì QLTK-Shop.exe hiện tại đang được sử dụng; không dừng tiến trình của người dùng để ghi đè.

Build backend và shop, build QLTK/worker, test backend về tiền/tồn kho/idempotency/phân quyền/phiên hết giờ/nhận lại/mật khẩu và test core offline đều chạy được. Chưa đăng nhập hay giao trên server game thật; cần acc test để xác minh đường đi, điều kiện giao dịch của món và việc giao nhiều lượt. Đây là giới hạn xác minh thực tế, không coi build/test offline là đã giao thành công trong game.

## Cập nhật route và bản EXE

Route shop/admin đăng ký trực tiếp trong bootstrap/app.php; không require file NRO trong routes/api.php hoặc routes/web.php nữa. Toàn bộ route tool nằm trong routes/app.php, prefix /app/nro-worker, dùng NroWorkerKey. URL /api/nro-worker đã bỏ. Nhóm app/v1 và app/v2 vẫn dùng middleware app/X-APP-KEY.

Tool có giao diện tối theo QLTK hiện có, bảng acc/job và log theo dòng. Proxy lấy từ worker.json và file proxies.txt; khi lỗi kết nối ban đầu, đóng socket rồi thử fallbackProxy một lần. fallbackProxy trống dùng IP máy. Không tự đổi proxy trong lúc giao dịch hoặc khi sai mật khẩu. Config và journal được tạo cạnh EXE; không cần phân phối DLL/map/.NET riêng. Hướng dẫn chi tiết và script build trong qltk/NroShopDesktop/README.md.

## Tồn kho, gói đã mua và thiếu đồ (08/09/2026)

Tool `QLTK-Shop-stock.exe` công nhận các mảng tồn kho đầy đủ trong gói đăng nhập CMD -30/sub 0; server bỏ qua yêu cầu đọc lại rương không làm mất xác nhận này. Sau khi thay EXE, lấy snapshot mới để thay các bản cũ có completeness.chest=false. Không sửa cờ các snapshot cũ để giả lập lần quét thành công.

Một tin gói đồ bán một lần. Purchase khóa acc/tin, giữ đồ và trừ tiền rồi chuyển tin sang sold trong cùng transaction; requestKey cũ vẫn trả lại đơn cũ. Public index/show loại cả tin sold lẫn tin đã có order cũ. Migration 000012 đánh dấu các tin đã có đơn là sold. Admin không bật lại tin đã mua; tạo tin mới nếu còn tồn. stockAvailable vẫn cho biết tồn vật lý chưa giữ, available chỉ tối đa 1 cho mỗi tin.

Outcome missing_items cần snapshot đủ bag/chest/equipped, trong 5 phút, lease còn hạn, session xác nhận chưa có trade in flight, và toàn bộ delivered=0. BE đối chiếu template + mọi options + info/content của từng dòng đơn với snapshot mới. Chỉ khi thực sự thiếu mới hoàn toàn bộ tiền, ghi refund_nro_items, giải phóng reservation, xóa thông tin acc nhận và đóng job; retry không hoàn lần hai. Thiếu sau giao một phần hoặc kết quả chưa rõ không được hoàn toàn bộ tự động. Kết quả journal quá hạn nhưng chưa có giao dịch không xác nhận được chuyển về awaiting_receipt để lấy lại dữ liệu, không chặn tool với journal cũ.

Đơn đã hoàn vẫn lưu lịch sử; tin không tự mở bán lại. Local đã chạy migration 000012, gói #1 được ẩn, đơn #1 vẫn awaiting_receipt và tiền/đồ giữ không đổi. Kiểm thử BE phủ hoàn tiền đúng một lần, sai options, packet thiếu/chậm, đồ trong rương/đang mặc, giao một phần, trade chưa rõ và journal hết hạn.

## Phân bổ đồ khi đăng gói và lọc ID (08/09/2026)

Tồn còn chọn = quantity − reserved cho đơn chưa nhận − tổng quantity trong các tin active chưa có order. Đồ trong tin sold không cộng lần hai vì đã chuyển sang reservation. Tạo gói và bật lại tin dùng khóa acc + kiểm tra tồn, vì vậy 500 đá đăng 300 thì chỉ đăng thêm tối đa 200; rescan không giải phóng phần đã đăng. Tạm dừng tin giải phóng phần phân bổ, bật lại phải kiểm tra đủ tồn. Dữ liệu phân bổ tính từ bảng item_listing_items/item_listings hiện có, không cần migration hay sửa reserved của các đơn cũ.

Admin → kho đồ → Tạo gói đồ → Cấu hình ID bán: bật giới hạn và nhập template ID ngăn cách bởi dấu phẩy/khoảng trắng/xuống dòng. Lưu ở settings.nro_sale_item_policy; chưa bật mặc định không giới hạn, bật với danh sách trống thì chặn tất cả. Admin/super-admin hoặc người được cấp nro-sale-policy.manage mới sửa cấu hình chung. nro-settings.manage chỉ cấu hình server/map/khu/thời gian của acc; không cấp quyền sửa ID bán. CTV được cấp quyền ID vẫn chỉ xem dữ liệu kho/gói/đơn thuộc mình. BE kiểm tra ở tạo tin, đăng lại và mua; đơn đã mua vẫn được giao. Danh sách là quy định đăng của shop, không tự chứng minh món có thể giao dịch trong game.

Bảng chọn đồ tìm theo tên không dấu/ID/chỉ số (ví dụ 5 sao), lọc được phép/không được phép/tất cả, sắp xếp tên hoặc số lượng; hiển thị tồn, đang đăng, giữ đơn và còn chọn. Dữ liệu game đầy đủ thu trong mục mở rộng để ưu tiên bảng chọn.

Thẻ Shophhp có ×số lượng dưới từng icon; một loại đồ hiện chỉ số chữ 12px, nhiều loại xem chỉ số ở chi tiết. Mua ngay mở dialog xác nhận giá/server/từng món tại danh sách, dùng requestKey giữ nguyên khi retry lỗi mạng, rồi chuyển sang đơn hàng. Chưa gộp danh mục thủ công khi chưa xác định danh mục đích.

Kiểm thử: 33 bài NroShopWorkflowTest, 502 assertions; gồm phân bổ 500/300/200, pause/reopen, rescan, chuyển phân bổ sang đơn không trừ kép, quyền cấu hình và chặn ID ở API. Đã kiểm tra trực tiếp thẻ + mở/đóng modal mua trên localhost; phiên trình duyệt chưa đăng nhập admin nên phần admin xác minh bằng TypeScript/build và các test API.

## Quyền cấu hình ID và phạm vi CTV

Migration 000013 tạo nro-sale-policy.manage và cấp cho hai vai trò admin/super-admin; không tự cấp cho CTV. Permission enum và nhãn ở bảng cấp quyền người dùng/vai trò đã bổ sung. Có thể cấp riêng quyền này, không cần cấp nro-settings.manage. Nút cấu hình xuất hiện cả đầu trang để người chỉ được cấp quyền cấu hình dùng được mà không phải mở kho.

Route sale-policy dùng auth/unlocked.user và kiểm tra admin/super-admin OR quyền nro-sale-policy.manage ngay trong controller (không dùng quyền cấu hình map cũ). Cùng điều kiện dùng cho capability hiển thị nút. Kho, gói, đơn, jobs trong admin tiếp tục theo account.user_id của CTV; quyền cấu hình ID không thay đổi canViewAllAdminData. Admin/super-admin xem được phạm vi mọi CTV. Public shop vẫn là danh sách bán chung cho khách.

Đã kiểm thử người chỉ được cấp quyền cấu hình, thu hồi quyền, CTV chỉ có quyền map bị chặn ID, CTV được cấp ID vẫn bị 404 khi đọc/sửa/đối soát dữ liệu người khác, admin/super-admin xem cả hai CTV. NroShopWorkflowTest: 34 tests, 618 assertions.

## Snapshot nhiệm vụ và thẻ đồ gọn

Tool tiếp nhận CMD 40 (nhiệm vụ), 41 (sang bước), 43 (tiến độ); snapshot giữ id/name/detail/currentStep/currentCount và steps với name/detail/mapId/objectiveType/requiredCount. Chờ tối đa 3 giây nếu gói nhiệm vụ đến chậm sau phần mở rộng; không tự nhận hay làm nhiệm vụ. CurrentCount/RequiredCount thiếu giữ -1; currentStep đọc signed byte để -1 không thành 255. Chuyển bước cũng cập nhật character.task dùng cho bản xem cũ.

BE xác thực cấu trúc và chỉ lưu các trường nhiệm vụ được phép. Admin và Shophhp có tab Nhiệm vụ với bước hiện tại, tiến độ, mục tiêu và các bước trước/sau. Snapshot cũ chưa có steps vẫn hiện tên/nội dung/bước đã lưu; không tự dựng các bước từ mô tả.

Dùng qltk/NroShopDesktop/release/QLTK-Shop-quests.exe, dừng bản cũ rồi mở bản mới cùng thư mục để giữ config/journal. Lấy dữ liệu lại; nick đã đăng cần Sửa tin bán → Lưu thay đổi để gắn snapshot mới. Chưa xác minh lần quét bằng game thật; đã qua 44 kiểm tra core offline và EXE self-check.

Thẻ đồ dùng các dòng icon + tên món + số lượng; bỏ tên tin tự nhập và dòng tổng số loại/số lượng. Gói một loại vẫn hiện chỉ số nhỏ hai cột, gói nhiều loại xem chỉ số trong chi tiết. Thẻ cao theo nội dung, không kéo cao bằng hàng. Mô tả tin chỉ admin thấy, public listing API không trả description; mô tả vật phẩm game trong chi tiết vẫn giữ. Đã kiểm tra thẻ thực tế trên localhost. BE: 36 tests / 634 assertions; build BE và FE thành công.


## Tự đăng nguyên nick khi thêm acc

- Chọn bán nguyên nick: bắt buộc danh mục và giá; mô tả, ảnh và thuộc tính tùy chọn. Server, hành tinh và cải trang chỉ được tự điền khi có một lựa chọn khớp. Giá trị người đăng chọn trước được ưu tiên.
- POST admin/nro-shop/accounts lưu cấu hình vào nro_accounts (auto_publish, publish_config, publish_status, publish_error) và tạo job snapshot trong cùng transaction. Không tạo thêm bảng hay tự tạo nick trước khi có snapshot. Acc warehouse cũng tự xếp hàng lấy snapshot lần đầu, nhưng không tự tạo tin nick.
- Ảnh JPG/PNG/WebP: tối đa 8 ảnh, 5 MB/ảnh. Lưu bằng Media Library vào acc, sau đó sao chép sang thư viện ảnh tin nick; ảnh đầu làm đại diện.
- Tool báo snapshot thành công: kiểm tra đủ bag/chest/equipped, sức mạnh/hành tinh và thời điểm chụp; kiểm tra lại người đăng, quyền và danh mục, rồi tạo hoặc cập nhật duy nhất tin liên kết theo game_account_id dưới khóa acc.
- Callback gửi lại không đăng trùng. Thuộc tính lỗi hoặc snapshot thiếu chuyển needs_attention; lỗi lấy dữ liệu chuyển scan_failed; lỗi đăng chuyển publish_failed, snapshot vẫn được giữ. Có thể lấy lại dữ liệu hoặc mở Đăng bán để bổ sung.
- Khi người dùng đăng/sửa tin thủ công, tắt auto_publish để các lần lấy snapshot sau không ghi đè giá và nội dung đã sửa. Tin cũ và lịch sử acc đã bán không thay đổi.
- Giao diện tự tải lại trạng thái mỗi 10 giây khi có công việc đang chờ/xử lý. Chọn danh mục/giá chỉ hiện cho nick; yêu cầu cả nro-accounts.manage và quyền nicks.create hoặc nicks.manage, kèm quyền đăng trong danh mục.
- Migration: database/migrations/2026_09_08_000014_add_nro_auto_publish.php. Tool vẫn dùng giao thức snapshot hiện có.
- Đã hỗ trợ nhập danh sách/TXT, mỗi acc có cấu hình và job độc lập.

## Nhập danh sách acc và quản lý kho

- Nút Nhập list / TXT: dán nội dung hoặc chọn TXT UTF-8 tối đa 1 MB, 200 dòng/lần. Kiểm tra trước, sau đó thêm các dòng hợp lệ; kết quả theo số dòng, không trả mật khẩu. Dòng đã thêm được gỡ khỏi ô nhập để có thể sửa/gửi lại phần lỗi.
- Mẫu: tk|mk|svview|svlogin|loại|danh mục|giá bán|mô tả|url image. Loại 0 bán nick, 1 kho đồ. Kho chỉ cần 5 cột đầu. Nick bắt buộc danh mục/giá; các cột cuối tùy chọn. Giá là số nguyên đồng.
- Dùng ID server/danh mục hoặc tên khớp duy nhất (danh mục hỗ trợ slug). Tên không rõ hoặc trùng phải dùng ID; không suy đoán vị trí trong danh sách. Có bảng tra ID thuộc phạm vi được phép đăng. Cột chứa dấu | phải bọc nháy kép, chỉ một dòng/acc.
- POST /admin/nro-shop/accounts/import nhận text và mode=preview/import. Cùng dịch vụ đăng ký với thêm đơn: kiểm tra quyền CTV, trùng acc chưa bán, từng dòng một transaction; callback tự đăng dùng quyền chủ acc. Không có bảng mới.
- Link ảnh HTTP(S) được lưu làm tham chiếu ảnh đại diện và có trong gallery API; backend không tự tải URL. Cần dùng link ảnh trực tiếp còn truy cập được.
- Thuộc tính Đăng kí/Đăng ký: tìm option đang bật có nhãn Ảo trong chính thuộc tính thuộc danh mục, lưu ID vào nick_attributes và cache. Không có thì bỏ qua; lựa chọn thủ công được giữ nguyên.
- Trang quản lý acc có tìm kiếm, lọc loại/server/trạng thái, thống kê theo phạm vi CTV và phân trang 30 acc. Các tab đơn/gói/tool lấy theo toàn bộ acc của chủ sở hữu, không bị giới hạn bởi trang acc hiện tại.
- Bỏ hạn 30 phút và quét lại mỗi 10 phút cho kho. Sau giao dịch thành công dùng snapshot tool gửi về; kho thiếu dữ liệu hoặc chưa xác nhận sau thay đổi sẽ tự xếp quét lại, tránh trùng công việc và có khoảng chờ 2 phút khi retry. Đơn đang đối soát vẫn khóa kho.

