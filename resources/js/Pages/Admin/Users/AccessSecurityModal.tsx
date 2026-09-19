import { useCallback, useEffect, useState } from 'react';
import axios from 'axios';
import { Alert, Button, ConfigProvider, Form, Input, InputNumber, Modal, Select, Switch, Table, Tabs, Tag, theme } from 'antd';
import { useTheme } from '@/Providers/ThemeProvider';
type Row = Record<string, any>;
const date = (value?: string) => value ? new Date(value).toLocaleString('vi-VN') : '—';
const statuses: Record<string, string> = { pending: 'Chờ duyệt', approved: 'Đã duyệt', revoked: 'Đã thu hồi' };

export default function AccessSecurityModal({ onClose }: { onClose: () => void }) {
    const { darkMode } = useTheme();
    const [data, setData] = useState<{ devices: { data: Row[]; total: number }; blocks: { data: Row[]; total: number }; current_ip: string; admin_approval_required: boolean } | null>(null);
    const [status, setStatus] = useState('pending');
    const [page, setPage] = useState(1);
    const [blockPage, setBlockPage] = useState(1);
    const [loading, setLoading] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [form] = Form.useForm();
    const load = useCallback(async () => {
        setLoading(true);
        try { setData((await axios.get('/admin/access-security', { params: { status: status || undefined, page, block_page: blockPage } })).data); }
        catch (e: any) { setError(e.response?.data?.message || 'Không tải được dữ liệu bảo mật.'); }
        finally { setLoading(false); }
    }, [status, page, blockPage]);
    useEffect(() => { void load(); }, [load]);
    const mutate = async (url: string, body?: Row, remove = false) => {
        if (busy) return;
        setBusy(true); setError(''); setNotice('');
        try {
            const response = remove ? await axios.delete(url) : await axios.post(url, body);
            setNotice(response.data.message);
            if (typeof response.data.admin_approval_required === 'boolean') {
                setData(current => current ? { ...current, admin_approval_required: response.data.admin_approval_required } : current);
            }
            if (body?.network) form.resetFields();
            await load();
        } catch (e: any) { setError(Object.values(e.response?.data?.errors || {}).flat().join(' ') || e.response?.data?.message || 'Thao tác thất bại.'); }
        finally { setBusy(false); }
    };
    return <ConfigProvider theme={{ algorithm: darkMode ? theme.darkAlgorithm : theme.defaultAlgorithm }}>
        <Modal open width={1100} footer={null} title="Bảo mật truy cập" onCancel={onClose}>
            {error && <Alert className="mb-3" type="error" message={error} />}
            {notice && <Alert className="mb-3" type="success" message={notice} />}
            <Tabs items={[
                { key: 'devices', label: 'Duyệt IP và thiết bị quản trị', children: <>
                    <div className="mb-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <div className="flex items-center justify-between gap-3">
                            <b>Duyệt đăng nhập admin</b>
                            <Switch aria-label="Duyệt đăng nhập admin" checked={data?.admin_approval_required ?? true}
                                checkedChildren="Bật" unCheckedChildren="Tắt" loading={busy}
                                disabled={!data || loading || busy}
                                onChange={enabled => void mutate('/admin/access-security/policy', { enabled })} />
                        </div>
                        <p className="mt-2 text-sm text-gray-600 dark:text-gray-300">
                            Khi bật, đăng nhập từ IP hoặc trình duyệt mới phải được duyệt. IP và trình duyệt đang dùng của người bật được duyệt để tiếp tục quản trị.
                        </p>
                    </div>
                    {data && !data.admin_approval_required && <Alert className="mb-3" type="warning"
                        message="Đang tắt duyệt đăng nhập admin: IP và trình duyệt mới có thể đăng nhập sau khi xác thực tài khoản. Chặn IP và khóa tài khoản vẫn có hiệu lực; lịch sử duyệt được giữ nguyên." />}
                    <Alert className="mb-3" type="info" message="Chỉ duyệt khi đã xác nhận với chủ tài khoản. Mỗi lần duyệt gắn với một trình duyệt và đúng IP, có hiệu lực 90 ngày. Sau khi duyệt, người dùng đăng nhập lại." />
                    <div className="mb-3 flex gap-2"><Select value={status} onChange={value => { setStatus(value); setPage(1); }} options={[
                        { value: 'pending', label: 'Chờ duyệt' }, { value: 'approved', label: 'Đã duyệt' }, { value: 'revoked', label: 'Đã thu hồi' }, { value: '', label: 'Tất cả' },
                    ]} /><Button loading={loading} onClick={load}>Làm mới</Button></div>
                    <Table size="small" rowKey="id" loading={loading} scroll={{ x: 950 }} dataSource={data?.devices.data} pagination={{
                        current: page, pageSize: 20, total: data?.devices.total || 0, showSizeChanger: false, onChange: setPage,
                    }} columns={[
                        { title: 'Yêu cầu / tài khoản', render: (_, row: Row) => <><b>#{row.id}</b><div>{row.user?.username} · user #{row.user_id}</div></> },
                        { title: 'IP', dataIndex: 'ip_address' },
                        { title: 'Trình duyệt', dataIndex: 'user_agent', width: 260, render: value => <div className="break-all text-xs">{value}</div> },
                        { title: 'Lần yêu cầu', dataIndex: 'last_seen_at', render: date },
                        { title: 'Trạng thái', render: (_, row: Row) => <><Tag>{statuses[row.status]}</Tag><div className="text-xs">Hết hạn: {date(row.expires_at)}</div></> },
                        { title: 'Thao tác', render: (_, row: Row) => <div className="flex gap-2">
                            <Button size="small" loading={busy} onClick={() => mutate('/admin/access-security/devices/' + row.id + '/approve')}>Duyệt</Button>
                            {row.status !== 'revoked' && <Button danger size="small" disabled={busy} onClick={() => mutate('/admin/access-security/devices/' + row.id + '/revoke')}>Thu hồi</Button>}
                        </div> },
                    ]} />
                </> },
                { key: 'blocks', label: 'Chặn IP / dải IP', children: <>
                    <Alert className="mb-3" type="warning" message={'Chặn dải IP ảnh hưởng mọi người cùng dải. IP hiện tại của bạn: ' + (data?.current_ip || '—') + '. Áp dụng tại backend cho web và API người dùng; webhook thanh toán và worker dùng xác thực riêng.'} />
                    <Form form={form} layout="vertical" onFinish={values => mutate('/admin/access-security/blocks', values)} initialValues={{ hours: 24 }}>
                        <div className="grid gap-x-3 sm:grid-cols-2">
                            <Form.Item name="network" label="IP hoặc CIDR" rules={[{ required: true, message: 'Nhập IP hoặc dải IP.' }]}><Input placeholder="203.0.113.25 hoặc 203.0.113.0/24" maxLength={49} /></Form.Item>
                            <Form.Item name="hours" label="Số giờ chặn (để trống: không hết hạn)"><InputNumber min={1} max={8760} className="w-full" /></Form.Item>
                        </div>
                        <Form.Item name="reason" label="Lý do" rules={[{ required: true, message: 'Nhập lý do chặn.' }]}><Input maxLength={500} /></Form.Item>
                        <Button danger type="primary" htmlType="submit" loading={busy}>Chặn IP / dải IP</Button>
                    </Form>
                    <Table className="mt-4" rowKey="id" size="small" dataSource={data?.blocks.data} pagination={{
                        current: blockPage, pageSize: 20, total: data?.blocks.total || 0, showSizeChanger: false, onChange: setBlockPage,
                    }} columns={[
                        { title: 'Dải IP', dataIndex: 'network' }, { title: 'Lý do', dataIndex: 'reason' },
                        { title: 'Hết hạn', dataIndex: 'expires_at', render: value => value ? date(value) : 'Không hết hạn' },
                        { title: 'Thao tác', render: (_, row: Row) => <Button disabled={busy} onClick={() => mutate('/admin/access-security/blocks/' + row.id, undefined, true)}>Mở chặn</Button> },
                    ]} />
                </> },
            ]} />
        </Modal>
    </ConfigProvider>;
}
