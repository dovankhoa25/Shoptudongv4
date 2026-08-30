import React, { useState, useMemo } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { PageProps, PaginatedData } from "@/types";
import GoldPriceModal, { IGoldPrice } from "./GoldPriceModal";
import { Column, DataTable } from "@/Components/Table/DataTable";
import {
    Settings, Coins, CheckCircle, XCircle, 
    Calendar, Clock, Hash, Server, DollarSign, TrendingUp, TrendingDown
} from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import { useTableFilters } from "@/Hooks/useTableFilters";
import { IServer } from '@/InterFaces/server';



export default function GoldPricePage() {
    const { prices, servers, filters: serverFilters } = usePage<
        PageProps & {
            prices: PaginatedData<IGoldPrice>;
            servers: IServer[];
            filters: { server_id?: number; status?: boolean; per_page?: number };
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
        routeName: 'admin.gold-prices.index',
        initialFilters: serverFilters,
        initialData: prices,
        debounceMs: 500,
    });

    // Modal states
    const [modalOpen, setModalOpen] = useState(false);
    const [selectedGoldPrice, setSelectedGoldPrice] = useState<IGoldPrice | null>(null);

    // Modal handlers
    const handleOpenModal = () => {
        setSelectedGoldPrice(null);
        setModalOpen(true);
    };

    const handleCloseModal = () => {
        setModalOpen(false);
        setSelectedGoldPrice(null);
    };

    const handleEdit = (goldPrice: IGoldPrice) => {
        setSelectedGoldPrice(goldPrice);
        setModalOpen(true);
    };

    const handleDelete = (goldPrice: IGoldPrice) => {
        if (confirm(`Bạn có chắc chắn muốn xóa giá vàng cho server "${goldPrice.server_name}"?`)) {
            router.delete(`/admin/gold-prices/${goldPrice.id}`, {
                onSuccess: () => {
                    toast.success(`Giá vàng cho server "${goldPrice.server_name}" đã được xóa thành công!`);
                },
                onError: () => {
                    toast.error('Xóa giá vàng thất bại. Vui lòng thử lại!');
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

    const formatPrice = (price: number): string => {
        return new Intl.NumberFormat('vi-VN', {
            style: 'currency',
            currency: 'VND'
        }).format(price);
    };

    const formatNumber = (number: number): string => {
        return new Intl.NumberFormat('vi-VN').format(number);
    };

    const renderGoldIcon = () => {
        return (
            <div className="w-10 h-10 bg-gradient-to-br from-yellow-100 to-amber-100 rounded-lg flex items-center justify-center">
                <Coins className="w-5 h-5 text-yellow-600" />
            </div>
        );
    };

    const renderPriceDifference = (sellPrice: number, importPrice: number) => {
        const difference = sellPrice - importPrice;
        const percentage = ((difference / importPrice) * 100).toFixed(1);
        
        if (difference > 0) {
            return (
                <div className="text-xs text-green-600 flex items-center gap-1">
                    <TrendingUp className="w-3 h-3" />
                    +{formatNumber(difference)} ({percentage}%)
                </div>
            );
        } else if (difference < 0) {
            return (
                <div className="text-xs text-red-600 flex items-center gap-1">
                    <TrendingDown className="w-3 h-3" />
                    {formatNumber(difference)} ({percentage}%)
                </div>
            );
        }
        return (
            <div className="text-xs text-gray-500">
                Không chênh lệch
            </div>
        );
    };

    // Define columns for DataTable
    const columns: Column<IGoldPrice>[] = useMemo(() => [
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
            key: 'server_name',
            title: 'Server',
            width: 200,
            sortable: true,
            render: (serverName: string, record: IGoldPrice) => (
                <div className="flex items-center gap-3">
                    {renderGoldIcon()}
                    <div>
                        <div className="font-semibold text-gray-900 flex items-center gap-1">
                            <Server className="w-4 h-4 text-blue-500" />
                            {serverName}
                        </div>
                        <div className="text-sm text-gray-500">
                            ID: {record.server_id}
                        </div>
                    </div>
                </div>
            )
        },
        {
            key: 'price',
            title: 'Giá bán',
            width: 140,
            align: 'right',
            sortable: true,
            render: (price: number) => (
                <div className="text-right">
                    <div className="font-semibold text-green-600 flex items-center justify-end gap-1">
                        <DollarSign className="w-3 h-3" />
                        {formatPrice(price)}
                    </div>
                </div>
            )
        },
        {
            key: 'import_price',
            title: 'Giá nhập',
            width: 140,
            align: 'right',
            sortable: true,
            render: (price: number) => (
                <div className="text-right">
                    <div className="font-semibold text-blue-600 flex items-center justify-end gap-1">
                        <DollarSign className="w-3 h-3" />
                        {formatPrice(price)}
                    </div>
                </div>
            )
        },
        {
            key: 'price_difference',
            title: 'Chênh lệch',
            width: 140,
            align: 'center',
            render: (_, record: IGoldPrice) => (
                <div className="text-center">
                    {renderPriceDifference(record.price, record.import_price)}
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
            {/* 🎯 DataTable cho Gold Prices */}
            <DataTable<IGoldPrice>
                data={prices.data}
                columns={columns}
                loading={loading}
                searchValue={filters.search}
                title="Quản lý giá vàng"
                description="Danh sách giá vàng theo từng server"
                pagination={{
                    current: prices.meta.current_page,
                    pageSize: prices.meta.per_page,
                    total: prices.meta.total,
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
            <GoldPriceModal
                open={modalOpen}
                onClose={handleCloseModal}
                goldPrice={selectedGoldPrice}
                servers={servers}
            />
        </>
    );
}

GoldPricePage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Gold Price Management" children={page} />
);
