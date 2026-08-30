// Admin/RandomNicks/Index.tsx - RandomNick Management
import React, { useState, useMemo } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { IRandomNick } from "@/InterFaces/randomnick";
import { IRandomBox } from "@/InterFaces/randombox";
import { PageProps, PaginatedData } from "@/types";
import RandomNickModal from "./RandomNickModal";
import BulkAddModal from "./BulkAddModal";
import { Column, DataTable } from "@/Components/Table/DataTable";
import { User, Settings, Star, TrendingUp, Image, Package, Upload, Users } from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import { useTableFilters } from "@/Hooks/useTableFilters";

interface RandomNickFilters {
    search?: string;
    status?: string;
    random_box_id?: string;
}

interface RandomNickPageProps extends PageProps {
    randomNicks: PaginatedData<IRandomNick>;
    randomBoxes: IRandomBox[];
    filters: RandomNickFilters;
    stats: {
        total: number;
        available: number;
        taken: number;
    };
}

export default function RandomNickPage() {
    const { randomNicks, randomBoxes, filters: serverFilters, stats } = usePage<RandomNickPageProps>().props;

    const toast = useToast();

    // 🎯 Table filters hook with proper typing
    const {
        filters,
        columnFilters,
        loading,
        handleSearch,
        handleResetFilters,
        handlePageChange,
        setColumnFilters,
    } = useTableFilters({
        routeName: 'admin.randomnicks.index',
        initialFilters: serverFilters || {},
        initialData: randomNicks,
        debounceMs: 500,
    });

    // Cast filters to our specific type
    const currentFilters = filters as RandomNickFilters;

    // Modal states
    const [showModal, setShowModal] = useState(false);
    const [showBulkModal, setShowBulkModal] = useState(false);
    const [selectedNick, setSelectedNick] = useState<IRandomNick | null>(null);

    // Modal handlers
    const handleOpenModal = () => {
        setSelectedNick(null);
        setShowModal(true);
    };

    const handleOpenBulkModal = () => {
        setShowBulkModal(true);
    };

    const handleCloseModal = () => {
        setShowModal(false);
        setSelectedNick(null);
    };

    const handleCloseBulkModal = () => {
        setShowBulkModal(false);
    };

    const handleEdit = (nick: IRandomNick) => {
        setSelectedNick(nick);
        setShowModal(true);
    };

    const handleDelete = (nick: IRandomNick) => {
        if (confirm(`Bạn có chắc chắn muốn xóa nick "${nick.account}"?`)) {
            router.delete(`/admin/random-nicks/${nick.id}`, {
                onSuccess: () => {
                    toast.success(`Nick "${nick.account}" đã được xóa thành công!`);
                },
                onError: () => {
                    toast.error('Xóa nick thất bại. Vui lòng thử lại!');
                }
            });
        }
    };

    const handleRestore = (nick: IRandomNick) => {
        router.patch(`/admin/random-nicks/${nick.id}/restore`, {}, {
            onSuccess: () => {
                toast.success(`Nick "${nick.account}" đã được khôi phục thành công!`);
            },
            onError: () => {
                toast.error('Khôi phục nick thất bại. Vui lòng thử lại!');
            }
        });
    };

    const handleChangeStatus = (nick: IRandomNick, status: string) => {
        router.patch(`/admin/random-nicks/${nick.id}/status`, { status }, {
            onSuccess: () => {
                toast.success(`Trạng thái nick "${nick.account}" đã được cập nhật!`);
            },
            onError: () => {
                toast.error('Cập nhật trạng thái thất bại. Vui lòng thử lại!');
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

    // Get current random box for display if filtered
    const currentRandomBox = useMemo(() => {
        return currentFilters.random_box_id
            ? randomBoxes.find(box => box.id.toString() === currentFilters.random_box_id)
            : null;
    }, [currentFilters.random_box_id, randomBoxes]);

    // Define columns for DataTable
    const columns: Column<IRandomNick>[] = useMemo(() => [
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
            key: 'account',
            title: 'Tài khoản',
            width: 200,
            sortable: true,
            render: (account: string, record: IRandomNick) => (
                <div className="flex items-center gap-3">
                    <div className="w-10 h-10 bg-gradient-to-br from-blue-100 to-purple-100 rounded-lg flex items-center justify-center overflow-hidden">
                        {record.image_url ? (
                            <img
                                src={record.image_url}
                                alt={account}
                                className="w-full h-full object-cover"
                            />
                        ) : (
                            <User className="w-5 h-5 text-blue-500" />
                        )}
                    </div>
                    <div>
                        <div className="font-semibold ">{account}</div>
                        <div className="text-sm ">
                            {record.has_own_image ? (
                                <span className="flex items-center gap-1">
                                    <Image className="w-3 h-3" />
                                    Ảnh riêng
                                </span>
                            ) : (
                                <span className="text-gray-400">Ảnh chung</span>
                            )}
                        </div>
                    </div>
                </div>
            )
        },
        {
            key: 'random_box',
            title: 'Hộp random',
            width: 180,
            render: (randomBox: any, record: IRandomNick) => (
                <div className="text-sm">
                    {randomBox ? (
                        <div>
                            <div className="font-medium ">{randomBox.name}</div>
                            <div className="">{randomBox.price_formatted}</div>
                        </div>
                    ) : (
                        <span className=" italic">Không xác định</span>
                    )}
                </div>
            )
        },
        {
            key: 'password',
            title: 'Mật khẩu',
            width: 120,
            render: (password: string, record: IRandomNick) => (
                <div className="font-mono text-sm">
                    <span className="text-gray-600">{record.password_masked}</span>
                </div>
            )
        },
        {
            key: 'description',
            title: 'Mô tả',
            width: 250,
            render: (description: string) => (
                <div className="text-sm text-gray-700">
                    {description ? (
                        <span className="line-clamp-2">{description}</span>
                    ) : (
                        <span className=" italic">Không có mô tả</span>
                    )}
                </div>
            )
        },
        {
            key: 'status',
            title: 'Trạng thái',
            width: 120,
            align: 'center',
            sortable: true,
            filters: [
                { text: 'Có sẵn', value: 'available' },
                { text: 'Đã bán', value: 'taken' },
                { text: 'Đã xóa', value: 'deleted' }
            ],
            onFilter: (value: any, record: IRandomNick) => record.status === value,
            render: (status: string, record: IRandomNick) => {
                const config = {
                    available: {
                        color: 'bg-green-100 text-green-800',
                        text: 'Có sẵn',
                        dot: 'bg-green-500'
                    },
                    taken: {
                        color: 'bg-yellow-100 text-yellow-800',
                        text: 'Đã bán',
                        dot: 'bg-yellow-500'
                    },
                    deleted: {
                        color: 'bg-red-100 text-red-800',
                        text: 'Đã xóa',
                        dot: 'bg-red-500'
                    }
                };

                const statusConfig = config[status as keyof typeof config] || config.available;

                return (
                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${statusConfig.color}`}>
                        <span className={`w-2 h-2 rounded-full mr-1.5 ${statusConfig.dot}`}></span>
                        {statusConfig.text}
                    </span>
                );
            }
        },
        {
            key: 'created_at',
            title: 'Ngày tạo',
            sortable: true,
            width: 150,
            render: (value: string) => (
                <span className="text-sm text-gray-600">{formatDate(value)}</span>
            )
        }
    ], []);

    // Prepare filter options
    const filterOptions = useMemo(() => [
        {
            key: 'random_box_id',
            type: 'select' as const,
            label: 'Hộp random',
            options: [
                { label: 'Tất cả hộp', value: '' },
                ...randomBoxes.map(box => ({
                    label: `${box.name} (${box.category?.name || 'Chưa phân loại'})`,
                    value: box.id.toString()
                }))
            ],
            value: currentFilters.random_box_id || ''
        },
        {
            key: 'status',
            type: 'select' as const,
            label: 'Trạng thái',
            options: [
                { label: 'Tất cả', value: '' },
                { label: 'Có sẵn', value: 'available' },
                { label: 'Đã bán', value: 'taken' },
                { label: 'Đã xóa', value: 'deleted' }
            ],
            value: currentFilters.status || ''
        }
    ], [randomBoxes, currentFilters]);

    return (
        <>
            {/* Header with stats */}
            <div className="mb-6 bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <div className="flex items-center justify-between mb-4">
                    <div className="flex items-center gap-3">
                        <div className="w-12 h-12 bg-gradient-to-br from-purple-100 to-pink-100 rounded-lg flex items-center justify-center">
                            <Users className="w-6 h-6 text-purple-500" />
                        </div>
                        <div>
                            <h1 className="text-xl font-semibold text-gray-900">
                                Quản lý Nick Random
                                {currentRandomBox && (
                                    <span className="text-purple-600"> - {currentRandomBox.name}</span>
                                )}
                            </h1>
                            <p className="text-sm text-gray-600">
                                {currentRandomBox ? (
                                    <>Giá: {currentRandomBox.price_formatted} • Danh mục: {currentRandomBox.category?.name}</>
                                ) : (
                                    'Quản lý tất cả nick random trong hệ thống'
                                )}
                            </p>
                        </div>
                    </div>
                </div>

                {/* Stats */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div className="bg-blue-50 p-4 rounded-lg border border-blue-200">
                        <div className="flex items-center gap-3">
                            <Users className="w-8 h-8 text-blue-500" />
                            <div>
                                <p className="text-sm text-blue-600 font-medium">
                                    Tổng nick {currentRandomBox ? `(${currentRandomBox.name})` : ''}
                                </p>
                                <p className="text-2xl font-bold text-blue-700">{stats.total}</p>
                            </div>
                        </div>
                    </div>

                    <div className="bg-green-50 p-4 rounded-lg border border-green-200">
                        <div className="flex items-center gap-3">
                            <Star className="w-8 h-8 text-green-500" />
                            <div>
                                <p className="text-sm text-green-600 font-medium">Có sẵn</p>
                                <p className="text-2xl font-bold text-green-700">{stats.available}</p>
                            </div>
                        </div>
                    </div>

                    <div className="bg-yellow-50 p-4 rounded-lg border border-yellow-200">
                        <div className="flex items-center gap-3">
                            <TrendingUp className="w-8 h-8 text-yellow-500" />
                            <div>
                                <p className="text-sm text-yellow-600 font-medium">Đã bán</p>
                                <p className="text-2xl font-bold text-yellow-700">{stats.taken}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            {/* Custom bulk add button */}
            <div className="m-2 right-12">
                <button
                    onClick={handleOpenBulkModal}
                    className="flex items-center gap-2 px-4 py-3 bg-gradient-to-r from-purple-600 to-pink-600 text-white rounded-full shadow-lg hover:shadow-xl transition-all duration-200 hover:scale-105"
                    title="Thêm nhiều nick cùng lúc"
                >
                    <Upload className="w-5 h-5" />
                    <span className="font-medium">Thêm hàng loạt</span>
                </button>
            </div>

            {/* 🎯 DataTable cho RandomNicks */}
            <DataTable<IRandomNick>
                data={randomNicks.data}
                columns={columns}
                loading={loading}
                searchValue={currentFilters.search || ''}
                title="Danh sách nick random"
                description="Quản lý tất cả nick random trong hệ thống"
                pagination={{
                    current: randomNicks.meta.current_page,
                    pageSize: randomNicks.meta.per_page,
                    total: randomNicks.meta.total,
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
                    restore: {
                        label: 'Khôi phục',
                        icon: User,
                        handler: handleRestore,
                        className: 'text-purple-600 hover:text-purple-800',
                        condition: (record: IRandomNick) => record.status === 'deleted'
                    },
                    markAvailable: {
                        label: 'Đánh dấu có sẵn',
                        icon: Star,
                        handler: (record: IRandomNick) => handleChangeStatus(record, 'available'),
                        className: 'text-green-600 hover:text-green-800',
                        condition: (record: IRandomNick) => record.status !== 'available'
                    },
                    markTaken: {
                        label: 'Đánh dấu đã bán',
                        icon: TrendingUp,
                        handler: (record: IRandomNick) => handleChangeStatus(record, 'taken'),
                        className: 'text-yellow-600 hover:text-yellow-800',
                        condition: (record: IRandomNick) => record.status !== 'taken'
                    }
                }}
                filters={filterOptions}
                searchPlaceholder="Tìm kiếm theo tài khoản, mô tả..."
                addButtonText="Thêm nick mới"
                emptyText="Chưa có nick nào"
                emptyDescription="Hãy thêm nick đầu tiên để bắt đầu quản lý"
            />



            {/* Modals */}
            {showModal && (
                <RandomNickModal
                    onClose={handleCloseModal}
                    randomNick={selectedNick}
                    randomBoxes={randomBoxes}
                />
            )}

            {showBulkModal && (
                <BulkAddModal
                    onClose={handleCloseBulkModal}
                    randomBoxes={randomBoxes}
                />
            )}
        </>
    );
}

RandomNickPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Random Nick Management" children={page} />
);
