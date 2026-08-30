// Admin/NickOrders/Index.tsx
import React, { useEffect, useState, useMemo } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { PageProps, PaginatedData } from "@/types";
import { Column, DataTable } from "@/Components/Table/DataTable";
import {
    Eye, ShoppingCart, User, DollarSign, CheckCircle,
    Clock, RotateCcw, Calendar, ExternalLink, Package,
    TrendingUp
} from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import { useTableFilters } from "@/Hooks/useTableFilters";
import { formatDate, formatPrice } from '@/Utils/currencyHelper';
import NickOrderDetailModal from './NickOrderDetailModal';
import RefundModal from './RefundModal';
import { INickOrder } from '@/InterFaces/nickOrder';
import { Card, Row, Col, Statistic, Badge } from 'antd';

interface NickOrderFilters {
    search?: string;
    status?: string;
    buyer_id?: number;
    seller_id?: number;
    date_from?: string;
    date_to?: string;
}

interface NickOrderPageProps extends PageProps {
    orders: PaginatedData<INickOrder>;
    filters: NickOrderFilters;
    stats: {
        total_orders: number;
        pending_orders: number;
        completed_orders: number;
        refunded_orders: number;
        total_revenue: number;
        today_orders: number;
        today_revenue: number;
        avg_order_value: number;
    };
}

export default function NickOrdersPage() {
    const { orders, filters: serverFilters, stats, flash } = usePage<NickOrderPageProps>().props;
    const toast = useToast();

    // Modal states
    const [selectedOrder, setSelectedOrder] = useState<INickOrder | null>(null);
    const [isDetailModalOpen, setIsDetailModalOpen] = useState(false);
    const [isRefundModalOpen, setIsRefundModalOpen] = useState(false);

    // Table filters hook
    const {
        filters,
        columnFilters,
        loading,
        handleSearch,
        handleResetFilters,
        handlePageChange,
        setColumnFilters,
    } = useTableFilters({
        routeName: 'admin.games.accounts.history.index',
        initialFilters: serverFilters || {},
        initialData: orders,
        debounceMs: 500,
    });

    const currentFilters = filters as NickOrderFilters;

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
    const handleView = (order: INickOrder) => {
        setSelectedOrder(order);
        setIsDetailModalOpen(true);
    };

    const handleRefund = (order: INickOrder) => {
        setSelectedOrder(order);
        setIsRefundModalOpen(true);
    };

    const handleStatusChange = (order: INickOrder, newStatus: string) => {
        router.put(`/admin/nick-orders/${order.id}/status`, {
            status: newStatus
        }, {
            onSuccess: () => {
                toast.success('Cập nhật trạng thái thành công!');
            },
            onError: () => {
                toast.error('Cập nhật trạng thái thất bại!');
            }
        });
    };

    // Format functions
    const formatCurrency = (amount: number): string => {
        return new Intl.NumberFormat('vi-VN', {
            style: 'currency',
            currency: 'VND',
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        }).format(amount);
    };

    // Status config
    const getStatusConfig = (status: string) => {
        const configs: any = {
            pending: {
                color: 'warning',
                bgColor: 'bg-yellow-100',
                textColor: 'text-yellow-800',
                borderColor: 'border-yellow-300',
                icon: Clock,
                text: 'Chờ xử lý'
            },
            completed: {
                color: 'success',
                bgColor: 'bg-green-100',
                textColor: 'text-green-800',
                borderColor: 'border-green-300',
                icon: CheckCircle,
                text: 'Hoàn thành'
            },
            refunded: {
                color: 'error',
                bgColor: 'bg-red-100',
                textColor: 'text-red-800',
                borderColor: 'border-red-300',
                icon: RotateCcw,
                text: 'Đã hoàn tiền'
            }
        };
        return configs[status] || configs.pending;
    };

    // Define columns
    const columns: Column<INickOrder>[] = useMemo(() => [
        {
            key: 'id',
            title: 'Mã đơn',
            width: 100,
            fixed: 'left',
            render: (id: number) => (
                <span className="font-mono font-bold text-blue-600">
                    #{id}
                </span>
            )
        },
        {
            key: 'nick',
            title: 'Thông tin Nick',
            width: 250,
            render: (_, record: INickOrder) => (
                <div className="flex items-start gap-3">
                    <div className="w-10 h-10 rounded-lg bg-gradient-to-br from-blue-100 to-blue-200 flex items-center justify-center flex-shrink-0">
                        <ShoppingCart className="w-5 h-5 text-blue-600" />
                    </div>
                    <div className="flex-1 min-w-0">
                        <div className="font-semibold truncate mb-1">
                            {record.nick?.account_name || 'N/A'}
                        </div>
                        <div className="text-xs text-gray-500 dark:text-white">
                            ID Nick: #{record.nick?.id}
                        </div>
                        {record.nick?.category && (
                            <div className="text-xs text-gray-600 dark:text-green-400 mt-1">
                                📁 {record.nick.category.name}
                            </div>
                        )}
                    </div>
                </div>
            )
        },
        {
            key: 'buyer',
            title: 'Người mua',
            width: 180,
            render: (_, record: INickOrder) => (
                <div className="bg-green-50 rounded-lg p-2">
                    <div className="flex items-center gap-2">
                        <User className="w-4 h-4 text-green-600" />
                        <div className="font-medium text-green-700 truncate">
                            {record.buyer?.name || 'N/A'}
                        </div>
                    </div>
                    {record.buyer?.email && (
                        <div className="text-xs text-green-600 mt-1 truncate">
                            {record.buyer.email}
                        </div>
                    )}
                </div>
            )
        },
        {
            key: 'seller',
            title: 'Người bán',
            width: 180,
            render: (_, record: INickOrder) => (
                <div className="bg-blue-50 rounded-lg p-2">
                    <div className="flex items-center gap-2">
                        <User className="w-4 h-4 text-blue-600" />
                        <div className="font-medium text-blue-700 truncate">
                            {record.seller?.name || 'N/A'}
                        </div>
                    </div>
                    {record.seller?.email && (
                        <div className="text-xs text-blue-600 mt-1 truncate">
                            {record.seller.email}
                        </div>
                    )}
                </div>
            )
        },
        {
            key: 'price',
            title: 'Giá bán',
            width: 150,
            align: 'right',
            sortable: true,
            render: (price: number) => (
                <div className="bg-purple-50 rounded-lg p-2">
                    <div className="flex items-center justify-end gap-2">
                        <DollarSign className="w-4 h-4 text-purple-600" />
                        <div className="font-bold text-purple-700">
                            {formatPrice(price)}
                        </div>
                    </div>
                </div>
            )
        },
        {
            key: 'commission',
            title: 'Hoa hồng',
            width: 120,
            align: 'right',
            visible: false,
            render: (commission: number) => (
                commission ? (
                    <div className="text-orange-600 font-medium">
                        {formatPrice(commission)}
                    </div>
                ) : (
                    <span className="text-gray-400 text-xs">Không có</span>
                )
            )
        },
        {
            key: 'status',
            title: 'Trạng thái',
            width: 140,
            align: 'center',
            filters: [
                { text: '⏳ Chờ xử lý', value: 'pending' },
                { text: '✅ Hoàn thành', value: 'completed' },
                { text: '↩️ Hoàn tiền', value: 'refunded' }
            ],
            render: (status: string) => {
                const config = getStatusConfig(status);
                const Icon = config.icon;
                return (
                    <div className={`inline-flex items-center px-3 py-1 rounded-full text-xs font-medium border ${config.bgColor} ${config.textColor} ${config.borderColor}`}>
                        <Icon className="w-3 h-3 mr-1" />
                        {config.text}
                    </div>
                );
            }
        },
        {
            key: 'created_at',
            title: 'Ngày tạo',
            width: 160,
            sortable: true,
            render: (date: string) => (
                <div className="text-sm">
                    <div className="flex items-center gap-1 ">
                        <Calendar className="w-3 h-3" />
                        {formatDate(date)}
                    </div>
                </div>
            )
        }
    ], []);

    // Filter options
    const filterOptions = useMemo(() => [
        {
            key: 'status',
            type: 'select' as const,
            label: 'Trạng thái',
            options: [
                { label: 'Chờ xử lý', value: 'pending' },
                { label: 'Hoàn thành', value: 'completed' },
                { label: 'Đã hoàn tiền', value: 'refunded' }
            ],
            value: currentFilters.status || ''
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
    ], [currentFilters]);

    return (
        <>
            {/* Statistics Dashboard */}
            <div className="mb-6">
                <Row gutter={16}>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4  dark:text-green-500 border-blue-500 dark:border-blue-400 dark:bg-gray-800">
                            <Statistic
                                className='!dark:text-green-500'
                                title="Tổng đơn hàng"
                                value={stats.total_orders}
                                prefix={<Package className="w-5 h-5 text-blue-500 dark:text-blue-400" />}
                            />
                            <div className="text-xs text-gray-500 dark:text-white mt-2">
                                Hôm nay: {stats.today_orders}
                            </div>
                        </Card>
                    </Col>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-green-500  dark:border-green-400 dark:bg-gray-800">
                            <Statistic
                                className='!dark:text-green-500'
                                title="Tổng doanh thu"
                                value={stats.total_revenue}
                                formatter={(value) => formatCurrency(Number(value))}
                                prefix={<DollarSign className="w-5 h-5 text-green-500 dark:text-green-400" />}
                            />
                            <div className="text-xs text-gray-500 dark:text-white mt-2">
                                Hôm nay: {formatCurrency(stats.today_revenue)}
                            </div>
                        </Card>
                    </Col>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-purple-500 dark:border-purple-400 dark:bg-gray-800">
                            <Statistic
                                title="Giá trung bình"
                                value={stats.avg_order_value}
                                formatter={(value) => formatCurrency(Number(value))}
                                prefix={<TrendingUp className="w-5 h-5 text-purple-500 dark:text-purple-400" />}
                            />
                        </Card>
                    </Col>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-yellow-500 dark:border-yellow-400 dark:bg-gray-800">
                            <div className="space-y-2">
                                <div className="flex justify-between">
                                    <span className="text-yellow-600 dark:text-yellow-400">Chờ xử lý:</span>
                                    <Badge count={stats.pending_orders} showZero style={{ backgroundColor: '#faad14' }} />
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-green-600 dark:text-green-400">Hoàn thành:</span>
                                    <Badge count={stats.completed_orders} style={{ backgroundColor: '#52c41a' }} />
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-red-600 dark:text-red-400">Hoàn tiền:</span>
                                    <Badge count={stats.refunded_orders} showZero style={{ backgroundColor: '#ff4d4f' }} />
                                </div>
                            </div>
                        </Card>
                    </Col>
                </Row>
            </div>

            {/* Data Table */}
            <DataTable<INickOrder>
                data={orders.data}
                columns={columns}
                loading={loading}
                searchValue={currentFilters.search || ''}
                title="Lịch sử bán nick"
                description="Quản lý và theo dõi tất cả các giao dịch bán nick"
                pagination={{
                    current: orders.meta.current_page,
                    pageSize: orders.meta.per_page,
                    total: orders.meta.total,
                    showSizeChanger: true,
                    pageSizeOptions: ['10', '20', '50', '100'],
                    onChange: handlePageChange,
                }}
                onFiltersChange={setColumnFilters}
                onSearch={handleSearch}
                onReset={handleResetFilters}
                onView={handleView}
                filters={filterOptions}
                searchPlaceholder="Tìm theo đơn hàng, nick, người mua..."
                emptyText="Chưa có giao dịch nào"
                emptyDescription="Các giao dịch sẽ xuất hiện ở đây"
                customActions={{
                    view: {
                        label: 'Xem chi tiết',
                        icon: Eye,
                        handler: handleView,
                        className: 'text-blue-600'
                    },
                    complete: {
                        label: 'Hoàn thành',
                        icon: CheckCircle,
                        handler: (order) => handleStatusChange(order, 'completed'),
                        condition: (order) => order.status === 'pending',
                        className: 'text-green-600'
                    },
                    refund: {
                        label: 'Hoàn tiền',
                        icon: RotateCcw,
                        handler: handleRefund,
                        condition: (order) => order.status === 'completed',
                        className: 'text-red-600'
                    },
                    external: {
                        label: 'Xem nick',
                        icon: ExternalLink,
                        handler: (order) => window.open(`http://shophhp.vn/nick/${order.nick?.id}`, '_blank'),
                        className: 'text-purple-600'
                    }
                }}
            />

            {/* Modals */}
            {selectedOrder && (
                <>
                    <NickOrderDetailModal
                        isOpen={isDetailModalOpen}
                        onClose={() => {
                            setIsDetailModalOpen(false);
                            setSelectedOrder(null);
                        }}
                        order={selectedOrder}
                        onStatusChange={handleStatusChange}
                    />
                    <RefundModal
                        isOpen={isRefundModalOpen}
                        onClose={() => {
                            setIsRefundModalOpen(false);
                            setSelectedOrder(null);
                        }}
                        order={selectedOrder}
                    />
                </>
            )}
        </>
    );
}

NickOrdersPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Nick Orders Management" children={page} />
);