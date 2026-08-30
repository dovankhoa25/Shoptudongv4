// Admin/CardTypes/Index.tsx - Card Types Management
import React, { useEffect, useMemo, useState } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { PageProps, PaginatedData } from "@/types";
import { Column, DataTable } from "@/Components/Table/DataTable";
import {
    Edit3, Trash2, Plus, Smartphone, Percent,
    CheckCircle, XCircle, Calendar
} from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import { useTableFilters } from "@/Hooks/useTableFilters";
import { formatDate } from '@/Utils/currencyHelper';
import CardTypeFormModal from './CardTypeFormModal';
import { ICardType } from '@/InterFaces/cardType';


export default function CardTypesPage() {
    const { card_types, filters: serverFilters, flash } = usePage<
        PageProps & {
            card_types: PaginatedData<ICardType>;
            filters: {
                status?: string;
            };
            flash: {
                success?: string;
                error?: string;
                info?: string;
            }
        }
    >().props;

    const toast = useToast();

    // Modal state
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingCardType, setEditingCardType] = useState<ICardType | null>(null);
    const [modalMode, setModalMode] = useState<'create' | 'edit'>('create');

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
        routeName: 'admin.cardtypes.index',
        initialFilters: serverFilters,
        initialData: card_types,
        debounceMs: 500,
    });

    // Flash messages
    useEffect(() => {
        if (flash?.success) {
            toast.success(flash.success);
        }
        if (flash?.error) {
            toast.error(flash.error);
        }
        if (flash?.info) {
            toast.info(flash.info);
        }
    }, [flash]);

    // Handlers
    const handleAdd = () => {
        setModalMode('create');
        setEditingCardType(null);
        setIsModalOpen(true);
    };

    const handleEdit = (cardType: ICardType) => {
        setModalMode('edit');
        setEditingCardType(cardType);
        setIsModalOpen(true);
    };

    const handleDelete = (cardType: ICardType) => {
        if (confirm(`Bạn có chắc chắn muốn xóa nhà mạng "${cardType.telco}"?`)) {
            router.delete(`/admin/cardtypes/${cardType.id}`, {
                onError: (errors) => {
                    toast.error('Xóa thất bại!');
                }
            });
        }
    };

    const handleCloseModal = () => {
        setIsModalOpen(false);
        setEditingCardType(null);
    };

    const handleFormSubmit = (data: any) => {
        if (modalMode === 'create') {
            router.post('/admin/cardtypes', data, {
                onSuccess: () => {
                    handleCloseModal();
                },
                onError: (errors) => {
                    toast.error('Tạo mới thất bại!');
                }
            });
        } else if (editingCardType) {
            router.put(`/admin/cardtypes/${editingCardType.id}`, data, {
                onSuccess: () => {
                    handleCloseModal();
                },
                onError: (errors) => {
                    toast.error('Cập nhật thất bại!');
                }
            });
        }
    };

    const renderTelcoInfo = (cardType: ICardType) => {
        // Map nhà mạng để hiển thị đẹp hơn
        const telcoConfig: Record<string, { name: string; color: string; bgColor: string }> = {
            'viettel': { name: 'Viettel', color: 'text-red-700', bgColor: 'bg-red-100' },
            'mobifone': { name: 'MobiFone', color: 'text-blue-700', bgColor: 'bg-blue-100' },
            'vinaphone': { name: 'VinaPhone', color: 'text-purple-700', bgColor: 'bg-purple-100' },
            'vietnamobile': { name: 'Vietnamobile', color: 'text-yellow-700', bgColor: 'bg-yellow-100' },
            'gmobile': { name: 'Gmobile', color: 'text-green-700', bgColor: 'bg-green-100' },
            'zing': { name: 'Zing', color: 'text-orange-700', bgColor: 'bg-orange-100' },
            'gate': { name: 'Gate', color: 'text-gray-700', bgColor: 'bg-gray-100' },
        };

        const config = telcoConfig[cardType.telco.toLowerCase()] || {
            name: cardType.telco,
            color: 'text-gray-700',
            bgColor: 'bg-gray-100'
        };

        return (
            <div className="flex items-center gap-3">
                <div className={`w-10 h-10 rounded-lg ${config.bgColor} flex items-center justify-center`}>
                    <Smartphone className={`w-5 h-5 ${config.color}`} />
                </div>
                <div>
                    <div className="font-semibold text-gray-900 dark:text-white">
                        {config.name}
                    </div>
                    <div className="text-sm text-gray-500">
                        Code: {cardType.telco}
                    </div>
                </div>
            </div>
        );
    };

    const renderDiscountRate = (rate: number) => (
        <div className="flex items-center gap-2">
            <Percent className="w-4 h-4 text-blue-500" />
            <span className="font-medium text-blue-600">
                {rate}%
            </span>
        </div>
    );

    const renderStatus = (status: boolean) => {
        return status ? (
            <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                <CheckCircle className="w-3 h-3 mr-1" />
                Hoạt động
            </span>
        ) : (
            <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                <XCircle className="w-3 h-3 mr-1" />
                Tạm dừng
            </span>
        );
    };

    // Define columns for DataTable
    const columns: Column<ICardType>[] = useMemo(() => [
        {
            key: 'id',
            title: 'ID',
            width: 60,
            align: 'center',
            render: (value: any) => (
                <span className="font-mono  text-gray-600 dark:text-gray-300 bg-gray-500 px-2 py-1 rounded text-sm">
                    #{value}
                </span>
            )
        },
        {
            key: 'telco_info',
            title: 'Nhà mạng',
            width: 200,
            render: (_, record: ICardType) => renderTelcoInfo(record)
        },
        {
            key: 'discount_rate',
            title: 'Chiết khấu',
            width: 120,
            align: 'center',
            render: (rate: number) => renderDiscountRate(rate)
        },
        {
            key: 'status',
            title: 'Trạng thái',
            width: 120,
            align: 'center',
            filters: [
                { text: '✅ Hoạt động', value: '1' },
                { text: '❌ Tạm dừng', value: '0' }
            ],
            render: (status: boolean) => renderStatus(status)
        },
        {
            key: 'created_at',
            title: 'Ngày tạo',
            sortable: true,
            width: 140,
            render: (value: string) => (
                <div className="text-sm ">
                    <div className="flex items-center gap-1">
                        <Calendar className="w-3 h-3" />
                        {formatDate(value)}
                    </div>
                </div>
            )
        }
    ], []);

    return (
        <>
            {/* 🎯 DataTable cho Card Types */}
            <DataTable<ICardType>
                data={card_types.data}
                columns={columns}
                loading={loading}
                searchValue={filters.search}
                title="Quản lý loại thẻ"
                description="Cấu hình các nhà mạng và mức chiết khấu"
                pagination={{
                    current: card_types.meta.current_page,
                    pageSize: card_types.meta.per_page,
                    total: card_types.meta.total,
                    showSizeChanger: true,
                    pageSizeOptions: ['10', '20', '50', '100'],
                    onChange: handlePageChange,
                }}
                onFiltersChange={setColumnFilters}
                onSearch={handleSearch}
                onAdd={handleAdd}
                onReset={handleResetFilters}
                onEdit={handleEdit}
                onDelete={handleDelete}
                customActions={{
                    edit: {
                        label: 'Chỉnh sửa',
                        icon: Edit3,
                        handler: handleEdit,
                        className: 'text-blue-600 hover:text-blue-800'
                    },
                    delete: {
                        label: 'Xóa',
                        icon: Trash2,
                        handler: handleDelete,
                        className: 'text-red-600 hover:text-red-800'
                    }
                }}
            // searchPlaceholder="Tìm kiếm theo tên nhà mạng..."
            // addButtonText="Thêm nhà mạng"
            // emptyText="Chưa có nhà mạng nào"
            // emptyDescription="Hãy thêm nhà mạng đầu tiên để bắt đầu"
            />

            {/* Modal thêm/sửa Card Type */}
            <CardTypeFormModal
                isOpen={isModalOpen}
                onClose={handleCloseModal}
                onSubmit={handleFormSubmit}
                cardType={editingCardType}
                mode={modalMode}
            />
        </>
    );
}

CardTypesPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Card Types Management" children={page} />
);