import React, { useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { CheckCircle2, Clock3, CreditCard, Landmark, Settings2, UserRound } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Column, DataTable } from '@/Components/Table/DataTable';
import { useTableFilters } from '@/Hooks/useTableFilters';
import { formatCurrency } from '@/Utils/currencyHelper';
import { PageProps, PaginatedData } from '@/types';
import CardTypeModal, { CardTypeItem } from './CardTypeModal';
import DepositDetailModal, { BankTopupItem, CardDepositItem, DepositUser } from './DepositDetailModal';

interface DepositsPageProps extends PageProps {
    cardTypes: { data: CardTypeItem[] };
    cards: PaginatedData<CardDepositItem>;
    bankTopups: PaginatedData<BankTopupItem>;
    filters: {
        card: { search?: string; status?: string; card_type_id?: number };
        bank: { search?: string; gateway?: string };
    };
    gateways: string[];
    stats: {
        bank_total: number;
        card_total: number;
        pending_cards: number;
        active_card_types: number;
    };
    can: { manage_card_types: boolean };
}

type Tab = 'bank' | 'cards' | 'types';

const STATUS_META: Record<string, { label: string; className: string }> = {
    pending: { label: 'Đang xử lý', className: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300' },
    confirmed: { label: 'Sai mệnh giá', className: 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300' },
    completed: { label: 'Hoàn tất', className: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' },
    failed: { label: 'Thất bại', className: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300' },
};

const StatusBadge = ({ status }: { status: string }) => {
    const meta = STATUS_META[status] ?? { label: status, className: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300' };
    return <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${meta.className}`}>{meta.label}</span>;
};

const UserCell = ({ user }: { user: DepositUser | null }) => user ? (
    <div className="flex min-w-0 items-center gap-2.5">
        <span className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">
            <UserRound size={17} />
        </span>
        <div className="min-w-0">
            <p className="truncate font-medium text-slate-900 dark:text-white">{user.username}</p>
            <p className="truncate text-xs text-slate-500">{user.email || `ID #${user.id}`}</p>
        </div>
    </div>
) : <span className="text-sm italic text-slate-400">Người dùng đã xóa</span>;

const formatDateTime = (value: string) => new Date(value).toLocaleString('vi-VN');

export default function DepositsPage() {
    const { cardTypes, cards, bankTopups, filters: initialFilters, gateways, stats, can } = usePage<DepositsPageProps>().props;
    const [activeTab, setActiveTab] = useState<Tab>('bank');
    const [editingType, setEditingType] = useState<CardTypeItem | null | undefined>(undefined);
    const [detail, setDetail] = useState<{ type: 'card'; item: CardDepositItem } | { type: 'bank'; item: BankTopupItem } | null>(null);

    const cardTable = useTableFilters({
        routeName: 'admin.deposits.index',
        initialFilters: initialFilters.card,
        initialData: cards,
        paramPrefix: 'card_',
        only: ['cards', 'filters'],
        debounceMs: 400,
    });
    const bankTable = useTableFilters({
        routeName: 'admin.deposits.index',
        initialFilters: initialFilters.bank,
        initialData: bankTopups,
        paramPrefix: 'bank_',
        only: ['bankTopups', 'filters'],
        debounceMs: 400,
    });

    const cardTypeColumns: Column<CardTypeItem>[] = useMemo(() => [
        {
            key: 'telco',
            title: 'Nhà mạng',
            width: 210,
            render: (value: string) => <span className="font-semibold uppercase text-slate-900 dark:text-white">{value}</span>,
        },
        {
            key: 'discount_rate',
            title: 'Chiết khấu',
            width: 150,
            align: 'right',
            render: (value: number) => <span className="font-semibold text-amber-600">{Number(value).toLocaleString('vi-VN')}%</span>,
        },
        {
            key: 'status',
            title: 'Trạng thái',
            width: 150,
            render: (value: boolean) => value
                ? <span className="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">Đang bật</span>
                : <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">Đã tắt</span>,
        },
        {
            key: 'cards_count',
            title: 'Số lượt nạp',
            width: 140,
            align: 'right',
            render: (value: number) => Number(value).toLocaleString('vi-VN'),
        },
        {
            key: 'updated_at',
            title: 'Cập nhật',
            width: 180,
            render: (value: string) => <span className="text-sm text-slate-500">{formatDateTime(value)}</span>,
        },
    ], []);

    const cardColumns: Column<CardDepositItem>[] = useMemo(() => [
        { key: 'id', title: 'Mã', width: 80, render: (value: number) => <span className="font-mono text-xs text-slate-500">#{value}</span> },
        { key: 'user', title: 'Người dùng', width: 230, render: (user: DepositUser | null) => <UserCell user={user} /> },
        {
            key: 'card_type_id',
            title: 'Nhà mạng',
            width: 135,
            filters: cardTypes.data.map(type => ({ text: type.telco.toUpperCase(), value: type.id })),
            render: (_: unknown, card: CardDepositItem) => <span className="font-medium uppercase">{card.card_type?.telco ?? '—'}</span>,
        },
        {
            key: 'declared_value',
            title: 'Mệnh giá',
            width: 145,
            align: 'right',
            render: (value: number) => <span className="font-medium">{formatCurrency(value)}</span>,
        },
        {
            key: 'amount_user',
            title: 'User nhận',
            width: 145,
            align: 'right',
            render: (value: number) => <span className="font-semibold text-emerald-600">{formatCurrency(value)}</span>,
        },
        {
            key: 'status',
            title: 'Trạng thái',
            width: 145,
            filters: [
                { text: 'Đang xử lý', value: 'pending' },
                { text: 'Sai mệnh giá', value: 'confirmed' },
                { text: 'Hoàn tất', value: 'completed' },
                { text: 'Thất bại', value: 'failed' },
            ],
            render: (value: string) => <StatusBadge status={value} />,
        },
        {
            key: 'card_info',
            title: 'Thẻ / Serial',
            width: 220,
            render: (_: unknown, card: CardDepositItem) => (
                <div className="font-mono text-xs text-slate-600 dark:text-slate-300">
                    <p>{card.code}</p><p>{card.serial}</p>
                </div>
            ),
        },
        { key: 'created_at', title: 'Thời gian', width: 180, render: (value: string) => <span className="text-sm text-slate-500">{formatDateTime(value)}</span> },
    ], [cardTypes.data]);

    const bankColumns: Column<BankTopupItem>[] = useMemo(() => [
        { key: 'id', title: 'Mã', width: 80, render: (value: number) => <span className="font-mono text-xs text-slate-500">#{value}</span> },
        { key: 'user', title: 'Người dùng', width: 230, render: (user: DepositUser | null) => <UserCell user={user} /> },
        {
            key: 'gateway',
            title: 'Ngân hàng',
            width: 140,
            filters: gateways.map(gateway => ({ text: gateway, value: gateway })),
            render: (value: string) => <span className="font-semibold text-blue-700 dark:text-blue-300">{value}</span>,
        },
        {
            key: 'amount',
            title: 'Số tiền',
            width: 155,
            align: 'right',
            render: (value: number) => <span className="font-semibold text-emerald-600">+{formatCurrency(value)}</span>,
        },
        { key: 'payment_code', title: 'Mã thanh toán', width: 160, render: (value: string) => <span className="font-mono text-sm">{value}</span> },
        { key: 'provider_transaction_id', title: 'ID SePay', width: 145, render: (value: string) => <span className="font-mono text-xs text-slate-500">{value}</span> },
        { key: 'reference_code', title: 'Mã tham chiếu', width: 190, render: (value: string | null) => <span className="font-mono text-xs text-slate-500">{value || '—'}</span> },
        { key: 'transaction_at', title: 'Thời gian', width: 180, render: (value: string) => <span className="text-sm text-slate-500">{formatDateTime(value)}</span> },
    ], [gateways]);

    const tabs: { key: Tab; label: string; icon: typeof Landmark; count: number }[] = [
        { key: 'bank', label: 'Nạp ngân hàng', icon: Landmark, count: bankTopups.meta.total },
        { key: 'cards', label: 'Nạp thẻ', icon: CreditCard, count: cards.meta.total },
        { key: 'types', label: 'Loại thẻ', icon: Settings2, count: cardTypes.data.length },
    ];

    return (
        <div className="space-y-5">
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {[
                    { label: 'Tổng nạp ngân hàng', value: formatCurrency(stats.bank_total), icon: Landmark, color: 'text-emerald-600 bg-emerald-100 dark:bg-emerald-900/30' },
                    { label: 'Tổng nạp thẻ', value: formatCurrency(stats.card_total), icon: CreditCard, color: 'text-blue-600 bg-blue-100 dark:bg-blue-900/30' },
                    { label: 'Thẻ đang xử lý', value: stats.pending_cards.toLocaleString('vi-VN'), icon: Clock3, color: 'text-amber-600 bg-amber-100 dark:bg-amber-900/30' },
                    { label: 'Loại thẻ đang bật', value: stats.active_card_types.toLocaleString('vi-VN'), icon: CheckCircle2, color: 'text-violet-600 bg-violet-100 dark:bg-violet-900/30' },
                ].map(stat => (
                    <div key={stat.label} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                        <div className="flex items-center gap-3">
                            <span className={`grid h-11 w-11 place-items-center rounded-xl ${stat.color}`}><stat.icon size={21} /></span>
                            <div><p className="text-xs font-medium uppercase tracking-wide text-slate-500">{stat.label}</p><p className="mt-1 text-lg font-bold text-slate-900 dark:text-white">{stat.value}</p></div>
                        </div>
                    </div>
                ))}
            </div>

            <div className="flex flex-wrap gap-2 rounded-2xl border border-slate-200 bg-white p-2 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                {tabs.map(tab => (
                    <button
                        key={tab.key}
                        type="button"
                        onClick={() => setActiveTab(tab.key)}
                        className={`flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold transition ${activeTab === tab.key ? 'bg-blue-600 text-white shadow-md' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'}`}
                    >
                        <tab.icon size={17} /> {tab.label}
                        <span className={`rounded-full px-2 py-0.5 text-xs ${activeTab === tab.key ? 'bg-white/20' : 'bg-slate-100 dark:bg-slate-700'}`}>{tab.count.toLocaleString('vi-VN')}</span>
                    </button>
                ))}
            </div>

            {activeTab === 'bank' && (
                <DataTable<BankTopupItem>
                    storageKey="admin-bank-topups-table" density="compact" rowKey="id" selectable={false} striped stickyHeader
                    data={bankTopups.data} columns={bankColumns} loading={bankTable.loading}
                    searchValue={bankTable.filters.search} searchPlaceholder="Tìm user, ID SePay, mã thanh toán hoặc tham chiếu..."
                    title="Lịch sử nạp ngân hàng" description={`${bankTopups.meta.total.toLocaleString('vi-VN')} webhook đã xử lý`}
                    onView={item => setDetail({ type: 'bank', item })} onSearch={bankTable.handleSearch}
                    onReset={bankTable.handleResetFilters} onFiltersChange={bankTable.setColumnFilters}
                    pagination={{ current: bankTopups.meta.current_page, pageSize: bankTopups.meta.per_page, total: bankTopups.meta.total, showSizeChanger: true, pageSizeOptions: ['10', '20', '50', '100'], onChange: bankTable.handlePageChange }}
                />
            )}

            {activeTab === 'cards' && (
                <DataTable<CardDepositItem>
                    storageKey="admin-card-deposits-table" density="compact" rowKey="id" selectable={false} striped stickyHeader
                    data={cards.data} columns={cardColumns} loading={cardTable.loading}
                    searchValue={cardTable.filters.search} searchPlaceholder="Tìm user, mã thẻ, serial hoặc mã đối tác..."
                    title="Lịch sử nạp thẻ" description={`${cards.meta.total.toLocaleString('vi-VN')} yêu cầu nạp thẻ`}
                    onView={item => setDetail({ type: 'card', item })} onSearch={cardTable.handleSearch}
                    onReset={cardTable.handleResetFilters} onFiltersChange={cardTable.setColumnFilters}
                    pagination={{ current: cards.meta.current_page, pageSize: cards.meta.per_page, total: cards.meta.total, showSizeChanger: true, pageSizeOptions: ['10', '20', '50', '100'], onChange: cardTable.handlePageChange }}
                />
            )}

            {activeTab === 'types' && (
                <DataTable<CardTypeItem>
                    storageKey="admin-card-types-table" density="compact" rowKey="id" selectable={false} striped
                    data={cardTypes.data} columns={cardTypeColumns} showSearch={false} showResetButton={false}
                    title="Cấu hình loại thẻ" description="Quản lý nhà mạng, chiết khấu và trạng thái nhận thẻ"
                    addButtonText="Thêm loại thẻ" onAdd={can.manage_card_types ? () => setEditingType(null) : undefined}
                    onEdit={can.manage_card_types ? item => setEditingType(item) : undefined}
                    pagination={{ current: 1, pageSize: 10, total: cardTypes.data.length, showSizeChanger: false }}
                />
            )}

            {editingType !== undefined && (
                <CardTypeModal
                    cardType={editingType}
                    onClose={() => setEditingType(undefined)}
                    onSaved={() => router.reload({ only: ['cardTypes', 'stats'] })}
                />
            )}
            {detail && <DepositDetailModal type={detail.type} item={detail.item} onClose={() => setDetail(null)} />}
        </div>
    );
}

DepositsPage.layout = (page: React.ReactNode) => <AdminLayout title="Quản lý nạp tiền">{page}</AdminLayout>;
