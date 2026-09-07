# Chat realtime

Hệ thống chat dùng chung dữ liệu cho trang quản trị, trang user trong backend và các frontend sử dụng Passport.

## Cấu trúc dữ liệu

Hệ thống dùng 5 bảng:

- `chat_conversations`: một phòng hỗ trợ chung hoặc một phòng gắn với đơn;
- `chat_messages`: tin nhắn, ghi chú nội bộ và khóa chống gửi trùng;
- `chat_participants`: admin/CTV tham gia, rời phòng và mốc đã đọc;
- `chat_conversation_events`: nhật ký gắn đơn, phân công, đổi trạng thái;
- `chat_realtime_sessions`: channel realtime riêng cho từng web session (Passport token dùng trực tiếp bảng token hiện có).

## Luồng chính

1. Frontend gọi `POST /api/chat/conversations/resolve` khi user mở hỗ trợ chung hoặc chọn một đơn.
2. Backend trả lại conversation hiện có của đơn; chỉ tạo mới khi chưa có.
   Nếu user đang có chat hỗ trợ chung chưa đóng, đơn đầu tiên được gắn vào chính chat đó để giữ toàn bộ lịch sử với admin.
   Khi user chọn một đơn khác, backend tạo hoặc lấy chat riêng của đơn mới.
3. Frontend tải nội dung qua `GET /api/chat/conversations/{id}`.
4. Mỗi frontend gọi endpoint `realtime-channel` rồi subscribe đúng private channel dành riêng cho credential hiện tại. Backend xác định lại quyền và danh sách credential hợp lệ ở thời điểm phát từng event.
5. Tin nhắn được gửi kèm `client_message_id` để retry không tạo bản ghi trùng.
6. Frontend cập nhật đã đọc bằng `PATCH /api/chat/conversations/{id}/read`.
7. Sau khi socket reconnect, frontend chỉ lấy phần bị lỡ qua
   `GET /api/chat/conversations/{id}/messages?after_id={last_message_id}&limit=100`,
   thay vì tải lại toàn bộ hội thoại.

Các frontend bên ngoài dùng Bearer Passport token có scope `chat:read chat:write` và Echo auth endpoint `/api/broadcasting/auth`. Giao diện Inertia trong backend dùng session và `/broadcasting/auth`. Token Passport được cấp trước khi bổ sung các scope chat cần đăng nhập lại để nhận scope mới.

## API user/frontend

Tất cả endpoint sau yêu cầu `auth:api`. Endpoint đọc yêu cầu `chat:read`; resolve, gửi tin và đánh dấu đã đọc yêu cầu cả `chat:read` + `chat:write`. Các thao tác phân công/quản lý qua API yêu cầu thêm `chat:manage`.

```text
GET    /api/chat/conversations
GET    /api/chat/realtime-channel
POST   /api/chat/conversations/resolve
GET    /api/chat/contexts
GET    /api/chat/conversations/{conversation}
GET    /api/chat/conversations/{conversation}/messages
POST   /api/chat/conversations/{conversation}/messages
PATCH  /api/chat/conversations/{conversation}/read
```

Tạo hoặc lấy chat hỗ trợ chung:

```json
{
  "category": "general",
  "source_app": "webgame",
  "source_url": "https://webgame.example/profile"
}
```

Tạo hoặc lấy chat của một đơn:

```json
{
  "subject_type": "service_order",
  "subject_id": 125,
  "source_app": "webgame",
  "source_url": "https://webgame.example/orders/125"
}
```

`subject_type` hợp lệ:

```text
service_order
nick_order
gold_transaction
gem_transaction
```

Backend luôn xác minh đơn thuộc user đang đăng nhập. Không lấy `customer_id` từ request.

Với `service_order`, khi CTV nhận một đơn đang chờ thì chat chưa có người phụ trách hoặc đang do admin hỗ trợ chung sẽ được chuyển cho CTV trong cùng giao dịch. Chat đang thuộc một CTV khác không bị ghi đè.

Gửi tin nhắn:

```json
{
  "body": "Nhờ admin kiểm tra giúp đơn này.",
  "client_message_id": "5dfe64e8-9d67-4d14-9a95-f3a10eeec2e6"
}
```

Đánh dấu đã đọc:

```json
{
  "last_read_message_id": 901
}
```

## API admin/CTV dùng session

```text
GET    /admin/chat/conversations
GET    /admin/chat/agents
GET    /admin/chat/conversations/{conversation}
POST   /admin/chat/conversations/{conversation}/messages
PATCH  /admin/chat/conversations/{conversation}/read
PATCH  /admin/chat/conversations/{conversation}/assign
PATCH  /admin/chat/conversations/{conversation}/status
```

CTV chỉ thấy conversation được giao hoặc conversation của đơn đang do họ nhận. Nếu admin bỏ phân công nhưng CTV vẫn là receiver/seller của đơn, CTV vẫn nhận realtime cho đúng phạm vi họ nhìn thấy qua API. Admin có thể lọc `assignment=mine|unassigned`, phân công và chuyển người xử lý.

## Realtime channels

```text
Chat.User.{id}.web-{digest}    Một session web cụ thể
Chat.User.{id}.api-{digest}    Một access token Passport cụ thể
```

`digest` là HMAC phía server và không phải token/session ID thô. Không tự ghép hoặc lưu cố định tên channel. Frontend Inertia lấy tên đầy đủ từ prop
`auth.realtime_channel`; frontend Passport gọi `GET /api/chat/realtime-channel`
rồi subscribe đúng giá trị `data.channel`.

Events:

```text
.ChatMessageSent
.ChatReadUpdated
.ChatInboxUpdated
```

`ChatMessageSent` mang cả `message` và bản tóm tắt `conversation`, vì vậy giao
diện cập nhật tin, thứ tự/status phòng và badge trực tiếp trong bộ nhớ. Event
`ChatInboxUpdated` chỉ dùng cho thay đổi cấu trúc phòng như tạo, phân công hoặc
đổi trạng thái; không phát lặp lại sau mỗi tin nhắn.

Khách hàng, CTV và admin đều chỉ nhận qua channel credential riêng khi vẫn còn
quyền với hội thoại. Danh sách user nhận được khử trùng trước khi ánh xạ sang
các web session/access token còn hiệu lực, nên mỗi connection chỉ nhận một bản.

Mỗi event chỉ được phát tới credential còn hợp lệ tại đúng thời điểm publish:

- session web phải còn lease, chưa logout/hết hạn và vẫn tồn tại trong session store phía server;
- access token phải chưa hết hạn/thu hồi, OAuth client còn hoạt động và có scope `chat:read`;
- tài khoản phải còn hoạt động.

Với khách hàng và CTV, mỗi credential có channel riêng nên logout hoặc thu hồi một thiết bị không làm gián đoạn các phiên khác của cùng user. Socket cũ có thể còn kết nối vật lý nhưng channel của credential đã hết hiệu lực không còn nằm trong danh sách phát, nên không nhận dữ liệu mới. Sau khi refresh access token, frontend Passport phải gọi lại `GET /api/chat/realtime-channel` và subscribe channel mới; token cũ sẽ nhận `401/403` khi xác thực lại.

Vì vậy CTV cũ không nhận nội dung sau khi chuyển giao; tài khoản vừa bị khóa hoặc admin vừa mất quyền cũng không nhận event mới dù kết nối WebSocket cũ chưa đóng. Trường `is_mine` chỉ có ý nghĩa trong response HTTP theo người gọi và được bỏ khỏi payload realtime; frontend phải suy ra bằng `message.sender.id === currentUser.id`.

Response danh sách conversation có thêm `unread_total` ở cấp cao nhất để hiển thị badge chính xác, không phụ thuộc số trang đã tải.

## Tích hợp frontend Passport

1. Đăng nhập/làm mới token có scope `chat:read chat:write`.
2. Cấu hình Echo dùng `/api/broadcasting/auth` và gửi cùng Bearer token.
3. Gọi `GET /api/chat/realtime-channel`, lấy `data.channel` rồi subscribe private channel đó.
4. Lắng nghe ba event ở trên; khi access token được refresh thì leave channel cũ, gọi lại endpoint và subscribe channel mới.
5. Dùng `unread_total` cho badge bong bóng; khi mở phòng gọi endpoint `read` với ID tin cuối cùng.
6. Chỉ hiển thị trạng thái realtime đã kết nối sau khi private channel báo
   subscribe thành công. Khi reconnect, dùng `after_id` để bù các tin đã lỡ.

## Cấu hình triển khai

```text
BROADCAST_CONNECTION=ably
ABLY_KEY=<private-key>
ABLY_PUBLIC_KEY=<public-part, có thể bỏ trống để suy ra từ ABLY_KEY>
```

Session web phải dùng store phía server như `file`, `database`, `redis`, `memcached` hoặc `dynamodb`; không dùng `cookie`/`array` cho realtime chat. Scheduler Laravel phải chạy để dọn lease hết hạn. Sau khi cập nhật source:

```bash
php artisan migrate
php artisan db:seed --class=PermissionFromRoutesSeeder
php artisan optimize:clear
npm run build
```

Các Passport token đã cấp trước khi có scope chat cần đăng nhập lại.

## Giao diện có sẵn

```text
/messages       Trang tin nhắn của user
/admin/chats    Hộp thư hỗ trợ của admin/CTV
```

`ChatBubble` được mount trong cả `AuthenticatedLayout` và `AdminLayout`. Ở các frontend khác, nên tái sử dụng cùng contract API, trạng thái và channel ở trên; không tạo bảng hoặc conversation riêng theo từng frontend.
