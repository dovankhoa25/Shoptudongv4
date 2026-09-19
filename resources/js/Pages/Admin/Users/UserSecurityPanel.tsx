import { useEffect, useState } from 'react';
import axios from 'axios';
import { Alert, Button, Input, Pagination, Spin, Table, Tabs, Tag } from 'antd';

type Row = Record<string, any>;
type History = {
    attempts: { data: Row[]; current_page: number; total: number; per_page: number };
    ips: Row[]; events: Row[]; sessions: Row[];
};
const date = (value?: string) => value ? new Date(value).toLocaleString('vi-VN') : '—';
export default function UserSecurityPanel({ userId }: { userId: number }) {
    const [data, setData] = useState<History | null>(null);
    const [page, setPage] = useState(1);
    const [tab, setTab] = useState('attempts');
    const [ip, setIp] = useState('');
    const [filter, setFilter] = useState('');
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    useEffect(() => {
        const controller = new AbortController();
        setLoading(true); setError('');
        axios.get('/admin/users/' + userId + '/security', {
            params: { page, ip: filter || undefined }, signal: controller.signal,
        }).then(r => setData(r.data)).catch(e => {
            if (!axios.isCancel(e)) setError(e.response?.data?.message || 'Không tải được lịch sử.');
        }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
        return () => controller.abort();
    }, [userId, page, filter]);
    const attempts = <>
        <div className="mb-3 flex gap-2">
            <Input value={ip} onChange={e => setIp(e.target.value)} placeholder="Lọc theo địa chỉ IP đầy đủ"
                onPressEnter={() => { setPage(1); setFilter(ip.trim()); }} />
            <Button onClick={() => { setPage(1); setFilter(ip.trim()); }}>Lọc</Button>
            <Button onClick={() => { setIp(''); setFilter(''); setPage(1); }}>Xóa lọc</Button>
        </div>
        <Table size="small" rowKey="id" pagination={false} scroll={{ x: 850 }} dataSource={data?.attempts.data} columns={[
            { title: 'Thời gian', dataIndex: 'created_at', render: date, width: 165 },
            { title: 'IP / kênh', render: (_, row: Row) => <><code>{row.ip_address || '—'}</code><div className="text-xs text-slate-500">{row.meta?.channel || row.provider} · {row.meta?.ip_source || 'Chưa ghi nguồn IP'}</div></> },
            { title: 'Tên khi đăng nhập', dataIndex: 'username', ellipsis: true },
            { title: 'Kết quả', render: (_, row: Row) => <><Tag color={row.is_success ? 'green' : 'red'}>{row.is_success ? 'Thành công' : 'Bị từ chối'}</Tag><div className="text-xs">{row.failure_reason}</div></> },
            { title: 'Trình duyệt / User-Agent', dataIndex: 'user_agent', width: 240, render: value => <div className="break-all text-xs">{value || '—'}</div> },
        ]} />
        <Pagination className="mt-3" current={page} pageSize={20} total={data?.attempts.total || 0} showSizeChanger={false} onChange={setPage} />
    </>;
    return <Spin spinning={loading}>
        {error && <Alert type="error" message={error} className="mb-3" />}
        <Alert className="mb-3" type="info" message="User-Agent mô tả trình duyệt, không xác định chắc chắn thiết bị. IP cũ có thể là IP máy chủ trung gian; đối chiếu nguồn trước khi chặn dải IP." />
        <Tabs activeKey={tab} onChange={setTab} items={[
            { key: 'attempts', label: 'Đăng nhập', children: attempts },
            { key: 'ips', label: 'Các IP', children: <Table size="small" rowKey="ip_address" dataSource={data?.ips} pagination={{ pageSize: 10 }} scroll={{ x: 750 }} columns={[
                { title: 'IP', dataIndex: 'ip_address', render: value => <Button type="link" onClick={() => { setIp(value); setFilter(value); setPage(1); setTab('attempts'); }}>{value}</Button> },
                { title: 'Số lần', dataIndex: 'total' }, { title: 'Thành công', dataIndex: 'successes' },
                { title: 'Lần đầu', dataIndex: 'first_seen', render: date }, { title: 'Gần nhất', dataIndex: 'last_seen', render: date },
            ]} /> },
            { key: 'events', label: 'Sự kiện bảo mật', children: <Table size="small" rowKey="id" dataSource={data?.events} columns={[
                { title: 'Thời gian', dataIndex: 'created_at', render: date }, { title: 'Sự kiện', dataIndex: 'event' }, { title: 'IP', dataIndex: 'ip_address' },
            ]} /> },
            { key: 'sessions', label: 'Phiên API', children: <Table size="small" rowKey="id" dataSource={data?.sessions} columns={[
                { title: 'IP', dataIndex: 'ip_address' }, { title: 'Hoạt động cuối', dataIndex: 'last_activity_at', render: date },
                { title: 'Trạng thái', render: (_, row: Row) => row.is_revoked ? 'Đã thu hồi: ' + (row.revoked_reason || '') : 'Chưa thu hồi' },
            ]} /> },
        ]} />
    </Spin>;
}
