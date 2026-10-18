import { useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import { Button, Alert } from 'antd';
import { ArrowLeft, Clock, Gem, RefreshCw, RotateCcw, Server, User } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import type { IGemOrder } from '@/InterFaces/gemorder';
import type { PageProps } from '@/types';
import RefundModal from './RefundModal';
import StatusUpdateModal from './StatusUpdateModal';
import GemDeliveryProgress from '@/Components/GemDeliveryProgress';

interface Props extends PageProps {
    order: IGemOrder | { data: IGemOrder };
    relatedOrders?: unknown;
}

export default function GemOrderShow() {
    const rawOrder = usePage<Props>().props.order;
    const order = 'data' in rawOrder ? rawOrder.data : rawOrder;
    const [refundModalOpen, setRefundModalOpen] = useState(false);
    const [statusModalOpen, setStatusModalOpen] = useState(false);
    const rows = [
        ['Khách hàng', `${order.user.username} (${order.user.email})`, User],
        ['Server', order.server.name, Server],
        ['Nhân vật', order.character_name, User],
        ['Tiền thực trừ', order.amount_vnd_formatted, Gem],
        ['Ngọc của đơn', `${order.gem_qty_formatted} ngọc`, Gem],
        ['Cách nhận ngọc', order.delivery_method_label ?? (order.delivery_method === 'gift_ticket' ? 'Vé tặng ngọc' : 'Đồ ký gửi'), Gem],
        ['Bot xử lý', order.bot_id ? `Bot #${order.bot_id}` : 'Chưa chọn bot', RefreshCw],
        ['Hệ số giao dịch', order.multiplier_display ?? `x${order.price_at_transaction}`, Gem],
        ['Nguồn cập nhật', order.updated_by === 'app' ? 'Ứng dụng bot' : 'Website/Admin', RefreshCw],
        ['Cập nhật gần nhất', order.last_synced_at ?? 'Chưa đồng bộ', Clock],
        ['Bắt đầu hủy quá hạn', order.cancel_requested_at ?? 'Không có', Clock],
        ['Thời điểm hoàn tiền', order.refunded_at ?? 'Chưa hoàn tiền', RotateCcw],
    ] as const;

    return (
        <>
            <Head title={`Chi tiết đơn ngọc #${order.id}`} />
            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <Link href="/admin/gem-orders" className="mb-2 inline-flex items-center gap-2 text-sm text-blue-600 hover:text-blue-800">
                            <ArrowLeft className="h-4 w-4" /> Quay lại danh sách
                        </Link>
                        <h1 className="text-2xl font-bold text-gray-900 dark:text-white">Chi tiết đơn ngọc #{order.id}</h1>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="rounded-full bg-purple-100 px-3 py-1 text-sm font-semibold text-purple-700">
                            {order.status_label}
                        </span>
                        {order.can_update_status && (
                            <Button icon={<RefreshCw className="h-4 w-4" />} onClick={() => setStatusModalOpen(true)}>
                                Đổi trạng thái
                            </Button>
                        )}
                        {order.can_refund && (
                            <Button danger type="primary" icon={<RotateCcw className="h-4 w-4" />} onClick={() => setRefundModalOpen(true)}>
                                Hoàn tiền
                            </Button>
                        )}
                    </div>
                </div>

                {order.status === 'processing' && (
                    <Alert
                        type="warning"
                        showIcon
                        message="Đơn đang được bot xử lý"
                        description={order.has_delivery_checkpoint
                            ? 'Tool lưu tiến độ và tự đối chiếu trước khi tiếp tục. Trang cập nhật theo kết quả tool gửi về.'
                            : 'Job tự động không hủy hoặc hoàn đơn đang xử lý. Admin cần kiểm tra kết quả trước khi hoàn tiền.'}
                    />
                )}

                {order.has_delivery_checkpoint && <GemDeliveryProgress order={order} />}

                {order.is_timeout_cancellation && (
                    <Alert
                        type="info"
                        showIcon
                        message="Đơn pending đã bị đóng do quá hạn"
                        description="Đơn đang trong thời gian chờ an toàn trước khi job tự hoàn tiền. Admin vẫn có thể hoàn ngay nếu đã xác minh."
                    />
                )}

                <div className="grid gap-4 rounded-xl bg-white p-6 shadow dark:bg-gray-800 md:grid-cols-2">
                    {rows.map(([label, value, Icon]) => (
                        <div key={label} className="flex items-start gap-3 rounded-lg border border-gray-100 p-4 dark:border-gray-700">
                            <Icon className="mt-0.5 h-5 w-5 text-purple-500" />
                            <div>
                                <p className="text-xs uppercase tracking-wide text-gray-500">{label}</p>
                                <p className="mt-1 font-medium text-gray-900 dark:text-white">{value}</p>
                            </div>
                        </div>
                    ))}
                </div>

                <RefundModal
                    open={refundModalOpen}
                    onClose={() => setRefundModalOpen(false)}
                    order={order}
                />
                <StatusUpdateModal
                    open={statusModalOpen}
                    onClose={() => setStatusModalOpen(false)}
                    order={order}
                />
            </div>
        </>
    );
}

GemOrderShow.layout = (page: React.ReactElement<Props>) => {
    const raw = page.props.order;
    const value = 'data' in raw ? raw.data : raw;
    return <AdminLayout title={`Chi tiết đơn ngọc #${value.id}`}>{page}</AdminLayout>;
};
