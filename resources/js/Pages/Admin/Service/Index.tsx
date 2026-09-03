// Admin/Service/Index.tsx - Service Management
import React, { useState, useMemo } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { IService } from "@/InterFaces/service";
import { PageProps, PaginatedData } from "@/types";
import ServiceModal from "./ServiceModal";
import { Column, DataTable } from "@/Components/Table/DataTable";
import {
    Settings, Wrench, CheckCircle, XCircle,
    Calendar, Clock, Hash, Tag, DollarSign
} from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import { useTableFilters } from "@/Hooks/useTableFilters";

export default function ServicePage() {
    const { services, filters: serverFilters } = usePage<
        PageProps & {
            services: PaginatedData<IService>;
            filters: { search?: string; status?: string; per_page?: number };
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
        routeName: 'admin.services.index',
        initialFilters: serverFilters,
        initialData: services,
        debounceMs: 500,
    });

    // Modal states
    const [modalOpen, setModalOpen] = useState(false);
    const [selectedService, setSelectedService] = useState<IService | null>(null);

    // Modal handlers
    const handleOpenModal = () => {
        setSelectedService(null);
        setModalOpen(true);
    };

    const handleCloseModal = () => {
        setModalOpen(false);
        setSelectedService(null);
    };

    const handleEdit = (service: IService) => {
        setSelectedService(service);
        setModalOpen(true);
    };

    const handleDelete = (service: IService) => {
        if (confirm(`Bạn có chắc chắn muốn xóa dịch vụ "${service.name}"?`)) {
            router.delete(`/admin/services/${service.id}`, {
                onSuccess: () => {
                    toast.success(`Dịch vụ "${service.name}" đã được xóa thành công!`);
                },
                onError: () => {
                    toast.error('Xóa dịch vụ thất bại. Vui lòng thử lại!');
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

    const formatPrice = (price: number | null): string => {
        if (!price || price === 0) return 'Miễn phí';
        return new Intl.NumberFormat('vi-VN', {
            style: 'currency',
            currency: 'VND'
        }).format(price);
    };

    const renderServiceIcon = (serviceName: string) => {
        return (
            <div className="w-10 h-10 bg-gradient-to-br from-green-100 to-blue-100 rounded-lg flex items-center justify-center">
                <Wrench className="w-5 h-5 text-green-600" />
            </div>
        );
    };

    const renderCategories = (categories: any[]) => {
        if (!categories || categories.length === 0) {
            return (
                <span className="text-gray-400 text-sm">Chưa có danh mục</span>
            );
        }

        return (
            <div className="flex flex-wrap gap-1">
                {categories.slice(0, 2).map((category, index) => (
                    <span
                        key={index}
                        className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800"
                    >
                        <Tag className="w-3 h-3 mr-1" />
                        {category.name}
                    </span>
                ))}
                {categories.length > 2 && (
                    <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                        +{categories.length - 2}
                    </span>
                )}
            </div>
        );
    };

    // Define columns for DataTable
    const columns: Column<IService>[] = useMemo(() => [
        {
            key: 'id',
            title: 'ID',
            width: 60,
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
            title: 'Tên dịch vụ',
            width: 300,
            sortable: true,
            render: (name: string, record: IService) => (
                <div className="flex items-center gap-3">
                    {renderServiceIcon(name)}
                    <div>
                        <div className="font-semibold ">{name}</div>
                        <div className="text-sm ">
                            ID: {record.id}
                        </div>
                    </div>
                </div>
            )
        },
        {
            key: 'description',
            title: 'Mô tả',
            width: 300,
            render: (description: string) => (
                <div className="text-sm  max-w-xs">
                    {description ? (
                        <span title={description}>
                            {description.length > 20 ? `${description.substring(0, 20)}...` : description}
                        </span>
                    ) : (
                        <span className="text-gray-400 italic">Chưa có mô tả</span>
                    )}
                </div>
            )
        },
        {
            key: 'categories',
            title: 'Danh mục',
            width: 200,
            render: (categories: any[]) => renderCategories(categories)
        },
        {
            key: 'default_price',
            title: 'Giá mặc định',
            width: 140,
            align: 'right',
            sortable: true,
            render: (price: number) => (
                <div className="text-right">
                    {price && price > 0 ? (
                        <span className="font-semibold text-green-600 flex items-center justify-end gap-1">
                            <DollarSign className="w-3 h-3" />
                            {formatPrice(price)}
                        </span>
                    ) : (
                        <span className="text-gray-400 bg-gray-100 px-2 py-1 rounded-full text-xs">
                            Miễn phí
                        </span>
                    )}
                </div>
            )
        },
        {
            key: 'original_price',
            title: 'Giá gốc',
            width: 140,
            align: 'right',
            visible: false,
            sortable: true,
            render: (price: number) => (
                <div className="text-right">
                    {price && price > 0 ? (
                        <span className="text-gray-500 line-through flex items-center justify-end gap-1">
                            <DollarSign className="w-3 h-3" />
                            {formatPrice(price)}
                        </span>
                    ) : (
                        <span className="text-gray-400 text-xs">-</span>
                    )}
                </div>
            )
        },
        {
            key: 'is_popular',
            title: 'Phổ biến',
            width: 100,
            align: 'center',
            visible: false,
            filters: [
                { text: '⭐ Phổ biến', value: true },
                { text: '➖ Bình thường', value: false }
            ],
            render: (isPopular: boolean) => (
                <div className="flex items-center justify-center">
                    {isPopular ? (
                        <span className="bg-yellow-100 text-yellow-800 inline-flex items-center px-2 py-1 rounded-full text-xs font-medium">
                            ⭐ Phổ biến
                        </span>
                    ) : (
                        <span className="bg-gray-100 text-gray-600 inline-flex items-center px-2 py-1 rounded-full text-xs font-medium">
                            ➖ Bình thường
                        </span>
                    )}
                </div>
            )
        },
        {
            key: 'processing_time',
            title: 'Thời gian xử lý',
            width: 140,
            visible: false,
            render: (time: string) => (
                <div className="text-sm text-gray-600">
                    {time ? (
                        <span className="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs">
                            {time}
                        </span>
                    ) : (
                        <span className="text-gray-400">-</span>
                    )}
                </div>
            )
        },
        {
            key: 'warranty',
            title: 'Bảo hành',
            width: 120,
            visible: false,
            render: (warranty: string) => (
                <div className="text-sm text-gray-600">
                    {warranty ? (
                        <span className="bg-green-100 text-green-800 px-2 py-1 rounded-full text-xs">
                            {warranty}
                        </span>
                    ) : (
                        <span className="text-gray-400">-</span>
                    )}
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
            {/* 🎯 DataTable cho Services */}
            <DataTable<IService>
                data={services.data}
                columns={columns}
                loading={loading}
                searchValue={filters.search}
                searchPreset="services"
                title="Quản lý dịch vụ"
                description="Danh sách tất cả các dịch vụ trong hệ thống"
                pagination={{
                    current: services.meta.current_page,
                    pageSize: services.meta.per_page,
                    total: services.meta.total,
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
            <ServiceModal
                open={modalOpen}
                onClose={handleCloseModal}
                service={selectedService}
            />
        </>
    );
}

ServicePage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Service Management" children={page} />
);
