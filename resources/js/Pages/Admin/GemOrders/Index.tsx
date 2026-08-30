import React, { useState, useMemo } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { PageProps, PaginatedData } from "@/types";
import RefundModal from "./RefundModal";
import StatusUpdateModal from "./StatusUpdateModal";
import CancelOrderModal from './CancelOrderModal';
import { Column, DataTable } from "@/Components/Table/DataTable";
import {
    Eye, CheckCircle, XCircle, Clock, Server, User, Gem, DollarSign,
    RefreshCw, Ban, Check, RotateCcw, Package
} from 'lucide-react';
import { Alert, Badge, Statistic, Card, Row, Col, Button, Dropdown, Menu, Space } from 'antd';
import { useToast } from "@/Components/ToastProvider";
import { useTableFilters } from "@/Hooks/useTableFilters";
import { IGemOrder } from '@/InterFaces/gemorder';
import { IServer } from '@/InterFaces/server';

interface GemOrderFilters {
    search?: string;
    server_id?: number;
    status?: string;
    date_from?: string;
    date_to?: string;
}

interface GemOrderPageProps extends PageProps {
    orders: PaginatedData<IGemOrder>;
    servers: IServer[];
    filters: GemOrderFilters;
    stats: {
        total_orders: number;
        pending_orders: number;
        processing_orders: number;
        completed_orders: number;
        cancelled_orders: number;
        refunded_orders: number;
        total_revenue: number;
        total_gems_sold: number;
        today_orders: number;
        today_revenue: number;
    };
}

export default function GemOrderPage() {
    const { orders, filters: serverFilters, servers, stats } = usePage<GemOrderPageProps>().props;

    const toast = useToast();

    const {
        filters,
        columnFilters,
        loading,
        handleSearch,
        handleResetFilters,
        handlePageChange,
        setColumnFilters,
    } = useTableFilters({
        routeName: 'admin.gem-orders.index',
        initialFilters: serverFilters || {},
        initialData: orders,
        debounceMs: 500,
    });

    const currentFilters = filters as GemOrderFilters;

    const [refundModalOpen, setRefundModalOpen] = useState(false);
    const [statusModalOpen, setStatusModalOpen] = useState(false);
    const [selectedOrder, setSelectedOrder] = useState<IGemOrder | null>(null);
    const [selectedOrders, setSelectedOrders] = useState<number[]>([]);
    const [cancelOrder, setCancelOrder] = useState<IGemOrder | null>(null);
    const [bulkCancelModalOpen, setBulkCancelModalOpen] = useState(false);

    const handleRefund = (order: IGemOrder) => {
        setSelectedOrder(order);
        setRefundModalOpen(true);
    };

    const handleStatusUpdate = (order: IGemOrder) => {
        setSelectedOrder(order);
        setStatusModalOpen(true);
    };

    const handleQuickComplete = (order: IGemOrder) => {
        router.patch(`/admin/gem-orders/${order.id}/status`, {
            action: 'complete',
        }, {
            onSuccess: () => toast.success(`Đơn hàng #${order.id} đã được hoàn thành!`),
            onError: () => toast.error('Không thể hoàn thành đơn hàng!'),
        });
    };
    const handleQuickCancel = (order: IGemOrder) => {
        setCancelOrder(order);
    };

    const handleView = (order: IGemOrder) => {
        router.visit(`/admin/gem-orders/${order.id}`);
    };

    const handleBulkAction = (action: string) => {
        if (selectedOrders.length === 0) {
            toast.warning('Vui lòng chọn ít nhất một đơn hàng!');
            return;
        }

        if (action === 'cancelled') {
            setBulkCancelModalOpen(true);
            return;
        }

        router.post('/admin/gem-orders/bulk-update-status', {
            order_ids: selectedOrders,
            status: action,
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
            refunded: {
                color: 'purple',
                icon: RotateCcw,
                text: 'Đã hoàn tiền',
                bgColor: 'bg-purple-100',
                textColor: 'text-purple-800',
                borderColor: 'border-purple-300'
            }
        };
        return configs[status] || configs.pending;
    };

    const columns: Column<IGemOrder>[] = useMemo(() => [
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
            render: (user: IGemOrder['user']) => (
                <div className="flex items-center gap-2">
                    <div className="w-8 h-8 bg-gradient-to-br from-blue-100 to-purple-100 rounded-full flex items-center justify-center">
                        <User className="w-4 h-4 text-blue-600" />
                    </div>
                    <div>
                        <div className="font-medium text-gray-900">{user?.username || 'N/A'}</div>
                        <div className="text-xs text-gray-500">{user?.email}</div>
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
            key: 'character_name',
            title: 'Nhân vật',
            width: 150,
            render: (name: string) => (
                <span className="font-medium text-purple-600">{name}</span>
            )
        },
        {
            key: 'amount_vnd_formatted',
            title: 'Số tiền',
            width: 150,
            align: 'right',
            sortable: true,
            render: (amount: string, record: IGemOrder) => (
                <div className="text-right">
                    <div className="font-semibold text-green-600">{amount}</div>
                    <div className="text-xs text-gray-500">
                        <Gem className="w-3 h-3 inline mr-1" />
                        {record.gem_qty_formatted} ngọc
                    </div>
                </div>
            )
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
                { text: 'Đã hoàn tiền', value: 'refunded' },
            ],
            render: (status: string, record: IGemOrder) => {
                const config = getStatusConfig(status);
                const Icon = config.icon;

                return (
                    <div className="space-y-1 text-center">
                        <div className={`inline-flex items-center px-3 py-1 rounded-full text-xs font-medium ${config.bgColor} ${config.textColor} border ${config.borderColor}`}>
                            <Icon className="w-3 h-3 mr-1" />
                            {config.text}
                        </div>
                        {record.is_timeout_cancellation && (
                            <div className="text-[11px] text-orange-600">Đang chờ job hoàn tiền</div>
                        )}
                    </div>
                );
            }
        },
        {
            key: 'created_at',
            title: 'Ngày tạo',
            width: 150,
            sortable: true,
            render: (date: string, record: IGemOrder) => (
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
            render: (_, record: IGemOrder) => {
                const menu = (
                    <Menu>
                        <Menu.Item key="view" icon={<Eye />} onClick={() => handleView(record)}>
                            Xem chi tiết
                        </Menu.Item>

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

                        {record.can_update_status && (
                            <Menu.Item key="status" icon={<RefreshCw />} onClick={() => handleStatusUpdate(record)}>
                                Cập nhật trạng thái
                            </Menu.Item>
                        )}
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
            key: 'status',
            type: 'select' as const,
            label: 'Trạng thái',
            options: [
                { label: 'Chờ xử lý', value: 'pending' },
                { label: 'Đang xử lý', value: 'processing' },
                { label: 'Hoàn thành', value: 'completed' },
                { label: 'Đã hủy', value: 'cancelled' },
                { label: 'Đã hoàn tiền', value: 'refunded' },
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
    ], [servers, currentFilters]);

    return (
        <>
            <Alert
                className="mb-4"
                type="info"
                showIcon
                message="Job tự động chỉ xử lý đơn ngọc đang chờ"
                description="Đơn pending quá hạn sẽ chuyển sang Đã hủy, chờ thêm thời gian an toàn rồi mới hoàn tiền. Đơn processing không bị job thay đổi; admin phải dùng nút Hoàn tiền nếu cần xử lý."
            />

            <div className="mb-6">
                <Row gutter={16}>
                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-blue-500">
                            <Statistic
                                title="Tổng đơn hàng"
                                value={stats.total_orders}
                                prefix={<Package className="w-5 h-5 text-blue-500" />}
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
                        <Card className="border-l-4 border-purple-500">
                            <Statistic
                                title="Tổng ngọc bán"
                                value={stats.total_gems_sold}
                                formatter={(value) => `${Number(value).toLocaleString()}`}
                                prefix={<Gem className="w-5 h-5 text-purple-500" />}
                            />
                        </Card>
                    </Col>

                    <Col xs={24} sm={12} md={6}>
                        <Card className="border-l-4 border-yellow-500">
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
                                <div className="flex justify-between">
                                    <span className="text-purple-600">Đã hoàn tiền:</span>
                                    <Badge count={stats.refunded_orders} showZero style={{ backgroundColor: '#722ed1' }} />
                                </div>
                            </div>
                        </Card>
                    </Col>
                </Row>
            </div>

            <DataTable<IGemOrder>
                data={orders.data}
                columns={columns}
                loading={loading}
                searchValue={currentFilters.search || ''}
                title="Quản lý Đơn hàng Ngọc"
                description="Theo dõi trạng thái và hoàn tiền thủ công cho mọi đơn chưa được hoàn"
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
                <>
                    <RefundModal
                        open={refundModalOpen}
                        onClose={() => {
                            setRefundModalOpen(false);
                            setSelectedOrder(null);
                        }}
                        order={selectedOrder}
                    />

                    <StatusUpdateModal
                        open={statusModalOpen}
                        onClose={() => {
                            setStatusModalOpen(false);
                            setSelectedOrder(null);
                        }}
                        order={selectedOrder}
                    />
                </>
            )}

            <CancelOrderModal
                open={cancelOrder !== null}
                order={cancelOrder}
                onClose={() => setCancelOrder(null)}
            />

            <CancelOrderModal
                open={bulkCancelModalOpen}
                orderIds={selectedOrders}
                onClose={() => setBulkCancelModalOpen(false)}
                onSuccess={() => setSelectedOrders([])}
            />
        </>
    );
}

GemOrderPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Gem Order Management" children={page} />
);
