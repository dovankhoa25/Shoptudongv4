// Admin/GoldPrices/GoldPriceModal.tsx - Gold Price Modal Component with Ant Design
import React, { useState, useEffect } from 'react';
import { router } from "@inertiajs/react";
import { Modal, Form, InputNumber, Switch, Button, message, Select, Card, Statistic } from 'antd';
import { SaveOutlined, GoldOutlined } from '@ant-design/icons';
import { IServer } from '@/InterFaces/server';

// Use the updated interface matching the one in Index.tsx
export interface IGoldPrice {
    id: number;
    server_id: number;
    server_name: string;
    price: number;
    import_price: number;
    status: boolean;
    created_at: string;
    updated_at: string;
}

interface GoldPriceModalProps {
    open: boolean;
    onClose: () => void;
    goldPrice: IGoldPrice | null;
    servers: IServer[];
}

interface FormData {
    server_id: number;
    price: number;
    import_price: number;
    status: boolean;
}

export default function GoldPriceModal({ open, onClose, goldPrice, servers }: GoldPriceModalProps) {
    const [form] = Form.useForm<FormData>();
    const [loading, setLoading] = useState(false);

    // Watch form values for real-time calculation
    const watchedValues = Form.useWatch([], form);
    
    // Calculate price difference and profit margin
    const calculateStats = () => {
        const sellPrice = watchedValues?.price || 0;
        const importPrice = watchedValues?.import_price || 0;
        
        const difference = sellPrice - importPrice; // Profit = sell - import
        const profitMargin = importPrice > 0 ? ((difference / importPrice) * 100) : 0;
        
        return {
            difference,
            profitMargin: profitMargin.toFixed(2),
            isProfit: difference > 0
        };
    };

    const stats = calculateStats();

    // Initialize form data
    useEffect(() => {
        if (open) {
            if (goldPrice) {
                form.setFieldsValue({
                    server_id: goldPrice.server_id,
                    price: goldPrice.price,
                    import_price: goldPrice.import_price,
                    status: goldPrice.status,
                });
            } else {
                form.resetFields();
                form.setFieldsValue({
                    status: true,
                });
            }
        }
    }, [open, goldPrice, form]);

    const handleSubmit = async (values: FormData) => {
        setLoading(true);

        const submitData = {
            server_id: values.server_id,
            price: values.price,
            import_price: values.import_price,
            status: values.status,
        };

        const url = goldPrice 
            ? `/admin/gold-prices/${goldPrice.id}`
            : '/admin/gold-prices';

        const method = goldPrice ? 'put' : 'post';

        router[method](url, submitData, {
            onSuccess: () => {
                const serverName = servers.find(s => s.id === values.server_id)?.name || 'Server';
                message.success(
                    goldPrice 
                        ? `Giá vàng cho "${serverName}" đã được cập nhật thành công!`
                        : `Giá vàng cho "${serverName}" đã được tạo thành công!`
                );
                onClose();
                form.resetFields();
            },
            onError: (errors) => {
                console.error('Submission errors:', errors);
                
                // Set form errors với type assertion
                const formErrors = Object.keys(errors).map(key => ({
                    name: key as keyof FormData,
                    errors: Array.isArray(errors[key]) ? errors[key] : [errors[key]]
                }));
                
                form.setFields(formErrors);
                
                message.error('Có lỗi xảy ra. Vui lòng kiểm tra lại thông tin!');
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

    const formatCurrency = (value: number) => {
        return new Intl.NumberFormat('vi-VN', {
            style: 'currency',
            currency: 'VND'
        }).format(value);
    };

    return (
        <Modal
            title={
                <div className="flex items-center gap-2">
                    <GoldOutlined className="text-yellow-600" />
                    {goldPrice ? 'Chỉnh sửa giá vàng' : 'Thêm giá vàng mới'}
                </div>
            }
            open={open}
            onCancel={handleCancel}
            footer={null}
            width={700}
            destroyOnClose
            maskClosable={!loading}
            closable={!loading}
            style={{ 
                top: 20,
                maxHeight: 'calc(100vh - 40px)'
            }}
            styles={{
                body: {
                    maxHeight: 'calc(100vh - 200px)',
                    overflowY: 'auto',
                    overflowX: 'hidden',
                    paddingRight: '4px'
                }
            }}
        >
            <Form
                form={form}
                layout="vertical"
                onFinish={handleSubmit}
                disabled={loading}
                className="mt-4"
            >
                {/* Server Selection */}
                <Form.Item
                    label="Server"
                    name="server_id"
                    rules={[
                        { required: true, message: 'Vui lòng chọn server!' }
                    ]}
                >
                    <Select
                        placeholder="Chọn server"
                        size="large"
                        showSearch
                        optionFilterProp="children"
                        filterOption={(input, option) =>
                            (option?.children as unknown as string)?.toLowerCase().includes(input.toLowerCase())
                        }
                        disabled={!!goldPrice} // Disable when editing
                    >
                        {servers?.map(server => (
                            <Select.Option key={server.id} value={server.id}>
                                {server.name}
                            </Select.Option>
                        ))}
                    </Select>
                </Form.Item>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {/* Selling Price */}
                    <Form.Item
                        label="Giá bán"
                        name="price"
                        rules={[
                            { required: true, message: 'Vui lòng nhập giá bán!' },
                            { type: 'number', min: 0, message: 'Giá bán không thể âm!' }
                        ]}
                    >
                        <InputNumber
                            placeholder="Nhập giá bán"
                            size="large"
                            style={{ width: '100%' }}
                            formatter={(value) => `${value}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                            // parser={(value) => value!.replace(/\$\s?|(,*)/g, '')}
                            min={0}
                            precision={0}
                            controls={false}
                            addonAfter="VND"
                        />
                    </Form.Item>

                    {/* Import Price */}
                    <Form.Item
                        label="Giá nhập"
                        name="import_price"
                        rules={[
                            { required: true, message: 'Vui lòng nhập giá nhập!' },
                            { type: 'number', min: 0, message: 'Giá nhập không thể âm!' }
                        ]}
                    >
                        <InputNumber
                            placeholder="Nhập giá nhập"
                            size="large"
                            style={{ width: '100%' }}
                            formatter={(value) => `${value}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                            // parser={(value) => value!.replace(/\$\s?|(,*)/g, '')}
                            min={0}
                            precision={0}
                            controls={false}
                            addonAfter="VND"
                        />
                    </Form.Item>
                </div>

                {/* Price Analysis Card */}
                {(watchedValues?.price > 0 && watchedValues?.import_price > 0) && (
                    <Card className="mb-4" size="small" title="Phân tích giá">
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <Statistic
                                title="Chênh lệch giá"
                                value={stats.difference}
                                formatter={(value) => formatCurrency(Number(value))}
                                valueStyle={{ 
                                    color: stats.isProfit ? '#3f8600' : '#cf1322',
                                    fontSize: '16px'
                                }}
                            />
                            <Statistic
                                title="Tỷ suất lợi nhuận"
                                value={stats.profitMargin}
                                suffix="%"
                                valueStyle={{ 
                                    color: stats.isProfit ? '#3f8600' : '#cf1322',
                                    fontSize: '16px'
                                }}
                            />
                            <Statistic
                                title="Trạng thái"
                                value={stats.isProfit ? "Có lãi" : "Thua lỗ"}
                                valueStyle={{ 
                                    color: stats.isProfit ? '#3f8600' : '#cf1322',
                                    fontSize: '14px'
                                }}
                            />
                        </div>
                    </Card>
                )}

                {/* Status */}
                <Form.Item
                    label="Trạng thái"
                    name="status"
                    valuePropName="checked"
                    help="Chọn trạng thái áp dụng giá vàng này"
                >
                    <Switch
                        checkedChildren="Hoạt động"
                        unCheckedChildren="Tạm dừng"
                        size="default"
                    />
                </Form.Item>

                {/* Submit Buttons */}
                <Form.Item className="mb-0 pt-4">
                    <div className="flex gap-3 justify-end">
                        <Button
                            size="large"
                            onClick={handleCancel}
                            disabled={loading}
                        >
                            Hủy
                        </Button>
                        <Button
                            type="primary"
                            size="large"
                            htmlType="submit"
                            loading={loading}
                            icon={<SaveOutlined />}
                        >
                            {goldPrice ? 'Cập nhật' : 'Tạo mới'}
                        </Button>
                    </div>
                </Form.Item>
            </Form>
        </Modal>
    );
}