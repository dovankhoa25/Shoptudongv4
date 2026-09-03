// Admin/Orders/Index.tsx - Gold Order Management
import React, { useState, useMemo } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { PageProps, PaginatedData } from "@/types";
import { Column, DataTable } from "@/Components/Table/DataTable";
import {
    Eye, CheckCircle, XCircle, Clock, AlertCircle,
    Calendar, Server, User, Bot, Coins, DollarSign,
    RefreshCw, Ban, Check, RotateCcw, FileText,
    TrendingUp, ShoppingCart, Users, Wallet
} from 'lucide-react';
import { Tag, Badge, Statistic, Card, Row, Col, Button, Dropdown, Menu, Space } from 'antd';
import { useToast } from "@/Components/ToastProvider";
import { useTableFilters } from "@/Hooks/useTableFilters";
import { IGoldTransaction } from '@/InterFaces/goldtransaction';
import { IServer } from '@/InterFaces/server';
import { IBot } from '@/InterFaces/bot';
import RefundModal from './RefundModal';

interface GoldOrderFilters {
    search?: string;
    server_id?: number;
    bot_id?: number;
    status?: string;
    date_from?: string;
    date_to?: string;
}

interface GoldOrderPageProps extends PageProps {
    orders: PaginatedData<IGoldTransaction>;
    servers: IServer[];
    bots: IBot[];
    filters: GoldOrderFilters;
    stats: {
        total_orders: number;
        pending_orders: number;
        processing_orders: number;
        completed_orders: number;
        cancelled_orders: number;
        failed_orders: number;
        total_revenue: number;
        total_gold_sold: number;
        today_orders: number;
        today_revenue: number;
    };
}

export default function OrderPage() {
    const { orders, filters: serverFilters, servers, bots, stats } = usePage<GoldOrderPageProps>().props;

    const toast = useToast();

    // 🎯 Table filters hook
    const {
        filters,
        columnFilters,
        loading,
        handleSearch,
        handleResetFilters,
        handlePageChange,
        setColumnFilters,
    } = useTableFilters({
        routeName: 'admin.orders.index',
        initialFilters: serverFilters || {},
        initialData: orders,
        debounceMs: 500,
    });

    const currentFilters = filters as GoldOrderFilters;

    // Modal states
    const [refundModalOpen, setRefundModalOpen] = useState(false);
    const [statusModalOpen, setStatusModalOpen] = useState(false);
    const [selectedOrder, setSelectedOrder] = useState<IGoldTransaction | null>(null);
    const [selectedOrders, setSelectedOrders] = useState<number[]>([]);

    // Handle refund
    const handleRefund = (order: IGoldTransaction) => {
        setSelectedOrder(order);
        setRefundModalOpen(true);
    };

    // Handle status update
    const handleStatusUpdate = (order: IGoldTransaction) => {
        setSelectedOrder(order);
        setStatusModalOpen(true);
    };

    const handleQuickComplete = (order: IGoldTransaction) => {
        if (!order.can_complete) return;
        router.put(`/admin/orders/${order.id}/status`, { status: 'completed' }, {
            onSuccess: () => toast.success(`Đơn hàng #${order.id} đã hoàn thành!`),
            onError: () => toast.error('Không thể hoàn thành đơn hàng!'),
        });
    };

    const handleQuickCancel = (order: IGoldTransaction) => {
        if (!order.can_cancel) return;

        handleRefund(order);
    };

    const handleQuickProcess = (order: IGoldTransaction) => {
        if (!order.can_process) return;
        router.put(`/admin/orders/${order.id}/status`, { status: 'processing' }, {
            onSuccess: () => toast.success(`Đơn hàng #${order.id} đang được xử lý!`),
            onError: () => toast.error('Không thể xử lý đơn hàng!'),
        });
    };

    // View detail
    const handleView = (order: IGoldTransaction) => {
        router.visit(`/admin/orders/${order.id}`);
    };

    // Bulk actions
    const handleBulkAction = (action: string) => {
        if (selectedOrders.length === 0) {
            toast.warning('Vui lòng chọn ít nhất một đơn hàng!');
            return;
        }

        const cancelReason = action === 'cancelled'
            ? prompt('Lý do hủy các đơn đã chọn:')
            : null;
        if (action === 'cancelled' && !cancelReason) return;

        router.post('/admin/orders/bulk-update-status', {
            order_ids: selectedOrders,
            status: action,
            cancel_reason: cancelReason,
        }, {
            onSuccess: () => {
                toast.success(`Đã cập nhật ${selectedOrders.length} đơn hàng!`);
                setSelectedOrders([]);
            },
            onError: () => {
                toast.error('Có lỗi xảy ra!');
            }
        });
    };

    // Format functions
    const formatDate = (dateString: string): string => {
        return new Date(dateString).toLocaleDateString('vi-VN', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    };

    const formatCurrency = (amount: number): string => {
        return new Intl.NumberFormat('vi-VN', {
            style: 'currency',
            currency: 'VND',
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        }).format(amount);
    };

    const formatNumber = (number: number): string => {
        return new Intl.NumberFormat('vi-VN').format(number);
    };

    // Status config
    const getStatusConfig = (status: string) => {
        const configs: any = {
            pending: {
                color: 'warning',
                icon: Clock,
                text: 'Chờ xử lý',
                bgColor: 'bg-yellow-100',
                textColor: 'text-yellow-800',
                borderColor: 'border-yellow-300'
            },
            processing: {
                color: 'processing',
                icon: RefreshCw,
                text: 'Đang xử lý',
                bgColor: 'bg-blue-100',
                textColor: 'text-blue-800',
                borderColor: 'border-blue-300'
            },
            completed: {
                color: 'success',
                icon: CheckCircle,
                text: 'Hoàn thành',
                bgColor: 'bg-green-100',
                textColor: 'text-green-800',
                borderColor: 'border-green-300'
            },
            cancelled: {
                color: 'error',
                icon: XCircle,
                text: 'Đã hủy',
                bgColor: 'bg-red-100',
                textColor: 'text-red-800',
                borderColor: 'border-red-300'
            },
            failed: {
                color: 'error',
                icon: AlertCircle,
                text: 'Thất bại',
                bgColor: 'bg-red-100',
                textColor: 'text-red-800',
                borderColor: 'border-red-300'
            }
        };
        return configs[status] || configs.pending;
    };

    const renderGoldInfo = (order: IGoldTransaction) => {
        return (
            <div className="text-right">
                <div className="font-semibold text-yellow-600">
                    <Coins className="w-3 h-3 inline mr-1" />
                    {order.gold_qty_formatted} Tổng
                </div>
                {order.gold_bar_qty > 0 && (
                    <div className="text-xs text-orange-600">
                        {order.gold_bar_qty_formatted} thỏi vàng
                    </div>
                )}
                {order.pure_gold_qty > 0 && (
                    <div className="text-xs text-yellow-500">
                        {order.pure_gold_qty_formatted} vàng Tươi
                    </div>
                )}
            </div>
        );
    };

    // Define columns
    const columns: Column<IGoldTransaction>[] = useMemo(() => [
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
            key: 'user',
            title: 'Khách hàng',
            width: 200,
            render: (user: any, record: IGoldTransaction) => (
                <div className="flex items-center gap-2">
                    <div className="w-8 h-8 bg-gradient-to-br from-blue-100 to-purple-100 rounded-full flex items-center justify-center">
                        <User className="w-4 h-4 text-blue-600" />
                    </div>
                    <div>
                        <div className="font-medium text-gray-900">{user?.username || 'N/A'}</div>
                        <div className="text-xs text-purple-600 font-medium">{record.character_name}</div>
                    </div>
                </div>
            )
        },
        {
            key: 'server',
            title: 'Server',
            width: 120,
            render: (server: any) => (
                <div className="flex items-center gap-1">
                    <Server className="w-3 h-3 text-gray-500" />
                    <span className="text-sm">{server?.name || 'N/A'}</span>
                </div>
            )
        },
        {
            key: 'bot',
            title: 'Bot xử lý',
            width: 120,
            render: (bot: any) => (
                <div className="flex items-center gap-1">
                    {bot?.name ? (
                        <>
                            <Bot className="w-3 h-3 text-blue-500" />
                            <span className="text-sm text-blue-600">{bot.name}</span>
                        </>
                    ) : (
                        <span className="text-xs text-gray-400 italic">Chưa chỉ định</span>
                    )}
                </div>
            )
        },
        {
            key: 'amount_vnd_formatted',
            title: 'Số tiền',
            width: 150,
            align: 'right',
            sortable: true,
            render: (amount: string, record: IGoldTransaction) => (
                <div className="text-right">
                    <div className="font-semibold text-green-600">{amount}</div>
                    <div className="text-xs text-gray-500">
                        giá thời điểm mua :  {record.price_formatted}
                    </div>
                </div>
            )
        },
        {
            key: 'gold_qty',
            title: 'Số lượng vàng',
            width: 150,
            align: 'right',
            render: (gold_qty: number, record: IGoldTransaction) => renderGoldInfo(record)
        },
        {
            key: 'status',
            title: 'Trạng thái',
            width: 140,
            align: 'center',
            filters: [
                { text: 'Chờ xử lý', value: 'pending' },
                { text: 'Đang xử lý', value: 'processing' },
                { text: 'Hoàn thành', value: 'completed' },
                { text: 'Đã hủy', value: 'cancelled' },
                { text: 'Thất bại', value: 'failed' },
            ],
            render: (status: string, record: IGoldTransaction) => {
                const config = getStatusConfig(status);
                const Icon = config.icon;

                return (
                    <div className={`inline-flex items-center px-3 py-1 rounded-full text-xs font-medium ${config.bgColor} ${config.textColor} border ${config.borderColor}`}>
                        <Icon className="w-3 h-3 mr-1" />
                        {config.text}
                    </div>
                );
            }
        },
        {
            key: 'created_at',
            title: 'Ngày tạo',
            width: 150,
            sortable: true,
            render: (date: string, record: IGoldTransaction) => (
                <div className="text-sm">
                    <div className="text-gray-900">{formatDate(date)}</div>
                    <div className="text-xs text-gray-500">{record.created_at_human}</div>
                </div>
            )
        },
        {
            key: 'actions',
            title: 'Thao tác',
            width: 200,
            fixed: 'right',
            align: 'center',
            render: (_, record: IGoldTransaction) => {
                const menu = (
                    <Menu>
                        <Menu.Item key="view" icon={<Eye />} onClick={() => handleView(record)}>
                            Xem chi tiết
                        </Menu.Item>

                        {record.can_process && (
                            <Menu.Item key="process" icon={<RefreshCw />} onClick={() => handleQuickProcess(record)}>
                                Bắt đầu xử lý
                            </Menu.Item>
                        )}

                        {record.can_complete && (
                            <Menu.Item key="complete" icon={<Check />} onClick={() => handleQuickComplete(record)}>
                                Hoàn thành
                            </Menu.Item>
                        )}

                        {record.can_refund && (
                            <Menu.Item key="refund" icon={<RotateCcw />} onClick={() => handleRefund(record)}>
                                Hoàn tiền
                            </Menu.Item>
                        )}

                        {record.can_cancel && (
                            <Menu.Item key="cancel" icon={<Ban />} onClick={() => handleQuickCancel(record)}>
                                Hủy đơn
                            </Menu.Item>
                        )}

                        <Menu.Divider />

                        <Menu.Item key="status" icon={<RefreshCw />} onClick={() => handleStatusUpdate(record)}>
                            Cập nhật trạng thái
                        </Menu.Item>
                    </Menu>
                );

                return (
                    <Space>
                        {record.can_refund && (
                            <Button
                                type="primary"
                                size="small"
                                danger
                                icon={<RotateCcw className="w-3 h-3" />}
                                onClick={() => handleRefund(record)}
                            >
                                Hoàn tiền
                            </Button>
                        )}

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
            key: 'server_id',
            type: 'select' as const,
            label: 'Server',
            options: [
                ...servers.map(server => ({
                    label: server.name,
                    value: server.id.toString()
                }))
            ],
            value: currentFilters.server_id?.toString() || ''
        },
        {
            key: 'bot_id',
            type: 'select' as const,
            label: 'Bot xử lý',
            options: [
                ...bots.map(bot => ({
                    label: bot.name || bot.account_name || `Bot #${bot.id}`, // ✅ Fix
                    value: bot.id.toString()
                }))
            ],
            value: currentFilters.bot_id?.toString() || ''
        },
        {
            key: 'status',
            type: 'select' as const,
            label: 'Trạng thái',
            options: [
                { label: 'Chờ xử lý', value: 'pending' },
                { label: 'Đang xử lý', value: 'processing' },
                { label: 'Hoàn thành', value: 'completed' },
                { label: 'Đã hủy', value: 'cancelled' },
                { label: 'Thất bại', value: 'failed' },
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
    ], [servers, bots, currentFilters]);

    return (
        <>
            {/* Statistics Cards */}
            <div className="mb-6">
                <Row gutter={16}>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-blue-500">
                            <Statistic
                                title="Tổng đơn hàng"
                                value={stats.total_orders}
                                prefix={<ShoppingCart className="w-5 h-5 text-blue-500" />}
                            />
                            <div className="text-xs text-gray-500 mt-2">
                                Hôm nay: {stats.today_orders}
                            </div>
                        </Card>
                    </Col>

                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-green-500">
                            <Statistic
                                title="Doanh thu"
                                value={stats.total_revenue}
                                formatter={(value) => formatCurrency(Number(value))}
                                prefix={<DollarSign className="w-5 h-5 text-green-500" />}
                            />
                            <div className="text-xs text-gray-500 mt-2">
                                Hôm nay: {formatCurrency(stats.today_revenue)}
                            </div>
                        </Card>
                    </Col>

                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-yellow-500">
                            <Statistic
                                title="Tổng vàng bán"
                                value={stats.total_gold_sold}
                                formatter={(value) => `${formatNumber(Number(value))}`}
                                prefix={<Coins className="w-5 h-5 text-yellow-500" />}
                            />
                        </Card>
                    </Col>

                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-purple-500">
                            <div className="space-y-2">
                                <div className="flex justify-between">
                                    <span className="text-yellow-600">Chờ xử lý:</span>
                                    <Badge count={stats.pending_orders} showZero style={{ backgroundColor: '#faad14' }} />
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-blue-600">Đang xử lý:</span>
                                    <Badge count={stats.processing_orders} showZero style={{ backgroundColor: '#1890ff' }} />
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-green-600">Hoàn thành:</span>
                                    <Badge count={stats.completed_orders} showZero style={{ backgroundColor: '#52c41a' }} />
                                </div>
                            </div>
                        </Card>
                    </Col>
                </Row>
            </div>

            {/* Data Table */}
            <DataTable<IGoldTransaction>
                data={orders.data}
                columns={columns}
                loading={loading}
                searchValue={currentFilters.search || ''}
                searchPreset="goldOrders"
                title="Quản lý Đơn hàng Bán vàng"
                description="Danh sách tất cả đơn hàng bán vàng"
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
                filters={filterOptions}
                searchPlaceholder="Tìm theo tên nhân vật, khách hàng..."
                emptyText="Chưa có đơn hàng nào"
                emptyDescription="Các đơn hàng sẽ xuất hiện ở đây"
                rowSelection={{
                    selectedRowKeys: selectedOrders,
                    onChange: (keys) => setSelectedOrders(keys as number[])
                }}
                customActions={
                    selectedOrders.length > 0 ? {
                        bulkProcessing: {
                            label: 'Chuyển sang Đang xử lý',
                            icon: RefreshCw,
                            handler: () => handleBulkAction('processing'),
                            className: 'text-blue-600 hover:text-blue-800'
                        },
                        bulkComplete: {
                            label: 'Hoàn thành',
                            icon: CheckCircle,
                            handler: () => handleBulkAction('completed'),
                            className: 'text-green-600 hover:text-green-800'
                        },
                        bulkCancel: {
                            label: 'Hủy',
                            icon: XCircle,
                            handler: () => handleBulkAction('cancelled'),
                            className: 'text-red-600 hover:text-red-800'
                        }
                    } : undefined
                }
            />

            {selectedOrder && (
                    <RefundModal
                        open={refundModalOpen}
                        onClose={() => {
                            setRefundModalOpen(false);
                            setSelectedOrder(null);
                        }}
                        order={selectedOrder}
                    />
            )}
        </>
    );
}

OrderPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Gold Order Management" children={page} />
);
