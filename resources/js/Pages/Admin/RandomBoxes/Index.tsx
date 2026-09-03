// Admin/RandomBoxes/Index.tsx - RandomBox Management
import React, { useState, useMemo } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { IRandomBox } from "@/InterFaces/randombox";
import { ICategory } from "@/InterFaces/category";
import { PageProps, PaginatedData } from "@/types";
import RandomBoxModal from "./RandomBoxModal";
import { Column, DataTable } from "@/Components/Table/DataTable";
import { Package, Settings, Star, TrendingUp, Image, DollarSign, Users } from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import { useTableFilters } from "@/Hooks/useTableFilters";
import { Badge } from 'antd';

export default function RandomBoxPage() {
    const { randomBoxes, categories, filters: serverFilters } = usePage<
        PageProps & {
            randomBoxes: PaginatedData<IRandomBox>;
            categories: ICategory[];
            filters: {
                search?: string;
                category_id?: string;
                is_public?: string;
            };
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
        routeName: 'admin.randombox.index',
        initialFilters: serverFilters,
        initialData: randomBoxes,
        debounceMs: 500,
    });

    // Modal states
    const [showModal, setShowModal] = useState(false);
    const [selectedRandomBox, setSelectedRandomBox] = useState<IRandomBox | null>(null);

    // Modal handlers
    const handleOpenModal = () => {
        setSelectedRandomBox(null);
        setShowModal(true);
    };

    const handleCloseModal = () => {
        setShowModal(false);
        setSelectedRandomBox(null);
    };

    const handleEdit = (randomBox: IRandomBox) => {
        setSelectedRandomBox(randomBox);
        setShowModal(true);
    };

    const handleManageNicks = (randomBox: IRandomBox) => {
        router.visit(`/admin/random-nicks?random_box_id=${randomBox.id}`);
    };

    const handleDelete = (randomBox: IRandomBox) => {
        if (confirm(`Bạn có chắc chắn muốn ẩn hộp random "${randomBox.name}"?`)) {
            router.delete(`/admin/randombox/${randomBox.id}`, {
                onSuccess: () => {
                    toast.success(`Hộp random "${randomBox.name}" đã được ẩn thành công!`);
                },
                onError: () => {
                    toast.error('Ẩn hộp random thất bại. Vui lòng thử lại!');
                }
            });
        }
    };

    const handleRestore = (randomBox: IRandomBox) => {
        router.patch(`/admin/randombox/${randomBox.id}/restore`, {}, {
            onSuccess: () => {
                toast.success(`Hộp random "${randomBox.name}" đã được khôi phục thành công!`);
            },
            onError: () => {
                toast.error('Khôi phục hộp random thất bại. Vui lòng thử lại!');
            }
        });
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
        return new Intl.NumberFormat('vi-VN').format(price) + 'đ';
    };

    // Define columns for DataTable
    const columns: Column<IRandomBox>[] = useMemo(() => [
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
            title: 'Tên hộp random',
            width: 280,
            sortable: true,
            render: (name: string, record: IRandomBox) => (
                <div className="flex items-center gap-3">
                    <div className="w-12 h-12 bg-gradient-to-br from-purple-100 to-pink-100 rounded-lg flex items-center justify-center overflow-hidden">
                        {record.image_url ? (
                            <img
                                src={record.image_url}
                                alt={name}
                                className="w-full h-full object-cover"
                            />
                        ) : (
                            <Package className="w-6 h-6 text-purple-500" />
                        )}
                    </div>
                    <div>
                        <div className="font-semibold ">{name}</div>
                        <div className="text-sm ">
                            {record.category?.name || 'Chưa phân loại'}
                        </div>
                    </div>
                </div>
            )
        },
        {
            key: 'category',
            title: 'Danh mục',
            width: 150,
            render: (category: any) => (
                <div className="text-sm">
                    {category ? (
                        <span className="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs font-medium">
                            {category.name}
                        </span>
                    ) : (
                        <span className="text-gray-400 italic">Chưa phân loại</span>
                    )}
                </div>
            )
        },
        {
            key: 'price',
            title: 'Giá',
            width: 120,
            align: 'right',
            sortable: true,
            render: (price: number) => (
                <div className="flex items-center justify-end gap-1">
                    <DollarSign className="w-4 h-4 text-green-500" />
                    <span className="font-semibold text-green-700">
                        {formatPrice(price)}
                    </span>
                </div>
            )
        },
        {
            key: 'total_nicks',
            title: 'Tổng nick',
            width: 100,
            align: 'center',
            render: (value: number = 0) => (
                <div className="bg-indigo-100 text-indigo-800 text-center inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium">
                    <Star className="w-3 h-3 mr-1" />
                    {value}
                </div>
            )
        },
        {
            key: 'available_nicks',
            title: 'Còn lại',
            width: 100,
            align: 'center',
            render: (value: number = 0, record: IRandomBox) => {
                const isLow = value <= 5;
                const isEmpty = value === 0;

                return (
                    <div className={`text-center inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${isEmpty
                        ? 'bg-red-100 text-red-800'
                        : isLow
                            ? 'bg-yellow-100 text-yellow-800'
                            : 'bg-green-100 text-green-800'
                        }`}>
                        <Star className="w-3 h-3 mr-1" />
                        {value}
                    </div>
                );
            }
        },
        {
            key: 'sort_order',
            title: 'Thứ tự',
            width: 100,
            align: 'center',
            sortable: true,
            render: (sortOrder: number) => (
                <div className="flex items-center justify-center">
                    <span className="bg-indigo-100 text-indigo-800 text-xs font-medium px-2.5 py-1 rounded-full flex items-center gap-1">
                        <TrendingUp className="w-3 h-3" />
                        {sortOrder}
                    </span>
                </div>
            )
        },
        {
            key: 'is_public',
            title: 'Trạng thái',
            width: 120,
            align: 'center',
            sortable: true,
            render: (isPublic: boolean) => {
                const config = isPublic ? {
                    color: 'bg-green-100 text-green-800',
                    text: 'Công khai',
                    dot: 'bg-green-500'
                } : {
                    color: 'bg-red-100 text-red-800',
                    text: 'Đã ẩn',
                    dot: 'bg-red-500'
                };

                return (
                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${config.color}`}>
                        <span className={`w-2 h-2 rounded-full mr-1.5 ${config.dot}`}></span>
                        {config.text}
                    </span>
                );
            }
        },
        {
            key: 'created_at',
            title: 'Ngày tạo',
            sortable: true,
            visible: false,
            width: 150,
            render: (value: string) => (
                <span className="text-sm text-gray-600">{formatDate(value)}</span>
            )
        }
    ], []);

    return (
        <>
            {/* 🎯 DataTable cho RandomBoxes */}
            <DataTable<IRandomBox>
                data={randomBoxes.data}
                columns={columns}
                loading={loading}
                searchValue={filters.search}
                searchPreset="randomBoxes"
                title="Quản lý hộp random"
                description="Danh sách tất cả các hộp random trong hệ thống"
                pagination={{
                    current: randomBoxes.meta.current_page,
                    pageSize: randomBoxes.meta.per_page,
                    total: randomBoxes.meta.total,
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
                    manageNicks: {
                        label: 'Quản lý nick',
                        icon: Users, // import { Users } from 'lucide-react'
                        handler: handleManageNicks,
                        className: 'text-purple-600 hover:text-purple-800'
                    },
                    restore: {
                        label: 'Khôi phục',
                        icon: Package,
                        handler: handleRestore,
                        className: 'text-purple-600 hover:text-purple-800',
                        condition: (record: IRandomBox) => !record.is_public
                    }

                }}
                searchPlaceholder="Tìm kiếm theo tên hộp random..."
                addButtonText="Thêm hộp mới"
                emptyText="Chưa có hộp random nào"
                emptyDescription="Hãy thêm hộp random đầu tiên để bắt đầu bán nick random"

            />

            {/* Modals */}
            {showModal && (
                <RandomBoxModal
                    onClose={handleCloseModal}
                    randomBox={selectedRandomBox}
                    categories={categories}
                />
            )}
        </>
    );
}

RandomBoxPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Random Box Management" children={page} />
);
