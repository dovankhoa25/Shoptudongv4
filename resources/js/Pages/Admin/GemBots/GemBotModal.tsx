// Admin/GemBots/GemBotModal.tsx - Gem Bot Modal Component
import React, { useState, useEffect } from 'react';
import { router } from "@inertiajs/react";
import { Modal, Form, Input, InputNumber, Switch, Button, message, Select, Space } from 'antd';
import { SaveOutlined, RobotOutlined, EyeInvisibleOutlined, EyeTwoTone, UserOutlined } from '@ant-design/icons';
import { IServer } from '@/InterFaces/server';
import { IGemBot } from '@/InterFaces/gembot';
import { IServerGameLogin } from '@/InterFaces/servergamelogin';

interface GemBotModalProps {
    open: boolean;
    onClose: () => void;
    gemBot: IGemBot | null;
    servers: IServer[];
    logins: IServerGameLogin[];
}

interface FormData {
    name: string;
    account_name: string;
    account_password: string;
    server_id: number;
    server_game_id: number;
    gem_qty: number | null;
    map_name: string;
    map_id: string;
    area_number: string;
    coordinates: string;
    proxy: string;
    status: boolean;
}

export default function GemBotModal({ open, onClose, gemBot, servers, logins }: GemBotModalProps) {
    const [form] = Form.useForm<FormData>();
    const [loading, setLoading] = useState(false);

    // Initialize form data
    useEffect(() => {
        if (open) {
            if (gemBot) {
                form.setFieldsValue({
                    name: gemBot.name || '',
                    account_name: gemBot.account_name,
                    account_password: '', // Don't show existing password
                    server_id: gemBot.server.id,
                    server_game_id: gemBot.server_game_id,
                    gem_qty: gemBot.gem_qty || null,
                    map_name: gemBot.map_info?.map_name || '',
                    map_id: gemBot.map_info?.map_id || '',
                    area_number: gemBot.map_info?.area_number || '',
                    coordinates: gemBot.map_info?.coordinates || '',
                    proxy: gemBot.map_info?.proxy || '',
                    status: gemBot.status,
                });
            } else {
                form.resetFields();
                form.setFieldsValue({
                    status: true,
                    gem_qty: 0,
                });
            }
        }
    }, [open, gemBot, form]);

    const handleSubmit = async (values: FormData) => {
        setLoading(true);

        const submitData = {
            name: values.name?.trim() || null,
            account_name: values.account_name.trim(),
            account_password: values.account_password?.trim() || undefined, // Only send if provided
            server_id: values.server_id,
            server_game_id: values.server_game_id,
            gem_qty: values.gem_qty || 0,
            map_name: values.map_name?.trim() || null,
            map_id: values.map_id.trim(),
            area_number: values.area_number.trim(),
            coordinates: values.coordinates.trim(),
            proxy: values.proxy.trim(),
            status: values.status,
        };

        // Remove password if empty (for updates)
        if (!submitData.account_password && gemBot) {
            delete submitData.account_password;
        }

        const url = gemBot
            ? `/admin/gem-bots/${gemBot.id}`
            : '/admin/gem-bots';

        const method = gemBot ? 'put' : 'post';

        router[method](url, submitData, {
            onSuccess: () => {
                message.success(
                    gemBot
                        ? `Bot ngọc "${values.name || values.account_name}" đã được cập nhật thành công!`
                        : `Bot ngọc "${values.name || values.account_name}" đã được tạo thành công!`
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
                    <div className="w-8 h-8 bg-gradient-to-br from-purple-100 to-pink-100 rounded-lg flex items-center justify-center">
                        <RobotOutlined className="text-purple-600" />
                    </div>
                    <span>{gemBot ? 'Chỉnh sửa Bot Ngọc' : 'Thêm Bot Ngọc mới'}</span>
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
                    maxHeight: 'calc(100vh - 100px)',
                    overflowY: 'auto',
                    paddingRight: '8px'
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
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {/* Name */}
                    <Form.Item
                        label="Tên Bot (tùy chọn)"
                        name="name"
                        rules={[
                            { max: 255, message: 'Tên bot không được vượt quá 255 ký tự!' }
                        ]}
                    >
                        <Input
                            placeholder="VD: Bot Ngọc Server 1"
                            size="large"
                            showCount
                            maxLength={255}
                            prefix={<RobotOutlined className="text-gray-400" />}
                        />
                    </Form.Item>

                    {/* Account Name */}
                    <Form.Item
                        label="Tên tài khoản"
                        name="account_name"
                        rules={[
                            { required: true, message: 'Vui lòng nhập tên tài khoản!' },
                            { max: 255, message: 'Tên tài khoản không được vượt quá 255 ký tự!' }
                        ]}
                    >
                        <Input
                            placeholder="Nhập tên tài khoản game"
                            size="large"
                            showCount
                            maxLength={255}
                        />
                    </Form.Item>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {/* Account Password */}
                    <Form.Item
                        label={gemBot ? "Mật khẩu mới (bỏ trống nếu không đổi)" : "Mật khẩu tài khoản"}
                        name="account_password"
                        rules={[
                            { required: !gemBot, message: 'Vui lòng nhập mật khẩu!' },
                            { max: 255, message: 'Mật khẩu không được vượt quá 255 ký tự!' }
                        ]}
                    >
                        <Input.Password
                            placeholder={gemBot ? "Nhập để thay đổi mật khẩu" : "Nhập mật khẩu"}
                            size="large"
                            maxLength={255}
                            iconRender={(visible) => (visible ? <EyeTwoTone /> : <EyeInvisibleOutlined />)}
                        />
                    </Form.Item>
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

                </div>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">


                    {/* Server */}
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
                        >
                            {servers?.map(server => (
                                <Select.Option key={server.id} value={server.id}>
                                    {server.name}
                                </Select.Option>
                            ))}
                        </Select>
                    </Form.Item>

                    {/* Server */}
                    <Form.Item
                        label="Server login"
                        name="server_game_id"
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
                        >
                            {logins?.map(login => (
                                <Select.Option key={login.id} value={login.id}>
                                    {login.name}
                                </Select.Option>
                            ))}
                        </Select>
                    </Form.Item>
                </div>
                {/* Gem Quantity */}
                <Form.Item
                    label={
                        <span className="flex items-center gap-2">
                            <span className="text-purple-600">💎</span>
                            Số lượng ngọc hiện có
                        </span>
                    }
                    name="gem_qty"
                    rules={[
                        { type: 'number', min: 0, message: 'Số lượng ngọc không thể âm!' }
                    ]}
                >
                    <InputNumber
                        placeholder="Nhập số lượng ngọc"
                        size="large"
                        style={{ width: '100%' }}
                        formatter={(value) => `${value}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                        // parser={(value) => value!.replace(/\$\s?|(,*)/g, '') as unknown as number}
                        min={0}
                        precision={0}
                        controls={false}
                        addonAfter="ngọc"
                    />
                </Form.Item>

                <div className="bg-gray-50 p-4 rounded-lg mb-4">
                    <h4 className="font-medium text-gray-700 mb-3">Thông tin vị trí trong game</h4>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {/* Map Name */}
                        <Form.Item
                            label="Tên bản đồ"
                            name="map_name"
                            rules={[
                                { max: 255, message: 'Tên bản đồ không được vượt quá 255 ký tự!' }
                            ]}
                        >
                            <Input
                                placeholder="VD: Làng Aru"
                                size="large"
                                maxLength={255}
                            />
                        </Form.Item>

                        {/* Map ID */}
                        <Form.Item
                            label="ID bản đồ"
                            name="map_id"
                            rules={[
                                { required: true, message: 'Vui lòng nhập ID bản đồ!' },
                                { max: 255, message: 'ID bản đồ không được vượt quá 255 ký tự!' }
                            ]}
                        >
                            <Input
                                placeholder="VD: 123"
                                size="large"
                                maxLength={255}
                            />
                        </Form.Item>

                        {/* Area Number */}
                        <Form.Item
                            label="Khu vực"
                            name="area_number"
                            rules={[
                                { required: true, message: 'Vui lòng nhập khu vực!' },
                                { max: 255, message: 'Khu vực không được vượt quá 255 ký tự!' }
                            ]}
                        >
                            <Input
                                placeholder="VD: Khu 1"
                                size="large"
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
                </div>

                {/* Status */}
                <Form.Item
                    label="Trạng thái hoạt động"
                    name="status"
                    valuePropName="checked"
                    help="Bot sẽ chỉ phục vụ khi ở trạng thái hoạt động"
                >
                    <Switch
                        checkedChildren="Hoạt động"
                        unCheckedChildren="Tạm dừng"
                        size="default"
                    />
                </Form.Item>

                {/* Submit Buttons */}
                <Form.Item className="mb-0 pt-4 border-t">
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
                            className="bg-gradient-to-r from-purple-600 to-pink-600 border-0"
                        >
                            {gemBot ? 'Cập nhật' : 'Tạo mới'}
                        </Button>
                    </div>
                </Form.Item>
            </Form>
        </Modal>
    );
}