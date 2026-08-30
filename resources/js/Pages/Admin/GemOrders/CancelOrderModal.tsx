import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Alert, Button, Descriptions, Form, Input, Modal, Space, message } from 'antd';
import { Ban } from 'lucide-react';
import type { IGemOrder } from '@/InterFaces/gemorder';

interface CancelOrderModalProps {
    open: boolean;
    onClose: () => void;
    onSuccess?: () => void;
    order?: IGemOrder | null;
    orderIds?: number[];
}

interface CancelOrderFormValues {
    cancel_reason: string;
}

export default function CancelOrderModal({
    open,
    onClose,
    onSuccess,
    order,
    orderIds = [],
}: CancelOrderModalProps) {
    const [form] = Form.useForm<CancelOrderFormValues>();
    const [loading, setLoading] = useState(false);
    const isBulk = !order;

    const closeModal = () => {
        if (loading) return;
        form.resetFields();
        onClose();
    };

    const submit = (values: CancelOrderFormValues) => {
        setLoading(true);

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                message.success(isBulk
                    ? `Đã hủy ${orderIds.length} đơn ngọc.`
                    : `Đã hủy đơn ngọc #${order?.id}.`);
                form.resetFields();
                onSuccess?.();
                onClose();
            },
            onError: (errors: Record<string, string>) => {
                message.error(Object.values(errors)[0] || 'Không thể hủy đơn ngọc.');
            },
            onFinish: () => setLoading(false),
        };

        if (order) {
            router.patch(`/admin/gem-orders/${order.id}/status`, {
                action: 'cancel',
                cancel_reason: values.cancel_reason,
            }, options);

            return;
        }

        router.post('/admin/gem-orders/bulk-update-status', {
            order_ids: orderIds,
            status: 'cancelled',
            cancel_reason: values.cancel_reason,
        }, options);
    };

    return (
        <Modal
            open={open}
            title={
                <Space>
                    <Ban className="h-5 w-5 text-red-500" />
                    <span>{isBulk ? `Hủy ${orderIds.length} đơn ngọc` : `Hủy đơn ngọc #${order?.id}`}</span>
                </Space>
            }
            onCancel={closeModal}
            footer={null}
            width={560}
            closable={!loading}
            maskClosable={!loading}
            destroyOnHidden
        >
            {order && (
                <Descriptions bordered size="small" column={1} className="mb-4">
                    <Descriptions.Item label="Khách hàng">{order.user.username}</Descriptions.Item>
                    <Descriptions.Item label="Nhân vật">{order.character_name}</Descriptions.Item>
                    <Descriptions.Item label="Trạng thái">{order.status_label}</Descriptions.Item>
                    <Descriptions.Item label="Giá trị đơn">{order.amount_vnd_formatted}</Descriptions.Item>
                </Descriptions>
            )}

            <Alert
                type="warning"
                showIcon
                className="mb-4"
                message="Hủy đơn không đồng nghĩa với hoàn tiền"
                description="Thao tác này chỉ đổi trạng thái đơn. Nếu cần trả tiền cho khách, hãy dùng chức năng Hoàn tiền sau khi kiểm tra giao dịch."
            />

            <Form form={form} layout="vertical" onFinish={submit} preserve={false}>
                <Form.Item
                    name="cancel_reason"
                    label="Lý do hủy"
                    rules={[
                        { required: true, whitespace: true, message: 'Vui lòng nhập lý do hủy đơn.' },
                        { max: 500, message: 'Lý do không được vượt quá 500 ký tự.' },
                    ]}
                >
                    <Input.TextArea
                        autoFocus
                        rows={4}
                        maxLength={500}
                        showCount
                        placeholder="Nhập lý do để lưu vào lịch sử đơn..."
                    />
                </Form.Item>

                <Form.Item className="mb-0">
                    <div className="flex justify-end">
                        <Space>
                            <Button onClick={closeModal} disabled={loading}>Đóng</Button>
                            <Button danger type="primary" htmlType="submit" loading={loading} icon={<Ban className="h-4 w-4" />}>
                                Xác nhận hủy
                            </Button>
                        </Space>
                    </div>
                </Form.Item>
            </Form>
        </Modal>
    );
}
