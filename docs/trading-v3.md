# Trading V3 — một tool, cập nhật ngày 17/10/2026

Backend ở `AppAuto/VersionThree`, request/resource `VersionThree`, service `TradingV3` và route `routes/app_v3.php`. Ngọc, bán vàng, nhập vàng và kho phụ chạy trong cùng QLTK; hai module có thể bật/tắt riêng.

## Luồng vận hành

1. API trả đầy đủ bot, kể cả bot tắt; tool login theo `status`, trạng thái server và công tắc module. Không heartbeat, lease hoặc worker giữ bot.
2. API trả đầy đủ đơn `pending` và `processing`, không lọc phiên bản/worker. Tool tiếp tục đơn có checkpoint, ưu tiên đơn đủ điều kiện theo thứ tự cũ nhất.
3. Trước thao tác game đầu tiên, tool gửi `action=start` và chờ ACK xác nhận đơn `processing`: chốt thông tin đơn để cron không hoàn nhầm. Có thể kèm người nhận/pha đầu để tránh thêm một request phase. Timeout của start chưa cho phép thao tác tài sản.
4. Tool ghi intent trước thao tác game, lưu receipt khi có bằng chứng. API quyết toán đúng lượng của receipt và ghi ledger trong cùng DB transaction rồi ACK `step_id`; snapshot kho được áp dụng theo độ mới riêng.
5. Nếu mất response, gửi lại nguyên body/UUID đã lưu. Chỉ xóa outbox khi ACK đúng; không giao/mua/tặng lại vì API timeout.

API timeout không đóng TCP game. Tool giữ cấu hình đã nhận, tiếp tục giữ phiên/xin đậu/reconnect khi game ngắt, và gọi lại API. Status OFF hoặc công tắc OFF thật vẫn có hiệu lực.

## Schema

Migration `2026_10_15_000001_simplify_single_tool_trading_state.php` xóa 10 cột:

| Bảng | Cột xóa |
|---|---|
| `bots`, `gem_bots` | `v3_worker_id`, `v3_lease_until` |
| `gold_transactions`, `gem_transactions` | `processing_version`, `processing_owner`, `processing_token` |

Index đơn `(server_id,status,processing_version)` được thay bằng `(server_id,status)`. Không xóa đơn, tiền, tồn hoặc lịch sử giao dịch. `down()` phục hồi cấu trúc cột nullable; không khôi phục giá trị quyền cũ.

Giữ các cột còn dùng:

- `stock_version`: mốc thứ tự snapshot/receipt để request tồn cũ đến trễ không ghi đè sau thao tác đã xác nhận; không phải phiên bản tool.
- `delivery_method`, `delivered_gem_qty`, `bot_gem_spent_qty`, `tickets_used_qty`: cách giao và tiến độ tài sản.
- `bot_id`, `character_id`, `delivery_phase`, `delivery_data`, `processing_started_at`, `completed_at`: bot xử lý, người nhận đã xác nhận, món đã giao, phần còn lại và khôi phục sau khởi động.
- `delivery_data.claim`: tên lịch sử của dữ liệu điều khoản đã chốt, không chứa worker/token. Ngọc chốt tên/số ngọc/cách giao; vàng chốt tên/server/chiều giao/thỏi/vàng tươi/tiền/tỷ giá.
- `delivery_data.progress`, `inventory_movements` và UUID receipt: chống ghi lặp, giữ lịch sử thu/chi. Không thêm bảng job.

Admin/resource dùng `has_delivery_checkpoint` thay cho `processing_version`. Đơn cũ có NULL ở cột mới không chứng minh cột dư; đơn `processing` thiếu checkpoint trả `legacy_checkpoint_missing` để tránh phát lại một giao dịch chưa biết kết quả. Trường hợp này không chặn login hoặc các đơn khác đủ điều kiện.

## API

Root `/app/v3/`, xác thực HMAC. Tool mới không gửi `worker_id` hoặc `processing_token`.

| Method/path | Chức năng |
|---|---|
| GET `config` | Warmup, khoảng tặng lại, khả năng batch activity |
| GET `servers/login` | Endpoint login từ DB |
| GET `gem/bots`, GET `gold/bots` | Full bot, cấu hình login/điểm đứng và tồn |
| PUT `gem/bots/{id}`, PUT `gold/bots/{id}` | `action=stock`: snapshot tồn khi không có giao dịch/receipt cần đối chiếu |
| GET `gem-transactions`, GET `gold-transactions` | Full pending/processing, optional `server_id` |
| GET `gem-transactions/{id}`, GET `gold-transactions/{id}` | Chi tiết, kể cả đơn terminal |
| PUT `gem-transactions/{id}`, PUT `gold-transactions/{id}` | `start`, `phase`, `receipt`, `release_unstarted`, `refund_pending` |
| GET `gold-transfers` | Các lượt chuyển kho chưa hoàn tất |
| PUT `gold-transfers/{uuid}` | `claim` lập lượt chuyển, `receipt` hoàn tất, `cancel` khi xác nhận chưa chuyển |
| POST `activities` | Gộp hoạt động nhiều bot, gửi Ably sau response |
| POST `gem/bots/{id}/activity`, POST `gold/bots/{id}/activity` | Activity đơn lẻ và thông báo riêng của đơn |

`start` ngọc nhận `bot_id`, optional `character_id`, optional `phase=waiting_customer|gifting` (gifting chỉ cho vé). `start` vàng nhận `bot_id`, optional `character_id`, optional `phase=waiting_customer`; thiếu tồn/sức chứa thì backend chọn `waiting_stock` và đánh dấu `delivery_data.stock_wait_settled=true` vì chưa có thao tác game. Gửi lại start của đơn processing trả checkpoint hiện có, không xóa tiến độ. Riêng đơn chờ đã nhường lượt, start kiểm tra lại bot/role, tồn hoặc chỗ trống, đơn đang giao và lượt chuyển kho đã claim; đủ điều kiện mới bỏ marker và về `waiting_customer`, chưa đủ vẫn trả `waiting_stock`. Start của đơn terminal trả trạng thái cuối.

`phase` và `receipt` vẫn kiểm tra bot/server, điều khoản chốt, số lượng, người nhận và bằng chứng. Receipt cần UUID `step_id`, trả `ack_id=step_id`; cùng UUID khác body trả 409. Tiến độ, ledger và snapshot đủ mới được ghi nguyên tử. Không có thao tác game chỉ vì nhận ACK activity.

Sau thành công trong game, tool lưu receipt nguyên body và checkpoint cục bộ trước khi chuyển sang phục vụ khách khác. Sender nền chỉ gửi bản sao đã đóng băng; actor duy nhất nhận kết quả ACK rồi cập nhật journal đúng đơn/step. Giữ FIFO theo từng đơn: phase chốt món đồ phải đến trước receipt giao đồ, receipt giao đồ phải đến trước receipt mua ký gửi. ACK trễ không được ghi đè đơn đang chọn hoặc làm lùi tiến độ đã xác nhận cục bộ. Không xóa intent chưa rõ kết quả và không phát lại thao tác tài sản chỉ vì API chậm.

Ngọc cho phép nhiều đơn processing nên không cần bundle. Vàng giữ một đơn đang chiếm lượt giao dịch trên mỗi bot; các đơn `waiting_stock` có checkpoint đúng, marker `stock_wait_settled=true`, tiến độ bằng 0 và chưa có receipt được giữ processing nhưng không giữ tồn/chỗ trống hoặc chặn đơn đủ hàng khác. Để nhận đơn kế tiếp khi receipt hoàn tất đơn trước còn chờ ACK, `start`/`claim` vàng nhận optional `pending_receipts` (tối đa 16), theo thứ tự cũ trước: `[{"order_id":123,"payload":{...}}]`. `payload` là nguyên body receipt đã lưu, phải `action=receipt`, `completed=true`, cùng `bot_id`, khác đơn đang nhận, không lặp `order_id` và không lồng bundle. Backend dùng đầy đủ kiểm tra receipt thông thường, quyết toán các receipt rồi nhận đơn mới trong cùng DB transaction. Một receipt hoặc lần nhận đơn lỗi sẽ rollback toàn bộ bundle; không cấp quyền bỏ qua điều kiện bot đang bận.

Muốn nhường lượt của đơn vàng đã nhận, tool chỉ gửi `phase=waiting_stock`, `evidence={"confirmed":true,"no_effect":true}` sau khi giao dịch đã đóng, không còn intent/outbox/start chưa rõ và chưa giao/nhận tài sản. Backend tự tạo marker, không nhận `delivery_data` do tool đặt. Tool lưu ACK và checkpoint vào danh sách chờ trong journal trước khi chọn khách khác. Marker cũ không hợp lệ nếu điều khoản thay đổi; đơn chưa có bằng chứng, còn receipt chưa đồng bộ hoặc đã giao một phần không được nhường lượt. Đơn giao một phần có thể chờ bơm/cất sau receipt đã ACK và xác nhận không còn thao tác game đang mở: backend dùng `stock_transfer_settled=true`, vẫn giữ lượt khách và reservation. Lặp lại phase cũ sau khi đơn đã resume chỉ trả ACK/trạng thái hiện tại, không đưa đơn lùi lại. Receipt mới của đơn đã nhường lượt phải đi qua resume trước; cron vẫn không hoàn processing.

Response thành công giữ `data` của đơn vừa nhận và thêm `receipt_acks:[{"order_id":123,"step_id":"UUID","ack_id":"UUID","data":{...}}]`; `data` từng phần tử là resource đơn cũ đã hoàn tất. Tool chỉ bỏ từng mục outbox khi order/step/ACK khớp mục đã đóng băng, vẫn kiểm tra trạng thái đơn mới trước thao tác game. Retry bundle hoặc endpoint receipt riêng với cùng body không cộng tiền/ledger lần nữa; body khác cùng step trả 409. Gửi receipt riêng vẫn hoạt động nếu lần nhận đơn kế tiếp thất bại.

Lượt bơm/cất đã được game xác nhận cũng được gửi receipt nền sau khi lưu journal. Nếu HTTP ACK của lượt đó còn chậm, start vàng có thể kèm `pending_transfers:[{"transfer_id":"UUID","payload":{...}}]` tối đa 16, mỗi `payload` là nguyên receipt đã đóng băng, `action=receipt`, có một đầu là bot đang nhận đơn. Backend khóa toàn bộ tài khoản liên quan theo ID tăng dần trước workflow, quyết toán transfer trước receipt đơn rồi mới nhận đơn mới, trong một transaction. Response thêm `transfer_acks:[{"transfer_id":"UUID","step_id":"UUID","ack_id":"UUID","data":{...}}]`. Sai bằng chứng/body/UUID hoặc nhận đơn thất bại rollback toàn bộ; retry receipt độc lập vẫn quyết toán được. Coordinator chỉ xóa journal khi ACK đúng UUID/step/body đã lưu; lượt chưa rõ kết quả không được dùng bundle. Một lượt chuyển mới vẫn phải chờ ACK của lượt chuyển cũ trên hai đầu; mở giao dịch khách mới phải qua start và kiểm tra tồn mới.

Upload stock phải đứng sau ACK của mọi receipt cũ có thể tác động kho bot đó, kể cả receipt của đơn khác. Nếu upload tồn đã giảm trong game khi receipt cũ chưa quyết toán, reservation cũ có thể bị trừ thêm lần nữa. Refresh đang chờ vẫn phải giữ bền trong journal; receipt và stock dùng `stock_version` để response cũ không ghi đè quan sát mới.

Receipt đã xác nhận không suy lượng giao từ tổng chênh lệch kho: movement `sale`, `import`, `transfer_out`, `transfer_in` ghi đúng lượng của bước; phần chênh khác ghi riêng `sync_correction`, không gắn thành lượng/tiền của đơn hay tự kết luận là nạp ngoài. Hai dòng tạo chuỗi số dư kế toán, không khẳng định thứ tự biến động ngoài game. Snapshot có `stock_version` nhỏ hơn hoặc bằng DB vẫn cho receipt được ACK đúng một lần, nhưng giữ nguyên tồn/version mới hơn; metadata `snapshot_already_current` giải thích phần đối chiếu sổ. Snapshot âm hoặc vượt sức chứa túi vẫn bị từ chối.

Tool có bằng chứng thành công của đúng intent nhưng chưa có inventory mới có thể gửi `evidence.intent.stock_snapshot_pending=true` kèm proof: ngọc dùng `server_success`; vàng dùng `game_success` hoặc `from_game_success`/`to_game_success`. Backend kiểm tra liên kết intent/step, người nhận và số lượng; vàng còn kiểm tra session/offer đã xác nhận. Cờ này không được dùng riêng lẻ. Receipt vẫn quyết toán và ACK nhưng không áp số lượng/tên/thời điểm đồng bộ từ snapshot tạm; ledger ghi `snapshot_pending`. Chỉ `stock_version` được nâng lên mốc receipt nếu lớn hơn DB, nhằm từ chối request stock chụp trước thao tác nhưng đến trễ sau receipt; metadata `snapshot_watermark_advanced` cho biết mốc đã tăng. Tool lưu yêu cầu refresh bền, sau ACK lấy inventory mới rồi gửi `action=stock` với version cao hơn mốc receipt; receipt một phần có quyền đồng bộ đúng một lần tại bước đã ACK, mất quyền đó khi bắt đầu bước game tiếp theo. Quy tắc áp dụng riêng từng đầu chuyển vàng. Outbox cũ không cần thêm trường và vẫn gửi lại nguyên body; receipt cũ không có cờ tiếp tục dùng quy tắc version.

Các request cũ `action=claim` của đơn vẫn tương đương start. `worker_id`/`processing_token` cũ là field tương thích tùy chọn và được giữ trong hash body cũ, không kiểm tra quyền. Không sửa JSON outbox đã lưu để bỏ field cũ. Transfer meta lịch sử không bị viết lại; response không trả owner/token, lượt mới không tạo chúng.

`POST heartbeat` và `action=lease/release` của bot chỉ là endpoint tương thích không ghi DB/cache, tool mới không gọi. `config` báo `bot_lease_required=false`, `heartbeat_required=false`, `batch_heartbeat_supported=false`. Endpoint heartbeat không xác nhận bot tồn tại hay bật; tool lấy trạng thái từ danh sách bot.

## QLTK và polling

Setting **LẤY BOT / ĐƠN • GIÂY** là một giá trị chung, mặc định 5 giây, cho phép 1–3.600 giây, lưu `api_poll_seconds` trong `data/settings.json`. Đổi và lưu khi tool đang chạy sẽ áp dụng ngay cho lượt chờ tiếp theo, không ngắt request đang chạy hoặc TCP game.

Các endpoint `gem/bots`, `gem-transactions`, `gold/bots`, `gold-transactions` và `gold-transfers` dùng cùng khoảng chờ. Mỗi vòng chờ sau khi request trước hoàn tất; request chậm không tạo các lượt chồng nhau. `config` có chu kỳ riêng 60 giây; activity, gửi biên nhận, tự hoàn, retry game không bị kéo theo setting lấy danh sách.

Với setting 5 giây và API trả nhanh, năm endpoint danh sách tối đa khoảng 60 GET/phút cho cả tool, cộng khoảng 1 GET config/phút. Không nhân theo số bot. Response chậm làm tần suất thực tế thấp hơn. Activity và cập nhật tồn/đơn phát sinh riêng theo nhu cầu.

URL/HMAC, polling, thời hạn hoàn, ngưỡng bơm vàng và cờ ghi log lưu trong `data/settings.json`. HMAC được bảo vệ bằng Windows DPAPI của cùng user. `logs=true` ghi file dưới `data/logs`; nhật ký ngọc/vàng tách riêng. Journal nằm tại LocalAppData/NroTradingV3/state; giữ nguyên thư mục này khi thay EXE. Worker ID local cũ có thể còn trong file cũ, không được gửi để xin quyền.

`map_name`, `map_id`, `area_number`, `coordinates`, `status`, server và role do web quản lý. Tool không gửi ngược các trường này trong snapshot; tọa độ quan sát chỉ gửi qua activity.

## Ngọc

Ký gửi chỉ mua đúng trang bị bot đã giao, có xác nhận giao dịch; dùng ngọc xanh. Vé dùng tên nhân vật trong đơn, 1 vé tặng 10 ngọc, khách cần online nhưng không cần cùng map/khu hoặc mời giao dịch. Các packet mở vé/nhập thông tin/xác nhận theo thứ tự, cách tối thiểu 1,5 giây. Khi khách offline rõ ràng thì thử lại tối thiểu 10 giây. Khi đang tặng vé không mở cửa hàng ký gửi; polling API vẫn độc lập.

Receipt vé cần `evidence.kind=gift`, `confirmed=true`, tên và số lượng khớp điều khoản; số vé dùng bằng số ngọc giao chia 10. Ký gửi cần người nhận/món đồ đúng; receipt giao đồ ghi giảm một món, chưa hoàn tất đơn. Receipt mua ký gửi ghi lượng ngọc chi và tăng món trở lại. Tổng tồn quan sát có thể thay đổi thêm do tác động khác mà không chặn receipt đã xác nhận. API không cho phase tự đánh dấu đồ đã giao thành công.

Tool giữ TCP khi cần đối chiếu; chỉ tự login lại khi game ngắt hoặc cấu hình bật/tắt/endpoint yêu cầu. Phản hồi mua “Từ từ đừng vội” là chưa mua, không chạy vòng đối chiếu dài. Không dùng dữ liệu hành trang cache để kết luận đã hoặc chưa chi tài sản.

## Vàng và kho phụ

Giữ role CSV cũ: `selling_main`, `import_main`, `selling_sub`, `import_sub`. Có một trong hai role phụ là kho phụ; hai bot chính online 24/24, kho phụ chỉ login khi cần. Chỉ dùng bot có status bật trên cùng server.

Bot phụ đi đến map/khu/vị trí của bot chính được phân công; cấu hình điểm đứng riêng của phụ có thể trống và không dùng để dẫn đường cho lượt bơm/cất. Chính đứng tại điểm cấu hình và vẫn nhận đơn đủ tồn khi phụ đang login, di chuyển hoặc chờ game. Việc chuẩn bị này chỉ là kế hoạch cục bộ; API transfer `claim` chỉ gửi sát lúc hai bot đủ điều kiện mở giao dịch, sau khi tính lại nhu cầu và sức chứa. Từ claim đến receipt/cancel, bảo vệ độc quyền của hai đầu vẫn giữ nguyên. Người khác mời phụ không thay đổi ID bot chính đã chốt trong journal.

Thỏi template **457**, một thỏi **37.000.000 vàng**, chứa trong túi tối đa **999 thỏi/account**. `gold_qty` trên đơn là tổng quy đổi; `pure_gold_qty` là phần tươi riêng. Hiện giao thỏi, không tự bán thỏi thành vàng tươi.

Điều kiện nhận lại đơn chờ kiểm tra lượng của lượt giao kế tiếp, tối đa 999 thỏi và giới hạn packet vàng tươi. Đơn lớn hơn một lượt vẫn giữ tiến độ từng receipt; sau receipt một phần chỉ bơm/cất đúng lượng còn thiếu, không yêu cầu túi chứa toàn bộ đơn lớn cùng lúc.

Bot bán chỉ gọi bơm khi dưới ngưỡng thỏi cấu hình hoặc thiếu thỏi cho đơn được gán. Ngưỡng mặc định 0 nghĩa là không bơm chủ động theo tồn; đơn thiếu vẫn gọi bơm. Kho phụ hết nguồn/đầy chỗ có thời gian chờ, không liên tục lập giao dịch.

Lượt chuyển dùng UUID bền, khóa hai bot theo ID tăng dần, kiểm tra nguồn/sức chứa và không cho chồng lên giao dịch đang thực hiện. Reservation trong `inventory_movements` phục vụ khôi phục số thỏi đã định chuyển; không giữ quyền theo tool. Receipt chuyển cập nhật hai đầu nguyên tử, không cộng ví/doanh thu. Chỉ cancel khi xác nhận chưa chuyển, không tự coi timeout là thất bại. Với lượt chỉ chuyển thỏi (`pure_gold_qty=0`), biến động vàng tươi không liên quan không chặn cancel; số thỏi và mốc `stock_version` của hai đầu vẫn phải khớp reservation. Lượt có chuyển vàng tươi vẫn kiểm tra cả vàng tươi.

Đơn nhập chỉ cộng ví đúng `amount_vnd` sau khi nhận đủ, dùng ledger key `gold-import-credit:<id>`. Receipt một phần chưa cộng ví. Admin và API cũ không ghi đè đơn/tồn đang có checkpoint giao dịch chưa đối chiếu; guard này không dựa phiên bản tool.

## Tự hoàn pending

QLTK dùng chung thời hạn cho **mua vàng và mua ngọc**, mặc định 600 giây, chỉnh 1–86.400 giây; không áp dụng nhập vàng. Tool lưu body `refund_pending` với `bot_id`, UUID `step_id`, `timeout_seconds` rồi gửi API. Backend chỉ hoàn pending chưa giao/chi, kiểm tra tuổi đơn theo đồng hồ server, hủy và hoàn trong cùng transaction.

Khi đang giao dịch, chờ kết quả. Thành công giữ processing để hoàn tất. Thất bại xác nhận chưa tác động mới `release_unstarted` về pending rồi hoàn nếu hết hạn; không nhận giao dịch mới cho đơn đã đưa vào hàng chờ hoàn. Kết quả chưa rõ giữ journal để đối chiếu, không hoàn/mua lại mù.

Cron `gold:cancel-stale` giữ nguyên, dùng chung service/ledger key chống hoàn hai lần. Operator có thể đặt cron muộn hơn tool một phút; tool không gửi policy để thay cron. Các API ngọc `timeout`/`refund` cũ vẫn xử lý outbox cũ với grace đã chốt. Bỏ tích tự hoàn không xóa yêu cầu đã gửi có thể đã commit; vẫn gửi lại để nhận ACK.

## Realtime và hosting

Hoạt động là event `.TradingBotActivity` trên `Trading.Bots`, không phải status bật/tắt hoặc tiến độ đơn. Browser giữ RAM, event hết hạn sau 35 giây. Public event không chứa tên khách/mã đơn/mật khẩu; thông báo riêng dùng kênh user. Chỉ đơn ký gửi cần chat ingame, không retry chat thất bại.

Backend gửi Ably qua callback sau response, không Laravel job, không Redis cache trạng thái, không worker. Gộp frame cùng bot/request, nhóm publish 100 frame, ngân sách tối đa 12 giây. Mất activity không thay tồn/đơn. Cache nonce HMAC và rate limiter vẫn giữ để xác thực; cache public website vẫn có, không phải quyền chạy bot.

Nếu còn backlog phiên bản cũ:

```sh
php artisan trading:activity-redis
php artisan trading:activity-redis --clear-backlog --clear-tool-cache
```

Chỉ dọn queue/cache activity riêng đã xác minh. Không dùng FLUSHALL/FLUSHDB. Xem `TRADING-HOSTING-REDIS-OOM-20261015.txt` về giới hạn và bằng chứng thử Redis.

## Triển khai

Bản bổ sung receipt nền ngày 17/10 chỉ cập nhật PHP/tool trên backend V3 đã nâng cấp bên dưới; không thêm migration, bảng queue, Redis hoặc biến môi trường. Cập nhật backend trước tool, nạp lại PHP OPcache nếu hosting bật và giữ nguyên journal/outbox.

Đây là thay đổi schema và contract phối hợp backend/tool. Sao lưu DB trước migration; tạm dừng tool trong lúc đưa bản backend mới và migration vào cùng lượt bảo trì. Không xóa journal/outbox.

1. Cập nhật source PHP, migration mới và asset admin `public/build` cùng bản phát hành. Không ghi đè `.env` hosting bằng file mẫu.
2. `php artisan migrate --force`, sau đó `php artisan config:cache` và `php artisan route:cache`.
3. Nếu hosting có worker dài hạn cho tác vụ khác, `php artisan queue:restart` để nạp code mới. Hoạt động bot không cần worker.
4. Giữ `NRO_TRADING_V3_ENABLED=true`, `NRO_TRADING_V3_TOOL_ID`, `NRO_TRADING_V3_HMAC_SECRET` và Ably hiện tại; không thêm biến bắt buộc. Giữ cron hoàn tiền như cấu hình đã chọn.
5. Thay QLTK bằng bản mới, giữ `data/settings.json` và thư mục journal. Lưu số giây polling; mặc định bản nâng cấp là 5 giây.

Schema cũ không còn phù hợp với backend cũ từng truy vấn các cột đã xóa. Các API v1/v2 trong source mới đã được cập nhật; không chạy lại bản backend cũ sau migration mà không rollback schema có kiểm soát. Receipt cũ đã lưu vẫn được API mới chấp nhận với nguyên body.

Các kiểm thử local dùng SQLite trong RAM, fake HTTP/loopback game; không tự xác nhận latency game, Ably hoặc concurrency MySQL production. Báo cáo kiểm thử bản phát hành được ghi riêng khi hoàn tất. Chưa triển khai bot kiểm tra hiện diện mới; phần đó sẽ chốt sau khi tổ chức API/schema/polling xong.
