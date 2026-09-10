import { useLiveView } from '@/Realtime/useLiveView';
import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Eye, Pencil, Plus, MoreVertical, EyeOff } from 'lucide-react';
import { Button, Dropdown, Form, Input, Select, Space, Table, Tag, Tooltip, message } from 'antd';
import type { NroSnapshot } from '@/Components/Nro/NroSnapshot';
import { base, dateTime, isSold, NickSaleSummary } from '../shared';
import AccountDetailModal from '../Modals/AccountDetailModal';
import PublishModal from '../Modals/PublishModal';
import WarehouseListingsModal from '../Modals/WarehouseListingsModal';
import { DeliverySettingsModal, EditAccountModal, PasswordModal } from '../Modals/AccountEditModals';
import type { Account, AccountFilters, Capabilities, Category, Inventory, LoginServer, Server } from '../types';

const EMPTY_FILTERS: AccountFilters = {};

export default function AccountsTab({
    accounts,
    pagination,
    initialFilters,
    servers,
    loginServers,
    categories,
    caps,
    salePolicy,
    shopUrl,
    busy,
    run,
    reload,
    onOpenPolicy,
    dataVersion,
}: {
    accounts: Account[];
    pagination?: { total: number; current: number; pageSize: number };
    initialFilters: AccountFilters;
    servers: Server[];
    loginServers: LoginServer[];
    categories: Category[];
    caps: Capabilities;
    salePolicy: { enabled: boolean; ids: number[] };
    shopUrl: string | null;
    busy: boolean;
    run: (action: () => Promise<unknown>, success?: string) => Promise<void>;
    reload: () => void;
    onOpenPolicy: () => void;
    dataVersion: number;
}) {
    const [filters, setFilters] = useState<AccountFilters>(initialFilters || EMPTY_FILTERS);
    useEffect(() => {
        setFilters(initialFilters || EMPTY_FILTERS);
    }, [initialFilters]);

    const applyFilters = (next: AccountFilters = filters, page = 1) => {
        setFilters(next);
        router.get(
            base,
            { ...next, page },
            {
                preserveState: true,
                preserveScroll: true,
                only: ['accounts', 'accountPagination', 'accountFilters', 'accountStats'],
            },
        );
    };

    // Detail / publish share the loaded account, its snapshot and the item selection.
    const [account, setAccount] = useState<Account | null>(null);
    const [snapshot, setSnapshot] = useState<NroSnapshot | null>(null);
    const [inventory, setInventory] = useState<Inventory[]>([]);
    const [selected, setSelected] = useState<Record<number, number>>({});
    const [detailOpen, setDetailOpen] = useState(false);
    const [publishOpen, setPublishOpen] = useState(false);
    const [detailLoading, setDetailLoading] = useState(false);

    const [warehouse, setWarehouse] = useState<Account | null>(null);
    const [editingAccount, setEditingAccount] = useState<Account | null>(null);
    const [editingPassword, setEditingPassword] = useState<Account | null>(null);
    const [settings, setSettings] = useState<Account | null>(null);

    const [editAccountForm] = Form.useForm();
    const [settingsForm] = Form.useForm();
    const [publishForm] = Form.useForm();

    const canPublish = (a: Account) =>
        !isSold(a) &&
        a.status === 'active' &&
        !!a.latest_snapshot_id &&
        (a.usage_type === 'nick' ? (a.nick ? caps.editNick : caps.publishNick) : caps.manageListings);

    const beginPublish = (a: Account) => {
        publishForm.resetFields();
        if (!a.nick && a.publishConfig) publishForm.setFieldsValue(a.publishConfig);
        if (a.nick) {
            publishForm.setFieldsValue({
                nickId: a.nick.id,
                categoryId: a.nick.categoryId,
                price: Number(a.nick.price),
                description: a.nick.description,
            });
        }
        setDetailOpen(false);
        setPublishOpen(true);
    };

    const inspect = async (a: Account, intent: 'view' | 'publish' = 'view') => {
        setDetailLoading(true);
        setAccount(null);
        setSnapshot(null);
        setInventory([]);
        setSelected({});
        try {
            const { data } = await axios.get(`${base}/accounts/${a.id}`);
            const fresh = {
                ...a,
                status: data.status,
                nick: data.nick,
                latest_snapshot_id: data.latestSnapshotId,
                publishConfig: data.publishConfig,
                publishStatus: data.publishStatus,
                publishError: data.publishError,
            };
            setAccount(fresh);
            setSnapshot(data.snapshot);
            setInventory(data.inventory);
            if (intent === 'publish' && canPublish(fresh)) beginPublish(fresh);
            else setDetailOpen(true);
        } catch {
            message.error('Không tải được dữ liệu acc');
        } finally {
            setDetailLoading(false);
        }
    };

    useLiveView<any>(account && (detailOpen || publishOpen) ? `${base}/accounts/${account.id}` : null,data=> {
        setAccount(current=>current?{...current,status:data.status,nick:data.nick,latest_snapshot_id:data.latestSnapshotId,publishConfig:data.publishConfig,publishStatus:data.publishStatus,publishError:data.publishError}:current);
        setSnapshot(data.snapshot);setInventory(data.inventory);
    });

    const working = busy || detailLoading;

    return (
        <>
            <div className="mb-3 flex flex-wrap gap-2">
                <Input.Search
                    aria-label="Tìm tài khoản"
                    placeholder="Tài khoản hoặc tên nhân vật"
                    className="!w-64"
                    value={filters.q || ''}
                    onChange={e => setFilters({ ...filters, q: e.target.value })}
                    onSearch={() => applyFilters()}
                    allowClear
                />
                <Select
                    aria-label="Lọc loại acc"
                    className="min-w-36"
                    placeholder="Tất cả loại acc"
                    allowClear
                    value={filters.usage || undefined}
                    onChange={usage => setFilters({ ...filters, usage })}
                    options={[
                        { value: 'nick', label: 'Bán nguyên nick' },
                        { value: 'warehouse', label: 'Kho đồ' },
                    ]}
                />
                <Select
                    aria-label="Lọc server"
                    className="min-w-36"
                    placeholder="Tất cả server"
                    allowClear
                    value={filters.server || undefined}
                    onChange={server => setFilters({ ...filters, server })}
                    options={servers.map(v => ({ value: v.id, label: v.name_view || v.name }))}
                />
                <Select
                    aria-label="Lọc trạng thái"
                    className="min-w-40"
                    placeholder="Tất cả trạng thái"
                    allowClear
                    value={filters.state || undefined}
                    onChange={state => setFilters({ ...filters, state })}
                    options={[
                        { value: 'waiting', label: 'Chờ tự đăng' },
                        { value: 'published', label: 'Nick đang bán' },
                        { value: 'hidden', label: 'Kho tạm ẩn khỏi shop' },
                        { value: 'attention', label: 'Cần xử lý' },
                        { value: 'sold', label: 'Đã bán' },
                    ]}
                />
                <Button type="primary" onClick={() => applyFilters()}>
                    Lọc
                </Button>
                <Button onClick={() => applyFilters({})}>Xóa lọc</Button>
            </div>

            <Table
                size="small"
                rowKey="id"
                dataSource={accounts}
                scroll={{ x: 1000 }}
                pagination={{
                    current: pagination?.current || 1,
                    total: pagination?.total ?? accounts.length,
                    pageSize: pagination?.pageSize || 30,
                    showSizeChanger: false,
                    showTotal: total => `${total} acc`,
                    onChange: page => applyFilters(filters, page),
                }}
                columns={[
                    {
                        title: 'Acc',
                        dataIndex: 'account_name',
                        width: 200,

                        render: (v, a: Account) => (
                            <>
                                <strong>{v}</strong>
                                <div className="text-xs text-slate-500">
                                    #{a.id} · {a.character_name || 'Chưa quét'}
                                </div>
                            </>
                        ),
                    },
                    { title: 'Người đăng', dataIndex: 'ownerUsername', width: 120, render: v => v || '—' },
                    {
                        title: 'Server',
                        dataIndex: 'server_id',
                        width: 130,
                        render: (v, a: Account) => <><div>{servers.find(s => s.id === v)?.name_view || 'Chưa cấu hình'}</div><div className="text-xs text-slate-500">Đăng nhập: {loginServers.find(s => s.id === a.server_game_id)?.name || 'Chưa cấu hình'}</div></>,
                    },
                    {
                        title: 'Vai trò',
                        dataIndex: 'usage_type',
                        width: 100,
                        render: (v, a: Account) => <><Tag>{v === 'nick' ? 'Bán nick' : 'Kho đồ'}</Tag>{a.shop_hidden && <Tooltip title="Gói đồ đã ẩn khỏi shop. Đơn đã mua vẫn được giao."><Tag color="orange">Tạm ẩn shop</Tag></Tooltip>}{(a.snapshotFailures ?? 0) >= 3 && <Tag color="red">Dừng tự quét · 3 lần lỗi</Tag>}{a.deliveryActivity?.message && <div className="mt-1 text-xs text-sky-700 dark:text-sky-300">{a.deliveryActivity.message}</div>}</>,
                    },
                    {
                        title: 'Tin bán',
                        width: 310,
                        render: (_, a: Account) =>
                            a.usage_type === 'nick' ? (
                                <NickSaleSummary account={a} shopUrl={shopUrl} categories={categories} />
                            ) : a.publishStatus === 'login_blocked' ? (
                                <div className="max-w-sm">
                                    <Tag color="red">Bị chặn đăng nhập</Tag>
                                    <p className="mt-1 text-xs text-red-600 dark:text-red-400">
                                        {a.publishError || 'Hãy sửa mật khẩu rồi chạy lại.'}
                                    </p>
                                </div>
                            ) : a.listingCounts ? (
                                <div>
                                    <Button type="link" className="!p-0" onClick={() => setWarehouse(a)}>
                                        {a.listingCounts.active} {a.shop_hidden ? 'gói tạm ẩn' : 'gói đang bán'}
                                    </Button>
                                    <div className="text-xs text-slate-500">
                                        {a.listingCounts.total} gói tổng cộng ·{' '}
                                        {a.listingCounts.total - a.listingCounts.active} gói đã bán / tạm dừng
                                    </div>
                                </div>
                            ) : (
                                <span className="text-slate-500">Không có quyền xem gói đồ</span>
                            ),
                    },
                    {
                        title: 'Dữ liệu game',
                        dataIndex: 'last_synced_at',
                        width: 150,
                        render: v => (
                            <span className="text-xs text-slate-500 dark:text-slate-400">
                                {v ? dateTime(v) : 'Chờ lấy / cập nhật'}
                            </span>
                        ),
                    },
                    {
                        title: 'Thao tác',
                        width: 130,
                        render: (_, a: Account) => (
                            <Space wrap>
                                {(caps.readAccountSnapshots ||
                                    (a.usage_type === 'nick' ? caps.publishNick : caps.manageListings)) && (
                                    <Tooltip title="Xem dữ liệu"><Button aria-label="Xem dữ liệu" icon={<Eye size={16} />} disabled={working} onClick={() => inspect(a)} /></Tooltip>
                                )}
                                {canPublish(a) && (
                                    <Tooltip title={a.usage_type === 'warehouse' ? 'Tạo gói đồ' : a.nick ? 'Sửa / đăng lại tin bán' : 'Đăng bán'}>
                                        <Button type="primary" aria-label={a.usage_type === 'warehouse' ? 'Tạo gói đồ' : 'Sửa / đăng tin bán'}
                                            icon={a.usage_type === 'warehouse' || !a.nick ? <Plus size={16} /> : <Pencil size={16} />}
                                            disabled={working} onClick={() => inspect(a, a.usage_type === 'nick' ? 'publish' : 'view')} />
                                    </Tooltip>
                                )}
                                {(caps.manageAccounts || caps.settings) && (
                                    <Dropdown
                                        trigger={['click']}
                                        menu={{
                                            items: [
                                                ...(caps.manageAccounts
                                                    ? [
                                                          ...(a.usage_type === 'warehouse' ? [{
                                                              key: 'visibility',
                                                              icon: a.shop_hidden ? <Eye size={14} /> : <EyeOff size={14} />,
                                                              label: a.shop_hidden ? 'Hiện lại gói đồ trên shop' : 'Tạm ẩn acc và gói đồ khỏi shop',
                                                              disabled: working,
                                                              onClick: () => run(() => axios.patch(`${base}/accounts/${a.id}/visibility`, { hidden: !a.shop_hidden }),
                                                                  a.shop_hidden ? 'Đã hiện lại gói đồ đang bán' : 'Đã ẩn khỏi shop. Đơn đã mua vẫn được giao.'),
                                                          }] : []),
                                                          {
                                                              key: 'scan',
                                                              label: 'Lấy dữ liệu',
                                                              disabled: working || isSold(a) || a.status !== 'active',
                                                              onClick: () =>
                                                                  run(
                                                                      () => axios.post(`${base}/accounts/${a.id}/scan`),
                                                                      'Đã xếp hàng lấy dữ liệu',
                                                                  ),
                                                          },
                                                          {
                                                              key: 'edit-account',
                                                              label: 'Sửa tài khoản / server',
                                                              disabled:
                                                                  isSold(a) ||
                                                                  a.status !== 'active' ||
                                                                  a.usage_type !== 'nick' ||
                                                                  !(a.nick ? caps.editNick : caps.publishNick),
                                                              onClick: () => {
                                                                  setEditingAccount(a);
                                                                  editAccountForm.resetFields();
                                                                  editAccountForm.setFieldsValue({
                                                                      username: a.account_name,
                                                                      serverId: a.server_id,
                                                                      serverGameId: a.server_game_id,
                                                                  });
                                                              },
                                                          },
                                                          {
                                                              key: 'password',
                                                              label: 'Sửa mật khẩu',
                                                              disabled: isSold(a) || a.status !== 'active',
                                                              onClick: () => setEditingPassword(a),
                                                          },
                                                      ]
                                                    : []),
                                                ...(caps.settings
                                                    ? [
                                                          {
                                                              key: 'settings',
                                                              label: 'Cấu hình giao đồ',
                                                              disabled: isSold(a),
                                                              onClick: () => {
                                                                  setSettings(a);
                                                                  settingsForm.setFieldsValue(a);
                                                              },
                                                          },
                                                      ]
                                                    : []),
                                            ],
                                        }}
                                    >
                                        <Button aria-label="Thao tác khác" title="Thao tác khác" icon={<MoreVertical size={16} />} disabled={working} />
                                    </Dropdown>
                                )}
                            </Space>
                        ),
                    },
                ]}
            />

            <AccountDetailModal
                open={detailOpen}
                account={account}
                snapshot={snapshot}
                inventory={inventory}
                selected={selected}
                setSelected={setSelected}
                servers={servers}
                categories={categories}
                caps={caps}
                salePolicy={salePolicy}
                shopUrl={shopUrl}
                busy={working}
                canPublish={canPublish}
                onClose={() => {
                    setDetailOpen(false);
                    setAccount(null);
                }}
                onReload={a => inspect(a)}
                onPublish={beginPublish}
                onOpenPolicy={onOpenPolicy}
                run={run}
            />

            <PublishModal
                open={publishOpen}
                account={account}
                snapshot={snapshot}
                selected={selected}
                inventory={inventory}
                form={publishForm}
                servers={servers}
                categories={categories}
                caps={caps}
                busy={working}
                onBack={() => {
                    setPublishOpen(false);
                    setDetailOpen(!!account);
                }}
                onDone={() => {
                    setPublishOpen(false);
                    setDetailOpen(false);
                    setAccount(null);
                    reload();
                }}
                run={run}
            />

            <WarehouseListingsModal
                account={warehouse}
                caps={caps}
                shopUrl={shopUrl}
                busy={working}
                onClose={() => setWarehouse(null)}
                run={run}
            />

            <EditAccountModal
                account={editingAccount}
                form={editAccountForm}
                servers={servers}
                loginServers={loginServers}
                busy={working}
                onClose={() => setEditingAccount(null)}
                run={run}
            />

            <PasswordModal
                account={editingPassword}
                busy={working}
                onClose={() => setEditingPassword(null)}
                run={run}
            />

            <DeliverySettingsModal
                account={settings}
                form={settingsForm}
                servers={servers}
                loginServers={loginServers}
                busy={working}
                onClose={() => setSettings(null)}
                run={run}
            />
        </>
    );
}
