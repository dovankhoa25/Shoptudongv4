// Admin/CongTacVien/Index.tsx
import React, { useState, useMemo } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { IUser } from "@/InterFaces/user";
import { PageProps, PaginatedData } from "@/types";
import UserPermissionModal from "../Users/UserPermissionModal";
import UserModel from "../Users/UserModel";
import LockUserModal from "../Users/LockUserModal";
import axios from "axios";
import { formatCurrency } from "@/Utils/currencyHelper";
import { Column, DataTable } from "@/Components/Table/DataTable";
import { Lock, Tag, Unlock, UserCheck, Users, Wallet, TrendingUp, Shield, FolderTree } from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import { useTableFilters } from "@/Hooks/useTableFilters";
import UserCategoryModal from '../UserCategory/UserCategoryModal';

// 📊 Interface cho statistics
interface Statistics {
    total_ctv: number;
    active_ctv: number;
    locked_ctv: number;
    total_balance: string | number;
    average_balance: string | number;
    ctv_with_categories: number;
}

export default function CongTacVienPage() {


    const pageProps = usePage<
        PageProps & {
            users?: PaginatedData<IUser>;
            congTacViens?: PaginatedData<IUser>;
            statistics?: Statistics;
            filters?: {
                search?: string;
                role?: string;
                is_locked?: number;
            };
        }
    >().props;

    const congTacViens = pageProps.congTacViens || pageProps.users || {
        data: [],
        meta: {
            current_page: 1,
            per_page: 10,
            total: 0,
            last_page: 1,
        },
        links: []
    };

    const statistics = pageProps.statistics || {
        total_ctv: 0,
        active_ctv: 0,
        locked_ctv: 0,
        total_balance: 0,
        average_balance: 0,
        ctv_with_categories: 0,
    };

    const serverFilters = pageProps.filters || {};
    const toast = useToast();

    const {
        filters,
        columnFilters,
        loading,
        handleSearch,
        handleResetFilters,
        handlePageChange,
        setColumnFilters,
    } = useTableFilters({
        routeName: 'admin.users.ctv',
        initialFilters: serverFilters,
        initialData: congTacViens,
        debounceMs: 500,
    });

    // Modal states
    const [showModal, setShowModal] = useState(false);
    const [selectedCongTacVien, setSelectedCongTacVien] = useState<IUser | null>(null);
    const [openPermissionModal, setOpenPermissionModal] = useState(false);
    const [openLockModal, setOpenLockModal] = useState(false);
    const [openCategoryModal, setOpenCategoryModal] = useState(false);

    // Modal handlers (giữ nguyên như code cũ)
    const handleOpenModal = () => {
        setSelectedCongTacVien(null);
        setShowModal(true);
    };

    const handleOpenPermissionModal = (congTacVien: IUser) => {
        setSelectedCongTacVien(congTacVien);
        setOpenPermissionModal(true);
    };

    const handleLockModal = (congTacVien: IUser) => {
        setSelectedCongTacVien(congTacVien);
        setOpenLockModal(true);
    };

    const handleCloseModal = () => {
        setShowModal(false);
        setOpenPermissionModal(false);
        setOpenLockModal(false);
        setSelectedCongTacVien(null);
    };

    const handleCategoryModal = (congTacVien: IUser) => {
        setSelectedCongTacVien(congTacVien);
        setOpenCategoryModal(true);
    };

    const handleCloseCategoryModal = () => {
        setOpenCategoryModal(false);
        setSelectedCongTacVien(null);
    };

    const handleUnlock = async (congTacVien: IUser) => {
        try {
            await axios.post(`/admin/users/${congTacVien.id}/unlock`);
            toast.success("Đã mở khóa tài khoản cộng tác viên");
            router.reload({ only: ['congTacViens', 'statistics'] });
        } catch {
            toast.error("Không thể mở khóa tài khoản cộng tác viên");
        }
    };

    const handleEdit = (congTacVien: IUser) => {
        setSelectedCongTacVien(congTacVien);
        setShowModal(true);
    };

    const formatDate = (dateString: string): string => {
        return new Date(dateString).toLocaleDateString('vi-VN', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    };

    // Define columns (giữ nguyên như code cũ)
    const columns: Column<IUser>[] = useMemo(() => [
        {
            key: 'id',
            title: 'ID',
            width: 50,
            align: 'center',
            visible: false,
            render: (value: any) => (
                <span className="font-mono text-gray-600 bg-gray-100 px-2 py-1 rounded text-sm">
                    #{value}
                </span>
            )
        },
        {
            key: 'username',
            title: 'Thông tin cộng tác viên',
            width: 250,
            sortable: true,
            render: (value: string, record: IUser) => (
                <div className="flex items-center gap-3">
                    <img
                        src={record.avatar ?? 'https://scontent.fhan5-8.fna.fbcdn.net/v/t39.30808-6/506088108_9958910117558987_5588490029722723904_n.jpg?_nc_cat=106&ccb=1-7&_nc_sid=6ee11a&_nc_ohc=oglpzPQKBboQ7kNvwHoJHj7&_nc_oc=AdmrcBD0r4Kcc5nip2qB1r8BSk13WyuRgQz9-eqp7G8TQ4_xRjuQUixCzkDow0P6soo&_nc_zt=23&_nc_ht=scontent.fhan5-8.fna&_nc_gid=YHjkD56Nw9nPPJfrXaL8zA&oh=00_AfNM6HH8xnCd84bbytbk_ud9flJ_TywQr3Y-t2zYwW32eQ&oe=686464E5'}
                        alt={value}
                        className="w-10 h-10 rounded-full object-cover border-2 border-orange-200"
                    />
                    <div>
                        <div className="font-medium  flex items-center gap-2">
                            <Users className="w-4 h-4 text-orange-500" />
                            {value}
                        </div>
                        <div className="text-sm ">{record.email}</div>
                    </div>
                </div>
            )
        },
        {
            key: 'balance',
            title: 'Số Dư',
            width: 120,
            render: (value: string) => (
                <div className="bg-orange-100 text-orange-800 text-center inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium">
                    {formatCurrency(value || '0')}
                </div>
            )
        },
        {
            key: 'roles',
            title: 'Quyền',
            width: 180,
            render: (value: string[]) => (
                <div className="flex flex-wrap gap-1">
                    {value && value.length > 0 ? (
                        value.map((role, index) => (
                            <span
                                key={index}
                                className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800"
                            >
                                {role}
                            </span>
                        ))
                    ) : (
                        <span className="text-gray-400 text-xs">Chưa có quyền</span>
                    )}
                </div>
            )
        },
        {
            key: 'categories',
            title: 'Danh mục quản lý',
            width: 250,
            render: (value: IUser['categories']) => (
                <div className="flex flex-wrap gap-1 max-w-sm">
                    {value && value.length > 0 ? (
                        value.map((category, index) => (
                            <span
                                key={index}
                                className={`inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium ${category.can_post
                                    ? 'bg-green-100 text-green-800'
                                    : 'bg-purple-100 text-purple-800'
                                    }`}
                                title={category.can_post ? 'Có quyền đăng bài' : 'Chỉ xem'}
                            >
                                {category.can_post && '✍️ '}
                                {category.name}
                            </span>
                        ))
                    ) : (
                        <span className="text-gray-400 text-xs">Chưa có danh mục</span>
                    )}
                </div>
            )
        },
        {
            key: 'is_locked',
            title: 'Trạng thái',
            filters: [
                { text: '✅ Hoạt động', value: 0 },
                { text: '🔒 Bị khóa', value: 1 }
            ],
            width: 130,
            visible: false,
            render: (value: number) => {
                const isLocked = Boolean(value);
                return (
                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${isLocked
                        ? 'bg-red-100 text-red-800'
                        : 'bg-green-100 text-green-800'
                        }`}>
                        {isLocked ? '🔒 Bị khóa' : '✅ Hoạt động'}
                    </span>
                );
            }
        },
        {
            key: 'locked_reason',
            title: 'Lý do khóa',
            visible: false,
            render: (value: string) => (
                <div className="text-sm text-gray-600 max-w-xs truncate">
                    {value || 'Không có'}
                </div>
            )
        },
        {
            key: 'created_at',
            title: 'Ngày tham gia',
            sortable: true,
            width: 150,
            visible: false,
            render: (value: string) => (
                <span className="text-sm text-gray-600">{formatDate(value)}</span>
            )
        }
    ], []);

    return (
        <>
            {/* 📊 Statistics Cards - Thêm phần này */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                {/* Tổng số CTV */}
                <div className="bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg p-6 border border-blue-200">
                    <div className="flex items-center justify-between">
                        <div>
                            <p className="text-sm font-medium text-blue-600">Tổng số CTV</p>
                            <p className="text-sm font-bold text-blue-900 mt-2">
                                {statistics.total_ctv.toLocaleString('vi-VN')}
                            </p>
                            <p className="text-xs text-blue-600 mt-1">
                                {statistics.active_ctv} hoạt động • {statistics.locked_ctv} bị khóa
                            </p>
                        </div>
                        <div className="bg-blue-200 rounded-full p-3">
                            <Users className="w-8 h-8 text-blue-700" />
                        </div>
                    </div>
                </div>

                {/* Tổng số dư */}
                <div className="bg-gradient-to-br from-orange-50 to-orange-100 rounded-lg p-6 border border-orange-200">
                    <div className="flex items-center justify-between">
                        <div>
                            <p className="text-sm font-medium text-orange-600">Tổng số dư</p>
                            <p className="text-sm font-bold text-orange-900 mt-2">
                                {formatCurrency(statistics.total_balance)}
                            </p>
                            <p className="text-xs text-orange-600 mt-1">
                                TB: {formatCurrency(statistics.average_balance)}/CTV
                            </p>
                        </div>
                        <div className="bg-orange-200 rounded-full p-3">
                            <Wallet className="w-8 h-8 text-orange-700" />
                        </div>
                    </div>
                </div>

                {/* CTV đang hoạt động */}
                <div className="bg-gradient-to-br from-green-50 to-green-100 rounded-lg p-6 border border-green-200">
                    <div className="flex items-center justify-between">
                        <div>
                            <p className="text-sm font-medium text-green-600">Đang hoạt động</p>
                            <p className="text-sm font-bold text-green-900 mt-2">
                                {statistics.active_ctv.toLocaleString('vi-VN')}
                            </p>
                            <p className="text-xs text-green-600 mt-1">
                                {statistics.total_ctv > 0
                                    ? ((statistics.active_ctv / statistics.total_ctv) * 100).toFixed(1)
                                    : 0}% tổng số CTV
                            </p>
                        </div>
                        <div className="bg-green-200 rounded-full p-3">
                            <Shield className="w-8 h-8 text-green-700" />
                        </div>
                    </div>
                </div>

                {/* CTV có danh mục */}
                <div className="bg-gradient-to-br from-purple-50 to-purple-100 rounded-lg p-6 border border-purple-200">
                    <div className="flex items-center justify-between">
                        <div>
                            <p className="text-sm font-medium text-purple-600">Có danh mục</p>
                            <p className="text-3xl font-bold text-purple-900 mt-2">
                                {statistics.ctv_with_categories.toLocaleString('vi-VN')}
                            </p>
                            <p className="text-xs text-purple-600 mt-1">
                                {statistics.total_ctv > 0
                                    ? ((statistics.ctv_with_categories / statistics.total_ctv) * 100).toFixed(1)
                                    : 0}% đã phân danh mục
                            </p>
                        </div>
                        <div className="bg-purple-200 rounded-full p-3">
                            <FolderTree className="w-8 h-8 text-purple-700" />
                        </div>
                    </div>
                </div>
            </div>

            {/* 🎯 DataTable cho cộng tác viên */}
            <DataTable<IUser>
                data={congTacViens.data}
                columns={columns}
                loading={loading}
                searchValue={filters.search}
                searchPreset="users"
                title="Quản lý cộng tác viên"
                description="Danh sách tất cả cộng tác viên trong hệ thống"
                pagination={{
                    current: congTacViens.meta.current_page,
                    pageSize: congTacViens.meta.per_page,
                    total: congTacViens.meta.total,
                    showSizeChanger: true,
                    pageSizeOptions: ['5', '10', '20', '50'],
                    onChange: handlePageChange,
                }}
                onFiltersChange={setColumnFilters}
                onSearch={handleSearch}
                onAdd={handleOpenModal}
                onReset={handleResetFilters}
                onEdit={handleEdit}
                customActions={{
                    lock: {
                        label: 'Khóa tài khoản',
                        icon: Lock,
                        handler: handleLockModal,
                        condition: (congTacVien) => !congTacVien.is_locked,
                        className: '!text-red-600'
                    },
                    unlock: {
                        label: 'Mở khóa',
                        icon: Unlock,
                        handler: handleUnlock,
                        condition: (congTacVien) => Boolean(congTacVien.is_locked),
                        className: '!text-green-600'
                    },
                    permissions: {
                        label: 'Phân quyền',
                        icon: UserCheck,
                        handler: handleOpenPermissionModal,
                        className: 'text-orange-600'
                    },
                    categories: {
                        label: 'Quản lý danh mục',
                        icon: Tag,
                        handler: handleCategoryModal,
                        className: 'text-blue-600'
                    },
                }}
            />

            {/* Modals - giữ nguyên */}
            {openPermissionModal && selectedCongTacVien && (
                <UserPermissionModal
                    user={selectedCongTacVien}
                    onClose={handleCloseModal}
                />
            )}

            {showModal && (
                <UserModel
                    user={selectedCongTacVien}
                    onClose={handleCloseModal}
                />
            )}

            {openLockModal && selectedCongTacVien && (
                <LockUserModal
                    user={selectedCongTacVien}
                    onClose={() => setOpenLockModal(false)}
                    onLocked={() => {
                        setOpenLockModal(false);
                        router.reload({ only: ['congTacViens', 'statistics'] });
                    }}
                />
            )}

            {selectedCongTacVien && (
                <UserCategoryModal
                    user={selectedCongTacVien}
                    open={openCategoryModal}
                    onClose={handleCloseCategoryModal}
                    onSuccess={() => {
                        handleCloseCategoryModal();
                        router.reload({ only: ['congTacViens', 'statistics'] });
                    }}
                />
            )}
        </>
    );
}

CongTacVienPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Cộng tác viên Management" children={page} />
);
