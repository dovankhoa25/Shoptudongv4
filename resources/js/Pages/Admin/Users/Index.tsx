import React, { useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { Edit3, Lock, ShieldCheck, Unlock, WalletCards } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Column, DataTable } from '@/Components/Table/DataTable';
import { useTableFilters } from '@/Hooks/useTableFilters';
import { useToast } from '@/Components/ToastProvider';
import { formatCurrency } from '@/Utils/currencyHelper';
import { IUser } from '@/InterFaces/user';
import { PageProps, PaginatedData } from '@/types';
import UserDetailModal from './UserDetailModal';
import UserFormModal from './UserFormModal';
import UserPermissionModal from './UserPermissionModal';
import LockUserModal from './LockUserModal';
import BalanceAdjustmentModal from './BalanceAdjustmentModal';

interface UsersPageProps extends PageProps {
    users: PaginatedData<IUser>;
    filters: { search?: string; role?: string; is_locked?: number };
    can: { create: boolean };
}

const STATUS_META: Record<string, { label: string; className: string }> = {
    active: { label: 'Hoạt động', className: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' },
    locked: { label: 'Đã khóa', className: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300' },
    banned: { label: 'Đã cấm', className: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300' },
    pending: { label: 'Chờ xác thực', className: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300' },
};

const errorMessage = (error: unknown, fallback: string): string => {
    if (axios.isAxiosError(error)) {
        return error.response?.data?.message || fallback;
    }
    return fallback;
};

export default function UserPage() {
    const { users, filters: serverFilters, can } = usePage<UsersPageProps>().props;
    const toast = useToast();
    const [selectedUser, setSelectedUser] = useState<IUser | null>(null);
    const [modal, setModal] = useState<'form' | 'detail' | 'permission' | 'lock' | 'balance' | null>(null);
    const [unlockingId, setUnlockingId] = useState<number | null>(null);

    const { filters, loading, handleSearch, handleResetFilters, handlePageChange, setColumnFilters } = useTableFilters({
        routeName: 'admin.users.index',
        initialFilters: serverFilters,
        initialData: users,
        debounceMs: 400,
    });

    const open = (name: NonNullable<typeof modal>, user: IUser | null = null) => {
        setSelectedUser(user);
        setModal(name);
    };

    const close = () => {
        setModal(null);
        setSelectedUser(null);
    };

    const reloadUsers = () => router.reload({ only: ['users', 'can'] });

    const unlock = async (user: IUser) => {
        if (unlockingId !== null) return;
        setUnlockingId(user.id);
        try {
            await axios.post(`/admin/users/${user.id}/unlock`);
            toast.success(`Đã mở khóa tài khoản ${user.username}`);
            reloadUsers();
        } catch (error) {
            toast.error(errorMessage(error, 'Không thể mở khóa tài khoản.'));
        } finally {
            setUnlockingId(null);
        }
    };

    const columns: Column<IUser>[] = useMemo(() => [
        {
            key: 'username',
            title: 'Người dùng',
            width: 260,
            render: (value: string, user: IUser) => (
                <div className="flex min-w-0 items-center gap-3">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-gradient-to-br from-blue-500 to-violet-600 font-semibold text-white">
                        {user.avatar ? <img src={user.avatar} alt="" className="h-full w-full object-cover" /> : value?.slice(0, 1).toUpperCase()}
                    </div>
                    <div className="min-w-0">
                        <div className="truncate font-medium text-slate-900 dark:text-white">{value}</div>
                        <div className="truncate text-xs text-slate-500 dark:text-slate-400">{user.email || 'Chưa có email'}</div>
                    </div>
                </div>
            ),
        },
        {
            key: 'role',
            title: 'Vai trò',
            width: 150,
            filters: [
                { text: 'Super admin', value: 'super-admin' },
                { text: 'Admin', value: 'admin' },
                { text: 'CTV', value: 'ctv' },
            ],
            render: (_: string, user: IUser) => (
                <div className="flex flex-wrap gap-1">
                    {user.roles?.length ? user.roles.map(role => (
                        <span key={role.id} className="rounded-full bg-violet-100 px-2 py-1 text-xs font-medium text-violet-700 dark:bg-violet-900/30 dark:text-violet-300">
                            {role.name}
                        </span>
                    )) : <span className="text-xs text-slate-400">Chưa có vai trò</span>}
                </div>
            ),
        },
        {
            key: 'balance',
            title: 'Số dư',
            width: 140,
            align: 'right',
            render: (value: string) => <span className="font-medium text-emerald-600 dark:text-emerald-400">{formatCurrency(value || '0')}</span>,
        },
        {
            key: 'is_locked',
            title: 'Trạng thái',
            width: 140,
            filters: [
                { text: 'Hoạt động', value: 0 },
                { text: 'Đã khóa/cấm', value: 1 },
            ],
            render: (_: boolean, user: IUser) => {
                const meta = STATUS_META[user.status || 'active'] ?? STATUS_META.active;
                return <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${meta.className}`}>{meta.label}</span>;
            },
        },
        {
            key: 'created_at',
            title: 'Ngày tạo',
            width: 160,
            render: (value: string) => <span className="text-sm text-slate-500 dark:text-slate-400">{new Date(value).toLocaleString('vi-VN')}</span>,
        },
    ], []);

    return (
        <>
            <DataTable<IUser>
                storageKey="admin-users-table"
                density="compact"
                rowKey="id"
                selectable={false}
                striped
                stickyHeader
                data={users.data}
                columns={columns}
                loading={loading}
                searchValue={filters.search}
                searchPlaceholder="Tìm theo username hoặc email..."
                title="Quản lý người dùng"
                description={`${users.meta.total.toLocaleString('vi-VN')} tài khoản trong hệ thống`}
                addButtonText="Thêm người dùng"
                onAdd={can.create ? () => open('form') : undefined}
                onView={user => open('detail', user)}
                onSearch={handleSearch}
                onReset={handleResetFilters}
                onFiltersChange={setColumnFilters}
                pagination={{
                    current: users.meta.current_page,
                    pageSize: users.meta.per_page,
                    total: users.meta.total,
                    showSizeChanger: true,
                    pageSizeOptions: ['10', '20', '50', '100'],
                    onChange: handlePageChange,
                }}
                customActions={{
                    edit: {
                        label: 'Sửa thông tin', icon: Edit3, handler: user => open('form', user),
                        condition: user => Boolean(user.can?.update), className: 'text-blue-600',
                    },
                    permissions: {
                        label: 'Vai trò & quyền', icon: ShieldCheck, handler: user => open('permission', user),
                        condition: user => Boolean(user.can?.manage_roles), className: 'text-violet-600',
                    },
                    balance: {
                        label: 'Cộng / trừ số dư', icon: WalletCards, handler: user => open('balance', user),
                        condition: user => Boolean(user.can?.adjust_balance), className: 'text-emerald-600',
                    },
                    lock: {
                        label: 'Khóa tài khoản', icon: Lock, handler: user => open('lock', user),
                        condition: user => Boolean(user.can?.lock && !user.is_locked), className: 'text-red-600',
                    },
                    unlock: {
                        label: unlockingId ? 'Đang mở khóa...' : 'Mở khóa', icon: Unlock, handler: unlock,
                        condition: user => Boolean(user.can?.lock && user.is_locked), className: 'text-emerald-600',
                    },
                }}
            />

            {modal === 'form' && <UserFormModal user={selectedUser} onClose={close} />}
            {modal === 'detail' && selectedUser && <UserDetailModal user={selectedUser} onClose={close} />}
            {modal === 'permission' && selectedUser && <UserPermissionModal user={selectedUser} onClose={close} onSaved={reloadUsers} />}
            {modal === 'balance' && selectedUser && (
                <BalanceAdjustmentModal user={selectedUser} onClose={close} onAdjusted={reloadUsers} />
            )}
            {modal === 'lock' && selectedUser && (
                <LockUserModal user={selectedUser} onClose={close} onLocked={() => { close(); reloadUsers(); }} />
            )}
        </>
    );
}

UserPage.layout = (page: React.ReactNode) => <AdminLayout title="Quản lý người dùng">{page}</AdminLayout>;
