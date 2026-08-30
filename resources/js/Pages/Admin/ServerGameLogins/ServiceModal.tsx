// Admin/Servers/ServerModal.tsx - Server Modal Component with Ant Design
import React, { useState, useEffect } from 'react';
import { router } from "@inertiajs/react";
import { Modal, Form, Input, Switch, Button, message } from 'antd';
import { CloudServerOutlined, SaveOutlined } from '@ant-design/icons';
import { IServerGameLogin } from '@/InterFaces/servergamelogin';


interface ServerModalProps {
    open: boolean;
    onClose: () => void;
    server: IServerGameLogin | null;
}

interface FormData {
    name: string;
    ip: string;
    port: string;
}

export default function ServerModal({ open, onClose, server }: ServerModalProps) {
    const [form] = Form.useForm<FormData>();
    const [loading, setLoading] = useState(false);

    // Initialize form data
    useEffect(() => {
        if (open) {
            if (server) {
                form.setFieldsValue({
                    name: server.name,
                    ip: server.ip,
                    port: server.port,
                });
            } else {
                form.resetFields();
                form.setFieldsValue({
                    // status: true,
                });
            }
        }
    }, [open, server, form]);

    const handleSubmit = async (values: FormData) => {
        setLoading(true);

        const submitData = {
            name: values.name.trim(),
            ip: values.ip.trim(),
            port: values.port,
        };

        const url = server
            ? `/admin/server-game-logins/${server.id}`
            : '/admin/server-game-logins';

        const method = server ? 'put' : 'post';

        router[method](url, submitData, {
            onSuccess: () => {
                message.success(
                    server
                        ? `Server "${values.name}" đã được cập nhật thành công!`
                        : `Server "${values.name}" đã được tạo thành công!`
                );
                onClose();
                form.resetFields();
            },
            onError: (errors) => {
                console.error('Submission errors:', errors);

                const validFields: (keyof FormData)[] = ['name', 'ip', 'port'];
                const formErrors = Object.keys(errors)
                    .filter((key): key is keyof FormData => validFields.includes(key as keyof FormData))
                    .map(key => ({
                        name: key,
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
                    <CloudServerOutlined className="text-blue-600" />
                    {server ? 'Chỉnh sửa Server' : 'Thêm Server mới'}
                </div>
            }
            open={open}
            onCancel={handleCancel}
            footer={null}
            width={600}
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
                    label="Tên Server"
                    name="name"
                    rules={[
                        { required: true, message: 'Vui lòng nhập tên server!' },
                        { min: 2, message: 'Tên server phải có ít nhất 2 ký tự!' },
                        { max: 255, message: 'Tên server không được vượt quá 255 ký tự!' },
                        {
                            pattern: /^[a-zA-Z0-9\s\-_\.]+$/,
                            message: 'Tên server chỉ được chứa chữ cái, số, dấu gạch ngang, gạch dưới và dấu chấm!'
                        }
                    ]}
                >
                    <Input
                        placeholder="Nhập tên server"
                        size="large"
                        showCount
                        maxLength={255}
                        prefix={<CloudServerOutlined className="text-gray-400" />}
                    />
                </Form.Item>


                {/* ip*/}
                <Form.Item
                    label="server ip"
                    name="ip"
                    rules={[
                        { required: true, message: 'Vui lòng nhập tên ip!' },
                        { min: 2, message: 'ip server phải có ít nhất 2 ký tự!' },
                        { max: 255, message: 'ip server không được vượt quá 255 ký tự!' },
                    ]}
                >
                    <Input
                        placeholder="Nhập ip"
                        size="large"
                        showCount
                        maxLength={255}
                        prefix={<CloudServerOutlined className="text-gray-400" />}
                    />
                </Form.Item>

                {/* Name view*/}
                <Form.Item
                    label="server port"
                    name="port"
                    rules={[
                        { required: true, message: 'Vui lòng nhập tên port!' },
                        { min: 2, message: 'port server phải có ít nhất 2 ký tự!' },
                        { max: 255, message: 'port server không được vượt quá 255 ký tự!' },
                    ]}
                >
                    <Input
                        placeholder="Nhập port"
                        size="large"
                        showCount
                        maxLength={255}
                        prefix={<CloudServerOutlined className="text-gray-400" />}
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
                            {server ? 'Cập nhật' : 'Tạo mới'}
                        </Button>
                    </div>
                </Form.Item>
            </Form>
        </Modal>
    );
}