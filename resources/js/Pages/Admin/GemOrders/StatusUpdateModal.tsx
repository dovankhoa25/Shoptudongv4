import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Alert, Button, Descriptions, Form, Input, Modal, Select, Space, message } from 'antd';
import { RefreshCw } from 'lucide-react';
import { IGemOrder } from '@/InterFaces/gemorder';

interface StatusUpdateModalProps {
    open: boolean;
    onClose: () => void;
    order: IGemOrder;
}

export default function StatusUpdateModal({ open, onClose, order }: StatusUpdateModalProps) {
    const [form] = Form.useForm();
    const [loading, setLoading] = useState(false);
    const selectedStatus = Form.useWatch('status', form);

    const getValidStatuses = () => {
        const transitions: Record<string, string[]> = {
            'pending': ['processing', 'cancelled'],
            'processing': ['completed', 'cancelled'],
            'completed': [],
            'cancelled': ['pending', 'processing'],
            'refunded': [],
        };

        return transitions[order.status] || [];
    };

    const validStatuses = getValidStatuses();

    const handleSubmit = (values: StatusFormValues) => {
        setLoading(true);

        router.patch(`/admin/gem-orders/${order.id}/status`, {
            action: 'set_status',
            status: values.status,
            note: values.note,
            cancel_reason: values.status === 'cancelled' ? values.note : undefined,
        }, {
            onSuccess: () => {
                message.success('Cập nhật trạng thái thành công!');
                form.resetFields();
                onClose();
            },
            onError: (errors: Record<string, string>) => {
                message.error(Object.values(errors)[0] || 'Không thể cập nhật trạng thái đơn ngọc.');
            },
            onFinish: () => {
                setLoading(false);
            }
        });
    };

    const handleClose = () => {
        if (loading) return;
        form.resetFields();
        onClose();
    };

    return (
        <Modal
            title={
                <Space>
                    <RefreshCw className="w-5 h-5 text-blue-500" />
                    <span>Cập nhật trạng thái đơn #{order.id}</span>
                </Space>
            }
            open={open}
            onCancel={handleClose}
            footer={null}
            width={560}
            closable={!loading}
            maskClosable={!loading}
            destroyOnHidden
        >
            <Descriptions bordered size="small" column={1} className="mb-4">
                <Descriptions.Item label="Nhân vật">{order.character_name}</Descriptions.Item>
                <Descriptions.Item label="Server">{order.server.name}</Descriptions.Item>
                <Descriptions.Item label="Trạng thái hiện tại">{order.status_label}</Descriptions.Item>
            </Descriptions>

            {validStatuses.length === 0 ? (
                <Alert
                    message="Không thể thay đổi trạng thái"
                    description="Đơn hàng này không thể chuyển sang trạng thái khác."
                    type="warning"
                    showIcon
                />
            ) : (
                <Form
                    form={form}
                    layout="vertical"
                    onFinish={handleSubmit}
                    preserve={false}
                >
                    <Form.Item
                        label="Trạng thái mới"
                        name="status"
                        rules={[{ required: true, message: 'Vui lòng chọn trạng thái!' }]}
                    >
                        <Select size="large" placeholder="Chọn trạng thái">
                            {validStatuses.includes('processing') && (
                                <Select.Option value="processing">Đang xử lý</Select.Option>
                            )}
                            {validStatuses.includes('pending') && (
                                <Select.Option value="pending">Chờ xử lý</Select.Option>
                            )}
                            {validStatuses.includes('completed') && (
                                <Select.Option value="completed">Hoàn thành</Select.Option>
                            )}
                            {validStatuses.includes('cancelled') && (
                                <Select.Option value="cancelled">Đã hủy</Select.Option>
                            )}
                        </Select>
                    </Form.Item>

                    <Form.Item
                        label={selectedStatus === 'cancelled' ? 'Lý do hủy' : 'Ghi chú (tùy chọn)'}
                        name="note"
                        rules={[{
                            required: selectedStatus === 'cancelled',
                            message: 'Vui lòng nhập lý do hủy đơn!',
                        }]}
                    >
                        <Input.TextArea
                            rows={3}
                            placeholder="Nhập ghi chú..."
                            maxLength={500}
                        />
                    </Form.Item>

                    <Form.Item className="mb-0">
                        <div className="flex gap-3 justify-end">
                            <Button size="large" onClick={handleClose} disabled={loading}>
                                Đóng
                            </Button>
                            <Button
                                type="primary"
                                size="large"
                                htmlType="submit"
                                loading={loading}
                                icon={<RefreshCw className="w-4 h-4" />}
                            >
                                Cập nhật
                            </Button>
                        </div>
                    </Form.Item>
                </Form>
            )}
        </Modal>
    );
}

interface StatusFormValues {
    status: 'pending' | 'processing' | 'completed' | 'cancelled';
    note?: string;
}
