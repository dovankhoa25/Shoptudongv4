// Admin/Cards/Index.tsx - Trang quản lý danh sách card
import React, { useState, useMemo } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { PageProps, PaginatedData } from "@/types";
import axios from "axios";
import { formatCurrency } from "@/Utils/currencyHelper";
import { Column, DataTable } from "@/Components/Table/DataTable";
import { CreditCard, Edit, Trash2, CheckCircle, XCircle, Clock, AlertTriangle } from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import { useTableFilters } from "@/Hooks/useTableFilters";
import { ICard } from '@/InterFaces/card';



export default function CardPage() {
    const pageProps = usePage<
        PageProps & {
            cards?: PaginatedData<ICard>;
            filters?: {
                search?: string;
                status?: string;
                card_type?: string;
                loaded_type?: string;
            };
        }
    >().props;

    const cards = pageProps.cards || pageProps.cards || {
        data: [],
        meta: {
            current_page: 1,
            per_page: 10,
            total: 0,
            last_page: 1,
        },
        links: []
    };


    const serverFilters = pageProps.filters || {};
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
        routeName: 'admin.cards.index',
        initialFilters: serverFilters,
        initialData: cards,
        debounceMs: 500,
    });

    // Modal states
    const [showModal, setShowModal] = useState(false);
    const [selectedCard, setSelectedCard] = useState<ICard | null>(null);

    // Modal handlers
    const handleOpenModal = () => {
        setSelectedCard(null);
        setShowModal(true);
    };

    const handleCloseModal = () => {
        setShowModal(false);
        setSelectedCard(null);
    };

    const handleEdit = (card: ICard) => {
        setSelectedCard(card);
        setShowModal(true);
    };

    const handleDelete = async (card: ICard) => {
        if (!confirm('Bạn có chắc chắn muốn xóa card này?')) return;

        try {
            await axios.delete(`/admin/cards/${card.id}`);
            toast.success("Đã xóa card thành công");
            router.reload({ only: ['cards'] });
        } catch {
            toast.error("Không thể xóa card");
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

    // Status configuration
    const getStatusConfig = (status: string) => {
        const statusConfigs = {
            'pending': {
                color: 'bg-yellow-100 text-yellow-800',
                icon: Clock,
                text: '⏳ Chờ xử lý'
            },
            'completed': {
                color: 'bg-green-100 text-green-800',
                icon: CheckCircle,
                text: '✅ Thành công'
            },
            'failed': {
                color: 'bg-red-100 text-red-800',
                icon: XCircle,
                text: '❌ Thất bại'
            },
            'confirmed': {
                color: 'bg-blue-100 text-blue-800',
                icon: AlertTriangle,
                text: '🔄 Đang xử lý'
            },
        };
        return statusConfigs[status as keyof typeof statusConfigs] || statusConfigs.pending;
    };
    // Loaded type configuration
    const getLoadedTypeConfig = (type: string) => {
        const typeConfigs = {
            'manual': { color: 'bg-purple-100 text-purple-800', text: '✋ Thủ công' },
            'auto': { color: 'bg-blue-100 text-blue-800', text: '🤖 Tự động' },
            'api': { color: 'bg-indigo-100 text-indigo-800', text: '🔗 API' },
        };
        return typeConfigs[type as keyof typeof typeConfigs] || { color: 'bg-gray-100 text-gray-800', text: type };
    };

    // Define columns for DataTable
    const columns: Column<ICard>[] = useMemo(() => [
        {
            key: 'id',
            title: 'ID',
            width: 60,
            align: 'center',
            render: (value: any) => (
                <span className="font-mono  bg-gray-100 px-2 py-1 rounded text-sm">
                    #{value}
                </span>
            )
        },
        {
            key: 'code',
            title: 'Thông tin Card',
            width: 200,
            sortable: true,
            render: (value: string, record: ICard) => (
                <div className="space-y-1">
                    <div className="flex items-center gap-2">
                        <CreditCard className="w-4 h-4 text-blue-500" />
                        <span className="font-medium text-gray-900">{value}</span>
                    </div>
                    <div className="text-sm ">Serial: {record.serial}</div>
                    <div className="text-xs  font-medium">{record.card_type.name}</div>
                </div>
            )
        },
        {
            key: 'user',
            title: 'Người dùng',
            width: 150,
            render: (value: ICard['user']) => (
                <div className="text-sm">
                    <div className="font-medium text-gray-900">{value.name}</div>
                    <div className="">ID: {value.id}</div>
                </div>
            )
        },
        {
            key: 'declared_value',
            title: 'Mệnh giá',
            width: 120,
            sortable: true,
            render: (value: string) => (
                <div className="text-right">
                    <div className="font-medium ">
                        {formatCurrency(value)}
                    </div>
                </div>
            )
        },
        {
            key: 'value',
            title: 'Giá trị thực',
            width: 120,
            sortable: true,
            render: (value: string) => (
                <div className="text-right">
                    <div className="font-medium ">
                        {formatCurrency(value)}
                    </div>
                </div>
            )
        },
        {
            key: 'amount_user',
            title: 'Tiền user nhận',
            width: 130,
            render: (value: string) => (
                <div className="bg-green-100 text-green-800 text-center inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium">
                    {formatCurrency(value)}
                </div>
            )
        },
        {
            key: 'discount_rate_at_time',
            title: 'Tỷ lệ chiết khấu',
            width: 100,
            align: 'center',
            render: (value: string) => (
                <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-800">
                    {parseFloat(value).toFixed(1)}%
                </span>
            )
        },
        {
            key: 'status',
            title: 'Trạng thái',
            width: 130,
            filters: [
                { text: '⏳ Chờ xử lý', value: 'pending' },
                { text: '✅ Thành công', value: 'completed' },
                { text: '❌ Thất bại', value: 'failed' },
                { text: '🔄 Đang xử lý', value: 'confirmed' }
            ],
            render: (value: string) => {
                const config = getStatusConfig(value);
                return (
                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${config.color}`}>
                        {config.text}
                    </span>
                );
            }
        },
        {
            key: 'loaded_type',
            title: 'Loại nạp',
            width: 100,
            filters: [
                { text: '✋ Thủ công', value: 'manual' },
                { text: '🤖 Tự động', value: 'auto' },
                { text: '🔗 API', value: 'api' }
            ],
            render: (value: string) => {
                const config = getLoadedTypeConfig(value);
                return (
                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${config.color}`}>
                        {config.text}
                    </span>
                );
            }
        },
        {
            key: 'note',
            title: 'Ghi chú',
            width: 150,
            visible: false,
            render: (value: string) => (
                <div className="text-sm  max-w-xs truncate">
                    {value || 'Không có'}
                </div>
            )
        },
        {
            key: 'created_at',
            title: 'Thời gian tạo',
            sortable: true,
            width: 150,
            render: (value: string) => (
                <span className="text-sm text-gray-600">{formatDate(value)}</span>
            )
        }
    ], []);

    return (
        <>
            {/* 🎯 DataTable cho cards */}
            <DataTable<ICard>
                data={cards.data}
                columns={columns}
                loading={loading}
                searchValue={filters.search}
                searchPreset="cards"
                title="Quản lý danh sách Card"
                description="Danh sách tất cả các card trong hệ thống"
                pagination={{
                    current: cards.meta.current_page,
                    pageSize: cards.meta.per_page,
                    total: cards.meta.total,
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
                    delete: {
                        label: 'Xóa card',
                        icon: Trash2,
                        handler: handleDelete,
                        className: '!text-red-600',
                        condition: (card) => card.status !== 'success' // Chỉ cho phép xóa card chưa thành công
                    },
                }}
            />

        </>
    );
}

CardPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Card Management" children={page} />
);
