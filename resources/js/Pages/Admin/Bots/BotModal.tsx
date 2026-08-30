// Admin/Bots/BotModal.tsx - Updated for multiple types
import React, { useState, useEffect } from 'react';
import { router } from "@inertiajs/react";
import {
    Modal, Form, Input, InputNumber, Switch, Button,
    Select, Card, Divider, Space, Alert, Tooltip
} from 'antd';
import {
    SaveOutlined, RobotOutlined, EyeInvisibleOutlined,
    EyeTwoTone, InfoCircleOutlined,
    UserOutlined,
    CloudServerOutlined
} from '@ant-design/icons';
import { useToast } from "@/Components/ToastProvider";
import { IServer } from '@/InterFaces/server';
import { IBot } from '@/InterFaces/bot';
import { IServerGameLogin } from '@/InterFaces/servergamelogin';

interface BotModalProps {
    open: boolean;
    onClose: () => void;
    bot: IBot | null;
    servers: IServer[];
    logins: IServerGameLogin[];
}

interface FormData {
    name: string;
    account_name: string;
    account_password: string;
    types: string[]; // Changed from type to types (array)
    server_id: number;
    server_game_id: number;
    gold_bar_qty: number | null;
    gold_qty: number | null;
    map_name: string;
    map_id: string;
    area_number: string;
    coordinates: string;
    proxy: string;
    status: boolean;
}

export default function BotModal({ open, onClose, bot, servers, logins }: BotModalProps) {
    const [form] = Form.useForm<FormData>();
    const [loading, setLoading] = useState(false);
    const toast = useToast();

    // Initialize form data
    useEffect(() => {
        if (open) {
            if (bot) {
                // Edit mode - populate form with bot data
                // Parse types from comma-separated string to array
                const typesArray = bot.types || (bot.type ? bot.type.split(',').map(t => t.trim()) : []);

                form.setFieldsValue({
                    name: bot.name || '',
                    account_name: bot.account_name,
                    account_password: bot.account_password,
                    types: typesArray, // Use array
                    server_id: bot.server_id,
                    server_game_id: bot.server_game_id,
                    gold_bar_qty: bot.gold_bar_qty || null,
                    gold_qty: bot.gold_qty || null,
                    map_name: bot.map_name || '',
                    map_id: bot.map_id?.toString() || '',
                    area_number: bot.area_number?.toString() || '',
                    coordinates: bot.coordinates?.toString() || '',
                    proxy: bot.proxy?.toString() || '',
                    status: bot.status,
                });
            } else {
                // Create mode - set defaults
                form.resetFields();
                form.setFieldsValue({
                    status: true,
                    types: ['selling_main'], // Default type as array
                    gold_bar_qty: 0,
                    gold_qty: 0,
                });
            }
        }
    }, [open, bot, form]);

    const handleSubmit = async (values: FormData) => {
        setLoading(true);

        // Convert types array to comma-separated string for backend
        const typeString = values.types.join(',');

        // Prepare data for submission
        const submitData = {
            name: values.name?.trim() || null,
            account_name: values.account_name.trim(),
            account_password: values.account_password?.trim() || '',
            type: typeString, // Send as comma-separated string
            server_id: values.server_id,
            server_game_id: values.server_game_id,
            gold_bar_qty: values.gold_bar_qty || 0,
            gold_qty: values.gold_qty || 0,
            map_name: values.map_name?.trim() || null,
            map_id: values.map_id.trim(),
            area_number: values.area_number.trim(),
            coordinates: values.coordinates.trim(),
            proxy: values.proxy.trim(),
            status: values.status,
        };

        const url = bot ? `/admin/bots/${bot.id}` : '/admin/bots';
        const method = bot ? 'put' : 'post';

        router[method](url, submitData, {
            onSuccess: () => {
                const action = bot ? 'cập nhật' : 'tạo';
                const botName = values.name || values.account_name;
                toast.success(`Bot "${botName}" đã được ${action} thành công!`);
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
                toast.error('Có lỗi xảy ra. Vui lòng kiểm tra lại thông tin!');
            },
            onFinish: () => {
                setLoading(false);
            }
        });
    };

    const handleCancel = () => {
        if (loading) return;

        form.resetFields();
        onClose();
    };

    // Bot type options with better styling - Added auto_sell_bar
    const botTypeOptions = [
        {
            value: 'selling_main',
            label: (
                <Space>
                    <span>🛒</span>
                    <span>Bán chính</span>
                </Space>
            )
        },
        {
            value: 'selling_sub',
            label: (
                <Space>
                    <span>🛍️</span>
                    <span>Bán phụ</span>
                </Space>
            )
        },
        {
            value: 'import_main',
            label: (
                <Space>
                    <span>📦</span>
                    <span>Nhập chính</span>
                </Space>
            )
        },
        {
            value: 'import_sub',
            label: (
                <Space>
                    <span>📋</span>
                    <span>Nhập phụ</span>
                </Space>
            )
        },
        {
            value: 'auto_sell_bar',
            label: (
                <Space>
                    <span>🤖</span>
                    <span>Auto bán thỏi</span>
                </Space>
            )
        },
    ];

    return (
        <Modal
            title={
                <div className="flex items-center gap-3">
                    <div className="w-8 h-8 bg-gradient-to-br from-blue-100 to-purple-100 rounded-lg flex items-center justify-center">
                        <RobotOutlined className="text-blue-600" />
                    </div>
                    <div>
                        <div className="text-lg font-semibold">
                            {bot ? 'Chỉnh sửa Bot' : 'Thêm Bot mới'}
                        </div>
                        <div className="text-sm text-gray-500 font-normal">
                            {bot ? `Cập nhật thông tin bot ${bot.name || bot.account_name}` : 'Tạo bot mới trong hệ thống'}
                        </div>
                    </div>
                </div>
            }
            open={open}
            onCancel={handleCancel}
            footer={null}
            width={900}
            destroyOnClose
            maskClosable={!loading}
            closable={!loading}
            style={{ top: 20 }}
            styles={{
                body: {
                    maxHeight: 'calc(100vh - 120px)',
                    overflowY: 'auto',
                    paddingRight: '8px'
                }
            }}
        >
            {/* Security Alert for Edit Mode */}
            {bot && (
                <Alert
                    message="Chế độ chỉnh sửa"
                    description="Để bảo mật, mật khẩu không được hiển thị. Nhập mật khẩu mới nếu muốn thay đổi."
                    type="info"
                    icon={<InfoCircleOutlined />}
                    className="mb-4"
                    showIcon
                />
            )}

            <Form
                form={form}
                layout="vertical"
                onFinish={handleSubmit}
                disabled={loading}
                size="large"
            >
                {/* Basic Information */}
                <Card title="Thông tin cơ bản" className="mb-4" size="small">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {/* Name */}
                        <Form.Item
                            label={
                                <Space>
                                    <UserOutlined />
                                    <span>Tên Bot (tùy chọn)</span>
                                </Space>
                            }
                            name="name"
                            rules={[
                                { max: 255, message: 'Tên bot không được vượt quá 255 ký tự!' }
                            ]}
                        >
                            <Input
                                placeholder="Nhập tên bot (hiển thị)"
                                showCount
                                maxLength={255}
                            />
                        </Form.Item>

                        {/* Account Name */}
                        <Form.Item
                            label={
                                <Space>
                                    <UserOutlined />
                                    <span>Tên tài khoản</span>
                                    <span className="text-red-500">*</span>
                                </Space>
                            }
                            name="account_name"
                            rules={[
                                { required: true, message: 'Vui lòng nhập tên tài khoản!' },
                                { max: 255, message: 'Tên tài khoản không được vượt quá 255 ký tự!' },
                            ]}
                        >
                            <Input
                                placeholder="Nhập tên tài khoản game"
                                showCount
                                maxLength={255}
                            />
                        </Form.Item>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {/* Account Password */}
                        <Form.Item
                            label={
                                <Space>
                                    <EyeInvisibleOutlined />
                                    <span>Mật khẩu tài khoản</span>
                                    {!bot && <span className="text-red-500">*</span>}
                                </Space>
                            }
                            name="account_password"
                            rules={[
                                {
                                    required: !bot,
                                    message: 'Vui lòng nhập mật khẩu!'
                                },
                                { min: 3, message: 'Mật khẩu phải có ít nhất 3 ký tự!' },
                                { max: 255, message: 'Mật khẩu không được vượt quá 255 ký tự!' }
                            ]}
                        >
                            <Input.Password
                                placeholder={bot ? "Nhập mật khẩu mới (để trống nếu không đổi)" : "Nhập mật khẩu"}
                                maxLength={255}
                                iconRender={(visible) => (visible ? <EyeTwoTone /> : <EyeInvisibleOutlined />)}
                            />
                        </Form.Item>

                        {/* Types - Multiple Select */}
                        <Form.Item
                            label={
                                <Space>
                                    <RobotOutlined />
                                    <span>Loại Bot</span>
                                    <span className="text-red-500">*</span>
                                    <Tooltip title="Có thể chọn nhiều loại bot">
                                        <InfoCircleOutlined className="text-gray-400" />
                                    </Tooltip>
                                </Space>
                            }
                            name="types"
                            rules={[
                                {
                                    required: true,
                                    message: 'Vui lòng chọn ít nhất một loại bot!',
                                    type: 'array',
                                    min: 1
                                }
                            ]}
                        >
                            <Select
                                mode="multiple"
                                placeholder="Chọn loại bot (có thể chọn nhiều)"
                                options={botTypeOptions}
                                maxTagCount="responsive"
                                allowClear
                                showSearch={false}
                                optionFilterProp="label"
                            />
                        </Form.Item>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {/* Server */}
                        <Form.Item
                            label={
                                <Space>
                                    <CloudServerOutlined />
                                    <span>Server của bot</span>
                                    <span className="text-red-500">*</span>
                                </Space>
                            }
                            name="server_id"
                            rules={[
                                { required: true, message: 'Vui lòng chọn server!' }
                            ]}
                        >
                            <Select
                                placeholder="Chọn server"
                                showSearch
                                optionFilterProp="children"
                                filterOption={(input, option) =>
                                    (option?.children as unknown as string)?.toLowerCase().includes(input.toLowerCase())
                                }
                            >
                                {servers?.map(server => (
                                    <Select.Option key={server.id} value={server.id}>
                                        <Space>
                                            {server.name}
                                        </Space>
                                    </Select.Option>
                                ))}
                            </Select>
                        </Form.Item>

                        <Form.Item
                            label={
                                <Space>
                                    <CloudServerOutlined />
                                    <span>Server để đăng nhập</span>
                                    <span className="text-red-500">*</span>
                                </Space>
                            }
                            name="server_game_id"
                            rules={[
                                { required: true, message: 'Vui lòng chọn server!' }
                            ]}
                        >
                            <Select
                                placeholder="Chọn server login game"
                                showSearch
                                optionFilterProp="children"
                                filterOption={(input, option) =>
                                    (option?.children as unknown as string)?.toLowerCase().includes(input.toLowerCase())
                                }
                            >
                                {logins?.map(login => (
                                    <Select.Option key={login.id} value={login.id}>
                                        <Space>
                                            {login.name}
                                        </Space>
                                    </Select.Option>
                                ))}
                            </Select>
                        </Form.Item>
                    </div>

                    <Form.Item
                        label={
                            <Space>
                                <UserOutlined />
                                <span>proxy (có thể trống)</span>
                            </Space>
                        }
                        name="proxy"
                        rules={[
                            { max: 150, message: 'Tên bot không được vượt quá 255 ký tự!' }
                        ]}
                    >
                        <Input
                            placeholder="Nhập proxy (hiển thị)"
                            showCount
                            maxLength={150}
                        />
                    </Form.Item>
                    {/* Status */}
                    <Form.Item
                        label={
                            <Space>
                                <span>Trạng thái hoạt động</span>
                                <Tooltip title="Bot có thể hoạt động hay không">
                                    <InfoCircleOutlined className="text-gray-400" />
                                </Tooltip>
                            </Space>
                        }
                        name="status"
                        valuePropName="checked"
                    >
                        <Switch
                            checkedChildren="Hoạt động"
                            unCheckedChildren="Tạm dừng"
                            className="bg-gray-200"
                        />
                    </Form.Item>
                </Card>

                {/* Inventory Information */}
                <Card title="Thông tin kho" className="mb-4" size="small">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {/* Gold Quantity */}
                        <Form.Item
                            label={
                                <Space>
                                    <span className="text-yellow-600">🪙</span>
                                    <span>Số lượng vàng</span>
                                </Space>
                            }
                            name="gold_qty"
                            rules={[
                                {
                                    type: 'number',
                                    min: 0,
                                    message: 'Số lượng vàng không thể âm!',
                                    transform: (value) => {
                                        if (typeof value === 'string') {
                                            const num = Number(value.replace(/,/g, ''));
                                            return isNaN(num) ? value : num;
                                        }
                                        return value;
                                    }
                                }
                            ]}
                            normalize={(value) => {
                                if (value === '' || value === undefined || value === null) {
                                    return null;
                                }
                                return Number(value);
                            }}
                        >
                            <InputNumber
                                placeholder="Nhập số lượng vàng"
                                style={{ width: '100%' }}
                                formatter={(value) => {
                                    if (!value) return '';
                                    return `${value}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                                }}
                                min={0}
                                max={999999999}
                                precision={0}
                                controls={false}
                                addonAfter="vàng"
                                onChange={(value) => {
                                    form.setFieldValue('gold_qty', value || 0);
                                }}
                            />
                        </Form.Item>

                        {/* Gold Bar Quantity */}
                        <Form.Item
                            label={
                                <Space>
                                    <span className="text-orange-600">🟨</span>
                                    <span>Số lượng thỏi vàng</span>
                                </Space>
                            }
                            name="gold_bar_qty"
                            rules={[
                                {
                                    type: 'number',
                                    min: 0,
                                    message: 'Số lượng thỏi vàng không thể âm!',
                                    transform: (value) => {
                                        if (typeof value === 'string') {
                                            const num = Number(value.replace(/,/g, ''));
                                            return isNaN(num) ? value : num;
                                        }
                                        return value;
                                    }
                                }
                            ]}
                            normalize={(value) => {
                                if (value === '' || value === undefined || value === null) {
                                    return null;
                                }
                                return Number(value);
                            }}
                        >
                            <InputNumber
                                placeholder="Nhập số lượng thỏi vàng"
                                style={{ width: '100%' }}
                                formatter={(value) => {
                                    if (!value) return '';
                                    return `${value}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                                }}
                                min={0}
                                max={999999999}
                                precision={0}
                                controls={false}
                                addonAfter="thỏi"
                                onChange={(value) => {
                                    form.setFieldValue('gold_bar_qty', value || 0);
                                }}
                            />
                        </Form.Item>
                    </div>
                </Card>

                {/* Location Information */}
                <Card title="Thông tin vị trí" className="mb-6" size="small">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {/* Map Name */}
                        <Form.Item
                            label={
                                <Space>
                                    <span>🗺️</span>
                                    <span>Tên bản đồ</span>
                                </Space>
                            }
                            name="map_name"
                            rules={[
                                { max: 255, message: 'Tên bản đồ không được vượt quá 255 ký tự!' }
                            ]}
                        >
                            <Input
                                placeholder="VD: Làng Aru"
                                maxLength={255}
                            />
                        </Form.Item>

                        {/* Map ID */}
                        <Form.Item
                            label={
                                <Space>
                                    <span>🆔</span>
                                    <span>ID bản đồ</span>
                                    <span className="text-red-500">*</span>
                                </Space>
                            }
                            name="map_id"
                            rules={[
                                { required: true, message: 'Vui lòng nhập ID bản đồ!' },
                                { max: 255, message: 'ID bản đồ không được vượt quá 255 ký tự!' },
                                { pattern: /^[0-9]+$/, message: 'ID bản đồ chỉ chứa số!' }
                            ]}
                        >
                            <Input
                                placeholder="VD: 5"
                                maxLength={255}
                            />
                        </Form.Item>

                        {/* Area Number */}
                        <Form.Item
                            label={
                                <Space>
                                    <span>📍</span>
                                    <span>Số khu vực</span>
                                    <span className="text-red-500">*</span>
                                </Space>
                            }
                            name="area_number"
                            rules={[
                                { required: true, message: 'Vui lòng nhập số khu vực!' },
                                { max: 255, message: 'Số khu vực không được vượt quá 255 ký tự!' },
                                { pattern: /^[0-9]+$/, message: 'Số khu vực chỉ chứa số!' }
                            ]}
                        >
                            <Input
                                placeholder="VD: 1"
                                maxLength={255}
                            />
                        </Form.Item>


                        {/* coordinates */}
                        <Form.Item
                            label={
                                <Space>
                                    <span>📍</span>
                                    <span>tọa độ bot</span>
                                    <span className="text-red-500">*</span>
                                </Space>
                            }
                            name="coordinates"
                            rules={[
                                { required: true, message: 'Vui lòng nhập tọa độ!' },
                                { max: 255, message: 'Số khu vực không được vượt quá 255 ký tự!' },
                            ]}
                        >
                            <Input
                                placeholder="VD: 552-336"
                                maxLength={255}
                            />
                        </Form.Item>
                    </div>
                </Card>

                {/* Submit Buttons */}
                <div className="flex gap-3 justify-end pt-4 border-t">
                    <Button
                        size="large"
                        onClick={handleCancel}
                        disabled={loading}
                        className="min-w-[100px]"
                    >
                        Hủy
                    </Button>
                    <Button
                        type="primary"
                        size="large"
                        htmlType="submit"
                        loading={loading}
                        icon={<SaveOutlined />}
                        className="min-w-[120px]"
                    >
                        {bot ? 'Cập nhật Bot' : 'Tạo Bot'}
                    </Button>
                </div>
            </Form>
        </Modal>
    );
}