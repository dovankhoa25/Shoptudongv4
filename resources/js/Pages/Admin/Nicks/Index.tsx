// Admin/Nicks/Index.tsx - Enhanced Nick Management
import React, { useEffect, useMemo, useState } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { INick } from "@/InterFaces/nick";
import { ICategory } from "@/InterFaces/category";
import { PageProps, PaginatedData } from "@/types";
import { Column, DataTable } from "@/Components/Table/DataTable";
import {
    Eye, Tag as TagIcon, Crown, User, Image as ImageIcon,
    CheckCircle, RotateCcw, Trash2, Edit3, Calendar,
    TrendingUp, Package, Users, DollarSign, Gem,
    Server, AlertCircle, RefreshCw, Ban, Check,
    EyeOff,
    Filter
} from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import { useTableFilters } from "@/Hooks/useTableFilters";
import { Image, Tag, Badge, Statistic, Card, Row, Col, Button, Dropdown, Menu, Space } from "antd";
import { formatDate, formatPrice } from '@/Utils/currencyHelper';
import EditModel from './EditModel';
import { BulkUpdateModal } from './BulkUpdateModal';

interface NickFilters {
    search?: string;
    status?: string;
    listing_type?: string;
    category_id?: number;
    user_id?: number;
    date_from?: string;
    date_to?: string;
}

interface NickPageProps extends PageProps {
    nicks: PaginatedData<INick>;
    categories: ICategory[];
    filters: NickFilters;
    stats: {
        total_nicks: number;
        sold_nicks: number;
        not_sold_nicks: number;
        deleted_nicks: number;
        returned_nicks: number;
        vip_nicks: number;
        normal_nicks: number;
        total_revenue: number;
        today_nicks: number;
        today_revenue: number;
        avg_price: number;
    };
}

export default function NickPage() {
    const {
        nicks,
        filters: serverFilters,
        categories,
        stats,
        flash
    } = usePage<NickPageProps>().props;

    const toast = useToast();
    const [showEditModal, setShowEditModal] = useState(false);
    const [selectedNick, setSelectedNick] = useState<number | null | undefined>(null);
    const [showBulkUpdateModal, setShowBulkUpdateModal] = useState(false);
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
        routeName: 'admin.games.accounts.index',
        initialFilters: serverFilters || {},
        initialData: nicks,
        debounceMs: 500,
    });

    const currentFilters = filters as NickFilters;

    // Handlers
    const handleAdd = () => {
        router.visit('/admin/games/accounts/create');
    };

    const handleEdit = (nick: INick) => {
        setShowEditModal(true);
        setSelectedNick(nick.id);
    };

    const handleCloseUpdateModal = () => {
        setShowEditModal(false);
        setSelectedNick(null);
    };

    const handleView = (nick: INick) => {
        setShowEditModal(true);
        setSelectedNick(nick.id);
    };

    const handleDelete = (nick: INick) => {
        if (confirm(`Bạn có chắc chắn muốn xóa nick "${nick.account_name}"?`)) {
            router.delete(`/admin/games/accounts/${nick.id}`, {
                onSuccess: () => {
                    toast.success(`Nick "${nick.account_name}" đã được xóa!`);
                },
                onError: (errors) => {
                    toast.error('Xóa nick thất bại!');
                }
            });
        }
    };

    const handleStatusChange = (nick: INick, newStatus: string) => {
        router.put(`/admin/games/accounts/${nick.id}`, {
            status: newStatus
        }, {
            onSuccess: () => {
                toast.success(`Trạng thái nick "${nick.account_name}" đã được cập nhật!`);
            },
            onError: (errors) => {
                toast.error('Cập nhật trạng thái thất bại!');
            }
        });
    };

    // Quick actions
    const handleQuickSold = (nick: INick) => {
        if (nick.status === 'sold') return;
        handleStatusChange(nick, 'sold');
    };

    const handleQuickReturn = (nick: INick) => {
        if (nick.status !== 'sold') return;
        handleStatusChange(nick, 'return');
    };

    const handleQuickRestore = (nick: INick) => {
        if (nick.status !== 'deleted') return;
        handleStatusChange(nick, 'not_sold');
    };


    // Quick toggle hide/not_sold
    const handleToggleHideStatus = (nick: INick) => {
        const newStatus = nick.status === 'hide' ? 'not_sold' : 'hide';
        const actionText = newStatus === 'hide' ? 'ẩn' : 'hiện';

        if (confirm(`Bạn có chắc chắn muốn ${actionText} nick "${nick.account_name}"?`)) {
            router.put(`/admin/nicks/${nick.id}/toggle-visibility`, {
                new_status: newStatus
            }, {
                onSuccess: () => {
                    toast.success(`Đã ${actionText} nick thành công!`);
                },
                onError: (errors) => {
                    toast.error(`${actionText.charAt(0).toUpperCase() + actionText.slice(1)} nick thất bại!`);
                }
            });
        }
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

    // Format functions
    const formatCurrency = (amount: number): string => {
        return new Intl.NumberFormat('vi-VN', {
            style: 'currency',
            currency: 'VND',
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        }).format(amount);
    };

    // Render functions
    const renderNickInfo = (nick: INick) => (
        <div className="flex items-start gap-3">
            <div className="w-12 h-12 rounded-lg overflow-hidden border border-gray-200 bg-gray-100 flex-shrink-0">
                {nick.image ? (
                    <Image
                        src={nick.image}
                        alt={nick.account_name}
                        className="w-full h-full object-cover"
                        fallback="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAMIAAADDCAYAAADQvc6UAAABRWlDQ1BJQ0MgUHJvZmlsZQAAKJFjYGASSSwoyGFhYGDIzSspCnJ3UoiIjFJgf8LAwSDCIMogwMCcmFxc4BgQ4ANUwgCjUcG3awyMIPqyLsis7PPOq3QdDFcvjV3jOD1boQVTPQrgSkktTgbSf4A4LbmgqISBgTEFyFYuLykAsTuAbJEioKOA7DkgdjqEvQHEToKwj4DVhAQ5A9k3gGyB5IxEoBmML4BsnSQk8XQkNtReEOBxcfXxUQg1Mjc0dyHgXNJBSWpFCYh2zi+oLMpMzyhRcASGUqqCZ16yno6CkYGRAQMDKMwhqj/fAIcloxgHQqxAjIHBEugw5sUIsSQpBobtQPdLciLEVJYzMPBHMDBsayhILEqEO4DxG0txmrERhM29nYGBddr//5/DGRjYNRkY/l7////39v///y4Dmn+LgeHANwDrkl1AuO+pmgAAADhlWElmTU0AKgAAAAgAAYdpAAQAAAABAAAAGgAAAAAAAqACAAQAAAABAAAAwqADAAQAAAABAAAAwwAAAAD9b/HnAAAHlklEQVR4Ae3dP3Ik1RnG4W+FgYxN..."
                    />
                ) : (
                    <div className="w-full h-full flex items-center justify-center">
                        <User className="w-6 h-6 " />
                    </div>
                )}
            </div>
            <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 mb-1">
                    <span className="font-semibold  truncate">
                        {nick.account_name}
                    </span>
                    {nick.listing_type === 'vip' && (
                        <Crown className="w-4 h-4 text-yellow-500" />
                    )}
                </div>
                <div className="text-sm  mb-1">
                    Danh mục: {nick.category?.name || 'Chưa phân loại'}
                </div>
                <div className="text-sm  mb-1">
                    Người đăng: {nick.user?.username || 'Chưa có'}
                </div>
                <div className="text-sm font-medium text-green-600">
                    Giá: {formatPrice(nick.price)}
                </div>
            </div>
        </div>
    );

    const getStatusConfig = (status: string) => {
        const configs: any = {
            not_sold: {
                color: 'processing',
                icon: TagIcon,
                text: 'Chưa bán',
                bgColor: 'bg-blue-100',
                textColor: 'text-blue-800',
                borderColor: 'border-blue-300'
            },
            sold: {
                color: 'success',
                icon: CheckCircle,
                text: 'Đã bán',
                bgColor: 'bg-green-100',
                textColor: 'text-green-800',
                borderColor: 'border-green-300'
            },
            deleted: {
                color: 'error',
                icon: Trash2,
                text: 'Đã xóa',
                bgColor: 'bg-red-100',
                textColor: 'text-red-800',
                borderColor: 'border-red-300'
            },
            return: {
                color: 'warning',
                icon: RotateCcw,
                text: 'Hoàn trả',
                bgColor: 'bg-yellow-100',
                textColor: 'text-yellow-800',
                borderColor: 'border-yellow-300'
            },
            hide: {
                color: 'default',
                icon: EyeOff,
                text: 'Tạm ẩn',
                bgColor: 'bg-gray-100',
                textColor: 'text-gray-800',
                borderColor: 'border-gray-300'
            },
        };
        return configs[status] || configs.not_sold;
    };

    const renderStatus = (status: string) => {
        const config = getStatusConfig(status);
        const Icon = config.icon;

        return (
            <div className={`inline-flex items-center px-3 py-1 rounded-full text-xs font-medium ${config.bgColor} ${config.textColor} border ${config.borderColor}`}>
                <Icon className="w-3 h-3 mr-1" />
                {config.text}
            </div>
        );
    };

    const renderListingType = (listingType: string) => {
        return listingType === 'vip' ? (
            <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                <Crown className="w-3 h-3 mr-1" />
                VIP
            </span>
        ) : (
            <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                Normal
            </span>
        );
    };

    // Define columns for DataTable
    const columns: Column<INick>[] = useMemo(() => [
        {
            key: 'id',
            title: 'ID',
            width: 80,
            fixed: 'left',
            render: (id: number) => (
                <span
                    className="font-mono font-bold text-blue-600 hover:text-blue-800 cursor-pointer hover:underline transition-colors"
                    onClick={() => window.open(`http://shophhp.vn/nick/${id}`, '_blank')}
                    title={`Xem chi tiết nick #${id}`}
                >
                    #{id}
                </span>
            )
        },
        {
            key: 'account_info',
            title: 'Thông tin nick',
            width: 280,
            render: (_, record: INick) => renderNickInfo(record)
        },
        {
            key: 'attribute_cache_json',
            title: 'Thuộc tính',
            width: 200,
            align: 'center',
            render: (value: any) => {
                try {
                    const attributes = JSON.parse(value || '{}');
                    const entries = Object.entries(attributes);

                    if (entries.length === 0) {
                        return <span className="text-gray-400 text-xs">Không có</span>;
                    }

                    return (
                        <div className="space-y-1">
                            {entries.slice(0, 3).map(([key, val], index) => (
                                <div key={index} className="flex items-center justify-between bg-gray-50 px-2 py-1 rounded text-xs">
                                    <span className="font-medium text-gray-700 truncate">{key}:</span>
                                    <span className="font-mono text-gray-600 bg-blue-100 px-1 rounded ml-1">
                                        {val as string}
                                    </span>
                                </div>
                            ))}
                            {entries.length > 3 && (
                                <div className="text-xs text-gray-400">
                                    +{entries.length - 3} thuộc tính khác
                                </div>
                            )}
                        </div>
                    );
                } catch (error) {
                    return (
                        <span className="font-mono text-red-500 bg-red-50 px-2 py-1 rounded text-xs">
                            Invalid JSON
                        </span>
                    );
                }
            }
        },
        {
            key: 'listing_type',
            title: 'Loại tin',
            width: 120,
            align: 'center',
            visible: false,
            filters: [
                { text: '👑 VIP', value: 'vip' },
                { text: '📄 Normal', value: 'normal' }
            ],
            render: (listingType: string) => renderListingType(listingType)
        },
        {
            key: 'status',
            title: 'Trạng thái',
            width: 140,
            align: 'center',
            filters: [
                { text: '🔵 Chưa bán', value: 'not_sold' },
                { text: '✅ Đã bán', value: 'sold' },
                { text: '🗑️ Đã xóa', value: 'deleted' },
                { text: '↩️ Hoàn trả', value: 'return' },
                { text: '👁️ Tạm ẩn', value: 'hide' },
            ],
            render: (status: string) => renderStatus(status)
        },
        {
            key: 'price',
            title: 'Giá bán',
            width: 120,
            align: 'right',
            visible: false,
            sortable: true,
            render: (price: number) => (
                <div className="text-right">
                    <div className="font-semibold text-green-600">
                        {formatPrice(price)}
                    </div>
                </div>
            )
        },
        {
            key: 'created_at',
            title: 'Ngày tạo',
            width: 150,
            sortable: true,
            render: (date: string, record: INick) => (
                <div className="text-sm">
                    <div className="text-gray-900">{formatDate(date)}</div>
                    <div className="text-xs ">
                        {/* Assuming you have created_at_human like in gem orders */}
                        {record.created_at ? formatDate(record.created_at) : ''}
                    </div>
                </div>
            )
        },
        {
            key: 'updated_at',
            title: 'Cập Nhật',
            width: 150,
            sortable: true,
            visible: false,
            render: (date: string, record: INick) => (
                <div className="text-sm">
                    <div className="text-gray-900">{formatDate(date)}</div>
                    <div className="text-xs ">
                        {/* Assuming you have created_at_human like in gem orders */}
                        {record.updated_at ? formatDate(record.updated_at) : ''}
                    </div>
                </div>
            )
        },
        {
            key: 'actions',
            title: 'Thao tác',
            width: 200,
            fixed: 'right',
            align: 'center',
            render: (_, record: INick) => {
                const menu = (
                    <Menu>
                        <Menu.Item key="view" icon={<Eye />} onClick={() => handleView(record)}>
                            Xem chi tiết
                        </Menu.Item>

                        {record.status === 'not_sold' && (
                            <Menu.Item key="edit" icon={<Edit3 />} onClick={() => handleEdit(record)}>
                                Chỉnh sửa
                            </Menu.Item>
                        )}
                        <Menu.Divider />
                        {record.status === 'not_sold' && (
                            <>
                                <Menu.Item
                                    key="sold"
                                    icon={<CheckCircle />}
                                    onClick={() => handleQuickSold(record)}
                                >
                                    Đánh dấu đã bán
                                </Menu.Item>
                                <Menu.Item
                                    key="hide"
                                    icon={<EyeOff />}
                                    onClick={() => handleToggleHideStatus(record)}
                                >
                                    Ẩn nick
                                </Menu.Item>
                            </>
                        )}
                        {record.status === 'sold' && (
                            <Menu.Item key="return" icon={<RotateCcw />} onClick={() => handleQuickReturn(record)}>
                                Hoàn trả
                            </Menu.Item>
                        )}
                        {record.status === 'deleted' && (
                            <Menu.Item key="restore" icon={<RefreshCw />} onClick={() => handleQuickRestore(record)}>
                                Khôi phục
                            </Menu.Item>
                        )}
                        <Menu.Divider />
                        {record.status === 'not_sold' && (
                            <Menu.Item key="delete" icon={<Trash2 />} onClick={() => handleDelete(record)} danger>
                                Xóa nick
                            </Menu.Item>
                        )}
                        {record.status === 'hide' && (
                            <Menu.Item
                                key="show"
                                icon={<Eye />}
                                onClick={() => handleToggleHideStatus(record)}
                            >
                                Hiện nick
                            </Menu.Item>
                        )}

                    </Menu>
                );

                return (
                    <Space>
                        {/* {record.status === 'not_sold' && (
                            <Button
                                type="primary"
                                size="small"
                                icon={<CheckCircle className="w-3 h-3" />}
                                onClick={() => handleQuickSold(record)}
                            >
                                Đã bán
                            </Button>
                        )} */}
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
            key: 'status',
            type: 'select' as const,
            label: 'Trạng thái',
            options: [
                { label: 'Chưa bán', value: 'not_sold' },
                { label: 'Đã bán', value: 'sold' },
                { label: 'Đã xóa', value: 'deleted' },
                { label: 'Hoàn trả', value: 'return' },
                { label: 'Đã Ẩn', value: 'hide' },
            ],
            value: currentFilters.status || ''
        },
        {
            key: 'listing_type',
            type: 'select' as const,
            label: 'Loại tin',
            options: [
                { label: 'VIP', value: 'vip' },
                { label: 'Normal', value: 'normal' }
            ],
            value: currentFilters.listing_type || ''
        },
        {
            key: 'date_from',
            type: 'date' as const,
            label: 'Từ ngày',
            value: currentFilters.date_from || ''
        },
        {
            key: 'date_to',
            type: 'date' as const,
            label: 'Đến ngày',
            value: currentFilters.date_to || ''
        }
    ], [categories, currentFilters]);

    return (
        <>
            {/* Statistics Cards */}
            <div className="mb-6">
                <Row gutter={16}>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-blue-500 dark:border-blue-400 dark:bg-gray-800">
                            <Statistic
                                title="Tổng nick"
                                value={stats.total_nicks}
                                prefix={<Package className="w-5 h-5 text-blue-500 dark:text-blue-400" />}
                            />
                            <div className="text-xs text-gray-500 dark:text-gray-400 mt-2">
                                Hôm nay: {stats.today_nicks}
                            </div>
                        </Card>
                    </Col>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-green-500 dark:border-green-400 dark:bg-gray-800">
                            <Statistic
                                title="Tổng doanh thu"
                                value={stats.total_revenue}
                                formatter={(value) => formatCurrency(Number(value))}
                                prefix={<DollarSign className="w-5 h-5 text-green-500 dark:text-green-400" />}
                            />
                            <div className="text-xs text-gray-500 dark:text-gray-400 mt-2">
                                Hôm nay: {formatCurrency(stats.today_revenue)}
                            </div>
                        </Card>
                    </Col>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-purple-500 dark:border-purple-400 dark:bg-gray-800">
                            <Statistic
                                title="Giá trung bình"
                                value={stats.avg_price}
                                formatter={(value) => formatCurrency(Number(value))}
                                prefix={<TrendingUp className="w-5 h-5 text-purple-500 dark:text-purple-400" />}
                            />
                        </Card>
                    </Col>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-yellow-500 dark:border-yellow-400 dark:bg-gray-800">
                            <div className="space-y-2">
                                <div className="flex justify-between">
                                    <span className="text-blue-600 dark:text-blue-400">Chưa bán:</span>
                                    <Badge count={stats.not_sold_nicks} showZero style={{ backgroundColor: '#1890ff' }} />
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-green-600 dark:text-green-400">Đã bán:</span>
                                    <Badge count={stats.sold_nicks} showZero style={{ backgroundColor: '#52c41a' }} />
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-yellow-600 dark:text-yellow-400">VIP:</span>
                                    <Badge count={stats.vip_nicks} showZero style={{ backgroundColor: '#faad14' }} />
                                </div>
                            </div>
                        </Card>
                    </Col>
                </Row>
            </div>

            <div className="mb-4">
                <Button
                    type="primary"
                    icon={<Filter className="w-4 h-4" />}
                    onClick={() => setShowBulkUpdateModal(true)}
                >
                    Cập nhật hàng loạt
                </Button>
            </div>
            {/* Data Table */}
            <DataTable<INick>
                data={nicks.data}
                columns={columns}
                loading={loading}
                searchValue={currentFilters.search || ''}
                searchPreset="nicks"
                title="Quản lý Nick"
                description="Danh sách tất cả các nick trong hệ thống"
                pagination={{
                    current: nicks.meta.current_page,
                    pageSize: nicks.meta.per_page,
                    total: nicks.meta.total,
                    showSizeChanger: true,
                    pageSizeOptions: ['10', '20', '50', '100'],
                    onChange: handlePageChange,
                }}
                onFiltersChange={setColumnFilters}
                onSearch={handleSearch}
                onAdd={handleAdd}
                onReset={handleResetFilters}
                filters={filterOptions}
                searchPlaceholder="Tìm theo tên nick, người đăng..."
                emptyText="Chưa có nick nào"
                emptyDescription="Các nick sẽ xuất hiện ở đây"
            />

            {/* Edit Modal */}
            {showEditModal && (
                <EditModel
                    onClose={handleCloseUpdateModal}
                    nickId={selectedNick}
                />
            )}

            {showBulkUpdateModal && (
                <BulkUpdateModal
                    visible={showBulkUpdateModal}
                    onClose={() => setShowBulkUpdateModal(false)}
                    onSuccess={() => {
                        // Reload data
                        router.reload();
                    }}
                    categories={categories}
                />
            )}
        </>
    );
}

NickPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Nick Management" children={page} />
);
