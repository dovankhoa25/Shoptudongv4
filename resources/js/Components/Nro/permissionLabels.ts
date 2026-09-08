const names: Record<string, string> = {
    'nicks.view': 'Xem danh sách nick', 'nicks.create': 'Đăng nick', 'nicks.manage': 'Quản lý nick (gồm sửa và xóa)',
    'nro-accounts.view': 'Xem acc game và dữ liệu kho', 'nro-accounts.manage': 'Thêm acc, sửa đăng nhập và quét dữ liệu',
    'item-listings.view': 'Xem gói đồ', 'item-listings.manage': 'Đăng và quản lý gói đồ',
    'item-orders.view': 'Xem đơn giao đồ', 'item-orders.reconcile': 'Đối soát tiến độ giao đồ',
    'nro-workers.manage': 'Cấp và thu hồi API key tool', 'nro-settings.manage': 'Cấu hình server, map/khu và thời gian chờ',
    'nro-sale-policy.manage': 'Cấu hình chung ID đồ được phép bán (không cấp quyền xem kho người khác)',
};
export const permissionLabel = (name?: string) => names[name || ''] || name || '';
