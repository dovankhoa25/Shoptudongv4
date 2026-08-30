import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Alert, Button, Descriptions, Divider, Form, Input, InputNumber, Modal, Space, message } from 'antd';
import { AlertTriangle, RotateCcw } from 'lucide-react';
import type { IGoldTransaction } from '@/InterFaces/goldtransaction';

interface RefundModalProps {
    open: boolean;
    onClose: () => void;
    order: IGoldTransaction;
}

interface RefundFormValues {
    refund_amount: number;
    refund_reason: string;
}

export default function RefundModal({ open, onClose, order }: RefundModalProps) {
    const [form] = Form.useForm<RefundFormValues>();
    const [loading, setLoading] = useState(false);
    const maxRefundAmount = Number(order.amount_vnd);

    const closeModal = () => {
        if (loading) return;
        form.resetFields();
        onClose();
    };

    const submit = (values: RefundFormValues) => {
        setLoading(true);

        router.put(`/admin/orders/${order.id}/status`, {
            status: 'cancelled',
            cancel_reason: values.refund_reason,
            refund_amount: Number(values.refund_amount ?? maxRefundAmount),
        }, {
            preserveScroll: true,
            onSuccess: () => {
                message.success(`Đã huỷ đơn #${order.id} và hoàn tiền cho khách hàng.`);
                form.resetFields();
                onClose();
            },
            onError: (errors: Record<string, string>) => {
                message.error(Object.values(errors)[0] || 'Không thể hoàn tiền đơn bán vàng.');
            },
            onFinish: () => setLoading(false),
        });
    };

    return (
        <Modal
            open={open}
            title={
                <Space>
                    <RotateCcw className="h-5 w-5 text-red-500" />
                    <span>Hoàn tiền đơn bán vàng #{order.id}</span>
                </Space>
            }
            onCancel={closeModal}
            footer={null}
            width={600}
            closable={!loading}
            maskClosable={!loading}
            destroyOnHidden
        >
            <Descriptions bordered size="small" column={{ xs: 1, sm: 2 }} className="mb-4">
                <Descriptions.Item label="Khách hàng">{order.user?.username ?? 'N/A'}</Descriptions.Item>
                <Descriptions.Item label="Server">{order.server?.name ?? 'N/A'}</Descriptions.Item>
                <Descriptions.Item label="Nhân vật">{order.character_name}</Descriptions.Item>
                <Descriptions.Item label="Trạng thái">{order.status_label}</Descriptions.Item>
                <Descriptions.Item label="Số tiền">{order.amount_vnd_formatted}</Descriptions.Item>
                <Descriptions.Item label="Số vàng">{order.gold_qty_formatted}</Descriptions.Item>
            </Descriptions>

            <Alert
                message="Lưu ý quan trọng"
                description="Xác nhận sẽ huỷ đơn bán vàng và cộng số tiền hoàn vào số dư khách hàng. Đơn chỉ được hoàn một lần."
                type="warning"
                showIcon
                icon={<AlertTriangle className="h-4 w-4" />}
                className="mb-4"
            />

            <Form
                form={form}
                layout="vertical"
                onFinish={submit}
                preserve={false}
                initialValues={{ refund_amount: maxRefundAmount }}
            >
                <Form.Item
                    label="Số tiền hoàn"
                    name="refund_amount"
                    validateFirst
                    rules={[
                        { required: true, message: 'Vui lòng nhập số tiền hoàn!' },
                        { type: 'number', min: 1, message: 'Số tiền phải lớn hơn 0!' },
                        { type: 'number', max: maxRefundAmount, message: `Không được vượt quá ${order.amount_vnd_formatted}!` },
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
                        { required: true, whitespace: true, message: 'Vui lòng nhập lý do hoàn tiền!' },
                        { max: 500, message: 'Lý do không được quá 500 ký tự!' },
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
                            <Button size="large" onClick={closeModal} disabled={loading}>Đóng</Button>
                            <Button
                                type="primary"
                                danger
                                size="large"
                                htmlType="submit"
                                loading={loading}
                                icon={<RotateCcw className="h-4 w-4" />}
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
