
// Admin/GemPrices/Index.tsx - Gem Price Management với Multiplier
import React, { useState, useMemo } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { PageProps, PaginatedData } from "@/types";
import GemPriceModal from "./GemPriceModal";
import { Column, DataTable } from "@/Components/Table/DataTable";
import {
    Settings, CheckCircle, XCircle,
    Calendar, Clock, Server, DollarSign,
    TrendingUp, TrendingDown, Activity, Gem, Hash
} from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import { useTableFilters } from "@/Hooks/useTableFilters";
import { IGemPrice } from '@/InterFaces/gemprice';
import { IServer } from '@/InterFaces/server';

interface GemPriceFilters {
    search?: string;
    server_id?: number;
    status?: number;
    min_multiplier?: number;
    max_multiplier?: number;
}

interface GemPricePageProps extends PageProps {
    gemPrices: PaginatedData<IGemPrice>;
    servers: IServer[];
    filters: GemPriceFilters;
    stats: {
        total_prices: number;
        active_prices: number;
        average_multiplier: number;
        min_multiplier: number;
        max_multiplier: number;
    };
}

export default function GemPricePage() {
    const { gemPrices, filters: serverFilters, servers, stats } = usePage<GemPricePageProps>().props;

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
        routeName: 'admin.gem-prices.index',
        initialFilters: serverFilters || {},
        initialData: gemPrices,
        debounceMs: 500,
    });

    // Cast filters to our specific type
    const currentFilters = filters as GemPriceFilters;

    // Modal states
    const [modalOpen, setModalOpen] = useState(false);
    const [selectedGemPrice, setSelectedGemPrice] = useState<IGemPrice | null>(null);

    // Modal handlers
    const handleOpenModal = () => {
        setSelectedGemPrice(null);
        setModalOpen(true);
    };

    const handleCloseModal = () => {
        setModalOpen(false);
        setSelectedGemPrice(null);
    };

    const handleEdit = (gemPrice: IGemPrice) => {
        setSelectedGemPrice(gemPrice);
        setModalOpen(true);
    };

    const handleToggleStatus = (gemPrice: IGemPrice) => {
        router.patch(`/admin/gem-prices/${gemPrice.id}/toggle-status`, {}, {
            onSuccess: () => {
                const status = !gemPrice.status ? 'kích hoạt' : 'vô hiệu hóa';
                toast.success(`Hệ số giá đã được ${status}!`);
            },
            onError: () => {
                toast.error('Cập nhật trạng thái thất bại. Vui lòng thử lại!');
            }
        });
    };

    const handleDelete = (gemPrice: IGemPrice) => {
        if (confirm(`Bạn có chắc chắn muốn xóa hệ số giá này?`)) {
            router.delete(`/admin/gem-prices/${gemPrice.id}`, {
                onSuccess: () => {
                    toast.success('Hệ số giá đã được xóa thành công!');
                },
                onError: (errors: any) => {
                    toast.error(errors?.message || 'Xóa hệ số giá thất bại. Vui lòng thử lại!');
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

    const formatNumber = (number: number): string => {
        if (!number || number === 0) return '0';
        return new Intl.NumberFormat('vi-VN').format(number);
    };

    // Get current server for display if filtered
    const currentServer = useMemo(() => {
        return currentFilters.server_id
            ? servers.find(server => server.id === currentFilters.server_id)
            : null;
    }, [currentFilters.server_id, servers]);

    // Define columns for DataTable
    const columns: Column<IGemPrice>[] = useMemo(() => [
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
            key: 'server',
            title: 'Server',
            width: 200,
            sortable: true,
            render: (server: any) => (
                <div className="flex items-center gap-3">
                    <div className="w-10 h-10 bg-gradient-to-br from-blue-100 to-cyan-100 rounded-lg flex items-center justify-center">
                        <Server className="w-5 h-5 text-blue-500" />
                    </div>
                    <div>
                        <div className="font-semibold text-gray-900">
                            {server?.name || 'N/A'}
                        </div>
                        <div className="text-sm text-gray-500">
                            Server ID: {server?.id}
                        </div>
                    </div>
                </div>
            )
        },
        {
            key: 'multiplier_display',
            title: 'Hệ số',
            width: 120,
            align: 'center',
            sortable: true,
            render: (multiplierDisplay: string, record: IGemPrice) => (
                <div className="text-center">
                    <span className="inline-flex items-center px-3 py-1 rounded-full text-lg font-bold bg-gradient-to-r from-purple-100 to-pink-100 text-purple-700 border border-purple-200">
                        <Hash className="w-4 h-4 mr-1" />
                        {multiplierDisplay}
                    </span>
                </div>
            )
        },
        {
            key: 'gems_per_10k_formatted',
            title: 'Ngọc/10k VND',
            width: 160,
            align: 'center',
            render: (gemsFormatted: string, record: IGemPrice) => (
                <div className="text-center">
                    <div className="flex items-center justify-center gap-1">
                        <Gem className="w-4 h-4 text-purple-500" />
                        <span className="text-lg font-semibold text-gray-800">
                            {record.gems_per_10k} ngọc
                        </span>
                    </div>
                    <div className="text-xs text-gray-500">
                        cho 10,000 VND
                    </div>
                </div>
            )
        },
        {
            key: 'status_label',
            title: 'Trạng thái',
            width: 140,
            align: 'center',
            filters: [
                { text: '✅ Đang áp dụng', value: true },
                { text: '⏸️ Không áp dụng', value: false },
            ],
            render: (statusLabel: string, record: IGemPrice) => {
                const statusConfig = {
                    'Đang áp dụng': {
                        color: 'bg-green-100 text-green-800 border-green-200',
                        icon: CheckCircle,
                        dot: 'bg-green-500'
                    },
                    'Không áp dụng': {
                        color: 'bg-gray-100 text-gray-800 border-gray-200',
                        icon: XCircle,
                        dot: 'bg-gray-500'
                    },
                };

                const config = statusConfig[statusLabel as keyof typeof statusConfig] || statusConfig['Không áp dụng'];

                return (
                    <span className={`inline-flex items-center px-3 py-1 rounded-full text-xs font-medium border ${config.color}`}>
                        <span className={`w-2 h-2 rounded-full mr-1.5 ${config.dot} animate-pulse`}></span>
                        {statusLabel}
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
            key: 'updated_at_human',
            title: 'Cập nhật',
            width: 120,
            render: (value: string) => (
                <span className="text-sm text-gray-600 flex items-center gap-1">
                    <Clock className="w-3 h-3" />
                    {value}
                </span>
            )
        }
    ], []);

    // Prepare filter options
    const filterOptions = useMemo(() => [
        {
            key: 'server_id',
            type: 'select' as const,
            label: 'Server',
            options: [
                { label: 'Tất cả server', value: '' },
                ...servers.map(server => ({
                    label: server.name,
                    value: server.id.toString()
                }))
            ],
            value: currentFilters.server_id?.toString() || ''
        },
        {
            key: 'status',
            type: 'select' as const,
            label: 'Trạng thái',
            options: [
                { label: 'Tất cả', value: '' },
                { label: 'Đang áp dụng', value: '1' },
                { label: 'Không áp dụng', value: '0' }
            ],
            value: currentFilters.status?.toString() || ''
        },
        {
            key: 'min_multiplier',
            type: 'input' as const,
            label: 'Hệ số tối thiểu',
            placeholder: 'VD: 10',
            value: currentFilters.min_multiplier?.toString() || ''
        },
        {
            key: 'max_multiplier',
            type: 'input' as const,
            label: 'Hệ số tối đa',
            placeholder: 'VD: 20',
            value: currentFilters.max_multiplier?.toString() || ''
        }
    ], [servers, currentFilters]);

    return (
        <>
            {/* Header with stats */}
            <div className="mb-6 bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <div className="flex items-center justify-between mb-4">
                    <div className="flex items-center gap-3">
                        <div className="w-12 h-12 bg-gradient-to-br from-purple-100 to-pink-100 rounded-lg flex items-center justify-center">
                            <Gem className="w-6 h-6 text-purple-500" />
                        </div>
                        <div>
                            <h1 className="text-xl font-semibold text-gray-900">
                                Quản lý Hệ số Giá Ngọc
                                {currentServer && (
                                    <span className="text-purple-600"> - {currentServer.name}</span>
                                )}
                            </h1>
                            <p className="text-sm text-gray-600">
                                Thiết lập hệ số nhân giá ngọc cho các server (10k VND = hệ số x 10 ngọc)
                            </p>
                        </div>
                    </div>
                </div>

                {/* Stats */}
                <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <div className="bg-blue-50 p-4 rounded-lg border border-blue-200">
                        <div className="flex items-center gap-3">
                            <Activity className="w-8 h-8 text-blue-500" />
                            <div>
                                <p className="text-sm text-blue-600 font-medium">Tổng hệ số</p>
                                <p className="text-2xl font-bold text-blue-700">{stats.total_prices}</p>
                            </div>
                        </div>
                    </div>

                    <div className="bg-green-50 p-4 rounded-lg border border-green-200">
                        <div className="flex items-center gap-3">
                            <CheckCircle className="w-8 h-8 text-green-500" />
                            <div>
                                <p className="text-sm text-green-600 font-medium">Đang áp dụng</p>
                                <p className="text-2xl font-bold text-green-700">{stats.active_prices}</p>
                            </div>
                        </div>
                    </div>

                    <div className="bg-yellow-50 p-4 rounded-lg border border-yellow-200">
                        <div className="flex items-center gap-3">
                            <Hash className="w-8 h-8 text-yellow-500" />
                            <div>
                                <p className="text-sm text-yellow-600 font-medium">Hệ số TB</p>
                                <p className="text-xl font-bold text-yellow-700">x{stats.average_multiplier}</p>
                            </div>
                        </div>
                    </div>

                    <div className="bg-red-50 p-4 rounded-lg border border-red-200">
                        <div className="flex items-center gap-3">
                            <TrendingDown className="w-8 h-8 text-red-500" />
                            <div>
                                <p className="text-sm text-red-600 font-medium">Hệ số thấp</p>
                                <p className="text-xl font-bold text-red-700">x{stats.min_multiplier}</p>
                            </div>
                        </div>
                    </div>

                    <div className="bg-purple-50 p-4 rounded-lg border border-purple-200">
                        <div className="flex items-center gap-3">
                            <TrendingUp className="w-8 h-8 text-purple-500" />
                            <div>
                                <p className="text-sm text-purple-600 font-medium">Hệ số cao</p>
                                <p className="text-xl font-bold text-purple-700">x{stats.max_multiplier}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {/* 🎯 DataTable cho GemPrices */}
            <DataTable<IGemPrice>
                data={gemPrices.data}
                columns={columns}
                loading={loading}
                searchValue={currentFilters.search || ''}
                title="Danh sách Hệ số Giá"
                description="Quản lý hệ số giá ngọc cho từng server"
                pagination={{
                    current: gemPrices.meta.current_page,
                    pageSize: gemPrices.meta.per_page,
                    total: gemPrices.meta.total,
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
                        label: 'Chỉnh sửa',
                        icon: Settings,
                        handler: handleEdit,
                        className: 'text-blue-600 hover:text-blue-800'
                    },
                    toggleStatus: {
                        label: 'Đổi trạng thái',
                        icon: Activity,
                        handler: handleToggleStatus,
                        className: 'text-yellow-600 hover:text-yellow-800'
                    }
                }}
                filters={filterOptions}
                searchPlaceholder="Tìm kiếm theo server..."
                addButtonText="Thêm Hệ số mới"
                emptyText="Chưa có hệ số nào"
                emptyDescription="Hãy thêm hệ số đầu tiên để bắt đầu"
            />

            {/* Modal */}
            <GemPriceModal
                open={modalOpen}
                onClose={handleCloseModal}
                gemPrice={selectedGemPrice}
                servers={servers}
            />
        </>
    );
}

GemPricePage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Gem Price Management" children={page} />
);
