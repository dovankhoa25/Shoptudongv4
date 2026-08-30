// Admin/ServiceOrders/Index.tsx - Service Orders Management
import React, { useState } from 'react';
import { router, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { PageProps, PaginatedData } from "@/types";
import { Eye } from 'lucide-react';
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
    description: string;
    field_values_json: any;
    status: string;
    user: IUser;
    receiver: IUser;
    service: IService;
    created_at: string;
    updated_at: string;
}

export default function ServiceOrdersPage() {
    const { service_orders, filters: serverFilters, flash } = usePage<
        PageProps & {
            service_orders: PaginatedData<IServiceOrder>;
            filters: {
                search?: string;
                status?: string;
            };
            flash: {
                success?: string;
                error?: string;
                info?: string;
            }
        }
    >().props;

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

    const handleAccept = (order: IServiceOrder) => {
        router.post(`/admin/services/orders/${order.id}/accept`, {}, {
            onSuccess: () => {
                toast.success(`Đã nhận đơn hàng #${order.id}!`);
            },
            onError: () => {
                toast.error('Nhận đơn hàng thất bại. Vui lòng thử lại!');
            }
        });
    };

    return (
        <>
            {/* 🎯 Shared ServiceOrdersTable */}
            <ServiceOrdersTable
                data={service_orders}
                filters={serverFilters}
                flash={flash}
                title="Quản lý đơn hàng dịch vụ"
                description="Danh sách tất cả các đơn hàng dịch vụ trong hệ thống"
                routeName="admin.services.orders.index"
                showPassword={false} // No password display for admin orders
                onView={handleView}
                customActions={{
                    accept: {
                        label: 'Nhận Đơn',
                        icon: Eye,
                        handler: handleAccept,
                        className: 'text-green-600 hover:text-green-800',
                        condition: (order: IServiceOrder) => order.status === 'pending'
                    },
                }}
            />

            {/* Service Order Detail Modal */}
            <ServiceOrderDetailModal
                open={viewModalVisible}
                order={selectedOrder}
                onClose={handleCloseModal}
                onAccept={handleAccept}
                showPassword={false} // No password display for admin modal
            />
        </>
    );
}

ServiceOrdersPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Service Orders Management" children={page} />
);