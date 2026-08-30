import React, { useMemo } from 'react';
import { usePage } from '@inertiajs/react';
import { ArrowDownCircle, ArrowRight, ArrowUpCircle, CreditCard, Landmark, UserRound } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Column, DataTable } from '@/Components/Table/DataTable';
import { useTableFilters } from '@/Hooks/useTableFilters';
import { formatCurrency } from '@/Utils/currencyHelper';
import { PageProps, PaginatedData } from '@/types';

interface TransactionUser {
    id: number;
    username: string;
    email?: string | null;
}

interface BalanceTransaction {
    id: number;
    user: TransactionUser | null;
    performed_by: Pick<TransactionUser, 'id' | 'username'> | null;
    type: 'admin_credit' | 'admin_debit' | string;
    amount: number;
    balance_before: number;
    balance_after: number;
    description?: string | null;
    related_id?: string | null;
    related_type?: string | null;
    created_at: string;
}

interface TransactionsPageProps extends PageProps {
    transactions: PaginatedData<BalanceTransaction>;
    filters: { search?: string; type?: string };
}

const formatDateTime = (value: string) => new Date(value).toLocaleString('vi-VN');

const TYPE_CONFIG = {
    admin_credit: {
        label: 'Admin cộng tiền',
        icon: ArrowUpCircle,
        className: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
    },
    admin_debit: {
        label: 'Admin trừ tiền',
        icon: ArrowDownCircle,
        className: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
    },
    card_deposit: {
        label: 'Nạp tiền bằng thẻ',
        icon: CreditCard,
        className: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/30 dark:text-cyan-300',
    },
    bank_deposit: {
        label: 'Nạp tiền ngân hàng',
        icon: Landmark,
        className: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
    },
} as const;

export default function TransactionsPage() {
    const { transactions, filters: serverFilters } = usePage<TransactionsPageProps>().props;
    const { filters, loading, handleSearch, handleResetFilters, handlePageChange, setColumnFilters } = useTableFilters({
        routeName: 'admin.transactions.index',
        initialFilters: serverFilters,
        initialData: transactions,
        debounceMs: 400,
    });

    const columns: Column<BalanceTransaction>[] = useMemo(() => [
        {
            key: 'id',
            title: 'Mã GD',
            width: 90,
            render: (value: number) => <span className="font-mono text-xs text-slate-500">#{value}</span>,
        },
        {
            key: 'user',
            title: 'Người dùng',
            width: 220,
            render: (user: TransactionUser | null) => user ? (
                <div className="flex min-w-0 items-center gap-2.5">
                    <span className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">
                        <UserRound size={17} />
                    </span>
                    <div className="min-w-0">
                        <p className="truncate font-medium text-slate-900 dark:text-white">{user.username}</p>
                        <p className="truncate text-xs text-slate-500">{user.email || `ID #${user.id}`}</p>
                    </div>
                </div>
            ) : <span className="text-sm italic text-slate-400">Người dùng đã xóa</span>,
        },
        {
            key: 'type',
            title: 'Loại giao dịch',
            width: 155,
            filters: [
                { text: 'Admin cộng tiền', value: 'admin_credit' },
                { text: 'Admin trừ tiền', value: 'admin_debit' },
                { text: 'Nạp tiền bằng thẻ', value: 'card_deposit' },
                { text: 'Nạp tiền ngân hàng', value: 'bank_deposit' },
            ],
            render: (type: string) => {
                const config = TYPE_CONFIG[type as keyof typeof TYPE_CONFIG];
                const Icon = config?.icon ?? ArrowDownCircle;
                return (
                    <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ${config?.className ?? 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'}`}>
                        <Icon size={14} />
                        {config?.label ?? type}
                    </span>
                );
            },
        },
        {
            key: 'amount',
            title: 'Số tiền',
            width: 150,
            align: 'right',
            render: (amount: number) => (
                <span className={`font-semibold ${amount >= 0 ? 'text-emerald-600' : 'text-red-600'}`}>
                    {amount >= 0 ? '+' : '-'}{formatCurrency(Math.abs(amount))}
                </span>
            ),
        },
        {
            key: 'balance_change',
            title: 'Số dư trước / sau',
            width: 260,
            render: (_: unknown, transaction: BalanceTransaction) => (
                <div className="flex items-center gap-2 text-xs">
                    <span className="rounded-lg bg-slate-100 px-2 py-1.5 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        {formatCurrency(transaction.balance_before)}
                    </span>
                    <ArrowRight size={14} className="shrink-0 text-slate-400" />
                    <span className="rounded-lg bg-blue-50 px-2 py-1.5 font-medium text-blue-700 dark:bg-blue-950/50 dark:text-blue-300">
                        {formatCurrency(transaction.balance_after)}
                    </span>
                </div>
            ),
        },
        {
            key: 'performed_by',
            title: 'Người thực hiện',
            width: 165,
            render: (performer: BalanceTransaction['performed_by']) => performer
                ? <span className="text-sm font-medium text-slate-700 dark:text-slate-200">{performer.username}</span>
                : <span className="text-sm italic text-slate-400">Hệ thống</span>,
        },
        {
            key: 'description',
            title: 'Lý do',
            width: 280,
            render: (description?: string | null) => (
                <p className="max-w-xs truncate text-sm text-slate-600 dark:text-slate-300" title={description || undefined}>
                    {description || '—'}
                </p>
            ),
        },
        {
            key: 'created_at',
            title: 'Thời gian',
            width: 170,
            render: (value: string) => <span className="text-sm text-slate-500">{formatDateTime(value)}</span>,
        },
    ], []);

    return (
        <DataTable<BalanceTransaction>
            storageKey="admin-balance-transactions-table"
            density="compact"
            rowKey="id"
            selectable={false}
            striped
            stickyHeader
            data={transactions.data}
            columns={columns}
            loading={loading}
            searchValue={filters.search}
            searchPlaceholder="Tìm user, email, admin hoặc lý do..."
            title="Lịch sử số dư"
            description={`${transactions.meta.total.toLocaleString('vi-VN')} giao dịch đã được ghi nhận`}
            onSearch={handleSearch}
            onReset={handleResetFilters}
            onFiltersChange={setColumnFilters}
            pagination={{
                current: transactions.meta.current_page,
                pageSize: transactions.meta.per_page,
                total: transactions.meta.total,
                showSizeChanger: true,
                pageSizeOptions: ['10', '20', '50', '100'],
                onChange: handlePageChange,
            }}
        />
    );
}

TransactionsPage.layout = (page: React.ReactNode) => <AdminLayout title="Lịch sử số dư">{page}</AdminLayout>;
