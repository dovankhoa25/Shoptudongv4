import { Avatar, ConfigProvider, Descriptions, Modal, Tag, theme } from 'antd';
import { UserOutlined } from '@ant-design/icons';
import { IUser } from '@/InterFaces/user';
import { useTheme } from '@/Providers/ThemeProvider';
import { formatCurrency } from '@/Utils/currencyHelper';

interface Props { user: IUser; onClose: () => void }
const statuses: Record<string, { label: string; color: string }> = {
    active: { label: 'Hoạt động', color: 'green' }, locked: { label: 'Đã khóa', color: 'gold' },
    banned: { label: 'Đã cấm', color: 'red' }, pending: { label: 'Chờ xác thực', color: 'blue' },
};
const date = (value?: string) => value ? new Date(value).toLocaleString('vi-VN') : '—';

export default function UserDetailModal({ user, onClose }: Props) {
    const { darkMode } = useTheme();
    const status = statuses[user.status || 'active'] ?? statuses.active;
    return <ConfigProvider theme={{ algorithm: darkMode ? theme.darkAlgorithm : theme.defaultAlgorithm }}>
        <Modal open footer={null} width={680} title="Chi tiết người dùng" onCancel={onClose}>
            <div className="mb-5 flex items-center gap-4">
                <Avatar size={64} src={user.avatar} icon={<UserOutlined />} />
                <div className="min-w-0 flex-1"><h2 className="truncate text-lg font-semibold">{user.username}</h2><p className="truncate text-sm text-slate-500">{user.email || 'Chưa có email'}</p></div>
                <Tag color={status.color}>{status.label}</Tag>
            </div>
            <Descriptions bordered column={{ xs: 1, sm: 2 }} size="small">
                <Descriptions.Item label="ID">#{user.id}</Descriptions.Item>
                <Descriptions.Item label="Số dư">{formatCurrency(user.balance || '0')}</Descriptions.Item>
                <Descriptions.Item label="Vai trò" span={2}>{user.roles?.length ? user.roles.map(role => <Tag key={role.id}>{role.name}</Tag>) : 'Chưa có vai trò'}</Descriptions.Item>
                <Descriptions.Item label="Xác thực email" span={2}>{date(user.email_verified_at)}</Descriptions.Item>
                {user.locked_reason && <Descriptions.Item label="Lý do khóa" span={2}>{user.locked_reason}</Descriptions.Item>}
                {user.locked_until && <Descriptions.Item label="Khóa đến" span={2}>{date(user.locked_until)}</Descriptions.Item>}
                <Descriptions.Item label="Ngày tạo">{date(user.created_at)}</Descriptions.Item>
                <Descriptions.Item label="Cập nhật">{date(user.updated_at)}</Descriptions.Item>
            </Descriptions>
        </Modal>
    </ConfigProvider>;
}
