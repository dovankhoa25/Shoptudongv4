// Admin/Spins/Index.tsx - Updated with Modal
import React, { useEffect, useMemo, useState } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { PageProps, PaginatedData } from "@/types";
import { Column, DataTable } from "@/Components/Table/DataTable";
import {
    Eye, Edit3, Trash2, Plus, Package, DollarSign,
    TrendingUp, Tag as TagIcon, Users, RotateCcw,
    Crown, CheckCircle, XCircle, Calendar, Image as ImageIcon
} from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import { useTableFilters } from "@/Hooks/useTableFilters";
import { Image, Tag, Badge, Statistic, Card, Row, Col, Button, Dropdown, Menu, Space, Switch } from "antd";
import { formatDate, formatPrice } from '@/Utils/currencyHelper';
import SpinModal from './SpinModal'; // Import Modal

// Interfaces (keep the same as before)
interface ISpin {
    id: number;
    category_id: number;
    category: {
        id: number;
        name: string;
        slug: string;
    };
    name: string;
    image: string | null;
    image_url: string;
    type: 'wheel' | 'flip';
    type_label: string;
    price_per_turn: number;
    price_per_turn_formatted: string;
    total_slots: number;
    is_public: boolean;
    sort_order: number;
    description: string | null;
    created_at: string;
    updated_at: string;
    rewards_count?: number;
    results_count?: number;
    tickets_count?: number;
}

interface ICategory {
    id: number;
    name: string;
}

interface SpinFilters {
    search?: string;
    type?: string;
    category_id?: number;
    is_public?: string;
}

interface SpinPageProps extends PageProps {
    spins: PaginatedData<ISpin>;
    categories: ICategory[];
    filters: SpinFilters;
}

export default function SpinPage() {
    const {
        spins,
        filters: serverFilters,
        categories,
        flash
    } = usePage<SpinPageProps>().props;

    const toast = useToast();

    // ✨ ADD: Modal state
    const [showModal, setShowModal] = useState(false);
    const [selectedSpinId, setSelectedSpinId] = useState<number | null>(null);

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
        routeName: 'admin.spins.index',
        initialFilters: serverFilters || {},
        initialData: spins,
        debounceMs: 500,
    });

    const currentFilters = filters as SpinFilters;

    // ✨ UPDATE: Handlers
    const handleAdd = () => {
        setSelectedSpinId(null);
        setShowModal(true);
    };

    const handleEdit = (spin: ISpin) => {
        setSelectedSpinId(spin.id);
        setShowModal(true);
    };

    const handleCloseModal = () => {
        setShowModal(false);
        setSelectedSpinId(null);
    };

    const handleView = (spin: ISpin) => {
        router.visit(`/admin/spins/${spin.id}`);
    };

    const handleDelete = (spin: ISpin) => {
        if (confirm(`Bạn có chắc chắn muốn xóa vòng quay "${spin.name}"?`)) {
            router.delete(`/admin/spins/${spin.id}`, {
                onSuccess: () => {
                    toast.success(`Vòng quay "${spin.name}" đã được xóa!`);
                },
                onError: (errors) => {
                    toast.error('Xóa vòng quay thất bại!');
                }
            });
        }
    };

    const handleTogglePublic = (spin: ISpin) => {
        router.put(`/admin/spins/${spin.id}`, {
            is_public: !spin.is_public
        }, {
            onSuccess: () => {
                toast.success(`Đã ${!spin.is_public ? 'công khai' : 'ẩn'} vòng quay "${spin.name}"!`);
            },
            onError: (errors) => {
                toast.error('Cập nhật trạng thái thất bại!');
            }
        });
    };

    const handleManageRewards = (spin: ISpin) => {
        router.visit(`/admin/spins/${spin.id}/rewards`);
    };

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

    // Render functions (keep the same as before)
    const renderSpinInfo = (spin: ISpin) => (
        <div className="flex items-start gap-3">
            <div className="w-14 h-14 rounded-lg overflow-hidden border border-gray-200 bg-gray-100 flex-shrink-0">
                {spin.image_url ? (
                    <Image
                        src={spin.image_url}
                        alt={spin.name}
                        className="w-full h-full object-cover"
                        fallback="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAMIAAADDCAYAAADQvc6UAAABRWlDQ1BJQ0MgUHJvZmlsZQAAKJFjYGASSSwoyGFhYGDIzSspCnJ3UoiIjFJgf8LAwSDCIMogwMCcmFxc4BgQ4ANUwgCjUcG3awyMIPqyLsis7PPOq3QdDFcvjV3jOD1boQVTPQrgSkktTgbSf4A4LbmgqISBgTEFyFYuLykAsTuAbJEioKOA7DkgdjqEvQHEToKwj4DVhAQ5A9k3gGyB5IxEoBmML4BsnSQk8XQkNtReEOBxcfXxUQg1Mjc0dyHgXNJBSWpFCYh2zi+oLMpMzyhRcASGUqqCZ16yno6CkYGRAQMDKMwhqj/fAIcloxgHQqxAjIHBEugw5sUIsSQpBobtQPdLciLEVJYzMPBHMDBsayhILEqEO4DxG0txmrERhM29nYGBddr//5/DGRjYNRkY/l7////39v///y4Dmn+LgeHANwDrkl1AuO+pmgAAADhlWElmTU0AKgAAAAgAAYdpAAQAAAABAAAAGgAAAAAAAqACAAQAAAABAAAAwqADAAQAAAABAAAAwwAAAAD9b/HnAAAHlklEQVR4Ae3dP3Ik1RnG4W+FgYxN..."
                    />
                ) : (
                    <div className="w-full h-full flex items-center justify-center">
                        <ImageIcon className="w-6 h-6 text-gray-400" />
                    </div>
                )}
            </div>
            <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 mb-1">
                    <span className="font-semibold text-gray-900 truncate">
                        {spin.name}
                    </span>
                    {spin.type === 'wheel' ? (
                        <Tag color="purple" className="text-xs">🎡 Vòng quay</Tag>
                    ) : (
                        <Tag color="orange" className="text-xs">🪙 Lật xu</Tag>
                    )}
                </div>
                <div className="text-sm text-gray-600 mb-1">
                    Danh mục: {spin.category?.name || 'Chưa phân loại'}
                </div>
                <div className="text-sm text-gray-600 mb-1">
                    Số ô: <span className="font-medium">{spin.total_slots} ô</span>
                </div>
                <div className="text-sm font-medium text-green-600">
                    Giá mỗi lượt: {spin.price_per_turn_formatted}
                </div>
            </div>
        </div>
    );

    const renderPublicStatus = (isPublic: boolean) => {
        return isPublic ? (
            <div className="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 border border-green-300">
                <CheckCircle className="w-3 h-3 mr-1" />
                Công khai
            </div>
        ) : (
            <div className="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800 border border-gray-300">
                <XCircle className="w-3 h-3 mr-1" />
                Ẩn
            </div>
        );
    };

    const renderStatistics = (spin: ISpin) => {
        return (
            <div className="space-y-1">
                <div className="flex items-center justify-between text-xs">
                    <span className="text-gray-600">Phần thưởng:</span>
                    <Badge
                        count={spin.rewards_count || 0}
                        showZero
                        style={{ backgroundColor: '#1890ff' }}
                    />
                </div>
                <div className="flex items-center justify-between text-xs">
                    <span className="text-gray-600">Lượt chơi:</span>
                    <Badge
                        count={spin.results_count || 0}
                        showZero
                        style={{ backgroundColor: '#52c41a' }}
                    />
                </div>
                <div className="flex items-center justify-between text-xs">
                    <span className="text-gray-600">Lượt còn:</span>
                    <Badge
                        count={spin.tickets_count || 0}
                        showZero
                        style={{ backgroundColor: '#faad14' }}
                    />
                </div>
            </div>
        );
    };

    // Define columns for DataTable
    const columns: Column<ISpin>[] = useMemo(() => [
        {
            key: 'id',
            title: 'ID',
            width: 80,
            fixed: 'left',
            render: (id: number) => (
                <span
                    className="font-mono font-bold text-blue-600 hover:text-blue-800 cursor-pointer hover:underline transition-colors"
                    onClick={() => handleView({ id } as ISpin)}
                    title={`Xem chi tiết vòng quay #${id}`}
                >
                    #{id}
                </span>
            )
        },
        {
            key: 'spin_info',
            title: 'Thông tin vòng quay',
            width: 320,
            render: (_, record: ISpin) => renderSpinInfo(record)
        },
        {
            key: 'type',
            title: 'Loại',
            width: 120,
            align: 'center',
            filters: [
                { text: '🎡 Vòng quay', value: 'wheel' },
                { text: '🪙 Lật xu', value: 'flip' }
            ],
            render: (type: string) => (
                type === 'wheel' ? (
                    <Tag color="purple" className="text-sm">🎡 Vòng quay</Tag>
                ) : (
                    <Tag color="orange" className="text-sm">🪙 Lật xu</Tag>
                )
            )
        },
        {
            key: 'price_per_turn',
            title: 'Giá/lượt',
            width: 130,
            align: 'right',
            sortable: true,
            render: (price: number, record: ISpin) => (
                <div className="text-right">
                    <div className="font-semibold text-green-600">
                        {record.price_per_turn_formatted}
                    </div>
                </div>
            )
        },
        {
            key: 'statistics',
            title: 'Thống kê',
            width: 150,
            align: 'center',
            render: (_, record: ISpin) => renderStatistics(record)
        },
        {
            key: 'is_public',
            title: 'Trạng thái',
            width: 130,
            align: 'center',
            filters: [
                { text: '✅ Công khai', value: 'true' },
                { text: '❌ Ẩn', value: 'false' }
            ],
            render: (isPublic: boolean) => renderPublicStatus(isPublic)
        },
        {
            key: 'sort_order',
            title: 'Thứ tự',
            width: 100,
            align: 'center',
            sortable: true,
            render: (order: number) => (
                <div className="font-mono text-sm text-gray-600">
                    {order}
                </div>
            )
        },
        {
            key: 'created_at',
            title: 'Ngày tạo',
            width: 150,
            sortable: true,
            render: (date: string) => (
                <div className="text-sm">
                    <div className="text-gray-900">{formatDate(date)}</div>
                </div>
            )
        },
        {
            key: 'actions',
            title: 'Thao tác',
            width: 200,
            fixed: 'right',
            align: 'center',
            render: (_, record: ISpin) => {
                const menu = (
                    <Menu>
                        <Menu.Item
                            key="view"
                            icon={<Eye className="w-4 h-4" />}
                            onClick={() => handleView(record)}
                        >
                            Xem chi tiết
                        </Menu.Item>
                        <Menu.Item
                            key="rewards"
                            icon={<TagIcon className="w-4 h-4" />}
                            onClick={() => handleManageRewards(record)}
                        >
                            Quản lý phần thưởng
                        </Menu.Item>
                        <Menu.Divider />
                        <Menu.Item
                            key="edit"
                            icon={<Edit3 className="w-4 h-4" />}
                            onClick={() => handleEdit(record)}
                        >
                            Chỉnh sửa
                        </Menu.Item>
                        <Menu.Item
                            key="toggle"
                            icon={record.is_public ? <XCircle className="w-4 h-4" /> : <CheckCircle className="w-4 h-4" />}
                            onClick={() => handleTogglePublic(record)}
                        >
                            {record.is_public ? 'Ẩn vòng quay' : 'Công khai'}
                        </Menu.Item>
                        <Menu.Divider />
                        <Menu.Item
                            key="delete"
                            icon={<Trash2 className="w-4 h-4" />}
                            onClick={() => handleDelete(record)}
                            danger
                        >
                            Xóa vòng quay
                        </Menu.Item>
                    </Menu>
                );

                return (
                    <Space>
                        <Button
                            type="primary"
                            size="small"
                            icon={<TagIcon className="w-3 h-3" />}
                            onClick={() => handleManageRewards(record)}
                        >
                            Phần thưởng
                        </Button>
                        <Dropdown overlay={menu} trigger={['click']}>
                            <Button size="small">
                                Thao tác
                            </Button>
                        </Dropdown>
                    </Space>
                );
            }
        }
    ], []);

    // Filter options
    const filterOptions = useMemo(() => [
        {
            key: 'category_id',
            type: 'select' as const,
            label: 'Danh mục',
            options: [
                ...categories.map(category => ({
                    label: category.name,
                    value: category.id.toString()
                }))
            ],
            value: currentFilters.category_id?.toString() || ''
        },
        {
            key: 'type',
            type: 'select' as const,
            label: 'Loại vòng quay',
            options: [
                { label: '🎡 Vòng quay', value: 'wheel' },
                { label: '🪙 Lật xu', value: 'flip' }
            ],
            value: currentFilters.type || ''
        },
        {
            key: 'is_public',
            type: 'select' as const,
            label: 'Trạng thái',
            options: [
                { label: 'Công khai', value: 'true' },
                { label: 'Ẩn', value: 'false' }
            ],
            value: currentFilters.is_public || ''
        }
    ], [categories, currentFilters]);

    return (
        <>
            {/* Statistics Cards */}
            <div className="mb-6">
                <Row gutter={16}>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-purple-500">
                            <Statistic
                                title="Tổng vòng quay"
                                value={spins.meta.total}
                                prefix={<RotateCcw className="w-5 h-5 text-purple-500" />}
                            />
                            <div className="text-xs text-gray-500 mt-2">
                                Đang hoạt động: {spins.data.filter(s => s.is_public).length}
                            </div>
                        </Card>
                    </Col>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-blue-500">
                            <Statistic
                                title="Vòng quay"
                                value={spins.data.filter(s => s.type === 'wheel').length}
                                prefix={<span className="text-2xl">🎡</span>}
                            />
                        </Card>
                    </Col>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-orange-500">
                            <Statistic
                                title="Lật xu"
                                value={spins.data.filter(s => s.type === 'flip').length}
                                prefix={<span className="text-2xl">🪙</span>}
                            />
                        </Card>
                    </Col>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-green-500">
                            <div className="space-y-2">
                                <div className="flex justify-between">
                                    <span className="text-blue-600">Phần thưởng:</span>
                                    <Badge
                                        count={spins.data.reduce((sum, s) => sum + (s.rewards_count || 0), 0)}
                                        showZero
                                        style={{ backgroundColor: '#1890ff' }}
                                    />
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-green-600">Lượt chơi:</span>
                                    <Badge
                                        count={spins.data.reduce((sum, s) => sum + (s.results_count || 0), 0)}
                                        showZero
                                        style={{ backgroundColor: '#52c41a' }}
                                    />
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-yellow-600">Lượt còn:</span>
                                    <Badge
                                        count={spins.data.reduce((sum, s) => sum + (s.tickets_count || 0), 0)}
                                        showZero
                                        style={{ backgroundColor: '#faad14' }}
                                    />
                                </div>
                            </div>
                        </Card>
                    </Col>
                </Row>
            </div>

            {/* Data Table */}
            <DataTable<ISpin>
                data={spins.data}
                columns={columns}
                loading={loading}
                searchValue={currentFilters.search || ''}
                title="Quản lý Vòng Quay"
                description="Danh sách tất cả các vòng quay trong hệ thống"
                pagination={{
                    current: spins.meta.current_page,
                    pageSize: spins.meta.per_page,
                    total: spins.meta.total,
                    showSizeChanger: true,
                    pageSizeOptions: ['10', '20', '50', '100'],
                    onChange: handlePageChange,
                }}
                onFiltersChange={setColumnFilters}
                onSearch={handleSearch}
                onAdd={handleAdd}
                onReset={handleResetFilters}
                filters={filterOptions}
                searchPlaceholder="Tìm theo tên vòng quay..."
                emptyText="Chưa có vòng quay nào"
                emptyDescription="Các vòng quay sẽ xuất hiện ở đây"
            />

            {/* ✨ ADD: Spin Modal */}
            {showModal && (
                <SpinModal
                    open={showModal}
                    onClose={handleCloseModal}
                    spinId={selectedSpinId}
                    categories={categories}
                />
            )}
        </>
    );
}

SpinPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Quản lý Vòng Quay" children={page} />
);