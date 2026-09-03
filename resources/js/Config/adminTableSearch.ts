export interface SearchFieldOption {
    key: string;
    label: string;
    description?: string;
    mode?: 'contains' | 'exact';
}

const contains = (key: string, label: string): SearchFieldOption => ({ key, label, mode: 'contains' });
const exact = (key: string, label: string): SearchFieldOption => ({ key, label, mode: 'exact' });

const userFields = [
    contains('username', 'Tên đăng nhập'),
    contains('email', 'Email'),
];

const relatedUserFields = [
    contains('user', 'Người dùng'),
    exact('user_id', 'ID người dùng'),
];

export const ADMIN_TABLE_SEARCH_FIELDS = {
    users: userFields,
    roles: [contains('name', 'Tên vai trò')],
    frontendClients: [
        exact('client_id', 'Client ID'),
        contains('name', 'Tên client'),
        contains('domain', 'Domain được phép'),
    ],
    gameTypes: [contains('name', 'Tên loại game'), exact('slug', 'Slug')],
    categories: [contains('name', 'Tên danh mục'), exact('slug', 'Slug'), exact('template', 'Template')],
    attributes: [contains('name', 'Tên thuộc tính'), contains('option', 'Giá trị option')],
    fields: [contains('label', 'Tên hiển thị'), exact('key', 'Field key')],
    services: [contains('name', 'Tên dịch vụ')],
    cardTypes: [exact('telco', 'Nhà mạng')],
    servers: [contains('name', 'Tên server'), exact('ip', 'IP'), exact('port', 'Port')],
    serverGameLogins: [contains('name', 'Tên đăng nhập server'), exact('ip', 'IP'), exact('port', 'Port')],
    nicks: [
        contains('account', 'Tài khoản nick'),
        contains('user', 'Người đăng'),
        exact('user_id', 'ID người đăng'),
        exact('category_id', 'ID danh mục'),
    ],
    nickOrders: [
        exact('nick_id', 'ID nick'),
        contains('buyer', 'Người mua'),
        exact('buyer_id', 'ID người mua'),
        contains('seller', 'Người bán'),
        exact('seller_id', 'ID người bán'),
    ],
    randomBoxes: [contains('name', 'Tên hộp random'), exact('category_id', 'ID danh mục')],
    randomNicks: [
        contains('account', 'Tài khoản nick'),
        contains('box', 'Tên hộp random'),
        exact('random_box_id', 'ID hộp random'),
    ],
    spins: [contains('name', 'Tên vòng quay'), exact('category_id', 'ID danh mục')],
    spinResults: [
        ...relatedUserFields,
        contains('spin', 'Tên vòng quay'),
        exact('spin_id', 'ID vòng quay'),
        contains('reward', 'Phần thưởng'),
    ],
    spinTickets: [
        ...relatedUserFields,
        contains('spin', 'Tên vòng quay'),
        exact('spin_id', 'ID vòng quay'),
    ],
    bots: [
        contains('name', 'Tên bot'),
        contains('account', 'Tài khoản bot'),
        contains('map', 'Tên bản đồ'),
        exact('server_id', 'ID server'),
    ],
    botHistory: [
        exact('entity_id', 'ID đối tượng'),
        exact('transaction_id', 'ID giao dịch'),
        contains('admin', 'Admin thực hiện'),
        exact('admin_id', 'ID admin'),
        exact('ip', 'Địa chỉ IP'),
    ],
    prices: [contains('server', 'Tên server'), exact('server_id', 'ID server')],
    goldOrders: [
        contains('character', 'Tên nhân vật'),
        ...relatedUserFields,
        contains('bot', 'Tên bot'),
        exact('bot_id', 'ID bot'),
    ],
    gemOrders: [
        contains('character', 'Tên nhân vật'),
        ...relatedUserFields,
        contains('item', 'Vật phẩm'),
    ],
    serviceOrders: [
        contains('account', 'Tài khoản giao dịch'),
        ...relatedUserFields,
        contains('receiver', 'Người nhận'),
        exact('receiver_id', 'ID người nhận'),
        contains('service', 'Tên dịch vụ'),
        exact('service_id', 'ID dịch vụ'),
    ],
    cards: [
        exact('code', 'Mã thẻ'),
        exact('serial', 'Serial'),
        exact('trans_id', 'Mã giao dịch đối tác'),
        ...relatedUserFields,
    ],
    bankTopups: [
        exact('sepay_id', 'ID SePay'),
        exact('payment_code', 'Mã thanh toán'),
        exact('reference', 'Mã tham chiếu'),
        exact('account_number', 'Số tài khoản'),
        ...relatedUserFields,
    ],
    carotRecharges: [
        contains('account', 'Tài khoản nạp'),
        exact('transaction', 'Mã giao dịch'),
        ...relatedUserFields,
    ],
    transactions: [
        ...relatedUserFields,
        contains('performer', 'Người thực hiện'),
        exact('related_id', 'ID dữ liệu liên quan'),
        exact('idempotency', 'Khóa chống trùng'),
    ],
    withdrawals: [
        ...relatedUserFields,
        contains('bank', 'Ngân hàng'),
        exact('account_number', 'Số tài khoản'),
        contains('account_name', 'Tên chủ tài khoản'),
        contains('approver', 'Người xử lý'),
    ],
} satisfies Record<string, SearchFieldOption[]>;

export type AdminSearchPreset = keyof typeof ADMIN_TABLE_SEARCH_FIELDS;
