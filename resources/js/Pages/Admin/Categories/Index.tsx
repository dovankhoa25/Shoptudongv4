// Admin/Categories/Index.tsx - Category Management
import React, { useState, useMemo } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { ICategory } from "@/InterFaces/category";
import { PageProps, PaginatedData } from "@/types";
import CategoryModal from "./CategoryModal";
import { Column, DataTable } from "@/Components/Table/DataTable";
import {
    FolderOpen, Settings, TrendingUp, Globe,
    Lock, Gamepad2, Image as ImageIcon,
    CheckCircle, XCircle
} from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import { useTableFilters } from "@/Hooks/useTableFilters";

export default function CategoryPage() {
    const { categories, filters: serverFilters } = usePage<
        PageProps & {
            categories: PaginatedData<ICategory>;
            filters: { search?: string; status?: string };
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
        routeName: 'admin.games.categories.index',
        initialFilters: serverFilters,
        initialData: categories,
        debounceMs: 500,
    });

    // Modal states
    const [showModal, setShowModal] = useState(false);
    const [selectedCategory, setSelectedCategory] = useState<ICategory | null>(null);

    // Modal handlers
    const handleOpenModal = () => {
        setSelectedCategory(null);
        setShowModal(true);
    };

    const handleCloseModal = () => {
        setShowModal(false);
        setSelectedCategory(null);
    };

    const handleEdit = (category: ICategory) => {
        setSelectedCategory(category);
        setShowModal(true);
    };

    const handleDelete = (category: ICategory) => {
        if (confirm(`Bạn có chắc chắn muốn xóa danh mục "${category.name}"?`)) {
            router.delete(`/admin/categories/${category.id}`, {
                onSuccess: () => {
                    toast.success(`Danh mục "${category.name}" đã được xóa thành công!`);
                },
                onError: () => {
                    toast.error('Xóa danh mục thất bại. Vui lòng thử lại!');
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

    const renderCategoryImage = (imageUrl: string, categoryName: string) => {
        if (!imageUrl) {
            return (
                <div className="w-10 h-10 bg-gradient-to-br from-blue-100 to-purple-100 rounded-lg flex items-center justify-center">
                    <FolderOpen className="w-5 h-5 text-blue-600" />
                </div>
            );
        }

        return (
            <div className="w-10 h-10 rounded-lg overflow-hidden border border-gray-200">
                <img
                    src={imageUrl}
                    alt={categoryName}
                    className="w-full h-full object-cover"
                    onError={(e) => {
                        e.currentTarget.style.display = 'none';
                        e.currentTarget.parentElement!.innerHTML = `
                            <div class="w-full h-full bg-gray-100 flex items-center justify-center">
                                <svg class="w-5 h-5 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm12 12H4l4-8 3 6 2-4 3 6z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        `;
                    }}
                />
            </div>
        );
    };

    // Define columns for DataTable
    const columns: Column<ICategory>[] = useMemo(() => [
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
            title: 'Tên danh mục',
            width: 280,
            sortable: true,
            render: (name: string, record: ICategory) => (
                <div className="flex items-center gap-3">
                    {renderCategoryImage(record.image_url ?? '', name)}
                    <div>
                        <div className="font-semibold ">{name}</div>
                        <div className="text-sm  flex items-center gap-1">
                            <span>/{record.slug}</span>
                            {record.game_type && (
                                <>
                                    <span className="text-gray-300">•</span>
                                    <Gamepad2 className="w-3 h-3" />
                                    <span>{record.game_type.name}</span>
                                </>
                            )}
                        </div>
                    </div>
                </div>
            )
        },
        {
            key: 'template',
            title: 'Template',
            width: 120,
            filters: [
                { text: '🎮 Default', value: 'default' },
                { text: '📱 Mobile', value: 'mobile' },
                { text: '🖥️ PC', value: 'pc' },
                { text: '🎯 Arcade', value: 'arcade' },
                { text: '🎲 Casino', value: 'casino' },
                { text: '⚽ Sports', value: 'sports' }
            ],
            render: (template: string) => {
                const templateConfig = {
                    default: { icon: '🎮', color: 'bg-blue-100 text-blue-800' },
                    mobile: { icon: '📱', color: 'bg-green-100 text-green-800' },
                    pc: { icon: '🖥️', color: 'bg-purple-100 text-purple-800' },
                    arcade: { icon: '🎯', color: 'bg-orange-100 text-orange-800' },
                    casino: { icon: '🎲', color: 'bg-red-100 text-red-800' },
                    sports: { icon: '⚽', color: 'bg-emerald-100 text-emerald-800' }
                };
                const config = templateConfig[template as keyof typeof templateConfig] || templateConfig.default;

                return (
                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${config.color}`}>
                        {config.icon} {template}
                    </span>
                );
            }
        },
        {
            key: 'status',
            title: 'Trạng thái',
            width: 120,
            align: 'center',
            filters: [
                { text: '✅ Hoạt động', value: 1 },
                { text: '⏸️ Tạm dừng', value: 0 },
                // { text: '🚧 Bảo trì', value: 'maintenance' }
            ],
            render: (status: number) => {
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
                };

                const config = statusConfig[status as 0 | 1] || statusConfig[1];

                return (
                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${config.color}`}>
                        <span className={`w-2 h-2 rounded-full mr-1.5 ${config.dot}`}></span>
                        {config.text}
                    </span>
                );
            },

        },
        {
            key: 'is_public',
            title: 'Hiển thị',
            width: 100,
            visible: false,
            align: 'center',
            filters: [
                { text: '🌐 Công khai', value: true },
                { text: '🔒 Riêng tư', value: false }
            ],
            render: (isPublic: boolean) => (
                <div className="flex items-center justify-center">
                    {isPublic ? (
                        <span className="bg-blue-100 text-blue-800 inline-flex items-center px-2 py-1 rounded-full text-xs font-medium">
                            <Globe className="w-3 h-3 mr-1" />
                            Công khai
                        </span>
                    ) : (
                        <span className="bg-gray-100 text-gray-800 inline-flex items-center px-2 py-1 rounded-full text-xs font-medium">
                            <Lock className="w-3 h-3 mr-1" />
                            Riêng tư
                        </span>
                    )}
                </div>
            )
        },
        {
            key: 'sort_order',
            title: 'Thứ tự',
            width: 90,
            align: 'center',
            sortable: true,
            render: (sortOrder: number) => (
                <div className="flex items-center justify-center">
                    <span className="bg-indigo-100 text-indigo-800 text-xs font-medium px-2 py-1 rounded-full flex items-center gap-1">
                        <TrendingUp className="w-3 h-3" />
                        {sortOrder}
                    </span>
                </div>
            )
        },
        {
            key: 'games_count',
            title: 'Games',
            width: 80,
            align: 'center',
            render: (value: number = 0) => (
                <div className="bg-emerald-100 text-emerald-800 text-center inline-flex items-center px-2 py-1 rounded-full text-xs font-medium">
                    {value}
                </div>
            )
        },
        {
            key: 'created_at',
            title: 'Ngày tạo',
            sortable: true,
            visible: false,
            width: 140,
            render: (value: string) => (
                <span className="text-sm text-gray-600">{formatDate(value)}</span>
            )
        }
    ], []);

    return (
        <>
            {/* 🎯 DataTable cho Categories */}
            <DataTable<ICategory>
                data={categories.data}
                columns={columns}
                loading={loading}
                searchValue={filters.search}
                searchPreset="categories"
                title="Quản lý danh mục"
                description="Danh sách tất cả các danh mục game trong hệ thống"
                pagination={{
                    current: categories.meta.current_page,
                    pageSize: categories.meta.per_page,
                    total: categories.meta.total,
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
            // searchPlaceholder="Tìm kiếm theo tên danh mục..."
            // addButtonText="Thêm danh mục mới"
            // emptyText="Chưa có danh mục nào"
            // emptyDescription="Hãy thêm danh mục đầu tiên để bắt đầu phân loại các game"
            />

            {/* Modals */}
            {showModal && (
                <CategoryModal
                    onClose={handleCloseModal}
                    category={selectedCategory}
                />
            )}
        </>
    );
}

CategoryPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Category Management" children={page} />
);
