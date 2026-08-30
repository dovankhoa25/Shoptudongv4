// Admin/Servers/Index.tsx - Server Management
import React, { useState, useMemo } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { PageProps, PaginatedData } from "@/types";
import ServerModal from "./ServiceModal";
import { Column, DataTable } from "@/Components/Table/DataTable";
import {
    Settings, Server, CheckCircle, XCircle,
    Calendar, Clock, Hash
} from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import { useTableFilters } from "@/Hooks/useTableFilters";
import { IServer } from '@/InterFaces/server';



export default function ServerPage() {
    const { roles: servers, filters: serverFilters } = usePage<
        PageProps & {
            roles: PaginatedData<IServer>;
            filters: { search?: string; per_page?: number };
        }
    >().props;

    const toast = useToast();

    // 🎯 Sử dụng custom hook cho table filters
    const {
        filters,
        columnFilters,
        loading,
        handleSearch,
        handleResetFilters,
        handlePageChange,
        setColumnFilters,
    } = useTableFilters({
        routeName: 'admin.servers.index',
        initialFilters: serverFilters,
        initialData: servers,
        debounceMs: 500,
    });

    // Modal states
    const [modalOpen, setModalOpen] = useState(false);
    const [selectedServer, setSelectedServer] = useState<IServer | null>(null);

    // Modal handlers
    const handleOpenModal = () => {
        setSelectedServer(null);
        setModalOpen(true);
    };

    const handleCloseModal = () => {
        setModalOpen(false);
        setSelectedServer(null);
    };

    const handleEdit = (server: IServer) => {
        setSelectedServer(server);
        setModalOpen(true);
    };

    const handleDelete = (server: IServer) => {
        if (confirm(`Bạn có chắc chắn muốn xóa server "${server.name}"?`)) {
            router.delete(`/admin/servers/${server.id}`, {
                onSuccess: () => {
                    toast.success(`Server "${server.name}" đã được xóa thành công!`);
                },
                onError: () => {
                    toast.error('Xóa server thất bại. Vui lòng thử lại!');
                }
            });
        }
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

    const renderServerIcon = (serverName: string) => {
        return (
            <div className="w-10 h-10 bg-gradient-to-br from-blue-100 to-purple-100 rounded-lg flex items-center justify-center">
                <Server className="w-5 h-5 text-blue-600" />
            </div>
        );
    };

    // Define columns for DataTable
    const columns: Column<IServer>[] = useMemo(() => [
        {
            key: 'id',
            title: 'ID',
            width: 60,
            align: 'center',
            visible: false,
            render: (value: any) => (
                <span className="font-mono text-gray-600 bg-gray-100 px-2 py-1 rounded text-sm">
                    #{value}
                </span>
            )
        },
        {
            key: 'name',
            title: 'Tên Server',
            width: 300,
            sortable: true,
            render: (name: string, record: IServer) => (
                <div className="flex items-center gap-3">
                    {renderServerIcon(name)}
                    <div>
                        <div className="font-semibold text-gray-900">{name}</div>
                        <div className="text-sm text-gray-500">
                            ID: {record.id}
                        </div>
                    </div>
                </div>
            )
        },
        {
            key: 'name_view',
            title: 'Tên Server view',
            width: 300,
            sortable: true,
            render: (name_view: string, record: IServer) => (
                <div className="flex items-center gap-3">
                    {renderServerIcon(name_view)}
                    <div>
                        <div className="font-semibold text-gray-900">{name_view}</div>
                        <div className="text-sm text-gray-500">
                            ID: {record.id}
                        </div>
                    </div>
                </div>
            )
        },
        {
            key: 'ip',
            title: 'Server ip',
            width: 300,
            sortable: true,
            render: (ip: string) => (
                <div className="flex items-center gap-3">
                    <div>
                        <div className="font-semibold text-gray-900">{ip ?? 'null'}</div>
                    </div>
                </div>
            )
        },
        {
            key: 'port',
            title: 'Server port',
            width: 300,
            sortable: true,
            render: (port: string) => (
                <div className="flex items-center gap-3">
                    <div>
                        <div className="font-semibold text-gray-900">{port ?? 'null'}</div>
                    </div>
                </div>
            )
        },
        {
            key: 'status',
            title: 'Trạng thái',
            width: 120,
            align: 'center',
            filters: [
                { text: '✅ Hoạt động', value: 1 },
                { text: '⏸️ Tạm dừng', value: 0 },
            ],
            render: (status: boolean) => {
                const statusConfig = {
                    1: {
                        color: 'bg-green-100 text-green-800',
                        text: 'Hoạt động',
                        icon: CheckCircle,
                        dot: 'bg-green-500'
                    },
                    0: {
                        color: 'bg-red-100 text-red-800',
                        text: 'Tạm dừng',
                        icon: XCircle,
                        dot: 'bg-red-500'
                    },
                } as const;

                const statusKey = Number(status) as keyof typeof statusConfig;
                const config = statusConfig[statusKey] || statusConfig[1];

                return (
                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${config.color}`}>
                        <span className={`w-2 h-2 rounded-full mr-1.5 ${config.dot}`}></span>
                        {config.text}
                    </span>
                );
            },
        },
        {
            key: 'created_at',
            title: 'Ngày tạo',
            sortable: true,
            width: 140,
            render: (value: string) => (
                <span className="text-sm text-gray-600 flex items-center gap-1">
                    <Calendar className="w-3 h-3" />
                    {formatDate(value)}
                </span>
            )
        },
        {
            key: 'updated_at',
            title: 'Cập nhật',
            sortable: true,
            visible: false,
            width: 140,
            render: (value: string) => (
                <span className="text-sm text-gray-600 flex items-center gap-1">
                    <Clock className="w-3 h-3" />
                    {formatDate(value)}
                </span>
            )
        }
    ], []);

    return (
        <>
            {/* 🎯 DataTable cho Servers */}
            <DataTable<IServer>
                data={servers.data}
                columns={columns}
                loading={loading}
                searchValue={filters.search}
                title="Quản lý Server"
                description="Danh sách tất cả các server trong hệ thống"
                pagination={{
                    current: servers.meta.current_page,
                    pageSize: servers.meta.per_page,
                    total: servers.meta.total,
                    showSizeChanger: true,
                    pageSizeOptions: ['10', '20', '50', '100'],
                    onChange: handlePageChange,
                }}
                onFiltersChange={setColumnFilters}
                onSearch={handleSearch}
                onAdd={handleOpenModal}
                onReset={handleResetFilters}
                onEdit={handleEdit}
                onDelete={handleDelete}
                customActions={{
                    settings: {
                        label: 'Cài đặt',
                        icon: Settings,
                        handler: handleEdit,
                        className: 'text-blue-600 hover:text-blue-800'
                    },
                }}
            />

            {/* Modal */}
            <ServerModal
                open={modalOpen}
                onClose={handleCloseModal}
                server={selectedServer}
            />
        </>
    );
}

ServerPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Server Management" children={page} />
);
