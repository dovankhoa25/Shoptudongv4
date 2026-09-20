import React, { useEffect, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { Alert, Button, Drawer, Empty, Form, Input, InputNumber, Modal, Select, Spin, Switch, Table, Tag, Tooltip } from 'antd';
import { ArrowUpRight, Ban, Clock3, MonitorCheck, RefreshCw, Search, ShieldCheck, Users } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import type { PageProps } from '@/types';

type Section = 'overview' | 'devices' | 'blocks' | 'logins';
type Account = { user_id?: number; id?: number; username: string | null; status: string | null; deleted_at?: string | null };
type Block = { id: number; network: string; reason: string; expires_at: string | null; revoked_at?: string | null; created_at?: string; display_status?: string };
type Row = Account & {
    id: number; ip_address: string; users_count: number; successful_logins: number; first_seen: string; last_seen: string;
    users: Account[]; blocks: Block[]; user?: Account; user_agent?: string; created_at: string; expires_at?: string;
    last_seen_at?: string; approved_at?: string; display_status?: string; network?: string; reason?: string;
    is_success?: boolean; failure_reason?: string; provider?: string; meta?: { channel?: string; ip_source?: string };
};
type Pagination<T> = { data: T[]; total: number; per_page: number; current_page: number };
type Filters = { search: string; ip: string; days: number; status: string; per_page: number };
type Props = PageProps & { section: Section; filters: Filters; listing: Pagination<Row>; summary: { pending_devices: number; active_blocks: number };
    approval_required: boolean; current_ip: string; can_view_users: boolean };
type Detail = { ip: string; days: number; users: Pagination<Row>; recent: Row[]; blocks: Block[] };

const root = '/admin/ip-management/';
const sections = {
    overview: { title: 'IP dùng chung', description: 'Tra các tài khoản đăng nhập từ cùng một IP.', icon: Users },
    devices: { title: 'Duyệt thiết bị', description: 'Quản lý trình duyệt và IP được phép đăng nhập admin.', icon: MonitorCheck },
    blocks: { title: 'Chặn IP / dải IP', description: 'Theo dõi lệnh chặn, thời hạn và lịch sử mở chặn.', icon: Ban },
    logins: { title: 'Lịch sử đăng nhập', description: 'Đối chiếu tài khoản, nguồn IP và kết quả xác thực.', icon: Clock3 },
};
const statuses: Record<Section, { value: string; label: string }[]> = {
    overview: [{ value: 'shared', label: 'Từ 2 tài khoản cùng IP' }, { value: 'all', label: 'Tất cả IP đã đăng nhập' }],
    devices: [{ value: 'pending', label: 'Chờ duyệt' }, { value: 'approved', label: 'Đã duyệt' }, { value: 'expired', label: 'Hết hạn' }, { value: 'revoked', label: 'Đã thu hồi' }, { value: 'all', label: 'Tất cả' }],
    blocks: [{ value: 'active', label: 'Đang chặn' }, { value: 'expired', label: 'Hết hạn' }, { value: 'revoked', label: 'Đã mở chặn' }, { value: 'all', label: 'Tất cả' }],
    logins: [{ value: 'all', label: 'Tất cả kết quả' }, { value: 'success', label: 'Thành công' }, { value: 'failed', label: 'Thất bại' }],
};
const date = (value?: string | null) => value ? new Date(value).toLocaleString('vi-VN') : '—';
const number = (value: number) => Number(value).toLocaleString('vi-VN');
const source = (value?: string) => ({ peer: 'Kết nối trực tiếp', trusted_proxy: 'Proxy tin cậy', signed_storefront: 'Website đã xác minh' }[value || ''] || 'Chưa ghi nguồn IP');
const errors = (error: unknown) => axios.isAxiosError(error)
    ? Object.values(error.response?.data?.errors || {}).flat().join(' ') || error.response?.data?.message || 'Không thực hiện được. Vui lòng thử lại.'
    : 'Không thực hiện được. Vui lòng thử lại.';
const panel = 'rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900';

function AccountLabel({ account, link }: { account: Account; link: boolean }) {
    const id = account.user_id ?? account.id;
    const label = account.username || `Tài khoản #${id ?? '—'}`;
    return <span className="inline-flex max-w-full items-center gap-1.5">
        {link && id && !account.deleted_at ? <Link className="truncate font-medium text-indigo-600 dark:text-indigo-400" href={'/admin/users?search=' + encodeURIComponent('#' + id)}>{label}</Link>
            : <span className="truncate font-medium">{label}</span>}
        <span className="whitespace-nowrap text-xs text-slate-500">#{id}</span>
        {(account.deleted_at || account.status === 'banned' || account.status === 'locked') && <Tag color="red">{account.deleted_at ? 'Đã xóa' : 'Đã khóa'}</Tag>}
    </span>;
}

export default function IpManagementPage() {
    const { section, filters, listing, summary, approval_required, current_ip, can_view_users } = usePage<Props>().props;
    const [search, setSearch] = useState(filters.search);
    const [loading, setLoading] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [blockOpen, setBlockOpen] = useState(false);
    const [form] = Form.useForm();
    const [selectedIp, setSelectedIp] = useState<string | null>(null);
    const [detail, setDetail] = useState<Detail | null>(null);
    const [detailPage, setDetailPage] = useState(1);
    const [detailLoading, setDetailLoading] = useState(false);
    const [detailError, setDetailError] = useState('');
    const [revision, setRevision] = useState(0);
    useEffect(() => { setSearch(filters.search); }, [filters.search, section]);
    useEffect(() => { setError(''); setNotice(''); setSelectedIp(null); }, [section]);
    useEffect(() => {
        if (!selectedIp) return;
        const controller = new AbortController();
        setDetailLoading(true); setDetailError(''); setDetail(null);
        axios.get(root + 'ip-detail', { params: { ip: selectedIp, days: filters.days, page: detailPage }, signal: controller.signal })
            .then(response => setDetail(response.data)).catch(e => { if (!axios.isCancel(e)) setDetailError(errors(e)); })
            .finally(() => { if (!controller.signal.aborted) setDetailLoading(false); });
        return () => controller.abort();
    }, [selectedIp, filters.days, detailPage, revision]);

    const navigate = (changes: Partial<Filters> & { page?: number } = {}) => router.get(root + section, { ...filters, page: 1, ...changes }, {
        preserveState: true, preserveScroll: true, onStart: () => setLoading(true), onFinish: () => setLoading(false),
        onError: response => setError(Object.values(response).join(' ')),
    });
    const refresh = () => router.reload({ only: ['listing', 'summary', 'approval_required'], onStart: () => setLoading(true), onFinish: () => setLoading(false) });
    const inspect = (ip: string) => { setDetailPage(1); setSelectedIp(ip); };
    const openBlock = (ip = '') => { form.resetFields(); form.setFieldsValue({ network: ip, hours: 24 }); setBlockOpen(true); };
    const mutate = async (url: string, body?: object, remove = false) => {
        if (busy) return false;
        setBusy(true); setError(''); setNotice('');
        try {
            const response = remove ? await axios.delete(url) : await axios.post(url, body);
            setNotice(response.data.message); setRevision(value => value + 1);
            await new Promise<void>(resolve => router.reload({ only: ['listing', 'summary', 'approval_required'], onFinish: () => resolve() }));
            return true;
        } catch (e) { setError(errors(e)); return false; }
        finally { setBusy(false); }
    };
    const ipCell = (ip: string) => ip ? <button type="button" onClick={() => inspect(ip)} className="font-mono text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400">{ip}</button> : '—';
    const loginColumns = [
        { title: 'Thời gian', dataIndex: 'created_at', width: 150, render: date },
        { title: 'Tài khoản / tên nhập', key: 'user', width: 170, render: (_: unknown, row: Row) => <>
            {row.user ? <AccountLabel account={row.user} link={can_view_users} /> : <span>{row.username || 'Không xác định'}</span>}
            {row.user && row.username !== row.user.username && <div className="text-xs text-slate-500">Tên nhập: {row.username || '—'}</div>}
        </> },
        { title: 'IP / nguồn', key: 'ip', width: 180, render: (_: unknown, row: Row) => <>{ipCell(row.ip_address)}<div className="mt-1 text-xs text-slate-500">{source(row.meta?.ip_source)}</div></> },
        { title: 'Kết quả', key: 'result', width: 135, render: (_: unknown, row: Row) => <><Tag color={row.is_success ? 'green' : 'red'}>{row.is_success ? 'Thành công' : 'Thất bại'}</Tag><div className="mt-1 break-words text-xs text-slate-500">{row.failure_reason || ''}</div></> },
        { title: 'Kênh / trình duyệt', key: 'agent', width: 200, render: (_: unknown, row: Row) => <><span className="text-xs">{row.meta?.channel || '—'} · {row.provider || '—'}</span><Tooltip title={row.user_agent}><div className="mt-1 line-clamp-2 break-all text-xs text-slate-500">{row.user_agent || 'Chưa ghi trình duyệt'}</div></Tooltip></> },
    ];
    const columns = section === 'overview' ? [
        { title: 'Địa chỉ IP', key: 'ip', width: 170, render: (_: unknown, row: Row) => <>{ipCell(row.ip_address)}<div className="mt-1">{row.blocks.length > 0 ? <Tag color="red">Đang chặn</Tag> : <span className="text-xs text-slate-500">Chưa chặn</span>}</div></> },
        { title: 'Tài khoản cùng IP', key: 'users', width: 240, render: (_: unknown, row: Row) => <><button type="button" className="mb-2 text-sm font-semibold" onClick={() => inspect(row.ip_address)}>{number(row.users_count)} tài khoản <ArrowUpRight size={13} className="inline" /></button>
            <div className="flex flex-col gap-1">{row.users.map(account => <AccountLabel key={account.user_id} account={account} link={can_view_users} />)}</div>
            {row.users_count > 3 && <button type="button" onClick={() => inspect(row.ip_address)} className="mt-1 text-xs text-indigo-600">Xem thêm {number(row.users_count - 3)} tài khoản</button>}</> },
        { title: 'Đăng nhập thành công', dataIndex: 'successful_logins', width: 110, render: number },
        { title: 'Hoạt động trong kỳ', key: 'time', width: 170, render: (_: unknown, row: Row) => <><div className="text-sm">{date(row.last_seen)}</div><div className="mt-1 text-xs text-slate-500">Lần đầu: {date(row.first_seen)}</div></> },
        { title: 'Thao tác', key: 'actions', width: 160, render: (_: unknown, row: Row) => <div className="flex flex-wrap gap-2"><Button size="small" onClick={() => inspect(row.ip_address)}>Xem chi tiết</Button><Button size="small" danger disabled={busy || row.ip_address === current_ip} onClick={() => openBlock(row.ip_address)}>Chặn</Button></div> },
    ] : section === 'devices' ? [
        { title: 'Yêu cầu / tài khoản', key: 'user', width: 180, render: (_: unknown, row: Row) => <><div className="mb-1 text-xs text-slate-500">Yêu cầu #{row.id}</div>{row.user ? <AccountLabel account={row.user} link={can_view_users} /> : 'Tài khoản đã xóa'}</> },
        { title: 'IP / trình duyệt', key: 'ip', width: 230, render: (_: unknown, row: Row) => <>{ipCell(row.ip_address)}<Tooltip title={row.user_agent}><div className="mt-2 line-clamp-2 break-all text-xs text-slate-500">{row.user_agent || 'Chưa ghi trình duyệt'}</div></Tooltip></> },
        { title: 'Trạng thái', key: 'status', width: 110, render: (_: unknown, row: Row) => <Tag color={row.display_status === 'approved' ? 'green' : row.display_status === 'pending' ? 'gold' : 'default'}>{statuses.devices.find(s => s.value === row.display_status)?.label}</Tag> },
        { title: 'Gần nhất / hết hạn', key: 'time', width: 170, render: (_: unknown, row: Row) => <><div>{date(row.last_seen_at)}</div><div className="mt-1 text-xs text-slate-500">Hết hạn: {date(row.expires_at)}</div></> },
        { title: 'Thao tác', key: 'actions', width: 155, render: (_: unknown, row: Row) => <div className="flex flex-wrap gap-2">
            {row.display_status !== 'approved' && <Button type="primary" size="small" disabled={busy || !row.user || ['banned', 'locked'].includes(row.user.status || '')} onClick={() => void mutate('/admin/access-security/devices/' + row.id + '/approve')}>Duyệt</Button>}
            {row.display_status !== 'revoked' && <Button size="small" danger disabled={busy} onClick={() => void mutate('/admin/access-security/devices/' + row.id + '/revoke')}>Thu hồi</Button>}
        </div> },
    ] : section === 'blocks' ? [
        { title: 'IP / dải IP', dataIndex: 'network', width: 180, render: (value: string) => <code className="text-sm font-semibold">{value}</code> },
        { title: 'Lý do', dataIndex: 'reason', width: 240, render: (value: string) => <div className="whitespace-pre-wrap break-words">{value}</div> },
        { title: 'Trạng thái', key: 'status', width: 110, render: (_: unknown, row: Row) => <Tag color={row.display_status === 'active' ? 'red' : 'default'}>{statuses.blocks.find(s => s.value === row.display_status)?.label}</Tag> },
        { title: 'Thời hạn', key: 'time', width: 170, render: (_: unknown, row: Row) => <><div>{row.expires_at ? date(row.expires_at) : 'Không hết hạn'}</div><div className="mt-1 text-xs text-slate-500">Tạo: {date(row.created_at)}</div></> },
        { title: 'Thao tác', key: 'actions', width: 100, render: (_: unknown, row: Row) => row.display_status === 'active' && <Button size="small" disabled={busy} onClick={() => void mutate('/admin/access-security/blocks/' + row.id, undefined, true)}>Mở chặn</Button> },
    ] : loginColumns;

    return <>
        <Head title={sections[section].title + ' · Quản lý IP'} />
        <div className="space-y-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div><div className="mb-1 text-xs font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">Quản lý IP</div><h1 className="text-xl font-bold sm:text-2xl">{sections[section].title}</h1><p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{sections[section].description}</p></div>
                <div className="flex items-center gap-2"><Button icon={<RefreshCw size={14} />} loading={loading} disabled={busy} onClick={refresh}>Làm mới</Button><Button type="primary" icon={<Ban size={14} />} disabled={busy} onClick={() => openBlock()}>Chặn IP</Button></div>
            </div>
            <div className="grid grid-cols-2 gap-2 xl:grid-cols-4">
                <div className={panel + ' p-3'}><div className="text-xs text-slate-500">{section === 'overview' ? 'IP phù hợp bộ lọc' : section === 'devices' ? 'Yêu cầu phù hợp' : section === 'blocks' ? 'Lệnh chặn phù hợp' : 'Lượt đăng nhập phù hợp'}</div><div className="mt-1 text-2xl font-semibold">{number(listing.total)}</div></div>
                <Link href={root + 'devices'} className={panel + ' p-3 hover:border-indigo-400'}><div className="flex items-center justify-between text-xs text-slate-500"><span>Thiết bị chờ duyệt</span><MonitorCheck size={15} /></div><div className="mt-1 text-2xl font-semibold text-amber-600">{number(summary.pending_devices)}</div></Link>
                <Link href={root + 'blocks'} className={panel + ' p-3 hover:border-indigo-400'}><div className="flex items-center justify-between text-xs text-slate-500"><span>IP / dải đang chặn</span><Ban size={15} /></div><div className="mt-1 text-2xl font-semibold text-red-600">{number(summary.active_blocks)}</div></Link>
                <Link href={root + 'devices'} className={panel + ' p-3 hover:border-indigo-400'}><div className="flex items-center justify-between text-xs text-slate-500"><span>Duyệt đăng nhập admin</span><ShieldCheck size={15} /></div><div className={'mt-1 text-xl font-semibold ' + (approval_required ? 'text-emerald-600' : 'text-amber-600')}>{approval_required ? 'Đang bật' : 'Đang tắt'}</div></Link>
            </div>
            <nav aria-label="Quản lý IP" className="flex gap-1 overflow-x-auto rounded-lg bg-slate-100 p-1 dark:bg-slate-800">
                {(Object.keys(sections) as Section[]).map(key => { const Icon = sections[key].icon; return <Link key={key} href={root + key} className={'flex shrink-0 items-center gap-2 rounded-md px-3 py-2 text-sm font-medium ' + (section === key ? 'bg-white text-indigo-700 shadow-sm dark:bg-slate-900 dark:text-indigo-300' : 'text-slate-600 hover:bg-white/60 dark:text-slate-300 dark:hover:bg-slate-700')}><Icon size={15} />{sections[key].title}</Link>; })}
            </nav>
            {error && <Alert type="error" showIcon message={error} closable onClose={() => setError('')} />}
            {notice && <Alert type="success" showIcon message={notice} closable onClose={() => setNotice('')} />}
            {section === 'overview' && <Alert type="info" showIcon message={`Chỉ tính đăng nhập thành công trong ${filters.days} ngày. IP chung có thể do cùng mạng hoặc máy chủ trung gian, không chứng minh các tài khoản thuộc cùng một người.`} />}
            {section === 'devices' && <div className={panel + ' p-4'}>
                <div className="flex items-center justify-between gap-4"><div><h2 className="font-semibold">Duyệt đăng nhập admin</h2><p className="mt-1 max-w-3xl text-xs leading-5 text-slate-500">IP hoặc trình duyệt mới cần được duyệt. Khi bật lại, IP và trình duyệt đang dùng của bạn được duyệt để tiếp tục quản trị. Chỉ duyệt yêu cầu sau khi xác nhận với chủ tài khoản.</p></div><Switch aria-label="Duyệt đăng nhập admin" checked={approval_required} checkedChildren="Bật" unCheckedChildren="Tắt" loading={busy} disabled={loading || busy} onChange={enabled => void mutate('/admin/access-security/policy', { enabled })} /></div>
                {!approval_required && <Alert className="mt-3" type="warning" showIcon message="Đang tắt bước chờ duyệt. Tài khoản vẫn phải xác thực; các lệnh khóa tài khoản và chặn IP vẫn áp dụng." />}
            </div>}
            {section === 'logins' && <p className="text-xs text-slate-500">Lần đăng nhập thất bại chỉ phản ánh tên được nhập. Nguồn IP chưa được ghi có thể là IP máy chủ trung gian.</p>}
            <div className={panel + ' overflow-hidden'}>
                <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 p-3 dark:border-slate-800">
                    <Input.Search aria-label="Tìm kiếm IP hoặc tài khoản" className="w-full sm:w-80" prefix={<Search size={14} className="text-slate-400" />} value={search} onChange={e => setSearch(e.target.value)} onSearch={value => navigate({ search: value.trim() })} allowClear placeholder={section === 'blocks' ? 'Tìm IP, dải IP hoặc lý do...' : 'Tìm IP, username hoặc #ID...'} />
                    <Select aria-label="Lọc trạng thái" className="min-w-[185px]" value={filters.status} options={statuses[section]} onChange={status => navigate({ status })} />
                    {(section === 'overview' || section === 'logins') && <Select aria-label="Khoảng thời gian" value={filters.days} className="min-w-[145px]" options={[1, 7, 30, 90].map(value => ({ value, label: value + ' ngày gần nhất' }))} onChange={days => navigate({ days })} />}
                    {(filters.search || filters.ip || filters.status !== statuses[section][0].value) && <Button type="text" onClick={() => navigate({ search: '', ip: '', status: statuses[section][0].value })}>Xóa bộ lọc</Button>}
                    {filters.ip && <Tag closable onClose={() => navigate({ ip: '' })}>IP: {filters.ip}</Tag>}
                </div>
                <Table<Row> rowKey={section === 'overview' ? 'ip_address' : 'id'} size="small" className="[&_.ant-table-cell]:align-top" columns={columns} dataSource={listing.data} loading={loading} scroll={{ x: 900 }} locale={{ emptyText: <Empty description={section === 'overview' ? 'Không có IP phù hợp. Thử mở rộng khoảng thời gian hoặc chọn tất cả IP.' : 'Không có dữ liệu phù hợp.'} image={Empty.PRESENTED_IMAGE_SIMPLE} /> }}
                    pagination={{ current: listing.current_page, pageSize: listing.per_page, total: listing.total, showSizeChanger: true, pageSizeOptions: [20, 50, 100], showTotal: total => number(total) + ' kết quả', onChange: (page, per_page) => navigate({ page, per_page }) }} />
            </div>
        </div>
        <Drawer title={<span className="font-mono">{selectedIp}</span>} open={selectedIp !== null} width="min(900px, 100vw)" onClose={() => setSelectedIp(null)}>
            {detailError && <Alert type="error" message={detailError} action={<Button size="small" onClick={() => setRevision(v => v + 1)}>Thử lại</Button>} />}
            <Spin spinning={detailLoading}><div className="min-h-32 space-y-4">
                {detail && <>
                    <div className="flex flex-wrap items-center justify-between gap-2"><div><b>{number(detail.users.total)} tài khoản</b><span className="ml-2 text-sm text-slate-500">đăng nhập thành công · {detail.days} ngày</span></div><div className="flex gap-2"><Link href={root + 'logins?ip=' + encodeURIComponent(detail.ip) + '&days=' + detail.days} onClick={() => setSelectedIp(null)} className="rounded-md border border-slate-300 px-3 py-1 text-sm dark:border-slate-600">Lịch sử đầy đủ</Link><Button danger size="small" disabled={busy || detail.ip === current_ip} onClick={() => openBlock(detail.ip)}>Chặn IP này</Button></div></div>
                    {detail.blocks.map(block => <Alert key={block.id} type="warning" message={'Đang chặn bởi ' + block.network} description={<><div>{block.reason}</div><div>Hết hạn: {block.expires_at ? date(block.expires_at) : 'Không hết hạn'}</div></>} action={<Button size="small" disabled={busy} onClick={() => void mutate('/admin/access-security/blocks/' + block.id, undefined, true)}>Mở chặn</Button>} />)}
                    <Table<Row> size="small" rowKey="user_id" scroll={{ x: 580 }} dataSource={detail.users.data} pagination={{ current: detail.users.current_page, total: detail.users.total, pageSize: 20, showSizeChanger: false, onChange: setDetailPage }} columns={[
                        { title: 'Tài khoản', render: (_, row) => <AccountLabel account={row} link={can_view_users} /> },
                        { title: 'Lượt thành công', dataIndex: 'successful_logins', render: number },
                        { title: 'Lần đầu', dataIndex: 'first_seen', render: date }, { title: 'Gần nhất', dataIndex: 'last_seen', render: date },
                    ]} />
                    <div><h3 className="mb-2 font-semibold">10 lần đăng nhập gần nhất tại IP này</h3><Table<Row> size="small" rowKey="id" dataSource={detail.recent} columns={loginColumns} pagination={false} scroll={{ x: 900 }} /></div>
                </>}
            </div></Spin>
        </Drawer>
        <Modal title="Chặn IP / dải IP" open={blockOpen} onCancel={() => !busy && setBlockOpen(false)} footer={null} destroyOnClose>
            <Alert className="mb-4" type="warning" showIcon message="Chặn cả dải ảnh hưởng mọi tài khoản cùng dải đó." description={'IP bạn đang dùng: ' + current_ip + '. Hệ thống không cho chặn dải chứa IP này.'} />
            {error && <Alert className="mb-3" type="error" message={error} />}
            <Form form={form} layout="vertical" initialValues={{ hours: 24 }} onFinish={async values => { if (await mutate('/admin/access-security/blocks', values)) setBlockOpen(false); }}>
                <Form.Item name="network" label="IP hoặc CIDR" rules={[{ required: true, message: 'Nhập IP hoặc dải IP.' }]}><Input className="!bg-transparent !text-inherit" autoComplete="off" placeholder="203.0.113.25 hoặc 203.0.113.0/24" maxLength={49} /></Form.Item>
                <Form.Item name="hours" label="Số giờ chặn (để trống: không hết hạn)"><InputNumber min={1} max={8760} className="w-full" /></Form.Item>
                <Form.Item name="reason" label="Lý do" rules={[{ required: true, whitespace: true, message: 'Nhập lý do chặn.' }]}><Input.TextArea rows={3} maxLength={500} showCount /></Form.Item>
                <div className="flex justify-end gap-2"><Button disabled={busy} onClick={() => setBlockOpen(false)}>Hủy</Button><Button danger type="primary" htmlType="submit" loading={busy}>Chặn IP / dải IP</Button></div>
            </Form>
        </Modal>
    </>;
}

IpManagementPage.layout = (page: React.ReactNode) => <AdminLayout title="Quản lý IP">{page}</AdminLayout>;
