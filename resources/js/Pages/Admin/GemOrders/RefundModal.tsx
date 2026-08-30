import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Alert, Button, Descriptions, Divider, Form, Input, InputNumber, Modal, Space, message } from 'antd';
import { RotateCcw, AlertTriangle } from 'lucide-react';
import { IGemOrder } from '@/InterFaces/gemorder';

interface RefundModalProps {
    open: boolean;
    onClose: () => void;
    order: IGemOrder;
}

export default function RefundModal({ open, onClose, order }: RefundModalProps) {
    const [form] = Form.useForm();
    const [loading, setLoading] = useState(false);
    const maxRefundAmount = Number(order.amount_vnd);

    const handleSubmit = (values: RefundFormValues) => {
        setLoading(true);

        router.post(`/admin/gem-orders/${order.id}/refund`, {
            refund_reason: values.refund_reason,
            refund_amount: Number(values.refund_amount ?? maxRefundAmount),
        }, {
            onSuccess: () => {
                message.success('Hoàn tiền thành công!');
                form.resetFields();
                onClose();
            },
            onError: (errors: Record<string, string>) => {
                message.error(Object.values(errors)[0] || 'Không thể hoàn tiền cho đơn ngọc.');
            },
            onFinish: () => {
                setLoading(false);
            }
        });
    };

    const handleCancel = () => {
        form.resetFields();
        onClose();
    };

    return (
        <Modal
            title={
                <div className="flex items-center gap-2">
                    <div className="w-8 h-8 bg-red-100 rounded-lg flex items-center justify-center">
                        <RotateCcw className="w-5 h-5 text-red-500" />
                    </div>
                    <span>Hoàn tiền đơn hàng #{order.id}</span>
                </div>
            }
            open={open}
            onCancel={handleCancel}
            footer={null}
            width={600}
            closable={!loading}
            maskClosable={!loading}
            destroyOnHidden
        >
            <Descriptions bordered size="small" column={{ xs: 1, sm: 2 }} className="mb-4">
                <Descriptions.Item label="Khách hàng">{order.user.username}</Descriptions.Item>
                <Descriptions.Item label="Server">{order.server.name}</Descriptions.Item>
                <Descriptions.Item label="Nhân vật">{order.character_name}</Descriptions.Item>
                <Descriptions.Item label="Trạng thái">{order.status_label}</Descriptions.Item>
                <Descriptions.Item label="Số tiền">{order.amount_vnd_formatted}</Descriptions.Item>
                <Descriptions.Item label="Số ngọc">{order.gem_qty_formatted}</Descriptions.Item>
            </Descriptions>

            <Alert
                message="Lưu ý quan trọng"
                description={`Đơn đang ở trạng thái “${order.status_label}”. Admin có thể hoàn tiền thủ công; đơn sẽ chuyển sang “Đã hoàn tiền” và không thể hoàn lần hai.`}
                type="warning"
                showIcon
                icon={<AlertTriangle className="w-4 h-4" />}
                className="mb-4"
            />

            <Form
                form={form}
                layout="vertical"
                onFinish={handleSubmit}
                preserve={false}
                initialValues={{
                    refund_amount: maxRefundAmount
                }}
            >
                <Form.Item
                    label="Số tiền hoàn"
                    name="refund_amount"
                    validateFirst
                    rules={[
                        { required: true, message: 'Vui lòng nhập số tiền hoàn!' },
                        { type: 'number', min: 1, message: 'Số tiền phải lớn hơn 0!' },
                        { type: 'number', max: maxRefundAmount, message: `Không được vượt quá ${order.amount_vnd_formatted}!` }
                    ]}
                >
                    <InputNumber<number>
                        style={{ width: '100%' }}
                        formatter={(value) => `${value}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                        parser={(value) => Number((value ?? '').replace(/,/g, ''))}
                        min={1}
                        max={maxRefundAmount}
                        precision={0}
                        addonAfter="VND"
                        size="large"
                    />
                </Form.Item>

                <Form.Item
                    label="Lý do hoàn tiền"
                    name="refund_reason"
                    rules={[
                        { required: true, message: 'Vui lòng nhập lý do hoàn tiền!' },
                        { max: 500, message: 'Lý do không được quá 500 ký tự!' }
                    ]}
                >
                    <Input.TextArea
                        rows={4}
                        placeholder="Nhập lý do hoàn tiền..."
                        showCount
                        maxLength={500}
                    />
                </Form.Item>

                <Divider />

                <Form.Item className="mb-0">
                    <div className="flex justify-end">
                        <Space>
                            <Button size="large" onClick={handleCancel} disabled={loading}>
                                Đóng
                            </Button>
                            <Button
                                type="primary"
                                danger
                                size="large"
                                htmlType="submit"
                                loading={loading}
                                icon={<RotateCcw className="w-4 h-4" />}
                            >
                                Xác nhận hoàn tiền
                            </Button>
                        </Space>
                    </div>
                </Form.Item>
            </Form>
        </Modal>
    );
}

interface RefundFormValues {
    refund_amount: number;
    refund_reason: string;
}
