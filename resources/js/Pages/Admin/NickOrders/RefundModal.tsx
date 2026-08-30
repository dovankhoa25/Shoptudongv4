// Admin/NickOrders/RefundModal.tsx
import React, { useState } from 'react';
import { router } from "@inertiajs/react";
import { Modal, Form, InputNumber, Typography, Space, Alert, Divider } from 'antd';
import { ExclamationCircleOutlined, DollarOutlined, UserOutlined } from '@ant-design/icons';
import { INickOrder } from '@/InterFaces/nickOrder';
import { formatPrice } from '@/Utils/currencyHelper';

const { Text, Title } = Typography;

interface RefundModalProps {
    isOpen: boolean;
    onClose: () => void;
    order: INickOrder | null;
}

export default function RefundModal({ isOpen, onClose, order }: RefundModalProps) {
    const [form] = Form.useForm();
    const [penaltyAmount, setPenaltyAmount] = useState<number>(0);
    const [isSubmitting, setIsSubmitting] = useState(false);

    if (!order) return null;

    const handleSubmit = async () => {
        try {
            const values = await form.validateFields();
            const penalty = values.penalty_amount || 0;

            setIsSubmitting(true);

            router.put(`/admin/nick-orders/${order.id}/refund`, {
                status: 'refunded',
                penalty_amount: penalty
            }, {
                onSuccess: () => {
                    onClose();
                    form.resetFields();
                    setPenaltyAmount(0);
                    setIsSubmitting(false);
                },
                onError: (errors) => {
                    console.error('Refund error:', errors);
                    setIsSubmitting(false);
                }
            });
        } catch (error) {
            setIsSubmitting(false);
        }
    };

    const handleClose = () => {
        if (!isSubmitting) {
            form.resetFields();
            setPenaltyAmount(0);
            onClose();
        }
    };

    const refundAmount = order.price; // Buyer luôn nhận đầy đủ
    const sellerDeduction = Number(order.price) + penaltyAmount; // Seller bị trừ giá + phạt

    return (
        <Modal
            title={
                <Space>
                    <ExclamationCircleOutlined style={{ color: '#faad14' }} />
                    <span>Hoàn tiền đơn hàng #{order.id}</span>
                </Space>
            }
            open={isOpen}
            onCancel={handleClose}
            onOk={handleSubmit}
            confirmLoading={isSubmitting}
            okText="Xác nhận hoàn tiền"
            cancelText="Hủy"
            okButtonProps={{ danger: true }}
            width={600}
            maskClosable={!isSubmitting}
        >
            <div className="space-y-4">
                {/* Order Information */}
                <div className="bg-gray-50 p-4 rounded-lg">
                    <Title level={5}>Thông tin đơn hàng</Title>
                    <div className="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <Text type="secondary">Nick:</Text>
                            <br />
                            <Text strong>{order.nick?.account_name || 'N/A'}</Text>
                        </div>
                        <div>
                            <Text type="secondary">Giá bán:</Text>
                            <br />
                            <Text strong className="text-green-600">
                                {formatPrice(order.price)}
                            </Text>
                        </div>
                        <div>
                            <Text type="secondary">Người mua:</Text>
                            <br />
                            <Space>
                                <UserOutlined />
                                <Text>{order.buyer?.name || 'N/A'}</Text>
                            </Space>
                        </div>
                        <div>
                            <Text type="secondary">Người bán:</Text>
                            <br />
                            <Space>
                                <UserOutlined />
                                <Text>{order.seller?.name || 'N/A'}</Text>
                            </Space>
                        </div>
                    </div>
                </div>

                {/* Penalty Form */}
                <Form
                    form={form}
                    layout="vertical"
                    onValuesChange={(changedValues) => {
                        if ('penalty_amount' in changedValues) {
                            setPenaltyAmount(changedValues.penalty_amount || 0);
                        }
                    }}
                >
                    <Form.Item
                        label="Phí phạt seller (tùy chọn)"
                        name="penalty_amount"
                        rules={[
                            { type: 'number', min: 0, message: 'Phí phạt không thể âm' }
                        ]}
                        extra="Nhập 0 hoặc để trống nếu không phạt tiền"
                    >
                        <InputNumber
                            prefix={<DollarOutlined />}
                            style={{ width: '100%' }}
                            min={0}
                            step={1000}
                            formatter={value => `${value}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                            // parser={value => value!.replace(/\$\s?|(,*)/g, '')}
                            placeholder="0"
                            disabled={isSubmitting}
                        />
                    </Form.Item>
                </Form>

                {/* Calculation Summary */}
                {penaltyAmount > 0 && (
                    <div className="bg-yellow-50 border border-yellow-200 rounded p-4">
                        <Title level={5}>Tóm tắt giao dịch</Title>
                        <div className="space-y-2 text-sm">
                            <div className="flex justify-between">
                                <Text>Buyer nhận lại:</Text>
                                <Text strong className="text-green-600">
                                    {formatPrice(refundAmount)}
                                </Text>
                            </div>
                            <div className="flex justify-between">
                                <Text>Seller bị trừ (giá + phạt):</Text>
                                <Text strong className="text-red-600">
                                    -{formatPrice(sellerDeduction)}
                                </Text>
                            </div>
                            <Divider style={{ margin: '8px 0' }} />
                            <div className="flex justify-between text-xs text-gray-600">
                                <span>• Giá đơn hàng: {formatPrice(order.price)}</span>
                                <span>• Phí phạt: {formatPrice(penaltyAmount)}</span>
                            </div>
                        </div>
                    </div>
                )}

                {/* Warning */}
                <Alert
                    message="Cảnh báo về hoàn tiền"
                    description={
                        <ul className="text-xs space-y-1 mt-2">
                            <li>• Buyer sẽ được hoàn <strong>{formatPrice(refundAmount)}</strong></li>
                            <li>• Seller sẽ bị trừ <strong>{formatPrice(order.price)}</strong> (giá đơn hàng)</li>
                            {penaltyAmount > 0 && (
                                <li>• Seller sẽ bị phạt thêm <strong className="text-red-600">{formatPrice(penaltyAmount)}</strong></li>
                            )}
                            <li>• Nick sẽ được chuyển về trạng thái "return"</li>
                            <li>• <strong>Hành động này không thể hoàn tác</strong></li>
                        </ul>
                    }
                    type="warning"
                    showIcon
                />
            </div>
        </Modal>
    );
}