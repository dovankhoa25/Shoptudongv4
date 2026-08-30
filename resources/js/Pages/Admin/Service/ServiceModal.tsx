// Admin/Service/ServiceModal.tsx - Service Modal Component with Ant Design
import React, { useState, useEffect } from 'react';
import { router } from "@inertiajs/react";
import { Modal, Form, Input, InputNumber, Switch, Button, message } from 'antd';
import { SaveOutlined } from '@ant-design/icons';
import { IService } from "@/InterFaces/service";

const { TextArea } = Input;

interface ServiceModalProps {
    open: boolean;
    onClose: () => void;
    service: IService | null;
}

interface FormData {
    name: string;
    default_price: number | null;
    original_price: number | null;
    description: string;
    status: boolean;
    is_popular: boolean;
    processing_time: string;
    warranty: string;
}

export default function ServiceModal({ open, onClose, service }: ServiceModalProps) {
    const [form] = Form.useForm<FormData>();
    const [loading, setLoading] = useState(false);

    // Initialize form data
    useEffect(() => {
        if (open) {
            if (service) {
                form.setFieldsValue({
                    name: service.name,
                    default_price: service.default_price,
                    original_price: service.original_price,
                    description: service.description || '',
                    status: service.status,
                    is_popular: service.is_popular,
                    processing_time: service.processing_time || '',
                    warranty: service.warranty || '',
                });
            } else {
                form.resetFields();
                form.setFieldsValue({
                    status: true,
                    is_popular: false,
                });
            }
        }
    }, [open, service, form]);

    const handleSubmit = async (values: FormData) => {
        setLoading(true);

        const submitData = {
            name: values.name.trim(),
            default_price: values.default_price || null,
            original_price: values.original_price || null,
            description: values.description?.trim() || null,
            status: values.status,
            is_popular: values.is_popular,
            processing_time: values.processing_time?.trim() || null,
            warranty: values.warranty?.trim() || null,
        };

        const url = service
            ? `/admin/services/${service.id}`
            : '/admin/services';

        const method = service ? 'put' : 'post';

        router[method](url, submitData, {
            onSuccess: () => {
                message.success(
                    service
                        ? `Dịch vụ "${values.name}" đã được cập nhật thành công!`
                        : `Dịch vụ "${values.name}" đã được tạo thành công!`
                );
                onClose();
                form.resetFields();
            },
            onError: (errors) => {
                console.error('Submission errors:', errors);

                // Set form errors
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

    return (
        <Modal
            title={
                <div className="flex items-center gap-2">
                    <SaveOutlined className="text-blue-600" />
                    {service ? 'Chỉnh sửa dịch vụ' : 'Thêm dịch vụ mới'}
                </div>
            }
            open={open}
            onCancel={handleCancel}
            footer={null}
            width={700}
            destroyOnClose
            maskClosable={!loading}
            closable={!loading}
            // Cấu hình để modal scroll nội dung thay vì scroll trang
            style={{
                top: 20, // Đặt modal gần top để có space scroll
                maxHeight: 'calc(100vh - 40px)' // Giới hạn chiều cao modal
            }}
            styles={{
                body: {
                    maxHeight: 'calc(100vh - 100px)', // Giới hạn chiều cao body
                    overflowY: 'auto', // Cho phép scroll vertical
                    paddingRight: '8px' // Thêm padding để tránh scrollbar đè lên content
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
                {/* Name */}
                <Form.Item
                    label="Tên dịch vụ"
                    name="name"
                    rules={[
                        { required: true, message: 'Vui lòng nhập tên dịch vụ!' },
                        { min: 2, message: 'Tên dịch vụ phải có ít nhất 2 ký tự!' },
                        { max: 50, message: 'Tên dịch vụ không được vượt quá 50 ký tự!' }
                    ]}
                >
                    <Input
                        placeholder="Nhập tên dịch vụ"
                        size="large"
                        showCount
                        maxLength={50}
                    />
                </Form.Item>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {/* Default Price */}
                    <Form.Item
                        label="Giá mặc định (VND)"
                        name="default_price"
                        rules={[
                            { required: true, message: 'Vui lòng nhập giá mặc định!' },
                            { type: 'number', min: 0, message: 'Giá không thể là số âm!' }
                        ]}
                    >
                        <InputNumber
                            placeholder="Nhập giá mặc định"
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

                    {/* Original Price */}
                    <Form.Item
                        label="Giá gốc (VND)"
                        name="original_price"
                        rules={[
                            { type: 'number', min: 0, message: 'Giá không thể là số âm!' }
                        ]}
                    >
                        <InputNumber
                            placeholder="Nhập giá gốc (tùy chọn)"
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

                {/* Description */}
                <Form.Item
                    label="Mô tả"
                    name="description"
                    rules={[
                        { max: 1000, message: 'Mô tả không được vượt quá 1000 ký tự!' }
                    ]}
                >
                    <TextArea
                        placeholder="Nhập mô tả dịch vụ"
                        rows={4}
                        showCount
                        maxLength={1000}
                    />
                </Form.Item>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {/* Processing Time */}
                    <Form.Item
                        label="Thời gian xử lý"
                        name="processing_time"
                        rules={[
                            { max: 255, message: 'Thời gian xử lý không được vượt quá 255 ký tự!' }
                        ]}
                    >
                        <Input
                            placeholder="VD: 1-3 ngày làm việc"
                            size="large"
                            showCount
                            maxLength={255}
                        />
                    </Form.Item>

                    {/* Warranty */}
                    <Form.Item
                        label="Bảo hành"
                        name="warranty"
                        rules={[
                            { max: 255, message: 'Bảo hành không được vượt quá 255 ký tự!' }
                        ]}
                    >
                        <Input
                            placeholder="VD: 30 ngày"
                            size="large"
                            showCount
                            maxLength={255}
                        />
                    </Form.Item>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {/* Status */}
                    <Form.Item
                        label="Trạng thái"
                        name="status"
                        valuePropName="checked"
                    >
                        <Switch
                            checkedChildren="Hoạt động"
                            unCheckedChildren="Tạm dừng"
                            size="default"
                        />
                    </Form.Item>

                    {/* Is Popular */}
                    <Form.Item
                        label="Dịch vụ phổ biến"
                        name="is_popular"
                        valuePropName="checked"
                    >
                        <Switch
                            checkedChildren="Phổ biến"
                            unCheckedChildren="Bình thường"
                            size="default"
                        />
                    </Form.Item>
                </div>

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
                            {service ? 'Cập nhật' : 'Tạo mới'}
                        </Button>
                    </div>
                </Form.Item>
            </Form>
        </Modal>
    );
}