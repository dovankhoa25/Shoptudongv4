import { useCallback, useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Button, Collapse, Dropdown, Form, Space, Tabs, Tag, message } from 'antd';
import AdminLayout from '@/Layouts/AdminLayout';
import NroAccountImport from '@/Components/Nro/NroAccountImport';
import { base } from './shared';
import AccountsTab from './Tabs/AccountsTab';
import ListingsTab from './Tabs/ListingsTab';
import OrdersTab from './Tabs/OrdersTab';
import JobsTab from './Tabs/JobsTab';
import WorkerKeysTab from './Tabs/WorkerKeysTab';
import AddAccountModal from './Modals/AddAccountModal';
import SalePolicyModal from './Modals/SalePolicyModal';
import type { PageProps, Stats } from './types';

const EMPTY_STATS: Stats = {
    total: 0,
    nick: 0,
    warehouse: 0,
    attention: 0,
    reviewJobs: 0,
    activeJobs: 0,
    openOrders: 0,
    listings: 0,
    orders: 0,
    jobs: 0,
    workerOnline: false,
};

export default function NroShop({
    accountStats,
    accountFilters = {},
    accountPagination,
    accounts,
    categories,
    canReconcile,
    capabilities: caps,
    servers,
    loginServers,
    shopUrl,
    salePolicy,
}: PageProps) {
    const [stats, setStats] = useState<Stats>(accountStats || EMPTY_STATS);
    const [busy, setBusy] = useState(false);
    const [adding, setAdding] = useState(false);
    const [importing, setImporting] = useState(false);
    const [policyOpen, setPolicyOpen] = useState(false);
    const [accountForm] = Form.useForm();
    const [policyForm] = Form.useForm();

    useEffect(() => {
        if (accountStats) setStats(accountStats);
    }, [accountStats]);

    /** Poll only the header counts. Tab bodies are never rebuilt by the timer. */
    const refreshStats = useCallback(async () => {
        try {
            const { data } = await axios.get(`${base}/status`);
            setStats(data);
        } catch {
            /* A dropped poll is not worth interrupting the operator over. */
        }
    }, []);

    useEffect(() => {
        if (!stats.activeJobs) return;
        const timer = window.setInterval(refreshStats, 10000);
        return () => window.clearInterval(timer);
    }, [stats.activeJobs, refreshStats]);

    const reloadAccounts = () =>
        router.reload({
            only: ['accounts', 'accountStats', 'accountPagination', 'salePolicy'],
            onSuccess: () => void refreshStats(),
        });

    /** Bumped after every successful write so open tabs refetch instead of showing stale rows. */
    const [dataVersion, setDataVersion] = useState(0);

    const run = async (action: () => Promise<unknown>, success = 'Đã lưu') => {
        setBusy(true);
        try {
            await action();
            message.success(success);
            reloadAccounts();
            setDataVersion(v => v + 1);
        } catch (e) {
            message.error(axios.isAxiosError(e) ? e.response?.data?.message || 'Yêu cầu thất bại' : 'Yêu cầu thất bại');
        } finally {
            setBusy(false);
        }
    };

    const openPolicy = () => {
        policyForm.setFieldsValue({ enabled: salePolicy.enabled, ids: salePolicy.ids.join(', ') });
        setPolicyOpen(true);
    };

    const applyAccountFilter = (filter: Record<string, unknown>) =>
        router.get(base, { ...filter, page: 1 }, { preserveState: true, preserveScroll: true });

    const cards = [
        { label: 'Tất cả acc', value: stats.total, filter: {} },
        { label: 'Bán nguyên nick', value: stats.nick, filter: { usage: 'nick' } },
        { label: 'Kho đồ', value: stats.warehouse, filter: { usage: 'warehouse' } },
        { label: 'Acc cần xử lý', value: stats.attention, filter: { state: 'attention' }, alert: stats.attention > 0 },
    ];

    const tabs = [
        {
            key: 'accounts',
            label: `Tài khoản (${accountPagination?.total ?? accounts.length})`,
            show: caps.accounts,
            children: (
                <AccountsTab
                    accounts={accounts}
                    pagination={accountPagination}
                    initialFilters={accountFilters}
                    servers={servers}
                    loginServers={loginServers}
                    categories={categories}
                    caps={caps}
                    salePolicy={salePolicy}
                    shopUrl={shopUrl}
                    busy={busy}
                    run={run}
                    reload={reloadAccounts}
                    onOpenPolicy={openPolicy}
                    dataVersion={dataVersion}
                />
            ),
        },
        {
            key: 'listings',
            label: `Gói đồ (${stats.listings})`,
            show: caps.listings,
            children: <ListingsTab caps={caps} shopUrl={shopUrl} run={run} busy={busy} dataVersion={dataVersion} />,
        },
        {
            key: 'orders',
            label: `Đơn giao đồ (${stats.orders})`,
            show: caps.orders,
            children: <OrdersTab dataVersion={dataVersion} />,
        },
        {
            key: 'jobs',
            label: `Công việc tool (${stats.jobs})`,
            show: caps.manageAccounts || caps.reconcile,
            children: (
                <JobsTab canReconcile={canReconcile} run={run} busy={busy} dataVersion={dataVersion} />
            ),
        },
        {
            key: 'worker-keys',
            label: 'API key tool',
            show: caps.workers,
            children: <WorkerKeysTab run={run} busy={busy} />,
        },
    ].filter(tab => tab.show);

    return (
        <AdminLayout title="Quản lý acc & kho đồ NRO">
            {importing && (
                <NroAccountImport
                    open={importing}
                    onClose={() => setImporting(false)}
                    onImported={() => applyAccountFilter({})}
                    servers={servers}
                    loginServers={loginServers}
                    categories={categories}
                />
            )}

            <SalePolicyModal
                open={policyOpen}
                form={policyForm}
                busy={busy}
                onClose={() => setPolicyOpen(false)}
                onSaved={reloadAccounts}
                run={run}
            />

            <AddAccountModal
                open={adding}
                form={accountForm}
                caps={caps}
                servers={servers}
                loginServers={loginServers}
                categories={categories}
                busy={busy}
                onClose={() => setAdding(false)}
                run={run}
            />

            <div className="space-y-4 p-2">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-bold">Acc &amp; kho đồ NRO</h1>
                        <p className="mt-1 text-xs text-slate-500">
                            Theo dõi tài khoản, tin bán và công việc lấy dữ liệu.
                        </p>
                    </div>
                    <Space wrap>
                        <Tag color={stats.workerOnline ? 'green' : 'red'}>
                            Tool {stats.workerOnline ? 'đang online' : 'offline'}
                        </Tag>
                        <Button onClick={reloadAccounts}>Làm mới</Button>
                        {caps.salePolicy && (
                            <Dropdown
                                menu={{
                                    items: [{ key: 'policy', label: 'ID đồ được phép bán', onClick: openPolicy }],
                                }}
                            >
                                <Button>Cấu hình ▾</Button>
                            </Dropdown>
                        )}
                        {caps.manageAccounts && (
                            <>
                                <Button onClick={() => setImporting(true)}>Nhập list / TXT</Button>
                                <Button
                                    type="primary"
                                    onClick={() => {
                                        accountForm.resetFields();
                                        setAdding(true);
                                    }}
                                >
                                    Thêm acc game
                                </Button>
                            </>
                        )}
                    </Space>
                </div>

                {caps.accounts && (
                    <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
                        {cards.map(card => (
                            <button
                                key={card.label}
                                onClick={() => applyAccountFilter(card.filter)}
                                className={`rounded-lg border p-3 text-left hover:border-blue-500 dark:bg-slate-900 ${
                                    card.alert
                                        ? 'border-amber-400 bg-amber-50 dark:border-amber-500/60 dark:bg-amber-950/30'
                                        : 'border-slate-200 bg-white dark:border-slate-700'
                                }`}
                            >
                                <span className="text-xs text-slate-500 dark:text-slate-400">{card.label}</span>
                                <strong className="mt-1 block text-xl">{card.value}</strong>
                            </button>
                        ))}
                    </div>
                )}

                {/* What needs a human right now, separated from the inventory counts above. */}
                {(caps.orders || caps.reconcile || caps.manageAccounts) && (
                    <div className="flex flex-wrap gap-2 text-xs">
                        {stats.reviewJobs > 0 && (
                            <Tag color="orange">Có {stats.reviewJobs} công việc chờ đối soát</Tag>
                        )}
                        {stats.openOrders > 0 && <Tag color="blue">{stats.openOrders} đơn chưa hoàn tất</Tag>}
                        {stats.activeJobs > 0 && (
                            <Tag color="processing">{stats.activeJobs} công việc tool đang chạy</Tag>
                        )}
                        {!stats.reviewJobs && !stats.openOrders && !stats.activeJobs && (
                            <span className="text-slate-500 dark:text-slate-400">
                                Không có việc nào đang chờ xử lý.
                            </span>
                        )}
                    </div>
                )}

                <Collapse
                    size="small"
                    items={[
                        {
                            key: 'guide',
                            label: 'Cách thêm acc và đăng bán',
                            children: (
                                <div className="space-y-2 text-xs leading-5">
                                    <p>
                                        <strong>Bán nguyên nick:</strong> chọn danh mục và giá khi thêm acc; tool lấy dữ
                                        liệu game rồi tự đăng tin.
                                    </p>
                                    <p>
                                        <strong>Kho đồ:</strong> tool lấy dữ liệu, bạn chọn món trong kho để tạo gói bán
                                        lẻ.
                                    </p>
                                    <p>
                                        <strong>Dữ liệu game</strong> (trước gọi là snapshot) không hết hạn theo thời
                                        gian. Sau mỗi thay đổi tồn kho, tool sẽ tự cập nhật lại.
                                    </p>
                                </div>
                            ),
                        },
                    ]}
                />

                <Tabs items={tabs} destroyOnHidden={false} />
            </div>
        </AdminLayout>
    );
}
