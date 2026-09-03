// Admin/GameTypes/Index.tsx - GameType Management
import React, { useState, useMemo } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { IGameType } from "@/InterFaces/gametype";
import { PageProps, PaginatedData } from "@/types";
import GameTypeModal from "./GameTypeModal";
import { Column, DataTable } from "@/Components/Table/DataTable";
import { Gamepad2, Settings, Star, TrendingUp } from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import { useTableFilters } from "@/Hooks/useTableFilters";

export default function GameTypePage() {
    const { gameTypes, filters: serverFilters } = usePage<
        PageProps & {
            gameTypes: PaginatedData<IGameType>;
            filters: { search?: string };
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
        routeName: 'admin.games.gametypes.index',
        initialFilters: serverFilters,
        initialData: gameTypes,
        debounceMs: 500,
    });

    // Modal states
    const [showModal, setShowModal] = useState(false);
    const [selectedGameType, setSelectedGameType] = useState<IGameType | null>(null);

    // Modal handlers
    const handleOpenModal = () => {
        setSelectedGameType(null);
        setShowModal(true);
    };

    const handleCloseModal = () => {
        setShowModal(false);
        setSelectedGameType(null);
    };

    const handleEdit = (gameType: IGameType) => {
        setSelectedGameType(gameType);
        setShowModal(true);
    };

    const handleDelete = (gameType: IGameType) => {
        if (confirm(`Bạn có chắc chắn muốn xóa loại game "${gameType.name}"?`)) {
            router.delete(`/admin/game-types/${gameType.id}`, {
                onSuccess: () => {
                    toast.success(`Loại game "${gameType.name}" đã được xóa thành công!`);
                },
                onError: () => {
                    toast.error('Xóa loại game thất bại. Vui lòng thử lại!');
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

    const renderIcon = (iconClass: string) => {
        if (!iconClass) {
            return <Gamepad2 className="w-4 h-4 text-gray-400" />;
        }

        // Check if it's a FontAwesome class
        if (iconClass.includes('fa-')) {
            return <i className={iconClass} />;
        }

        // Default fallback
        return <Gamepad2 className="w-4 h-4 text-blue-500" />;
    };

    // Define columns for DataTable
    const columns: Column<IGameType>[] = useMemo(() => [
        {
            key: 'id',
            title: 'ID',
            width: 60,
            align: 'center',
            visible: false,
            render: (value: any) => (
                <span className="font-mono text-gray-600 dark:text-gray-300 bg-gray-100  px-2 py-1 rounded text-sm">
                    #{value}
                </span>
            )
        },

        {
            key: 'name',
            title: 'Tên loại game',
            width: 250,
            sortable: true,
            render: (name: string, record: IGameType) => (
                <div className="flex items-center gap-3">
                    <div className="w-10 h-10 bg-gradient-to-br from-blue-100 to-purple-100 
                    dark:from-blue-900 dark:to-purple-900 
                    rounded-lg flex items-center justify-center">
                        {renderIcon(record.icon)}
                    </div>

                    <div>
                        <div className="font-semibold text-gray-900 dark:text-gray-100">
                            {name}
                        </div>
                        <div className="text-sm text-gray-500 dark:text-gray-400">
                            /{record.slug}
                        </div>
                    </div>
                </div>

            )
        },
        {
            key: 'slug',
            title: 'Slug',
            width: 180,
            render: (slug: string) => (
                <div className="font-mono text-sm bg-gray-50 border border-gray-200 rounded px-2 py-1 text-gray-700">
                    /{slug}
                </div>
            )
        },
        {
            key: 'icon',
            title: 'Icon',
            width: 120,
            align: 'center',
            render: (iconClass: string) => (
                <div className="flex items-center justify-center gap-2">
                    {renderIcon(iconClass)}
                    <span className="text-xs text-gray-500 font-mono">
                        {iconClass || 'default'}
                    </span>
                </div>
            )
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
            key: 'games_count',
            title: 'Số game',
            width: 100,
            align: 'center',
            render: (value: number = 0) => (
                <div className="bg-green-100 text-green-800 text-center inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium">
                    <Star className="w-3 h-3 mr-1" />
                    {value} game
                </div>
            )
        },
        {
            key: 'status',
            title: 'Trạng thái',
            width: 120,
            align: 'center',
            sortable: true,
            visible: false,

            render: (status: string = 'active') => {
                const statusConfig = {
                    active: {
                        color: 'bg-green-100 text-green-800',
                        text: 'Hoạt động',
                        dot: 'bg-green-500'
                    },
                    inactive: {
                        color: 'bg-red-100 text-red-800',
                        text: 'Tạm dừng',
                        dot: 'bg-red-500'
                    }
                };
                const config = statusConfig[status as keyof typeof statusConfig] || statusConfig.active;

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
            {/* 🎯 DataTable cho GameTypes */}
            <DataTable<IGameType>
                data={gameTypes.data}
                columns={columns}
                loading={loading}
                searchValue={filters.search}
                searchPreset="gameTypes"
                title="Quản lý loại game"
                description="Danh sách tất cả các loại game trong hệ thống"
                pagination={{
                    current: gameTypes.meta.current_page,
                    pageSize: gameTypes.meta.per_page,
                    total: gameTypes.meta.total,
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
            // searchPlaceholder="Tìm kiếm theo tên loại game..."
            // addButtonText="Thêm loại game mới"
            // emptyText="Chưa có loại game nào"
            // emptyDescription="Hãy thêm loại game đầu tiên để bắt đầu phân loại các game trong hệ thống"
            />

            {/* Modals */}
            {showModal && (
                <GameTypeModal
                    onClose={handleCloseModal}
                    gameType={selectedGameType}
                />
            )}
        </>
    );
}

GameTypePage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Game Type Management" children={page} />
);
