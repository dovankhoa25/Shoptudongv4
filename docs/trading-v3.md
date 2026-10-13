# Tool vàng/ngọc v3

Source QLTK: `D:\code\App dev\NRO_NATIVE_SLEEP_247\qltk\NroTradingV3`. API riêng ở controller/request/resource `AppAuto/VersionThree`, middleware `VersionThree`, service `TradingV3` và `routes/app_v3.php`. Ngọc, bán vàng, nhập vàng và điều phối kho phụ cùng nằm trong một tool; hai module có thể bật/tắt riêng.

## DB và tương thích v2

Migration `2026_10_13_000001_add_trading_v3_fields` chỉ bổ sung:

- `gem_bots`: số vé `gift_ticket_qty`, số trang bị ký gửi đủ điều kiện `consign_item_qty` (nullable = chưa đo đầy đủ); `stock_version`, `v3_worker_id`, `v3_lease_until`.
- `bots`: `stock_version`, `v3_worker_id`, `v3_lease_until` cho vàng; tiếp tục dùng `type`, `gold_qty`, `gold_bar_qty`, endpoint login và điểm đứng có sẵn.
- `gem_transactions`: `bot_id`, `delivery_method` (`consignment` mặc định, `gift_ticket`), số ngọc đã chi/thực nhận đã quan sát/số vé dùng; owner/token/version/character ID/phase/data/thời điểm xử lý và hoàn tất.
- `gold_transactions`: các trường owner/token/version/character ID/phase/data/thời điểm. `delivery_data.claim` giữ điều khoản chốt; `delivery_data.progress = {bars, pure}` giữ số thỏi và vàng tươi đã giao/nhận từ receipt.
- Dùng `inventory_movements` có sẵn cho ledger và UUID idempotency, logger bot có sẵn cho thay đổi tồn. Không thêm bảng hàng đợi job mới.

`status` vẫn là lệnh bật/tắt trên web. API v3 trả full bot, kể cả tắt. `server_id` ghép đơn với bot; `server_login_id` trong response là alias của cột có sẵn `server_game_id`, dùng lấy IP/port từ `server_game_login`.

QLTK có hai công tắc riêng `ON NGỌC` / `ON VÀNG`, đổi được trong khi tool đang chạy và lưu chỉ hai cờ ở `tool-modes.json` tại thư mục LocalAppData/NroTradingV3. OFF chặn login/claim/thao tác mới của module tương ứng; bước game đã bắt đầu vẫn được quan sát và ghi biên nhận trước khi đóng phiên. Outbox vẫn gửi lại đến đúng ACK, intent chưa rõ kết quả giữ để tự đối chiếu sau login. ON tiếp tục các bot có status và business server bật trên web. Hai công tắc không sửa status/điểm đứng.

Activity `paused` biểu thị module bị tạm dừng tại QLTK và chỉ gửi Ably. Bot rảnh đã đóng phiên sẽ release lease; bot còn đơn/biên nhận/chuyển nội bộ giữ owner để không bị worker khác nhận lại.

`map_name`, `map_id`, `area_number`, `coordinates` là cấu hình web. Request tool cấm ghi những trường này, kể cả trong receipt snapshot. Tool đi đúng map/khu/tọa độ; vị trí quan sát thực gửi trong event Hoạt động.

V2 tiếp tục nhận đơn ký gửi chưa thuộc v3. V2 không nhận đơn vé hoặc đơn v3, không sửa bot có lease v3/đơn v3 chưa xong. Dừng v3 khi rảnh sẽ release bot; khi mất mạng lease hết sau TTL được API xác nhận. API per-bot cũ vẫn dùng TTL 90s; heartbeat theo lô dùng chu kỳ người vận hành chọn cộng tối thiểu 60s (mặc định 150s → TTL 210s; 600s → TTL 660s). Đơn v3 chưa hoàn tất vẫn giữ owner và journal, cần đối soát trước khi đổi tool. Giữ DB/migration v3 khi quay về tool v2; không rollback schema đang có biên nhận.

## API

Root `/app/v3/`, tất cả yêu cầu HMAC, không dùng `APP_API_KEY` v2:

| Method/path | Công việc |
|---|---|
| GET `config` | Khoảng chờ login 120s, tặng lại tối thiểu 10s, capability batch và chu kỳ gia hạn mặc định 150s |
| POST `heartbeat` | Gia hạn bot ngọc/vàng chung, tối đa một UPDATE cho mỗi bảng bot; ACK riêng từng bot |
| POST `activities` | Hoạt động nhiều bot qua Redis/queue → Ably; không ghi trạng thái vào DB |
| GET `servers/login` | Endpoint login từ DB |
| GET `gem/bots` | Full bot + credentials cho tool đã xác thực |
| PUT `gem/bots/{id}` | `action=lease`, `stock`, `release` |
| POST `gem/bots/{id}/activity` | Hoạt động qua Ably, không ghi DB |
| GET `gem-transactions?worker_id=<uuid>` | Đơn pending chưa claim và đơn v3 đang thuộc worker; không trả done |
| GET `gem-transactions/{id}?worker_id=<uuid>` | Đối soát đơn của worker, kể cả done |
| PUT `gem-transactions/{id}` | `action=claim`, `phase`, `receipt` |
| GET `gold/bots` | Full bot vàng, CSV role, tồn túi, endpoint login, credentials cho tool đã xác thực |
| PUT `gold/bots/{id}` | `action=lease`, `stock`, `release`; tồn vàng tươi/thỏi và version |
| POST `gold/bots/{id}/activity` | Hoạt động vàng qua Ably, không ghi DB |
| GET `gold-transactions?worker_id=<uuid>` | Đơn pending chưa claim và đơn vàng v3 processing của worker; không trả done |
| GET `gold-transactions/{id}?worker_id=<uuid>` | Đối chiếu đơn của worker, kể cả done |
| PUT `gold-transactions/{id}` | `action=claim`, `phase`, `receipt`; receipt theo từng thành phần |
| GET `gold-transfers?worker_id=<uuid>` | Reservation chuyển nội bộ còn `claimed` của worker |
| PUT `gold-transfers/{uuid}` | `action=claim`, `receipt`, `cancel`; UUID và điều khoản giữ nguyên khi retry |

### Gia hạn theo lô và hoạt động realtime

`POST heartbeat` nhận `worker_id`, `renewal_interval_seconds` (10–3.600 giây), `gem_bot_ids` và `gold_bot_ids` (tối đa 1.000 ID mỗi loại). Backend khóa hàng bot theo ID tăng dần, đọc server/endpoint/đơn/chuyển chưa hoàn tất theo nhóm, rồi cập nhật các ID hợp lệ bằng một câu UPDATE mỗi bảng. Không gọi lại service/resource từng bot, không sửa tồn, map/khu/tọa độ hoặc status của web, và không ghi lịch sử cho việc gia hạn. Đơn/receipt/DONE vẫn dùng API và ledger hiện có.

Response gồm `server_time`, `renewal_interval_seconds`, `lease_seconds`, `gem_bots` và `gold_bots`. Mỗi ACK có `{id, granted, code, lease_until}`; bot hợp lệ có `code=renewed`. Các code từ chối gồm `disabled`, `server_disabled`, `login_invalid`, `not_found`, `owned_elsewhere`, `legacy_order`, `unfinished_order`, `unfinished_transfer`; một bot bị từ chối không làm thất bại cả lô. Từ chối không kéo dài lease, cũng không xóa owner của đơn/chuyển đang xử lý. Claim tiền mới vẫn cần lease hợp lệ; receipt của đúng owner tiếp tục đối soát được theo quy tắc trước đây.

Gia hạn của cùng owner khi lease đang còn hạn và giữ nguyên/kéo dài TTL không xóa cache public. Nếu đổi chu kỳ ngắn hơn hoặc fallback sang endpoint cũ làm lease kết thúc sớm hơn, backend invalidate cache để tồn vé có thể mua không còn dựa trên TTL cũ. Acquisition sau khi lease hết hạn hoặc release/đổi tồn vẫn invalidate nhóm cần thiết. Cache public ngọc không sống lâu hơn lease sắp hết của bot trong cache. Danh sách vàng gom reservation/khả năng nhận theo cả danh sách (74 bot kiểm thử dùng 5 SELECT thay vì phát sinh truy vấn riêng mỗi bot).

Chu kỳ gia hạn và chu kỳ hoạt động là hai phần độc lập: kéo gia hạn lên 10 phút không làm realtime chờ 10 phút. `POST activities` nhận `{worker_id, activities:[{bot_type, bot_id, code, map_id, zone, x, y, online_seconds, wait_seconds}]}`, tối đa 2.000 frame. `bot_type` là `gem_bot` hoặc `bot`. Response có `accepted_count`, kết quả `rejected` theo frame và `activity_delivered` theo khả năng tiếp nhận; ACK không chứng minh frontend đã nhận Ably. Hoạt động lưu tạm TTL 35s trong Redis, worker chỉ publish bản mới nhất còn hạn; API trả trước khi gọi Ably. Những endpoint activity riêng từng bot vẫn giữ và gửi hoạt động/thông báo khách bất đồng bộ.

Cần Redis dùng chung và worker riêng `php artisan queue:work redis --queue=trading-activity`; `QUEUE_CONNECTION=sync` chung không được dùng cho luồng hoạt động này. Không dùng activity queue để lưu/gửi receipt tài chính. Redis/queue hoặc Ably lỗi không làm đổi status, tồn hay đơn; frame cũ hết hạn và tool gửi lại hoạt động mới.

`NRO_TRADING_V3_RENEWAL_INTERVAL_SECONDS=150` cấu hình giá trị mặc định backend. QLTK có thể gửi giá trị riêng, ví dụ 600 để gia hạn 10 phút/lần. `NRO_TRADING_V3_ACTIVITY_QUEUE_CONNECTION=redis`, `NRO_TRADING_V3_ACTIVITY_QUEUE=trading-activity`, `NRO_TRADING_V3_ACTIVITY_CACHE_STORE=redis` chọn queue/cache dùng chung riêng cho hoạt động. Bản backend cũ không có capability batch vẫn nhận các endpoint per-bot trước đây; tool phải giữ chu kỳ cũ khi fallback, không áp chu kỳ dài cho lease 90s.

Claim khóa bot rồi khóa đơn trong DB transaction, kiểm tra server/owner/lease/tồn và đóng băng tên, số ngọc, cách giao. Phase giữ character ID và kế hoạch món đồ. Phase có `step_id` được ACK idempotent; không thể tự đánh dấu trang bị đã giao thành công qua phase.

Receipt cần `worker_id`, `bot_id`, `processing_token`, UUID `step_id`, `character_id`, delta ngọc/vé, `completed`, `evidence.kind` + `confirmed=true`, `bot_snapshot` với version mới và số ngọc xanh/vé/đồ ký gửi. Snapshot phải khớp delta dự kiến; receipt giao đồ chỉ giảm một món, mua ký gửi tăng một món, tặng vé giữ số món.

API xử lý đơn và tồn bot trong cùng transaction, trả `ack_id=step_id`. Gửi lại cùng body/key chỉ ACK kết quả trước, không trừ lần hai; cùng key khác body trả 409. Receipt sau khi bot tắt hoặc lease hết vẫn có thể được ghi nhận bởi đúng owner để phục hồi lost response. Stock correction riêng của ngọc bị chặn khi bot còn đơn processing; vàng có ranh giới `waiting_stock` được mô tả bên dưới.

Nếu DONE đã commit nhưng response mất và bot đã được worker khác thuê, owner gốc vẫn gửi lại frozen receipt để nhận ACK. Nhánh này chỉ đọc ledger đã commit, không thay tồn hay lease của worker mới.

API mua web `/api/gem/orders` nhận `delivery_method` optional; mặc định ký gửi. Báo giá vé lấy số vé nguyên theo tiền khách nhập, mỗi vé tương ứng 10 ngọc, rồi chỉ trừ tiền tương ứng với số vé đó. Bot v3 cần giữ lease và đủ tồn khả dụng sau khi trừ các đơn đã dành cho bot. Không đủ tồn thì không trừ tiền user. Xem `docs/gem-ticket-pricing.md` để biết công thức tiền thực trừ và phần dư.

## Vàng và kho phụ

Giữ bốn role trong cột `bots.type`: `selling_main`, `import_main`, `selling_sub`, `import_sub`. Một record có thể chứa nhiều role cách nhau dấu phẩy. Resource v3 trả cả chuỗi `type` và mảng `types`; lọc admin/public/legacy nhận đúng token CSV. Một account dùng một actor và một kho; role chính ưu tiên chạy 24/24, tự login và xin đậu. Hai role phụ cùng được hiểu là kho phụ, nghỉ offline khi không có việc và được gọi lên khi cần gom/bơm. Account vừa có role chính vừa có role phụ giữ phiên chính, không tạo thêm phiên hoặc tự chuyển cho chính mình.

Thỏi dùng template ID **457**, quy đổi **37.000.000 vàng/thỏi**. Đếm và giao thỏi trong **túi**, không lấy từ rương. Giới hạn account và mỗi lượt là **999 thỏi**; sức chứa nhận = 999 trừ số hiện có và lượng incoming đã dành cho đơn/chuyển khác. Tool không tự đổi vàng tươi thành thỏi hoặc thỏi thành vàng tươi để thay cơ cấu đơn.

`bots.gold_qty` là vàng tươi thực có, `bots.gold_bar_qty` là số thỏi. Trong `gold_transactions`, `gold_qty` đã là tổng quy đổi = `gold_bar_qty × 37.000.000 + pure_gold_qty`; không cộng thỏi vào tổng lần hai. Đơn bán hiện có `pure_gold_qty=0`; nếu web trả số dương, engine dùng đúng vàng tươi theo đơn, theo từng lượt trong giới hạn packet. Kiểm tra nguồn/sức chứa và đối chiếu phải tách hai thành phần, không chỉ so tổng quy đổi. Việc code hỗ trợ trường này không chứng minh server đã chấp nhận giao vàng tươi.

Claim vàng kiểm tra role theo `type=order|import`, bot/server bật, endpoint login hợp lệ, lease còn hiệu lực và cùng `server_id`. Một bot chỉ có một đơn processing. Điều khoản chốt gồm ingame, server, chiều giao, số thỏi, vàng tươi, tổng quy đổi, tiền và tỷ giá. Thiếu tồn bán hoặc thiếu sức chứa nhập đưa đơn vào `waiting_stock` để điều phối kho. Receipt cần UUID `step_id`, owner/token, character ID, `bars_qty`, `pure_gold_qty`, snapshot và `evidence.kind=sale|import`, `confirmed=true`; `completed=true` chỉ khi đã đủ cả hai phần. API trả progress/remaining riêng. Receipt giữ nguyên body khi gửi lại; sau commit hoặc đổi lease chỉ ACK receipt gốc, không ghi lại tồn.

Đơn nhập chỉ cộng ví theo `amount_vnd` đã chốt khi nhận đủ toàn đơn, với idempotency `gold-import-credit:<id>`. Receipt một phần chưa cộng tiền. Tiền user, tiến độ đơn, ledger và tồn bot được cập nhật trong cùng DB transaction; lỗi cuối lượt rollback toàn bộ cập nhật web, tool giữ biên nhận để thử lại. Admin không complete/cancel/refund thủ công đơn đã claim v3 khi tool còn đang đối chiếu.

Chuyển nội bộ chỉ đi `import_main → kho phụ` để cất vàng hoặc `kho phụ → selling_main` để bơm. Claim cần `worker_id`, hai bot ID khác nhau, `bars_qty` và `pure_gold_qty` (mặc định 0); cả hai account/server phải bật, endpoint hợp lệ, cùng business server và cùng worker giữ lease. API khóa hai bot theo ID tăng dần, kiểm tra nguồn sau reservation và sức chứa nhận. Nếu một đầu đang có đơn, đơn đó phải ở `waiting_stock`; không chuyển chồng với trade hoặc biên nhận chưa đối chiếu.

Reservation được lưu bền trong `inventory_movements` với `asset_type=workflow`, `transaction_type=gold_transfer`, `movement_type=reservation`, delta 0; UUID/owner/token/điều khoản/tồn trước và trạng thái nằm trong `meta`. Không thêm bảng job. Receipt đúng hai snapshot cập nhật sender/receiver nguyên tử và ghi `transfer_out`/`transfer_in` có tổng delta nội bộ bằng 0, không cộng ví hoặc doanh thu. `cancel` cần xác nhận tồn chưa đổi; TCP đứt không tự coi là hủy. Terminal là `completed` hoặc `cancelled`; gửi lại UUID/body/step cũ chỉ nhận ACK, khác điều khoản bị từ chối. Bot phụ hết nguồn hoặc đầy sức chứa có thời gian chờ, không gọi bơm liên tục.

Stock correction vàng bị chặn khi có transfer hoặc đơn đang giao/đối chiếu. Riêng ranh giới `waiting_stock` của chính worker cho phép cập nhật tồn vừa đo đầy đủ trước claim chuyển, khi journal không còn intent/outbox; giữ nguyên tiến độ đơn. Release vẫn bị chặn nếu còn đơn hoặc reservation. V1/v2 không được ghi lên bot/đơn v3 đang giữ; khi release bot rảnh, v2 có thể dùng lại schema và role cũ.

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

Tin nhắn có mã đơn + giờ + suffix chống lặp gửi private chat ingame và event `UserEvent` type `bot_activity` trên private channel `User.<id>` cho chủ đơn. Không đưa tên khách/mã đơn/tin nhắn riêng lên public channel. Ably lỗi không ảnh hưởng transaction/receipt; activity API báo `activity_delivered=false`.

Admin gem bots có vé/đồ/Hoạt động/thời gian online. Admin đơn ngọc có phương thức giao, bot và phase v3. Chặn complete/cancel/refund thủ công đối với đơn đã claim v3 để tránh refund trong lúc mutation hoặc ACK đang chờ; cần đối soát journal/game trước. Trang vanghhp có lựa chọn cách nhận, hiển thị đúng bot đã được dành cho đơn vé và thông báo riêng cho chủ đơn.

Admin bot vàng có Hoạt động/thời gian online; đơn bán/nhập có phase, tiến độ thỏi/vàng tươi và số còn lại. Các resource public chỉ trả tiến độ hiển thị, không trả owner/token hoặc intent nội bộ.

### Admin v3 cập nhật ngày 2026-10-14

- Danh sách và trang chi tiết bot ngọc/vàng nhận tồn, cấu hình và thống kê qua AdminLive private sau khi transaction commit. Danh sách giá ngọc nhận thay đổi giá theo cùng luồng. Các quyền admin, bộ lọc và phân trang hiện có vẫn áp dụng; cập nhật lease hoặc snapshot tồn không đổi không phát lại toàn bộ bảng.
- Tồn bot ngọc gồm ngọc xanh, vé và đồ ký giao dịch được. `gift_unreserved_gems` là khả năng tặng từ tồn chưa giữ chỗ, lấy mức nhỏ hơn giữa ngọc xanh và số vé quy đổi sau reservation; đây không phải cam kết bot đang online. Tạo/đổi/hủy đơn ngọc cũng cập nhật phần tồn chưa giữ chỗ. API public tiếp tục kiểm tra status, v3 enabled và lease khi báo số ngọc có thể mua.
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

Mất API tạm thời không đóng phiên game đã được cấp quyền; lease hết thì chờ gia hạn trước thao tác tiền mới. Thu hồi quyền, status OFF hoặc checkbox của module OFF vẫn được tuân theo. Login chờ theo thời gian server trả, lỗi tài khoản thử lại chậm và nhận cấu hình mới để tiếp tục.

Thông báo hết đồ xét cả rương và túi, chỉ sau inventory đầy đủ. Gợi ý tên lệch 1-2 ký tự chỉ khi có đúng một đơn gần tên trên cùng server, không giao cho tên gần đúng. Gift offline bị từ chối rõ và tồn không đổi mới thử lại sau 10s; kết quả không rõ thì giữ intent. Sau login chờ tối thiểu 120s, nhưng vẫn đối soát nếu server ký gửi chưa trả dữ liệu/phản hồi mua.

Vàng ghi intent trước xác nhận trade, giữ số thỏi/vàng tươi trước và số lượng lượt. Mất TCP thì tự login, đối chiếu đầy đủ hành trang mới và tiếp tục đúng phần còn lại; chuyển nội bộ đối chiếu cả hai đầu. DONE/transfer receipt chưa ACK giữ trong journal, không giao lặp. Target map/khu/tọa độ vẫn lấy trên web; điểm gặp để chuyển nội bộ chỉ là trạng thái runtime, không ghi ngược cấu hình web.
