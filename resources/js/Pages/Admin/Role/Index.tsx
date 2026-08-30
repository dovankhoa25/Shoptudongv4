// Admin/Roles/Index.tsx - Refactored với DataTable
import React, { useState, useMemo } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { IRole } from "@/InterFaces/role";
import { PageProps, PaginatedData } from "@/types";
import Rolemodel from "./RoleModel";
import RoleHasPermissionModal from "./RoleHasPermissionModal";
import { Column, DataTable } from "@/Components/Table/DataTable";
import { Shield } from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import { useTableFilters } from "@/Hooks/useTableFilters";

export default function RolePage() {
    const { roles, filters: serverFilters, auth } = usePage<
        PageProps & {
            roles: PaginatedData<IRole>;
            filters: { search?: string };
        }
    >().props;
    const canManageRoles = auth.is_super_admin
        || (Array.isArray(auth.permissions) && auth.permissions.includes('roles.manage'));

    const toast = useToast();

    // 🎯 Sử dụng custom hook thay cho logic cũ
    const {
        filters,
        columnFilters,
        loading,
        handleSearch,
        handleResetFilters,
        handlePageChange,
        setColumnFilters,
    } = useTableFilters({
        routeName: 'admin.roles.index',
        initialFilters: serverFilters,
        initialData: roles,
        debounceMs: 500,
    });

    // Modal states
    const [showModal, setShowModal] = useState(false);
    const [selectedRole, setSelectedRole] = useState<IRole | null>(null);
    const [openRolePermissionModal, setOpenRolePermissionModal] = useState(false);

    // Modal handlers
    const handleOpenModal = () => {
        setSelectedRole(null);
        setShowModal(true);
    };

    const handleOpenPermissionModal = (role: IRole) => {
        setSelectedRole(role);
        setOpenRolePermissionModal(true);
    };

    const handleCloseModal = () => {
        setShowModal(false);
        setOpenRolePermissionModal(false);
        setSelectedRole(null);
    };

    const handleEdit = (role: IRole) => {
        setSelectedRole(role);
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

    // Define columns for DataTable
    const columns: Column<IRole>[] = useMemo(() => [
        {
            key: 'id',
            title: 'ID',
            width: 50,
            align: 'center',
            visible: false,
            render: (value: any) => (
                <span className="font-mono  bg-gray-100 px-2 py-1 rounded text-sm">
                    #{value}
                </span>
            )
        },
        {
            key: 'name',
            title: 'Tên vai trò',
            width: 200,
            sortable: true,
            render: (value: string, record: IRole) => (
                <div className="flex items-center gap-3">
                    <div className="w-8 h-8 bg-purple-100 rounded-full flex items-center justify-center">
                        <Shield className="w-4 h-4 text-purple-600" />
                    </div>
                    <div>
                        <div className="font-medium ">{value}</div>
                        <div className="text-sm ">Role: {record.name}</div>
                    </div>
                </div>
            )
        },
        {
            key: 'guard_name',
            title: 'Loại Auth',
            width: 150,
            filters: [
                { text: '🌐 Web', value: 'web' },
                { text: '📱 API', value: 'api' },
                { text: '🔧 Sanctum', value: 'sanctum' }
            ],
            render: (value: string) => {
                const guardConfig = {
                    web: { color: 'bg-blue-100 text-blue-800', icon: '🌐' },
                    api: { color: 'bg-green-100 text-green-800', icon: '📱' },
                    sanctum: { color: 'bg-purple-100 text-purple-800', icon: '🔧' }
                };
                const config = guardConfig[value as keyof typeof guardConfig] || { color: 'bg-gray-100 text-gray-800', icon: '🔒' };

                return (
                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${config.color}`}>
                        {config.icon} {value}
                    </span>
                );
            }
        },
        {
            key: 'permissions_count',
            title: 'Số quyền',
            width: 100,
            align: 'center',
            render: (value: number) => (
                <div className="bg-indigo-100 text-indigo-800 text-center inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium">
                    {value || 0} quyền
                </div>
            )
        },
        {
            key: 'users_count',
            title: 'Số người dùng',
            width: 120,
            align: 'center',
            render: (value: number) => (
                <div className="bg-emerald-100 text-emerald-800 text-center inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium">
                    {value || 0} người
                </div>
            )
        },
        {
            key: 'created_at',
            title: 'Ngày tạo',
            sortable: true,
            width: 150,
            render: (value: string) => (
                <span className="text-sm ">{formatDate(value)}</span>
            )
        }
    ], []);

    return (
        <>
            {/* 🎯 DataTable thay thế Ant Design Table */}
            <DataTable<IRole>
                data={roles.data}
                columns={columns}
                loading={loading}
                searchValue={filters.search}
                title="Quản lý vai trò"
                description="Tạo vai trò và gán các quyền được phép sử dụng"
                pagination={{
                    current: roles.meta.current_page,
                    pageSize: roles.meta.per_page,
                    total: roles.meta.total,
                    showSizeChanger: true,
                    pageSizeOptions: ['5', '10', '20', '50'],
                    onChange: handlePageChange,
                }}
                onFiltersChange={setColumnFilters}
                onSearch={handleSearch}
                onAdd={canManageRoles ? handleOpenModal : undefined}
                onReset={handleResetFilters}
                onEdit={canManageRoles ? handleEdit : undefined}
                customActions={{
                    permissions: {
                        label: 'Gán quyền',
                        icon: Shield,
                        handler: handleOpenPermissionModal,
                        condition: role => canManageRoles && role.can_manage === true,
                        className: 'text-purple-600'
                    }
                }}
            />

            {/* Modals */}
            {showModal && (
                <Rolemodel
                    onClose={handleCloseModal}
                    role={selectedRole}
                />
            )}

            {openRolePermissionModal && selectedRole && (
                <RoleHasPermissionModal
                    onClose={handleCloseModal}
                    onSaved={() => router.reload({ only: ['roles'] })}
                    role={selectedRole}
                />
            )}
        </>
    );
}

RolePage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Role Management" children={page} />
);
