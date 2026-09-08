import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Alert, Button, Card, Collapse, Dropdown, Form, Input, InputNumber, Modal, Select, Space, Switch, Table, Tabs, Tag, message } from 'antd';
import AdminLayout from '@/Layouts/AdminLayout';
import NroSnapshotPanel, { NroIcon, NroItem, NroSnapshot } from '@/Components/Nro/NroSnapshot';
import NroAccountImport from '@/Components/Nro/NroAccountImport';
import NroDraftAttributes from '@/Components/Nro/NroDraftAttributes';
import NroNickAttributes from '@/Components/Nro/NroNickAttributes';

type NickListing = { id: number; status: string; price: string; description: string; categoryId: number; categoryName?: string; categorySlug?: string; categoryActive: boolean; snapshotId?: number };
type PublishConfig = { categoryId: number; price: number; description?: string; attributeSelections?: Record<string, number | null> };
type Account = { publishStatus?: string; publishError?: string; publishConfig?: PublishConfig; id: number; account_name: string; server_index: number; server_id: number; server_game_id: number; delivery_map: number; delivery_zone: number; delivery_zone_mode: 'auto' | 'fixed'; wait_minutes: number; usage_type: string; character_name?: string; last_synced_at?: string; latest_snapshot_id?: number; status: string; nick: NickListing | null; listingCounts: { total: number; active: number } | null };
type Inventory = { listed: number; selectable: number; sellable: boolean; id: number; quantity: number; reserved: number; item: NroItem; locations: { location: string; slot: number; quantity: number }[] };
type Listing = { description?: string; id: number; accountId: number; title: string; price: string; available: number; stockAvailable?: number; unavailableReasons?: string[]; status: string; items: { item: NroItem; quantity: number }[] };
type Order = { serverName?: string; session?: { status: string; mode: string; expiresAt?: string }; items: { id: number; quantity: number; delivered: number; item: NroItem }[]; id: number; title: string; recipientName: string; serverIndex: number; price: string; status: string; message?: string };
type Job = { id: number; account_id: number; order_id?: number; type: string; status: string; result_json?: string };
type WorkerKey = { id: number; name: string; last_used_at?: string; revoked_at?: string; accepts_delivery: boolean };
type AccountFilters = { q?: string; usage?: string; server?: number; state?: string };
type Props = { accountStats?: { total: number; nick: number; warehouse: number; attention: number }; accountFilters?: AccountFilters; accountPagination?: { total: number; current: number; pageSize: number }; salePolicy: { enabled: boolean; ids: number[] }; shopUrl: string | null; capabilities: Record<string, boolean>; servers: { id: number; name: string; name_view: string }[]; loginServers: { id: number; name: string }[]; accounts: Account[]; categories: { id: number; name: string }[]; listings: Listing[]; orders: Order[]; jobs: Job[]; canReconcile: boolean; workerKeys: WorkerKey[] };
const statusName: Record<string, string> = { awaiting_receipt: 'Chưa nhận đồ', expired: 'Hết phiên chờ', queued: 'Chờ tool', processing: 'Đang xử lý', completed: 'Hoàn tất', review: 'Chờ đối soát', failed: 'Thất bại', refunded: 'Đã hoàn tiền', active: 'Đang bán', paused: 'Tạm dừng', sold: 'Đã bán' };
const base = '/admin/nro-shop';
const EMPTY_FILTERS: AccountFilters = {};
const money = (price: string | number) => `${Number(price).toLocaleString('vi-VN')}đ`;
const isSold = (a: Account) => a.status === 'sold' || a.nick?.status === 'sold';

function ListingAvailability({ listing }: { listing: Listing }) {
    if (listing.status === 'sold') return <span className="text-xs text-slate-500 dark:text-slate-400">Đã ẩn khỏi shop · Theo dõi tại Đơn giao đồ</span>;
    return <div className="min-w-40 text-xs"><strong className="text-sm">{listing.available} gói có thể mua</strong><div className="mt-1 text-slate-500 dark:text-slate-400">Tồn chưa giữ cho đơn: {listing.stockAvailable ?? listing.available} gói</div>{listing.unavailableReasons?.map(reason => <div key={reason} className="mt-1 text-amber-600 dark:text-amber-400">{reason}</div>)}</div>;
}

function NickSaleSummary({ account: a, shopUrl, categories }: { account: Account; shopUrl: string | null; categories: { id: number; name: string }[] }) {
    const nick = a.nick;
    if (!nick && a.publishStatus && !isSold(a)) return <div className="max-w-sm space-y-1"><Tag color={a.publishStatus === 'waiting_snapshot' ? 'blue' : 'orange'}>{{ waiting_snapshot: 'Chờ tool lấy dữ liệu → tự đăng', needs_attention: 'Chờ bổ sung', scan_failed: 'Lấy dữ liệu lỗi', publish_failed: 'Đăng tin lỗi' }[a.publishStatus] || 'Chưa đăng bán'}</Tag>{a.publishConfig && <div className="text-xs">{categories.find(c => c.id === a.publishConfig?.categoryId)?.name || `Danh mục #${a.publishConfig.categoryId}`} · {money(a.publishConfig.price)}</div>}{a.publishError && <p className="text-xs text-amber-600 dark:text-amber-400">{a.publishError}</p>}</div>;
    if (!nick) return <Tag color={isSold(a) ? 'default' : a.latest_snapshot_id ? 'gold' : undefined}>{isSold(a) ? 'Đã bán' : a.latest_snapshot_id ? 'Chưa đăng bán' : 'Chưa lấy dữ liệu'}</Tag>;
    return <div className="space-y-1">
        <div><Tag color={isSold(a) ? 'default' : nick.status === 'not_sold' ? 'green' : 'orange'}>{isSold(a) ? 'Đã bán' : nick.status === 'not_sold' ? 'Đang bán' : 'Ngừng bán'}</Tag><strong>Nick #{nick.id}</strong></div>
        <div className="text-sm">{nick.categoryName || 'Danh mục không còn tồn tại'} · <strong>{money(nick.price)}</strong></div>
        {!nick.categoryActive && <div className="text-xs text-amber-600">Danh mục đang ẩn hoặc không còn hoạt động</div>}
        {!isSold(a) && nick.snapshotId !== a.latest_snapshot_id && <div className="text-xs text-amber-600">Có snapshot mới chưa cập nhật vào tin</div>}
        {shopUrl && <a className="text-xs text-blue-500" href={`${shopUrl}/nick/${nick.id}`} target="_blank" rel="noopener noreferrer">Xem tin trên shop ↗</a>}
    </div>;
}

export default function NroShop({ accountStats, accountFilters = EMPTY_FILTERS, accountPagination, accounts, categories, listings, orders, jobs, canReconcile, workerKeys, capabilities: caps, servers, loginServers, shopUrl, salePolicy }: Props) {
    const [settings, setSettings] = useState<Account | null>(null);
    const [settingsForm] = Form.useForm();
    const [adding, setAdding] = useState(false);
    const [importing, setImporting] = useState(false);
    const [filters, setFilters] = useState<AccountFilters>(accountFilters);
    const stats = accountStats || { total: accounts.length, nick: accounts.filter(a => a.usage_type === 'nick').length, warehouse: accounts.filter(a => a.usage_type === 'warehouse').length, attention: accounts.filter(a => ['needs_attention', 'scan_failed', 'publish_failed'].includes(a.publishStatus || '')).length };
    const applyFilters = (next: AccountFilters = filters, page = 1) => { setFilters(next); router.get(base, { ...next, page }, { preserveState: true, preserveScroll: true, only: ['accounts', 'accountPagination', 'accountFilters', 'accountStats', 'jobs'] }); };
    useEffect(() => { setFilters(accountFilters); }, [accountFilters]);
    const [draftReady, setDraftReady] = useState(false);
    const [accountImages, setAccountImages] = useState<File[]>([]);
    const [busy, setBusy] = useState(false);
    const [account, setAccount] = useState<Account | null>(null);
    const [detailOpen, setDetailOpen] = useState(false);
    const [warehouse, setWarehouse] = useState<Account | null>(null);
    const [warehouseListings, setWarehouseListings] = useState<{ data: Listing[]; total: number; page: number; perPage: number }>({ data: [], total: 0, page: 1, perPage: 20 });
    const [warehouseLoading, setWarehouseLoading] = useState(false);
    const warehouseRequest = useRef(0);
    const [snapshot, setSnapshot] = useState<NroSnapshot | null>(null);
    const [inventory, setInventory] = useState<Inventory[]>([]);
    const [selected, setSelected] = useState<Record<number, number>>({});
    const [itemSearch, setItemSearch] = useState('');
    const [itemFilter, setItemFilter] = useState('sellable');
    const [itemSort, setItemSort] = useState('name');
    const [policyOpen, setPolicyOpen] = useState(false);
    const [policyForm] = Form.useForm();
    const searchText = (s: string) => s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd').toLowerCase();
    const filteredInventory = inventory.filter(i => (itemFilter === 'all' || (itemFilter === 'sellable' ? i.sellable : !i.sellable)) &&
        searchText(`${i.item.name} ${i.item.templateId} ${i.item.optionLabels?.join(' ') || ''}`).includes(searchText(itemSearch.trim())))
        .sort((a, b) => itemSort === 'quantity_desc' ? b.selectable - a.selectable : itemSort === 'quantity_asc' ? a.selectable - b.selectable : a.item.name.localeCompare(b.item.name, 'vi'));
    const inventoryReady = snapshot?.completeness.bag === true && snapshot?.completeness.chest === true;
    const [publish, setPublish] = useState(false);
    const [attributesReady, setAttributesReady] = useState(false);
    const [reconcile, setReconcile] = useState<Job | null>(null);
    const [editingPassword, setEditingPassword] = useState<Account | null>(null);
    const [newPassword, setNewPassword] = useState('');
    const [keyName, setKeyName] = useState('Máy NRO'); const [newKey, setNewKey] = useState('');
    const [accountForm] = Form.useForm(); const [publishForm] = Form.useForm(); const [reconcileForm] = Form.useForm();
    const usageType = Form.useWatch('usageType', accountForm);
    useEffect(() => {
        if (!accounts.some(a => a.publishStatus === 'waiting_snapshot') && !jobs.some(j => ['queued', 'processing'].includes(j.status))) return;
        const timer = window.setInterval(() => router.reload({ only: ['accounts', 'jobs', 'accountStats', 'accountPagination'] }), 10000);
        return () => window.clearInterval(timer);
    }, [accounts, jobs]);
    const reload = () => router.reload({ only: ['accounts', 'listings', 'orders', 'jobs', 'workerKeys', 'salePolicy', 'accountStats', 'accountPagination'] });
    const run = async (action: () => Promise<unknown>, success = 'Đã lưu') => {
        setBusy(true);
        try { await action(); message.success(success); reload(); }
        catch (e) { message.error(axios.isAxiosError(e) ? e.response?.data?.message || 'Yêu cầu thất bại' : 'Yêu cầu thất bại'); }
        finally { setBusy(false); }
    };
    const beginPublish = (a: Account) => {
        publishForm.resetFields(); setAttributesReady(false);
        if (!a.nick && a.publishConfig) publishForm.setFieldsValue(a.publishConfig);
        if (a.nick) publishForm.setFieldsValue({ nickId: a.nick.id, categoryId: a.nick.categoryId, price: Number(a.nick.price), description: a.nick.description });
        setDetailOpen(false); setPublish(true);
    };
    const canPublish = (a: Account) => !isSold(a) && a.status === 'active' && !!a.latest_snapshot_id && (a.usage_type === 'nick' ? (a.nick ? caps.editNick : caps.publishNick) : caps.manageListings);
    const inspect = async (a: Account, intent: 'view' | 'publish' = 'view') => {
        setBusy(true); setAccount(null); setSnapshot(null); setInventory([]); setSelected({});
        try {
            const { data } = await axios.get(`${base}/accounts/${a.id}`);
            const fresh = { ...a, status: data.status, nick: data.nick, latest_snapshot_id: data.latestSnapshotId, publishConfig: data.publishConfig, publishStatus: data.publishStatus, publishError: data.publishError };
            setAccount(fresh); setSnapshot(data.snapshot); setInventory(data.inventory);
            if (intent === 'publish' && canPublish(fresh)) beginPublish(fresh); else setDetailOpen(true);
        }
        catch { message.error('Không tải được dữ liệu acc'); } finally { setBusy(false); }
    };
    const showWarehouse = async (a: Account, page = 1) => {
        const requestId = ++warehouseRequest.current;
        setWarehouse(a); setWarehouseLoading(true); setWarehouseListings({ data: [], total: 0, page, perPage: 20 });
        try { const { data } = await axios.get(`${base}/accounts/${a.id}/listings`, { params: { page } }); if (requestId === warehouseRequest.current) setWarehouseListings(data); }
        catch { if (requestId === warehouseRequest.current) message.error('Không tải được gói đồ của acc'); } finally { if (requestId === warehouseRequest.current) setWarehouseLoading(false); }
    };
    return <AdminLayout title="Quản lý acc & kho đồ NRO">
        {importing && <NroAccountImport open={importing} onClose={() => setImporting(false)} onImported={() => applyFilters({}, 1)} servers={servers} loginServers={loginServers} categories={categories} />}
        <Modal zIndex={1200} title="Danh sách ID đồ được phép bán" open={policyOpen} onCancel={() => setPolicyOpen(false)} footer={null} destroyOnHidden>
            <p className="mb-3 text-sm">Áp dụng chung cho các kho và gói chưa mua. Đơn đã mua vẫn được giữ để giao. Đây là danh sách shop cho phép đăng, không thay thế điều kiện giao dịch trong game.</p>
            <Form form={policyForm} layout="vertical" onFinish={v => run(async () => {
                const parts = String(v.ids || '').trim().split(/[\s,;]+/).filter(Boolean);
                if (parts.some(id => !/^\d+$/.test(id) || Number(id) > 100000)) throw new Error('invalid IDs');
                await axios.patch(`${base}/sale-policy`, { enabled: !!v.enabled, ids: [...new Set(parts.map(Number))] });
                setPolicyOpen(false); if (account) await inspect(account);
            })}>
                <Form.Item name="enabled" label="Chỉ cho phép các ID bên dưới" valuePropName="checked"><Switch /></Form.Item>
                <Form.Item name="ids" label="ID mẫu vật phẩm (template ID)" extra="Ngăn cách bằng dấu phẩy, khoảng trắng hoặc xuống dòng. Bật giới hạn và để trống sẽ không cho đăng món nào." rules={[{ validator: (_, value) => String(value || '').trim().split(/[\s,;]+/).filter(Boolean).every(id => /^\d+$/.test(id) && Number(id) <= 100000) ? Promise.resolve() : Promise.reject(new Error('Nhập ID số nguyên từ 0 đến 100000.')) }]}><Input.TextArea rows={6} placeholder="Nhập ID của các món được phép bán" /></Form.Item>
                <Button type="primary" htmlType="submit" loading={busy}>Lưu danh sách ID</Button>
            </Form>
        </Modal>
        <div className="space-y-4 p-2">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div><h1 className="text-xl font-bold">Acc & kho đồ NRO</h1><p className="mt-1 text-xs text-slate-500">Theo dõi tài khoản, tin bán và công việc lấy dữ liệu.</p></div>
                <Space wrap><Button onClick={reload}>Làm mới</Button>{caps.salePolicy && <Dropdown menu={{ items: [{ key: 'policy', label: 'ID đồ được phép bán', onClick: () => { policyForm.setFieldsValue({ enabled: salePolicy.enabled, ids: salePolicy.ids.join(', ') }); setPolicyOpen(true); } }] }}><Button>Cấu hình ▾</Button></Dropdown>}{caps.manageAccounts && <><Button onClick={() => setImporting(true)}>Nhập list / TXT</Button><Button type="primary" onClick={() => { accountForm.resetFields(); setAccountImages([]); setDraftReady(false); setAdding(true); }}>Thêm acc game</Button></>}</Space>
            </div>
            {caps.accounts && <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">{[
                { label: 'Tất cả acc', value: stats.total, filter: {} }, { label: 'Bán nguyên nick', value: stats.nick, filter: { usage: 'nick' } },
                { label: 'Kho đồ', value: stats.warehouse, filter: { usage: 'warehouse' } }, { label: 'Cần xử lý', value: stats.attention, filter: { state: 'attention' } },
            ].map(card => <button key={card.label} onClick={() => applyFilters(card.filter)} className="rounded-lg border border-slate-200 bg-white p-3 text-left hover:border-blue-500 dark:border-slate-700 dark:bg-slate-900"><span className="text-xs text-slate-500 dark:text-slate-400">{card.label}</span><strong className="mt-1 block text-xl">{card.value}</strong></button>)}</div>}
            <Collapse size="small" items={[{ key: 'guide', label: 'Cách thêm acc và đăng bán', children: <p className="text-xs leading-5">Bán nguyên nick: chọn danh mục, giá khi thêm; tool lấy snapshot rồi tự đăng. Kho đồ: lấy dữ liệu, chọn món để tạo gói. Dữ liệu kho đầy đủ không hết hạn theo thời gian; sau thay đổi tồn kho, tool sẽ tự cập nhật lại.</p> }]} />
            <Tabs items={[
                { key: 'accounts', label: `Tài khoản (${accountPagination?.total ?? accounts.length})`, children: <>
                    <div className="mb-3 flex flex-wrap gap-2">
                        <Input.Search aria-label="Tìm tài khoản" placeholder="Tài khoản hoặc tên nhân vật" className="!w-64" value={filters.q || ''} onChange={e => setFilters({ ...filters, q: e.target.value })} onSearch={() => applyFilters()} allowClear />
                        <Select aria-label="Lọc loại acc" className="min-w-36" placeholder="Tất cả loại acc" allowClear value={filters.usage || undefined} onChange={usage => setFilters({ ...filters, usage })} options={[{ value: 'nick', label: 'Bán nguyên nick' }, { value: 'warehouse', label: 'Kho đồ' }]} />
                        <Select aria-label="Lọc server" className="min-w-36" placeholder="Tất cả server" allowClear value={filters.server || undefined} onChange={server => setFilters({ ...filters, server })} options={servers.map(v => ({ value: v.id, label: v.name_view || v.name }))} />
                        <Select aria-label="Lọc trạng thái" className="min-w-40" placeholder="Tất cả trạng thái" allowClear value={filters.state || undefined} onChange={state => setFilters({ ...filters, state })} options={[{ value: 'waiting', label: 'Chờ tự đăng' }, { value: 'published', label: 'Nick đang bán' }, { value: 'attention', label: 'Cần xử lý' }, { value: 'sold', label: 'Đã bán' }]} />
                        <Button type="primary" onClick={() => applyFilters()}>Lọc</Button><Button onClick={() => applyFilters({})}>Xóa lọc</Button>
                    </div>
                    <Table size="small" rowKey="id" dataSource={accounts} pagination={{ current: accountPagination?.current || 1, total: accountPagination?.total ?? accounts.length, pageSize: accountPagination?.pageSize || 30, showSizeChanger: false, showTotal: total => total + ' acc', onChange: page => applyFilters(filters, page) }} scroll={{ x: 1000 }} columns={[
                    { title: 'Acc', dataIndex: 'account_name', render: (v, a) => <><strong>{v}</strong><div className="text-xs text-slate-500">#{a.id} · {a.character_name || 'Chưa quét'}</div></> },
                    { title: 'Server', dataIndex: 'server_id', render: v => servers.find(s => s.id === v)?.name_view || 'Chưa cấu hình' },
                    { title: 'Vai trò', dataIndex: 'usage_type', render: v => <Tag>{v === 'nick' ? 'Bán nick' : 'Kho đồ'}</Tag> },
                    { title: 'Tin bán', width: 310, render: (_, a) => a.usage_type === 'nick' ? <NickSaleSummary account={a} shopUrl={shopUrl} categories={categories} /> : a.listingCounts ? <div><Button type="link" className="!p-0" onClick={() => showWarehouse(a)}>{a.listingCounts.active} gói đang bán</Button><div className="text-xs text-slate-500">{a.listingCounts.total} gói tổng cộng · {a.listingCounts.total - a.listingCounts.active} gói đã bán / tạm dừng</div></div> : <span className="text-slate-500">Không có quyền xem gói đồ</span> },
                    { title: 'Dữ liệu', dataIndex: 'last_synced_at', width: 145, render: v => <span className="text-xs text-slate-500 dark:text-slate-400">{v ? new Date(v).toLocaleString('vi-VN') : 'Chờ lấy / cập nhật'}</span> },
                    { title: 'Thao tác', width: 280, render: (_, a) => <Space wrap>
                        {(caps.readAccountSnapshots || (a.usage_type === 'nick' ? caps.publishNick : caps.manageListings)) && <Button disabled={busy} onClick={() => inspect(a)}>Xem dữ liệu</Button>}
                        {canPublish(a) && <Button type="primary" disabled={busy} onClick={() => inspect(a, a.usage_type === 'nick' ? 'publish' : 'view')}>{a.usage_type === 'warehouse' ? 'Tạo gói đồ' : a.nick ? 'Sửa tin bán' : 'Đăng bán'}</Button>}
                        {(caps.manageAccounts || caps.settings) && <Dropdown trigger={['click']} menu={{ items: [
                            ...(caps.manageAccounts ? [{ key: 'scan', label: 'Lấy dữ liệu', disabled: busy || isSold(a) || a.status !== 'active', onClick: () => run(() => axios.post(`${base}/accounts/${a.id}/scan`), 'Đã xếp hàng lấy dữ liệu') },
                                { key: 'password', label: 'Sửa mật khẩu', disabled: isSold(a) || a.status !== 'active', onClick: () => { setEditingPassword(a); setNewPassword(''); } }] : []),
                            ...(caps.settings ? [{ key: 'settings', label: 'Cấu hình', disabled: isSold(a), onClick: () => { setSettings(a); settingsForm.setFieldsValue(a); } }] : []),
                        ] }}><Button>Quản lý acc ▾</Button></Dropdown>}
                    </Space> },
                ]} /></> },
                { key: 'listings', label: `Gói đồ (${listings.length})`, children: <Table rowKey="id" dataSource={listings} scroll={{ x: 700 }} columns={[
                    { title: 'Gói đồ', render: (_, l) => <><strong>#{l.id} {l.title}</strong>{l.description && <p className="mt-1 whitespace-pre-line text-xs text-slate-500 dark:text-slate-400">{l.description}</p>}<div className="flex gap-1 mt-1">{l.items.map((i, n) => <span key={n} title={`${i.item.name} × ${i.quantity}`}><NroIcon item={i.item} size={30} /><span className="block text-center text-xs">×{i.quantity.toLocaleString('vi-VN')}</span></span>)}</div></> },
                    { title: 'Acc kho', render: (_, l) => accounts.find(a => a.id === l.accountId)?.account_name || `#${l.accountId}` },
                    { title: 'Giá gói', dataIndex: 'price', render: v => `${Number(v).toLocaleString('vi-VN')}đ` },
                    { title: 'Tồn kho / khả dụng', render: (_, l) => <ListingAvailability listing={l} /> },
                    { title: 'Trạng thái', dataIndex: 'status', render: v => statusName[v] || v },
                    { title: '', render: (_, l) => caps.manageListings && l.status !== 'sold' && <Button onClick={() => run(() => axios.patch(`${base}/listings/${l.id}`, { status: l.status === 'active' ? 'paused' : 'active' }))}>{l.status === 'active' ? 'Tạm dừng' : 'Đăng lại'}</Button> },
                ]} /> },
                { key: 'orders', label: 'Đơn giao đồ', children: <Table rowKey="id" dataSource={orders} scroll={{ x: 700 }} columns={[
                    { title: 'Đơn', render: (_, o) => `#${o.id} · ${o.title}` }, { title: 'Người nhận', render: (_, o) => `${o.recipientName || 'Chưa chọn'} · ${o.serverName || 'Chưa cấu hình server'}` },
                    { title: 'Giá', dataIndex: 'price', render: v => `${Number(v).toLocaleString('vi-VN')}đ` },
                    { title: 'Tiến độ', render: (_, o) => <><Tag>{statusName[o.status] || o.status}</Tag><p>{o.message}</p>{o.session && <p>Phiên: {o.session.mode === 'auto' ? 'Tool nhận hộ' : 'Khách tự nhận'} · {o.session.status}</p>}<p>Đã nhận {o.items.reduce((n, i) => n + i.delivered, 0)} / {o.items.reduce((n, i) => n + i.quantity, 0)}</p></> },
                ]} /> },
                { key: 'jobs', label: 'Công việc tool', children: <Table rowKey="id" dataSource={jobs} columns={[
                    { title: 'Mã', dataIndex: 'id' }, { title: 'Acc', dataIndex: 'account_id' }, { title: 'Loại', dataIndex: 'type', render: v => v === 'snapshot' ? 'Lấy dữ liệu' : 'Giao đồ' },
                    { title: 'Trạng thái', dataIndex: 'status', render: v => <Tag color={v === 'review' ? 'orange' : undefined}>{statusName[v] || v}</Tag> },
                    { title: 'Kết quả', dataIndex: 'result_json', render: v => { try { return v ? JSON.parse(v).message : ''; } catch { return ''; } } },
                    { title: '', render: (_, j) => canReconcile && j.status === 'review' ? <Button danger onClick={() => { setReconcile(j); reconcileForm.resetFields(); reconcileForm.setFieldsValue({ items: orders.find(o => o.id === j.order_id)?.items.map(i => ({ id: i.id, delivered: i.delivered })) || [] }); }}>Đối soát</Button> : null },
                ]} /> },
                ...(caps.workers ? [{ key: 'worker-keys', label: 'API key tool', children: <div className="space-y-4"><Alert type="info" message="Mỗi máy dùng một API key riêng. Key cấp quyền lấy thông tin đăng nhập và xử lý đơn; chỉ admin được tạo hoặc thu hồi." /><Space><Input aria-label="Tên máy" value={keyName} maxLength={100} onChange={e => setKeyName(e.target.value)} /><Button type="primary" loading={busy} onClick={() => run(async () => { const { data } = await axios.post(`${base}/worker-keys`, { name: keyName }); setNewKey(data.token); })}>Tạo key</Button></Space>
                    <Table rowKey="id" dataSource={workerKeys} columns={[
                        { title: 'Tên máy', dataIndex: 'name' }, { title: 'Lần kết nối', dataIndex: 'last_used_at', render: v => v ? new Date(v).toLocaleString('vi-VN') : 'Chưa kết nối' },
                        { title: 'Trạng thái', render: (_, k) => k.revoked_at ? 'Đã thu hồi' : k.accepts_delivery ? 'Quét và giao đồ' : 'Chỉ quét / chưa kết nối' },
                        { title: '', render: (_, k) => !k.revoked_at && <Button danger onClick={() => Modal.confirm({ title: 'Thu hồi API key?', content: 'Tool dùng key này sẽ bị ngắt quyền truy cập. Đơn đang giao cần kiểm tra kết quả trước khi chạy lại.', onOk: () => run(() => axios.delete(`${base}/worker-keys/${k.id}`)) })}>Thu hồi</Button> },
                    ]} /></div> }] : []),
            ].filter(tab => ({ accounts: caps.accounts, listings: caps.listings, orders: caps.orders, jobs: caps.manageAccounts || caps.reconcile, 'worker-keys': caps.workers } as Record<string, boolean>)[tab.key])} />
        </div>
        <Modal title="Cấu hình giao đồ riêng cho acc" open={!!settings} onCancel={() => setSettings(null)} footer={null} destroyOnHidden>
            <Form form={settingsForm} layout="vertical" onFinish={v => run(async () => { await axios.patch(base + '/accounts/' + settings?.id + '/settings', v); setSettings(null); })}>
                <Form.Item name="server_id" label="Server hiển thị" rules={[{ required: true }]}><Select options={servers.map(s => ({ value: s.id, label: s.name_view || s.name }))} /></Form.Item>
                <Form.Item name="server_game_id" label="Server đăng nhập (IP/port)" rules={[{ required: true }]}><Select options={loginServers.map(s => ({ value: s.id, label: s.name }))} /></Form.Item>
                <Form.Item name="delivery_map" label="Map giao đồ (5 = Đảo Kame)" rules={[{ required: true }]}><InputNumber min={0} max={255} /></Form.Item>
                <Form.Item name="delivery_zone_mode" label="Chọn khu"><Select options={[{ value: 'auto', label: 'Tự chọn khu ít người từ 4–15' }, { value: 'fixed', label: 'Khu cố định' }]} /></Form.Item><Form.Item name="delivery_zone" label="Khu khi dùng chế độ cố định" rules={[{ required: true }]}><InputNumber min={0} max={255} /></Form.Item>
                <Form.Item name="wait_minutes" label="Phút chờ từ khi bot sẵn sàng" rules={[{ required: true }]}><InputNumber min={1} max={60} /></Form.Item>
                <Button htmlType="submit" type="primary" loading={busy}>Lưu cấu hình</Button>
            </Form>
        </Modal>
        <Modal width={640} rootClassName="nro-account-modal" title="Thêm acc game" open={adding} onCancel={() => setAdding(false)} footer={null} destroyOnHidden>
            <p className="mb-4 text-xs text-slate-500 dark:text-slate-400">Nhập thông tin đăng nhập hiện tại. Acc đã bán có thể nhập lại thành lần bán mới; lịch sử cũ vẫn được giữ.</p>
            <Form form={accountForm} layout="vertical" onFinish={v => run(async () => {
                const body = new FormData();
                for (const key of ['username', 'password', 'serverId', 'serverGameId', 'usageType']) body.append(key, String(v[key]));
                if (v.usageType === 'nick') {
                    for (const key of ['categoryId', 'price', 'description']) if (v[key] != null) body.append(key, String(v[key]));
                    Object.entries(v.attributeSelections || {}).forEach(([id, option]) => { if (option != null) body.append(`attributeSelections[${id}]`, String(option)); });
                    accountImages.forEach(file => body.append('images[]', file));
                }
                await axios.post(`${base}/accounts`, body); setAdding(false); accountForm.resetFields(); setAccountImages([]);
            }, usageType === 'nick' ? 'Đã thêm acc và xếp hàng lấy snapshot để tự đăng.' : 'Đã thêm acc kho và xếp hàng lấy dữ liệu.')}>
                <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
                    <Form.Item name="username" label="Tài khoản game" rules={[{ required: true, whitespace: true, message: 'Nhập tài khoản game.' }]}><Input prefix={<span className="text-slate-400">@</span>} placeholder="Email hoặc tài khoản" maxLength={141} autoComplete="off" /></Form.Item>
                    <Form.Item name="password" label="Mật khẩu hiện tại" rules={[{ required: true, message: 'Nhập mật khẩu game hiện tại.' }]}><Input.Password placeholder="Mật khẩu đăng nhập game" maxLength={64} autoComplete="new-password" /></Form.Item>
                    <Form.Item name="serverId" label="Server hiển thị" rules={[{ required: true }]}><Select placeholder="Server trên shop" options={servers.map(s => ({ value: s.id, label: s.name_view || s.name }))} /></Form.Item>
                    <Form.Item name="serverGameId" label="Server đăng nhập" rules={[{ required: true }]}><Select placeholder="Server tool đăng nhập" options={loginServers.map(s => ({ value: s.id, label: s.name }))} /></Form.Item>
                </div>
                <Form.Item name="usageType" label="Dùng acc để" rules={[{ required: true }]}><Select options={[{ value: 'nick', label: 'Bán nguyên nick', disabled: !caps.publishNick }, { value: 'warehouse', label: 'Chứa đồ và giao cho khách' }]} /></Form.Item>
                {usageType === 'nick' && <>
                    <Alert className="mb-4" type="info" showIcon message="Lấy snapshot và tự đăng bán" description="Sau khi lưu, tool tự lấy dữ liệu rồi đăng vào danh mục bên dưới. Nếu tool chưa bật, acc sẽ nằm chờ; dữ liệu thiếu hoặc lỗi sẽ chưa đăng lên shop." />
                    <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
                        <Form.Item name="categoryId" label="Danh mục đăng nick" rules={[{ required: true, message: 'Chọn danh mục đăng bán.' }]}><Select showSearch optionFilterProp="label" placeholder="Chọn danh mục được phép đăng" options={categories.map(c => ({ value: c.id, label: c.name }))} /></Form.Item>
                        <Form.Item name="price" label="Giá bán (đ)" rules={[{ required: true }]}><InputNumber min={1} max={9999999999} precision={0} className="w-full" /></Form.Item>
                    </div>
                    <NroDraftAttributes form={accountForm} onReady={setDraftReady} />
                    <Form.Item name="description" label="Mô tả (tùy chọn)"><Input.TextArea rows={2} maxLength={10000} /></Form.Item>
                    <Form.Item label="Ảnh đăng bán (tùy chọn)" extra="Tối đa 8 ảnh JPG, PNG hoặc WebP, 5 MB/ảnh. Ảnh đầu làm đại diện; không chọn ảnh sẽ dùng giao diện snapshot.">
                        <input type="file" accept="image/jpeg,image/png,image/webp" multiple className="max-w-full text-xs" onChange={e => {
                            const files = Array.from(e.target.files || []);
                            if (files.length > 8 || files.some(f => f.size > 5 * 1024 * 1024)) { message.error('Chọn tối đa 8 ảnh, mỗi ảnh không quá 5 MB.'); e.target.value = ''; setAccountImages([]); return; }
                            setAccountImages(files);
                        }} />
                    </Form.Item>
                </>}
                <div className="flex justify-end gap-2 border-t border-slate-200 pt-3 dark:border-slate-700"><Button onClick={() => setAdding(false)}>Hủy</Button><Button type="primary" htmlType="submit" loading={busy} disabled={usageType === 'nick' && (!caps.publishNick || !draftReady)}>{usageType === 'nick' ? 'Thêm acc & tự đăng' : 'Thêm acc'}</Button></div>
            </Form>
        </Modal>
        <Modal title="API key mới — chỉ hiển thị lần này" open={!!newKey} onCancel={() => setNewKey('')} onOk={() => setNewKey('')}><p className="mb-3">Sao chép vào dấu nhắc API key của NroShopWorker. Không đưa vào frontend hoặc gửi cho CTV.</p><Input.TextArea readOnly value={newKey} rows={3} /><Button className="mt-3" onClick={async () => { try { await navigator.clipboard.writeText(newKey); message.success('Đã sao chép'); } catch { message.info('Hãy chọn và sao chép key trong ô trên'); } }}>Sao chép key</Button></Modal>
        <Modal title={`Cập nhật mật khẩu: ${editingPassword?.account_name || ''}`} open={!!editingPassword} onCancel={() => { setEditingPassword(null); setNewPassword(''); }} confirmLoading={busy} okButtonProps={{ disabled: !newPassword }} onOk={() => run(async () => {
            await axios.patch(`${base}/accounts/${editingPassword?.id}/password`, { password: newPassword }); setEditingPassword(null); setNewPassword('');
        })}><p className="mb-3">Nhập mật khẩu hiện tại trong game. Thao tác này cập nhật thông tin đăng nhập đã lưu trên web, không đổi mật khẩu tại game.</p><Input.Password value={newPassword} maxLength={64} autoComplete="new-password" onChange={e => setNewPassword(e.target.value)} /></Modal>
        <Modal width={900} title={warehouse ? `Gói đồ của ${warehouse.account_name}` : 'Gói đồ của acc'} open={!!warehouse} onCancel={() => { warehouseRequest.current++; setWarehouse(null); }} footer={null}>
            <Table rowKey="id" loading={warehouseLoading} dataSource={warehouseListings.data} scroll={{ x: 600 }} pagination={{ current: warehouseListings.page, total: warehouseListings.total, pageSize: warehouseListings.perPage, showSizeChanger: false, onChange: page => warehouse && showWarehouse(warehouse, page) }} columns={[
                { title: 'Gói đồ', render: (_, l) => <><strong>#{l.id} · {l.title}</strong>{l.description && <p className="mt-1 whitespace-pre-line text-xs text-slate-500 dark:text-slate-400">{l.description}</p>}<div className="flex gap-1 mt-1">{l.items.map((i, n) => <span key={n} title={`${i.item.name} × ${i.quantity}`}><NroIcon item={i.item} size={30} /><span className="block text-center text-xs">×{i.quantity.toLocaleString('vi-VN')}</span></span>)}</div></> },
                { title: 'Giá', dataIndex: 'price', render: money },
                { title: 'Trạng thái', dataIndex: 'status', render: v => <Tag color={v === 'active' ? 'green' : undefined}>{statusName[v] || v}</Tag> },
                { title: 'Tồn kho / khả dụng', render: (_, l) => <ListingAvailability listing={l} /> },
                { title: 'Thao tác', render: (_, l) => <Space wrap>{shopUrl && l.status !== 'sold' && <Button href={`${shopUrl}/mua-do/${l.id}`} target="_blank" rel="noopener noreferrer">Xem trên shop ↗</Button>}{caps.manageListings && l.status !== 'sold' && <Button disabled={busy} onClick={() => run(async () => { await axios.patch(`${base}/listings/${l.id}`, { status: l.status === 'active' ? 'paused' : 'active' }); if (warehouse) await showWarehouse(warehouse, warehouseListings.page); })}>{l.status === 'active' ? 'Tạm dừng' : 'Đăng lại'}</Button>}</Space> },
            ]} />
        </Modal>
        <Modal width={1000} title={account ? `${account.account_name} · ${servers.find(s => s.id === account.server_id)?.name_view || 'Chưa cấu hình server'}` : ''} open={detailOpen && !!account} onCancel={() => { setDetailOpen(false); setAccount(null); }} footer={null}>
            {account?.usage_type === 'nick' && <div className="mb-4"><NickSaleSummary account={account} shopUrl={shopUrl} categories={categories} /></div>}
            {snapshot ? account?.usage_type === 'warehouse' ? <Collapse items={[{ key: 'snapshot', label: 'Xem toàn bộ dữ liệu game (trang bị, túi, rương…)', children: <NroSnapshotPanel snapshot={snapshot} /> }]} /> : <NroSnapshotPanel snapshot={snapshot} /> : <Alert message="Chưa có snapshot. Bấm Lấy dữ liệu và chờ tool xử lý." />}
            {account?.usage_type === 'warehouse' && <Card title="1. Chọn món và số lượng cho gói" className="mt-4"><p className="mb-3 text-sm">Phía trên dùng để xem chỉ số. Tích ô ở bảng dưới để chọn nhiều món, nhập số lượng rồi bấm Tạo gói để đặt tên và giá chung.</p>
                <Space wrap className="mb-3">
                    <Input.Search allowClear aria-label="Tìm đồ" placeholder="Tên, ID, chỉ số (ví dụ: 5 sao)" value={itemSearch} onChange={e => setItemSearch(e.target.value)} style={{ width: 270 }} />
                    <Select aria-label="Lọc đồ được phép bán" value={itemFilter} onChange={setItemFilter} options={[{ value: 'sellable', label: `Được phép bán (${inventory.filter(i => i.sellable).length})` }, { value: 'blocked', label: `Không được phép bán (${inventory.filter(i => !i.sellable).length})` }, { value: 'all', label: 'Tất cả vật phẩm' }]} />
                    <Select aria-label="Sắp xếp đồ" value={itemSort} onChange={setItemSort} options={[{ value: 'name', label: 'Tên A–Z' }, { value: 'quantity_desc', label: 'Còn chọn: nhiều → ít' }, { value: 'quantity_asc', label: 'Còn chọn: ít → nhiều' }]} />
                    <Button disabled={busy} onClick={() => inspect(account)}>Tải lại kho</Button>
                    {caps.salePolicy && <Button onClick={() => { policyForm.setFieldsValue({ enabled: salePolicy.enabled, ids: salePolicy.ids.join(', ') }); setPolicyOpen(true); }}>Cấu hình ID bán</Button>}
                </Space>
                <p className="mb-3 text-xs text-slate-500">Còn chọn = tồn kho − đang đăng gói − giữ cho đơn. Đã chọn {Object.keys(selected).length} loại, kể cả món đang ẩn bởi bộ lọc. {!salePolicy.enabled && 'Chưa bật giới hạn ID.'}</p>
                {!inventoryReady && <Alert className="mb-3" type="warning" showIcon message="Chưa đủ dữ liệu để tạo gói đồ" description={<div className="space-y-2"><p>{!snapshot ? 'Acc chưa có snapshot.' : `Tool chưa xác nhận đầy đủ: ${[snapshot.completeness.bag !== true && 'hành trang', snapshot.completeness.chest !== true && 'rương'].filter(Boolean).join(', ')}. Các món phía trên mới là dữ liệu xem, chưa được xác nhận thành tồn kho bán.`}</p><p>Bật tool, yêu cầu lấy lại dữ liệu rồi tải lại danh sách sau khi tool hoàn tất.</p><Space wrap>{caps.manageAccounts && account.status === 'active' && <Button loading={busy} onClick={() => run(() => axios.post(`${base}/accounts/${account.id}/scan`), 'Đã yêu cầu lấy dữ liệu. Chờ tool hoàn tất rồi tải lại danh sách.')}>Yêu cầu lấy lại dữ liệu</Button>}<Button disabled={busy} onClick={() => inspect(account)}>Tải lại danh sách món</Button></Space></div>} />}
                <Table rowKey="id" dataSource={filteredInventory} locale={{ emptyText: inventoryReady ? 'Không có vật phẩm khớp bộ lọc. Kiểm tra danh sách ID hoặc chọn Tất cả vật phẩm.' : 'Chưa có tồn kho đã xác nhận để chọn bán.' }} pagination={{ pageSize: 8 }} rowSelection={{ preserveSelectedRowKeys: true, selectedRowKeys: Object.keys(selected).map(Number), getCheckboxProps: i => ({ disabled: !inventoryReady || !caps.manageListings || account.status !== 'active' || !i.sellable || i.selectable <= 0 }), onChange: keys => setSelected(Object.fromEntries(keys.map(k => [Number(k), selected[Number(k)] || 1]))) }} columns={[
                    { title: 'Món', render: (_, i) => <div className="flex gap-2"><NroIcon item={i.item} /><div>{i.item.name}<span className="ml-2 text-xs text-slate-500">ID {i.item.templateId}</span><div className="text-xs text-green-700 dark:text-green-300">{i.item.optionLabels?.join(' · ')}</div><div className="text-xs text-slate-500">{i.locations.map(l => `${({ bag: 'Túi', chest: 'Rương', equipped: 'Đang mặc' } as Record<string, string>)[l.location]} ô ${l.slot}`).join(', ')}</div></div></div> },
                    { title: 'Còn chọn', render: (_, i) => <><strong>{i.selectable.toLocaleString('vi-VN')}</strong><div className="text-xs text-slate-500">Tồn {i.quantity} · Đang đăng {i.listed} · Giữ đơn {i.reserved}</div>{!i.sellable && <Tag>Không được phép bán</Tag>}</> },
                    { title: 'SL trong gói', render: (_, i) => <InputNumber min={1} max={Math.max(1, i.selectable)} precision={0} disabled={!inventoryReady || !i.sellable || !selected[i.id]} value={selected[i.id] || 1} onChange={v => setSelected(s => ({ ...s, [i.id]: v || 1 }))} /> },
                ]} />
            </Card>}
            {snapshot && account && canPublish(account) && <Button type="primary" className="mt-4" disabled={account.usage_type === 'warehouse' && (!inventoryReady || !Object.keys(selected).length)} onClick={() => beginPublish(account)}>{account.usage_type === 'nick' ? account.nick ? 'Sửa tin bán' : 'Đăng bán' : `Tạo gói ${Object.keys(selected).length} loại đồ`}</Button>}
        </Modal>
        <Modal width={760} title={account?.usage_type === 'nick' ? account.nick ? `Sửa tin bán #${account.nick.id}` : 'Đăng nick bằng dữ liệu game' : 'Đăng gói đồ'} open={publish} onCancel={() => { setPublish(false); setDetailOpen(!!account); }} footer={null}>
            {account && <p className="mb-3">Acc: <strong>{account.account_name}</strong> · {servers.find(s => s.id === account.server_id)?.name_view || 'Chưa cấu hình server'}</p>}
            {account?.usage_type === 'nick' && snapshot && <Collapse className="mb-4" items={[{ key: 'snapshot', label: 'Xem dữ liệu game dùng cho tin bán', children: <NroSnapshotPanel snapshot={snapshot} /> }]} />}
            <Form form={publishForm} layout="vertical" onFinish={v => run(async () => {
                const endpoint = account?.usage_type === 'nick' ? 'nick' : 'listings';
                const { data } = await axios.post(`${base}/accounts/${account?.id}/${endpoint}`, { ...v, items: Object.entries(selected).map(([id, quantity]) => ({ id: Number(id), quantity })) });
                message.success(account?.nick ? `Đã cập nhật tin #${data.id}` : `Đã đăng mã #${data.id}`); setPublish(false); setDetailOpen(false); setAccount(null);
            })}>
                {account?.usage_type === 'nick' ? <>
                    {account.nick && <Form.Item name="nickId" hidden><InputNumber /></Form.Item>}
                    <Form.Item name="categoryId" label="Danh mục bán nick" rules={[{ required: true }]}><Select options={categories.map(c => ({ value: c.id, label: c.name }))} /></Form.Item>
                    {account.nick && !categories.some(c => c.id === account.nick?.categoryId) && <Alert className="mb-3" type="warning" message="Danh mục hiện tại không còn được phép đăng. Chọn danh mục đang được phân quyền để lưu." />}
                    {!account.nick && caps.editNick && <Collapse className="mb-4" items={[{ key: 'link', label: 'Gắn vào tin đã đăng bằng ảnh (tùy chọn)', children: <Form.Item name="nickId" label="Mã nick cần gắn snapshot"><InputNumber min={1} className="w-full" /></Form.Item> }]} />}
                </> : <Form.Item name="title" label="Tên gói đồ" rules={[{ required: true }]}><Input maxLength={180} /></Form.Item>}
                <Form.Item name="price" label={account?.usage_type === 'nick' ? 'Giá nick (đ)' : 'Giá toàn bộ gói (đ)'} rules={[{ required: true }]}><InputNumber min={1} max={9999999999} className="w-full" /></Form.Item>
                {publish && account?.usage_type === 'nick' && <NroNickAttributes accountId={account.id} initialConfig={!account.nick ? account.publishConfig : undefined} form={publishForm} onReady={setAttributesReady} />}
                <Form.Item name="description" label="Mô tả"><Input.TextArea rows={3} /></Form.Item><Space><Button onClick={() => { setPublish(false); setDetailOpen(true); }}>Quay lại dữ liệu</Button><Button htmlType="submit" type="primary" loading={busy} disabled={account?.usage_type === 'nick' && !attributesReady}>{account?.nick ? 'Lưu thay đổi' : 'Đăng bán'}</Button></Space>
            </Form>
        </Modal>
        <Modal title="Đối soát công việc" open={!!reconcile} onCancel={() => setReconcile(null)} footer={null}>
            <Alert type="warning" message="Dừng tool và kiểm tra lịch sử giao dịch thực tế trước khi xác nhận. Chỉ cho nhận lại khi đã xác minh không có đồ giao thêm ngoài tiến độ được ghi nhận." />
            <Form form={reconcileForm} layout="vertical" onFinish={v => run(async () => { await axios.post(`${base}/jobs/${reconcile?.id}/reconcile`, v); setReconcile(null); })}>
                <Form.Item name="resolution" label="Kết quả đã kiểm tra" rules={[{ required: true }]}><Select options={[...(reconcile?.order_id ? [{ value: 'delivered', label: 'Đã giao đủ toàn bộ đơn' }] : []), { value: 'not_delivered', label: reconcile?.order_id ? 'Chưa giao thêm — giữ đồ và cho nhận lại' : 'Đã dừng tool — cho phép quét lại' }]} /></Form.Item>
                <Form.List name="items">{fields => <>{fields.map((field, index) => { const item = orders.find(o => o.id === reconcile?.order_id)?.items[index]; return <Space key={field.key}><Form.Item name={[field.name, 'id']} hidden><InputNumber /></Form.Item><Form.Item name={[field.name, 'delivered']} label={(item?.item.name || 'Vật phẩm') + ' — tổng đã giao'}><InputNumber min={item?.delivered || 0} max={item?.quantity || 0} /></Form.Item></Space>; })}</>}</Form.List>
                <Form.Item name="note" label="Nội dung đối soát" rules={[{ required: true, min: 10 }]}><Input.TextArea /></Form.Item><Button danger htmlType="submit" loading={busy}>Xác nhận kết quả</Button>
            </Form>
        </Modal>
    </AdminLayout>;
}
