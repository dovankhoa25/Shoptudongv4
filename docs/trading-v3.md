# Tool vàng/ngọc v3

Source QLTK: `D:\code\App dev\NRO_NATIVE_SLEEP_247\qltk\NroTradingV3`. API riêng ở controller/request/resource `AppAuto/VersionThree`, middleware `VersionThree`, service `TradingV3` và `routes/app_v3.php`. Ngọc, bán vàng, nhập vàng và điều phối kho phụ cùng nằm trong một tool; hai module có thể bật/tắt riêng.

## DB và dữ liệu lịch sử

Migration `2026_10_13_000001_add_trading_v3_fields` chỉ bổ sung:

- `gem_bots`: số vé `gift_ticket_qty`, số trang bị ký gửi đủ điều kiện `consign_item_qty` (nullable = chưa đo đầy đủ); `stock_version`, `v3_worker_id`, `v3_lease_until`.
- `bots`: `stock_version`, `v3_worker_id`, `v3_lease_until` cho vàng; tiếp tục dùng `type`, `gold_qty`, `gold_bar_qty`, endpoint login và điểm đứng có sẵn.
- `gem_transactions`: `bot_id`, `delivery_method` (`consignment` mặc định, `gift_ticket`), số ngọc đã chi/thực nhận đã quan sát/số vé dùng; owner/token/version/character ID/phase/data/thời điểm xử lý và hoàn tất.
- `gold_transactions`: các trường owner/token/version/character ID/phase/data/thời điểm. `delivery_data.claim` giữ điều khoản chốt; `delivery_data.progress = {bars, pure}` giữ số thỏi và vàng tươi đã giao/nhận từ receipt.
- Dùng `inventory_movements` có sẵn cho ledger và UUID idempotency, logger bot có sẵn cho thay đổi tồn. Không thêm bảng hàng đợi job mới.

`status` vẫn là lệnh bật/tắt trên web. API v3 trả full bot, kể cả tắt. `server_id` ghép đơn với bot; `server_login_id` trong response là alias của cột có sẵn `server_game_id`, dùng lấy IP/port từ `server_game_login`.

QLTK có hai công tắc riêng `ON NGỌC` / `ON VÀNG`, đổi được trong khi tool đang chạy và lưu chỉ hai cờ ở `tool-modes.json` tại thư mục LocalAppData/NroTradingV3. OFF chặn login/claim/thao tác mới của module tương ứng; bước game đã bắt đầu vẫn được quan sát và ghi biên nhận trước khi đóng phiên. Outbox vẫn gửi lại đến đúng ACK, intent chưa rõ kết quả giữ để tự đối chiếu sau login. ON tiếp tục các bot có status và business server bật trên web. Hai công tắc không sửa status/điểm đứng.

API URL/HMAC, thời hạn hoàn pending theo giây và cờ ghi log lưu trong `data/settings.json` cạnh EXE. HMAC được bảo vệ bằng Windows DPAPI của cùng Windows user; log bật thì ghi dưới `data/logs`. Journal và worker ID nằm tại LocalAppData/NroTradingV3/state và phải được giữ khi thay EXE.

Activity `paused` biểu thị module bị tạm dừng tại QLTK và chỉ gửi Ably. Tool hiện tại không gọi heartbeat hoặc gia hạn lease. Bot còn thao tác hoặc biên nhận chưa ACK giữ journal để đối chiếu; không xóa checkpoint khi OFF hoặc khi API lỗi.

`map_name`, `map_id`, `area_number`, `coordinates` là cấu hình web. Request tool cấm ghi những trường này, kể cả trong receipt snapshot. Tool đi đúng map/khu/tọa độ; vị trí quan sát thực gửi trong event Hoạt động.

Vận hành một tool. Các cột version/worker và endpoint legacy vẫn giữ để đọc lịch sử và tương thích dữ liệu; không dùng phiên bản hoặc worker cũ để chặn bot login. GET đơn trả cả pending/processing cũ. Đơn processing có đủ token/checkpoint tiếp tục theo dữ liệu đã lưu; đơn legacy thiếu checkpoint trả lỗi riêng `legacy_checkpoint_missing`, không tự giao/mua lại và không chặn phiên game. Giữ schema và journal khi nâng cấp, không rollback bảng đang chứa biên nhận hoặc chạy hai bản tool đồng thời.

## API

Root `/app/v3/`, tất cả yêu cầu HMAC, không dùng `APP_API_KEY` v2:

| Method/path | Công việc |
|---|---|
| GET `config` | Khoảng chờ login 120s, tặng lại tối thiểu 10s, `single_tool_mode` và capability batch; các trường lease/presence giữ cho bản cũ |
| POST `heartbeat` | Endpoint tương thích bản cũ; không thay cấu hình hoàn tiền của cron |
| POST `activities` | Hoạt động nhiều bot qua Redis/queue → Ably; không ghi trạng thái vào DB |
| GET `servers/login` | Endpoint login từ DB |
| GET `gem/bots` | Full bot + credentials cho tool đã xác thực |
| PUT `gem/bots/{id}` | `action=lease`, `stock`, `release` |
| POST `gem/bots/{id}/activity` | Hoạt động qua Ably, không ghi DB |
| GET `gem-transactions` | Full pending/processing, không lọc worker/version; optional `server_id` và `worker_id` |
| GET `gem-transactions/{id}` | Đọc chi tiết, kể cả completed/cancelled/refunded; không lọc owner |
| PUT `gem-transactions/{id}` | `action=claim`, `phase`, `receipt`, `release_unstarted`, `refund_pending`; vẫn nhận `timeout`, `refund` để phục hồi outbox cũ |
| GET `gold/bots` | Full bot vàng, CSV role, tồn túi, endpoint login, credentials cho tool đã xác thực |
| PUT `gold/bots/{id}` | `action=lease`, `stock`, `release`; tồn vàng tươi/thỏi và version |
| POST `gold/bots/{id}/activity` | Hoạt động vàng qua Ably, không ghi DB |
| GET `gold-transactions` | Full pending/processing, không lọc worker/version; optional `server_id` và `worker_id` |
| GET `gold-transactions/{id}` | Đọc chi tiết terminal không lọc owner; guard ghi tài chính vẫn áp dụng |
| PUT `gold-transactions/{id}` | `action=claim`, `phase`, `receipt`, `release_unstarted`, `refund_pending`; receipt theo từng thành phần, hoàn chỉ đơn mua pending |
| GET `gold-transfers?worker_id=<uuid>` | Full reservation còn `claimed`; UUID worker chỉ là tham số tương thích của GET |
| PUT `gold-transfers/{uuid}` | `action=claim`, `receipt`, `cancel`; UUID và điều khoản giữ nguyên khi retry |

### Heartbeat tương thích và hoạt động realtime

Tool hiện tại không gọi `POST heartbeat`, `action=lease` hoặc `action=release` khi vận hành bình thường. Ngọc, bán/nhập vàng và cất/bơm kho không bị chặn bởi `v3_worker_id` hoặc `v3_lease_until`. Đơn/receipt/DONE vẫn dùng API và ledger hiện có; status, server, type, token, điều khoản chốt và tồn khả dụng tiếp tục được kiểm tra.

Endpoint heartbeat còn nhận payload và trả ACK cho bản cũ: `worker_id`, optional `single_tool_mode`, `renewal_interval_seconds`, `gem_bot_ids`, `gold_bot_ids`; response `purpose: presence`, `server_time`, thời hạn và `{id, granted, code, lease_until}`. Nếu bản cũ gọi, backend cập nhật metadata theo nhóm, không sửa tồn, điểm đứng hoặc status. ACK/TTL không cấp quyền thực hiện thao tác tài chính.

Các trường lease còn giữ trên DB và response để tương thích, không cần migration xóa cột. Tồn vé public phụ thuộc status, tồn đã đo và reservation, không trở về 0 khi presence hết hạn. Danh sách vàng gom reservation/khả năng nhận theo cả danh sách (74 bot kiểm thử dùng 5 SELECT).

Payload `gem_timeout_policy` của bản cũ còn được chấp nhận nhưng không ghi cache hoặc đổi thời hạn cron. Cron ngọc luôn dùng `trading.gem_order_pending_timeout_minutes` và `trading.gem_order_refund_grace_minutes` của web. Các giá trị policy còn sót trong cache không có hiệu lực. Với đơn đã hủy theo luồng cũ, grace đã chốt trong `delivery_data.timeout_policy` vẫn được giữ để phục hồi outbox đúng điều khoản cũ.

Hoạt động realtime độc lập với heartbeat. `POST activities` nhận `{worker_id, activities:[{bot_type, bot_id, code, map_id, zone, x, y, online_seconds, wait_seconds}]}`, tối đa 2.000 frame. `bot_type` là `gem_bot` hoặc `bot`. Response có `accepted_count`, kết quả `rejected` theo frame và `activity_delivered` theo khả năng tiếp nhận; ACK không chứng minh frontend đã nhận Ably. Hoạt động lưu tạm TTL 35s trong Redis, worker chỉ publish bản mới nhất còn hạn; API trả trước khi gọi Ably. Những endpoint activity riêng từng bot vẫn giữ và gửi hoạt động/thông báo khách bất đồng bộ.

Cần Redis dùng chung và worker riêng `php artisan queue:work redis --queue=trading-activity`; `QUEUE_CONNECTION=sync` chung không được dùng cho luồng hoạt động này. Không dùng activity queue để lưu/gửi receipt tài chính. Redis/queue hoặc Ably lỗi không làm đổi status, tồn hay đơn; frame cũ hết hạn và tool gửi lại hoạt động mới.

`NRO_TRADING_V3_ACTIVITY_QUEUE_CONNECTION=redis`, `NRO_TRADING_V3_ACTIVITY_QUEUE=trading-activity`, `NRO_TRADING_V3_ACTIVITY_CACHE_STORE=redis` chọn queue/cache hoạt động dùng chung. Native giữ bot theo status và không quay về gọi cấp quyền từng bot. Cần cập nhật backend cùng tool để dùng receipt và API hoàn pending ngay; fallback không thay thế hợp đồng tài chính mới.

Claim ngọc khóa bot rồi đơn trong DB transaction, kiểm tra cùng server, bot/server bật và tồn khả dụng; không yêu cầu lease/worker/version để nhận pending. Claim đóng băng tên, số ngọc và cách giao, tạo token. Processing có checkpoint trả token đã lưu để phục hồi. Phase giữ character ID cho ký gửi và kế hoạch món đồ; `step_id` được ACK idempotent, không thể tự đánh dấu trang bị đã giao qua phase hoặc thay thế kế hoạch đã chốt. Claim hoặc phase cũ của đơn terminal trả trạng thái cuối, không mở lại đơn.

Receipt ngọc cần `worker_id`, `bot_id`, `processing_token`, UUID `step_id`, delta ngọc/vé, `completed`, `evidence.kind` + `confirmed=true`, snapshot với version mới và số ngọc xanh/vé/đồ ký gửi. Ký gửi cần character ID đã xác nhận và đúng trang bị đã giao. Vé dùng tên đã chốt; receipt mới gửi `recipient_name`, `gift_gem_qty`, `gift_ticket_qty` khớp fresh confirmation và delta, không cần khách mời giao dịch hoặc đứng cùng map/khu, không gửi ID 0. Snapshot giao đồ giảm một món, mua ký gửi tăng một món, tặng vé giữ số món.

API cập nhật đơn và tồn trong cùng transaction, trả `ack_id=step_id`. Gửi lại cùng body/key chỉ ACK kết quả trước; cùng key khác body trả 409. Receipt ngọc không bị chặn chỉ vì bot OFF hoặc presence hết hạn. Stock correction ngọc được phép tại `waiting_customer`, `waiting_consignment`, `waiting_login`, `ready` khi native không còn intent/outbox; bị chặn ở pha đang giao/mua/tặng/đối chiếu. Vàng có ranh giới `waiting_stock` bên dưới.

Nếu DONE đã commit nhưng response mất, gửi lại frozen receipt để ACK ledger đã ghi, không thay tồn lần nữa. Giữ token/body gốc trong journal. `worker_id` còn là metadata của request đã lưu; đổi worker không chặn nhận lại processing có checkpoint hợp lệ, nhưng không được thay body của receipt cùng UUID.

API mua web `/api/gem/orders` nhận `delivery_method` optional, mặc định ký gửi. Báo giá vé lấy số vé nguyên, mỗi vé 10 ngọc và chỉ trừ tiền tương ứng. Bot/server phải bật và đủ tồn khả dụng sau reservation; không đòi presence/lease còn hạn để báo tồn vé hoặc đặt đơn. Không đủ tồn thì không trừ tiền user. Xem `docs/gem-ticket-pricing.md` về tiền thực trừ/phần dư.

## Vàng và kho phụ

Giữ bốn role trong cột `bots.type`: `selling_main`, `import_main`, `selling_sub`, `import_sub`. Một record có thể chứa nhiều role cách nhau dấu phẩy. Resource v3 trả cả chuỗi `type` và mảng `types`; lọc admin/public/legacy nhận đúng token CSV. Một account dùng một actor và một kho; role chính ưu tiên chạy 24/24, tự login và xin đậu. Hai role phụ cùng được hiểu là kho phụ, nghỉ offline khi không có việc và được gọi lên khi cần gom/bơm. Account vừa có role chính vừa có role phụ giữ phiên chính, không tạo thêm phiên hoặc tự chuyển cho chính mình.

Thỏi dùng template ID **457**, quy đổi **37.000.000 vàng/thỏi**. Đếm và giao thỏi trong **túi**, không lấy từ rương. Giới hạn account và mỗi lượt là **999 thỏi**; sức chứa nhận = 999 trừ số hiện có và lượng incoming đã dành cho đơn/chuyển khác. Tool không tự đổi vàng tươi thành thỏi hoặc thỏi thành vàng tươi để thay cơ cấu đơn.

`bots.gold_qty` là vàng tươi thực có, `bots.gold_bar_qty` là số thỏi. Trong `gold_transactions`, `gold_qty` đã là tổng quy đổi = `gold_bar_qty × 37.000.000 + pure_gold_qty`; không cộng thỏi vào tổng lần hai. Đơn bán hiện có `pure_gold_qty=0`; nếu web trả số dương, engine dùng đúng vàng tươi theo đơn, theo từng lượt trong giới hạn packet. Kiểm tra nguồn/sức chứa và đối chiếu phải tách hai thành phần, không chỉ so tổng quy đổi. Việc code hỗ trợ trường này không chứng minh server đã chấp nhận giao vàng tươi.

Claim vàng kiểm tra role theo `type=order|import`, bot/server bật, endpoint login hợp lệ và cùng `server_id`; không kiểm tra lease hoặc worker đang giữ bot. Một bot chỉ có một đơn processing. Processing cũ có token và điều khoản khớp trả checkpoint để tiếp tục; thiếu checkpoint báo lỗi riêng và không tự giao lại. Điều khoản chốt gồm ingame, server, chiều giao, số thỏi, vàng tươi, tổng quy đổi, tiền và tỷ giá. Thiếu tồn bán hoặc thiếu sức chứa nhập đưa đơn vào `waiting_stock` để điều phối kho. Receipt cần UUID `step_id`, token gốc, character ID, `bars_qty`, `pure_gold_qty`, snapshot và `evidence.kind=sale|import`, `confirmed=true`; `completed=true` chỉ khi đã đủ cả hai phần. API trả progress/remaining riêng. Receipt giữ nguyên body khi gửi lại; sau commit chỉ ACK receipt gốc, không ghi lại tồn.

Đơn nhập chỉ cộng ví theo `amount_vnd` đã chốt khi nhận đủ toàn đơn, với idempotency `gold-import-credit:<id>`. Receipt một phần chưa cộng tiền. Tiền user, tiến độ đơn, ledger và tồn bot được cập nhật trong cùng DB transaction; lỗi cuối lượt rollback toàn bộ cập nhật web, tool giữ biên nhận để thử lại. Admin không complete/cancel/refund thủ công đơn đã claim v3 khi tool còn đang đối chiếu.

Chuyển nội bộ chỉ đi `import_main → kho phụ` để cất vàng hoặc `kho phụ → selling_main` để bơm. Claim cần `worker_id` làm metadata, hai bot ID khác nhau, `bars_qty` và `pure_gold_qty` (mặc định 0); cả hai account/server phải bật, endpoint hợp lệ và cùng business server, không cần lease hoặc cùng worker. API khóa hai bot theo ID tăng dần, kiểm tra nguồn sau reservation và sức chứa nhận. Nếu một đầu đang có đơn, đơn đó phải có checkpoint hợp lệ và ở `waiting_stock`; không chuyển chồng với trade hoặc biên nhận chưa đối chiếu.

Reservation được lưu bền trong `inventory_movements` với `asset_type=workflow`, `transaction_type=gold_transfer`, `movement_type=reservation`, delta 0; UUID/owner/token/điều khoản/tồn trước và trạng thái nằm trong `meta`. Không thêm bảng job. Receipt đúng hai snapshot cập nhật sender/receiver nguyên tử và ghi `transfer_out`/`transfer_in` có tổng delta nội bộ bằng 0, không cộng ví hoặc doanh thu. `cancel` cần xác nhận tồn chưa đổi; TCP đứt không tự coi là hủy. Terminal là `completed` hoặc `cancelled`; gửi lại UUID/body/step cũ chỉ nhận ACK, khác điều khoản bị từ chối. Bot phụ hết nguồn hoặc đầy sức chứa có thời gian chờ, không gọi bơm liên tục.

Stock correction vàng bị chặn khi có transfer hoặc đơn đang giao/đối chiếu. Riêng ranh giới `waiting_stock` có checkpoint hợp lệ cho phép cập nhật tồn vừa đo đầy đủ trước claim chuyển, khi journal không còn intent/outbox; giữ nguyên tiến độ đơn, không yêu cầu worker cũ. Endpoint release tương thích vẫn bị chặn nếu còn đơn hoặc reservation. V1/v2 giữ schema và role cũ; việc luân phiên bản tool không được xóa hoặc phát lại checkpoint đang chưa hoàn tất.

## HMAC

Headers: `X-Tool-Id`, `X-Tool-Timestamp` (Unix seconds), `X-Tool-Nonce` (32 hex), `X-Tool-Signature` (64 hex). Chuỗi canonical gồm sáu dòng, không newline ở cuối:

```text
METHOD_UPPERCASE
RAW_REQUEST_URI_WITH_QUERY
TOOL_ID
TIMESTAMP
NONCE
SHA256_HEX_OF_RAW_BODY
```

Signature = HMAC-SHA256 hex của chuỗi trên bằng secret. GET body rỗng. Retry dùng nonce/timestamp mới, body receipt cũ. Chấp nhận lệch đồng hồ 90s; cache giữ nonce để chống replay. Các node backend cần dùng cache chung nếu chạy nhiều instance. API từ xa phải HTTPS. HMAC không đưa vào frontend/VITE/NEXT_PUBLIC hoặc log.

## Hoạt động realtime

Event `.TradingBotActivity`, public channel `Trading.Bots`, gồm bot ID/business server/code/text/vị trí quan sát/online seconds/observed/expires. `bot_type=gem_bot` cho ngọc, `bot_type=bot` cho vàng. Đây là thông tin hiển thị, không phải `status` hoặc trạng thái đơn. Browser giữ RAM và hết hiệu lực sau 35s nếu không có event mới. Vàng có hoạt động chờ khách/chờ kho, giao/nhận thỏi, gom/bơm, kho nghỉ, hết nguồn/đầy sức chứa và tự kết nối/đối chiếu.

Tin nhắn cần thiết của đơn ký gửi có mã đơn + giờ + suffix chống lặp, gửi private chat ingame và `UserEvent` type `bot_activity` trên private channel `User.<id>`. Tặng vé và các lượt retry không spam chat ingame; hoạt động chờ/thử lại hiển thị realtime. Không đưa tên khách/mã đơn/tin nhắn riêng lên public channel. Ably lỗi không ảnh hưởng receipt; activity API báo `activity_delivered=false`.

Admin gem bots có vé/đồ/Hoạt động/thời gian online. Đơn ngọc có phương thức giao, bot và phase. Chặn đổi thủ công đơn processing có token/checkpoint; hoàn tiền chỉ cho pending. Đơn legacy thiếu checkpoint cần đối soát trước, không dựa version để kết luận game đã giao. Trang vanghhp chọn cách nhận, hiển thị đúng bot được dành cho đơn vé và thông báo riêng cho chủ đơn.

Admin bot vàng có Hoạt động/thời gian online; đơn bán/nhập có phase, tiến độ thỏi/vàng tươi và số còn lại. Các resource public chỉ trả tiến độ hiển thị, không trả owner/token hoặc intent nội bộ.

### Admin v3 cập nhật ngày 2026-10-14

- Danh sách và trang chi tiết bot ngọc/vàng nhận tồn, cấu hình và thống kê qua AdminLive private sau khi transaction commit. Danh sách giá ngọc nhận thay đổi giá theo cùng luồng. Các quyền admin, bộ lọc và phân trang hiện có vẫn áp dụng; cập nhật lease hoặc snapshot tồn không đổi không phát lại toàn bộ bảng.
- Tồn bot ngọc gồm ngọc xanh, vé và đồ ký giao dịch được. `gift_unreserved_gems` lấy mức nhỏ hơn giữa ngọc xanh và vé quy đổi sau reservation; không cam kết bot đang online. Tạo/đổi/hủy đơn cũng cập nhật tồn chưa giữ chỗ. API public kiểm tra status, feature enabled và tồn khả dụng, không yêu cầu lease còn hạn.
- Hoạt động, vị trí thực và thời gian online chỉ lấy từ event `TradingBotActivity` trong RAM, hết hiệu lực sau 35 giây. `status` hiển thị “Bật/Tắt trên web”; map/khu/tọa độ trong form là điểm đứng cấu hình, không bị activity ghi đè. Public event không chứa worker, mã đơn, tên khách, mật khẩu hoặc tin nhắn riêng.
- Form sửa bot hiển thị tồn mới nhất chỉ để đọc; lưu tên, proxy, điểm đứng hoặc status không gửi lại tồn cũ. Backend bỏ qua field tồn không được gửi và đọc lại bot dưới khóa trước khi ghi lịch sử. Cập nhật realtime không xóa phần cấu hình admin đang nhập. Mật khẩu đang lưu không trả vào form; bỏ trống giữ nguyên mật khẩu.
- Đơn ngọc hiển thị cách nhận, bot, phase, số ngọc đã chi/thực nhận và số vé đã dùng. Đơn vàng giữ tiến độ thỏi/vàng tươi. Receipt/DONE và tồn là dữ liệu lưu DB; hoạt động không được dùng để tự hoàn tất đơn.

Phần admin này không thêm migration hoặc biến môi trường. Cần đưa source PHP và toàn bộ `public/build` được tạo bởi `npm run build` lên cùng bản phát hành; chạy lại `php artisan queue:restart` cho worker dài hạn đang dùng source cũ. Schema v3/giá vé và cấu hình Ably đã yêu cầu ở các phần triển khai vẫn phải có. Kiểm thử SQLite và preview dữ liệu giả trên localhost không xác nhận Ably hay DB production.

Validation local: 110 test/1.119 assertions về AdminLive, tồn/receipt/đơn, cấu hình bot và giá vé đạt; 2 test/3 assertions phân quyền kênh admin đạt khi chạy riêng với key giả. 16 test frontend về fallback HTTP và vòng đời activity đạt. `npm run build` (TypeScript + Vite) đạt. Preview dùng component thật với dữ liệu giả: giá vé/fallback, tồn đổi không reset form, payload sửa bot không gửi tồn/mật khẩu, tiến độ ký gửi/vé và activity hết hạn đều được kiểm tra. Không deploy hoặc thay DB/.env vận hành trong lượt này.

## Deploy

Cập nhật batch/config/vé ngày 14/10/2026: backend 108 test/1.247 assertions SQLite đạt; QLTK thường và NativeAOT bảo vệ cùng đạt 268 checks. Vé gửi đúng ba packet theo protocol, cách ít nhất 1,5s, nhận đúng form/menu mới và giữ TCP; đây là kiểm tra loopback, chưa tặng vé thật trên SV6 trong lượt này. Kiểm tra bổ sung gồm OFF → ON toàn bộ bot từ web với chu kỳ 600s và snapshot GET cũ không ghi đè receipt ACK mới. Luồng receipt/DONE lưu bền giữ nguyên. Không thêm migration cho phần batch; không thay DB/.env vận hành trong lượt phát triển này.

1. Chạy migration bổ sung trước khi đưa controller/resource mới vào phục vụ: `php artisan migrate --force`.
2. Trong `.env` backend đặt `NRO_TRADING_V3_ENABLED=true`, `NRO_TRADING_V3_TOOL_ID=nro-trading-v3`, `NRO_TRADING_V3_HMAC_SECRET=<random-secret-at-least-32-characters>`; sinh secret mới, không dùng lại khóa v2. Đã bổ sung mẫu không có secret trong `.env.example`.
3. Giữ cấu hình Ably hiện tại: `BROADCAST_CONNECTION=ably`, `ABLY_KEY`, `ABLY_PUBLIC_KEY`, `VITE_ABLY_PUBLIC_KEY` và cấu hình frontend Ably tương ứng. Đảm bảo quyền publish backend/subscribe public và private auth hiện có.
4. `php artisan config:cache`, `php artisan route:cache`, `php artisan queue:restart`; build asset admin/frontend sau khi deploy source. Chạy worker Redis riêng cho queue `trading-activity` và giữ worker này bằng process manager hiện có; nếu worker không chạy, hoạt động realtime hết hạn nhưng luồng đơn/tồn không bị chuyển sang queue này.
5. Nhập API URL/HMAC trên QLTK hoặc dùng env trong README. Kiểm tra endpoint login/map/khu/tọa độ/proxy trên web, bắt đầu với một bot test.

Vàng dùng cùng migration, HMAC và các biến v3 ở trên; không cần secret hoặc bảng job riêng. Kiểm tra role CSV và tồn túi thực tế trước khi bật, giữ cấu hình server/status/điểm đứng/proxy do web quản lý.

Không ghi/chạy migration vào DB vận hành hay thay `.env` thật trong quá trình phát triển này. Test DB SQLite không chứng minh concurrency MySQL hoặc Ably production. Các bằng chứng game trước đây ở `qltk/GemConsignment.LiveProbe` xác nhận protocol; cần kiểm thử tích hợp đơn v3 với khách thật và ngắt mạng trước khi chạy số lượng lớn.

Kiểm tra cập nhật tự phục hồi 2026-10-13: TradingV3Test 12 test/119 assertions, QLTK protected 58 checks; admin/Vang TypeScript đạt, build thường và NativeAOT đạt. Actor SV6 đã ready đúng 84/41/652-336. Hai lượt recovery test dừng ở đơn pending chưa mutation; lượt chẩn đoán tiếp theo gặp login timeout/đóng TCP và quan sát bot tự retry. Chưa đạt inject TCP sau tặng; không chi thêm ngọc/vé, không sửa DB/.env vận hành. Xem AUTO-RECOVERY-TEST-20261013.txt trong source tool để bằng chứng/phạm vi. Phiên test đã đóng, DB tạm chứa credential đã xóa.

Kiểm tra backend vàng v3: 37 test/402 assertions đạt trên SQLite trong RAM, gồm 15 case vàng v3, ngọc v3 và các case vàng/admin cũ. Bao gồm receipt hỗn hợp từng phần, credit nhập một lần, hai snapshot chuyển nguyên tử, reservation/cap999, owner/điều khoản và ACK sau đổi lease, stock tại `waiting_stock`, CSV role và guard legacy/admin. Kết quả này không xác nhận game SV11 hoặc concurrency MySQL; báo cáo live/build của tool được cập nhật riêng theo bằng chứng thực tế.

## Khi cần đối soát

Tool ghi intent trước mutation, receipt sau bằng chứng game và chỉ xóa khi ACK đúng. TCP đứt thì tự login lại, chờ đủ hành trang mới và đối chiếu tồn trước/sau với intent. Giảm đúng ngọc xanh/vé là đủ xác nhận tặng, không cần dòng chat; giao/mua ký đối chiếu thêm đúng món. Đã thực hiện thì tự gửi receipt/DONE; tồn mới chưa đổi thì tiếp tục đơn theo khoảng chờ. Không xóa journal hoặc đổi worker ID để chạy lại.

Hoạt động `reconciling` chỉ hiển thị qua Ably, không đổi status bot hoặc ghi trạng thái đơn `review_required` cho mất TCP thông thường. Khi dữ liệu chưa đủ/không khớp, actor vẫn online và tự kiểm tra lại; giữ intent đến khi có bằng chứng. Intent cũ thiếu dữ liệu món hoặc cấu hình sai không thể tự suy ra kết quả. Lỗi startup API/login/actor có retry; timeout API bao gồm đọc body. DONE đã lưu được retry dù bot đang chờ login hoặc điều hướng lỗi.

Mất API tạm thời không đóng phiên game. Bot tiếp tục giữ phiên/xin đậu/reconnect theo status và checkbox; không chờ quyền web để login hoặc hết hạn lease. Ngọc và vàng giữ intent/receipt để retry API; bot, token, điều khoản, bằng chứng và tồn vẫn được kiểm tra khi ghi tài chính. Status OFF hoặc checkbox OFF thật vẫn được tuân theo. Login chờ theo thời gian game trả, lỗi tài khoản thử lại chậm và nhận cấu hình mới.

Thông báo hết đồ xét cả rương và túi sau inventory đầy đủ. Gợi ý tên lệch 1-2 ký tự chỉ khi có đúng một đơn gần tên trên cùng server, không giao cho tên gần đúng. Gift offline bị từ chối rõ thì giữ khoảng thử lại 10s; kết quả chưa rõ giữ intent để nhận dữ liệu mới. Thông báo “Từ từ đừng vội” của lượt mua nghĩa là chưa mua, không đi vào vòng probe dài. Trong chuỗi tặng vé không mở NPC ký gửi; polling API vẫn độc lập. Sau login áp dụng warmup 120s, giữ phiên nếu ký gửi chưa trả phản hồi.

Vàng ghi intent trước xác nhận trade, giữ số thỏi/vàng tươi trước và số lượng lượt. Mất TCP thì tự login, đối chiếu đầy đủ hành trang mới và tiếp tục đúng phần còn lại; chuyển nội bộ đối chiếu cả hai đầu. DONE/transfer receipt chưa ACK giữ trong journal, không giao lặp. Target map/khu/tọa độ vẫn lấy trên web; điểm gặp để chuyển nội bộ chỉ là trạng thái runtime, không ghi ngược cấu hình web.
## Cập nhật luồng một tool, 14/10/2026

Bot login và giữ phiên theo `status`, server và checkbox local. GET v3 ngọc trả đầy đủ đơn pending/processing, không phân chia theo worker/version; GET chi tiết đọc được terminal. HMAC vẫn bắt buộc. Token, điều khoản đơn đã chốt, số lượng, baseline tồn và biên nhận idempotent vẫn được kiểm tra. Đơn legacy thiếu checkpoint không được tự giao/mua lại.

Vé dùng tên nhân vật đã chốt, không yêu cầu `character_id` hay giao dịch trước. Khi receipt không có ID nhân vật, cần evidence `recipient_name`, `gift_gem_qty`, `gift_ticket_qty` khớp tên/số lượng của đơn và delta ngọc/vé. Không gửi `character_id: 0`.

QLTK dùng một cấu hình tự hoàn chung cho đơn **mua vàng và mua ngọc** pending, mặc định 600 giây và chỉnh được từ 1 đến 86.400 giây. Không áp dụng cho đơn nhập vàng. Thời hạn này không được gửi qua heartbeat hoặc dùng để đổi cấu hình cron. Hoạt động bot tiếp tục qua hàng đợi realtime `trading-activity`; token, điều khoản, tồn và biên nhận vẫn được kiểm tra khi cập nhật đơn.

`PUT gem-transactions/{id}` và `PUT gold-transactions/{id}` nhận `action=refund_pending`, UUID `step_id`, `timeout_seconds` (1–86.400), `worker_id`, `bot_id`. Backend kiểm tra tuổi đơn bằng đồng hồ server, chỉ nhận pending chưa có giao/chi tài sản; vàng bắt buộc `type=order`. Hủy và hoàn đúng `amount_vnd` trong cùng transaction, không chờ grace; ngọc trả status `refunded`, vàng giữ schema cũ `status=cancelled` kèm `refunded_at`, cả hai trả ACK. Tool lưu nguyên request trước khi gửi và retry cùng UUID/body nếu response mất. Cùng UUID khác body bị từ chối; đơn đã hoàn chỉ trả trạng thái cuối. Yêu cầu hoàn đơn vàng completed trả 409, tool đọc lại chi tiết để thấy trạng thái terminal rồi bỏ yêu cầu chưa thực hiện; không hoàn ví. Khi đang giao dịch, tool chờ kết quả; đã giao thành công tiếp tục processing. Cả vàng và ngọc có `release_unstarted` với token, UUID và `evidence.no_effect=true` để trở lại pending sau thất bại đã xác nhận chưa có tác động, rồi mới hoàn nếu hết hạn. Đơn có tiến độ, biên nhận hoặc chuyển kho chưa đối chiếu không được nhả để hoàn pending.

Service `OrderTimeoutService` dùng chung với cron `gold:cancel-stale`, khóa đơn/user và cùng ledger key `gem-order-timeout-refund:<id>` / `gold-order-timeout-refund:<id>` để tránh hoàn hai lần. Cron giữ thời hạn web và grace độc lập, nên vẫn xử lý khi v3 tắt hoặc chạy v2. Operator có thể đặt thời hạn cron lâu hơn tool một phút; đơn tool đã hoàn không còn được cron chọn. Đơn nhập vàng không được hoàn ví bởi cơ chế này. API ngọc `timeout`/`refund` vẫn nhận body cũ để phục hồi outbox đã lưu, giữ nguyên grace đã chốt của đơn cũ; không chuyển request cũ sang body mới.

Ví dụ QLTK đặt 600 giây (10 phút), cron hoàn khoảng 11 phút từ tạo đơn thì dùng `GOLD_ORDER_PENDING_TIMEOUT_MINUTES=11`, `GEM_ORDER_PENDING_TIMEOUT_MINUTES=11`, `GOLD_ORDER_REFUND_GRACE_MINUTES=0`, `GEM_ORDER_REFUND_GRACE_MINUTES=0`. Nếu giữ grace 5 phút, pending 11 phút sẽ hoàn khoảng 16 phút, cộng độ trễ lịch chạy cron. Các giá trị web vẫn do operator lựa chọn; tool không ghi lại env/config cron.

Cập nhật backend cùng tool mới. Không có migration hoặc biến env bắt buộc mới; giữ `NRO_TRADING_V3_ENABLED=true`, tool ID/HMAC hiện có và cấu hình cache bền. Sau cập nhật nguồn, chạy `php artisan optimize:clear`, nạp lại config cache theo quy trình deploy và restart queue worker đang chạy `trading-activity`. Giữ cron `gold:cancel-stale` mỗi phút. Không xóa journal hoặc worker ID khi nâng cấp.

Kiểm tra backend cuối cho bản không lease và tự hoàn chung: **138 test / 1.398 assertions** đạt trên SQLite trong RAM. Bao gồm hồi phục đơn/chuyển vàng khi worker hoặc lease cũ, thời hạn theo giây, số tiền thực trừ được hoàn đúng một lần, claim/refund đổi thứ tự, thất bại trước giao trả pending, receipt đã nhận không được nhả/hoàn, rollback khi vượt số dư, cron fallback v2 và policy cache cũ không ghi đè cấu hình web. Không đổi DB/.env vận hành; kết quả SQLite không xác nhận concurrency MySQL hoặc production Ably.
