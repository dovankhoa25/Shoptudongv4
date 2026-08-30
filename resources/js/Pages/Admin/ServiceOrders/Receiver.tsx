// Admin/ServiceOrders/Receiver.tsx - Receiver Orders Page
import React, { useState, useMemo } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { PageProps, PaginatedData } from "@/types";
import { Eye, CheckCircle, XCircle } from 'lucide-react';
import ServiceOrdersTable from './Components/ServiceOrdersTable';
import ServiceOrderDetailModal from './ServiceOrderDetailModal';
import { useToast } from "@/Components/ToastProvider";

interface IUser {
    id: number;
    username: string;
}

interface IService {
    id: number;
    name: string;
    processing_time: string;
    warranty: string;
}

interface IServiceOrder {
    id: number;
    service_id: number;
    service_price: number;
    account: string;
    password?: string;
    description: string;
    field_values_json: any;
    status: string;
    user: IUser;
    receiver: IUser;
    service: IService;
    created_at: string;
    updated_at: string;
}

interface ReceiverOrderFilters {
    search?: string;
    status?: string;
    account?: string;
}

// ✅ GIỐNG NICK MANAGEMENT - extends PageProps NHƯNG KHÔNG định nghĩa lại flash
interface ReceiverOrderPageProps extends PageProps {
    service_orders: PaginatedData<IServiceOrder>;
    filters: ReceiverOrderFilters;
    // ❌ KHÔNG thêm flash vào đây vì đã có trong PageProps
}

export default function ReceiverServiceOrdersPage() {
    // ✅ SỬ DỤNG ReceiverOrderPageProps - GIỐNG NICK MANAGEMENT
    const {
        service_orders,
        filters: serverFilters,
        flash
    } = usePage<ReceiverOrderPageProps>().props;

    const toast = useToast();
    const [selectedOrder, setSelectedOrder] = useState<IServiceOrder | null>(null);
    const [viewModalVisible, setViewModalVisible] = useState(false);

    // Handlers
    const handleView = (order: IServiceOrder) => {
        setSelectedOrder(order);
        setViewModalVisible(true);
    };

    const handleCloseModal = () => {
        setViewModalVisible(false);
        setSelectedOrder(null);
    };

    const handleCompleted = (order: IServiceOrder) => {
        if (confirm(`Bạn có chắc chắn muốn xác nhận hoàn thành đơn hàng #${order.id}?`)) {
            router.put(`/admin/services/orders/${order.id}/receiver-complete`, {}, {
                onSuccess: () => {
                    toast.success(`Đã xác nhận hoàn thành đơn hàng #${order.id}!`);
                },
                onError: () => {
                    toast.error('Xác nhận hoàn thành thất bại. Vui lòng thử lại!');
                }
            });
        }
    };

    const handleCancel = (order: IServiceOrder) => {
        const reason = prompt('Vui lòng nhập lý do hủy đơn hàng:');
        if (reason && reason.trim()) {
            router.put(`/admin/services/orders/${order.id}/receiver-cancel`, {
                cancel_reason: reason.trim()
            }, {
                onSuccess: () => {
                    toast.success(`Đã hủy đơn hàng #${order.id}!`);
                },
                onError: () => {
                    toast.error('Hủy đơn hàng thất bại. Vui lòng thử lại!');
                }
            });
        }
    };

    // 🎯 Filter options - GIỐNG NICK MANAGEMENT
    const filterOptions = useMemo(() => [
        {
            key: 'status',
            type: 'select' as const,
            label: 'Trạng thái',
            options: [
                { label: 'Đang xử lý', value: 'approved' },
                { label: 'Đang tiến hành', value: 'processing' },
                { label: 'Hoàn thành', value: 'completed' },
                { label: 'Thất bại', value: 'failed' },
                { label: 'Đã hủy', value: 'cancelled' }
            ],
            value: serverFilters.status || ''
        },
        {
            key: 'account',
            type: 'input' as const,
            label: 'Tài khoản',
            placeholder: 'Nhập tài khoản...',
            value: serverFilters.account || ''
        }
    ], [serverFilters]);

    return (
        <>
            {/* 🎯 Shared ServiceOrdersTable */}
            <ServiceOrdersTable
                data={service_orders}
                filters={serverFilters}
                title="Quản lý đơn hàng người nhận"
                description="Danh sách đơn hàng dành cho người nhận (có thể xác nhận hoàn thành hoặc hủy)"
                routeName="admin.services.orders.receiver"
                showPassword={true}
                onView={handleView}
                customActions={{
                    view: {
                        label: 'Xem chi tiết',
                        icon: Eye,
                        handler: handleView,
                        className: 'text-blue-600 hover:text-blue-800'
                    },
                    completed: {
                        label: 'Xác nhận hoàn thành',
                        icon: CheckCircle,
                        handler: handleCompleted,
                        className: 'text-green-600 hover:text-green-800',
                    },
                    cancel: {
                        label: 'Xác nhận Hủy đơn hàng',
                        icon: XCircle,
                        handler: handleCancel,
                        className: 'text-red-600 hover:text-red-800',
                    }
                }}
                filterOptions={filterOptions}
                searchPlaceholder="Tìm theo ID, tài khoản, người dùng, dịch vụ..."
            />

            {/* Service Order Detail Modal */}
            <ServiceOrderDetailModal
                open={viewModalVisible}
                order={selectedOrder}
                onClose={handleCloseModal}
                onCompleted={handleCompleted}
                onCancel={handleCancel}
                showPassword={true}
            />
        </>
    );
}

ReceiverServiceOrdersPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Receiver Service Orders" children={page} />
);