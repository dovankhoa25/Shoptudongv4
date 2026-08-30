// UserModel.tsx - Modal cộng tiền cho user
import React, { useState, useEffect } from 'react';
import { Modal, Form, Input, Select, InputNumber, Button, message, Card, Avatar, Typography, Divider, Space } from "antd";
import { useForm, router } from "@inertiajs/react";
import { UserOutlined, DollarOutlined, SearchOutlined, PlusOutlined, MinusOutlined } from '@ant-design/icons';
import axios from 'axios';
import { IUser } from '@/InterFaces/user';
import { formatNumber } from '@/Utils/currencyHelper';

const { Text, Title } = Typography;
const { TextArea } = Input;



interface IProps {
    onClose: () => void;
    user?: IUser | null;
}

export default function UserBalanceModal({ onClose, user }: IProps) {
    const [form] = Form.useForm();
    const [searchLoading, setSearchLoading] = useState(false);
    const [selectedUser, setSelectedUser] = useState<IUser | null>(user || null);
    const [searchResults, setSearchResults] = useState<IUser[]>([]);
    const [searchValue, setSearchValue] = useState('');

    const { data, setData, post, processing, reset, errors } = useForm({
        user_id: user?.id || null,
        type: 'admin_adjust',
        amount: 0,
        description: '',
    });

    useEffect(() => {
        if (user) {
            setSelectedUser(user);
            setData('user_id', user.id);
        }
    }, [user]);

    // Tìm kiếm user theo username
    const searchUsers = async (username: string) => {
        if (!username || username.length < 2) {
            setSearchResults([]);
            return;
        }

        setSearchLoading(true);
        try {
            const response = await axios.get(`/admin/users/search`, {
                params: { q: username }
            });
            setSearchResults(response.data.users || []);
        } catch (error) {
            console.error('Search error:', error);
            message.error('Lỗi khi tìm kiếm user!');
            setSearchResults([]);
        } finally {
            setSearchLoading(false);
        }
    };

    // Debounce search
    useEffect(() => {
        const timer = setTimeout(() => {
            if (searchValue) {
                searchUsers(searchValue);
            }
        }, 500);

        return () => clearTimeout(timer);
    }, [searchValue]);

    const handleSelectUser = (user: IUser) => {
        setSelectedUser(user);
        setData('user_id', user.id);
        setSearchResults([]);
        setSearchValue('');
        form.setFieldsValue({ username: user.username });
    };

    const handleSubmit = async (values: any) => {
        if (!selectedUser) {
            message.error('Vui lòng chọn user!');
            return;
        }

        const submitData = {
            user_id: selectedUser.id,
            type: values.type,
            amount: values.amount,
            description: values.description || `Admin ${values.type === 'bonus' ? 'tặng' : 'điều chỉnh'} ${formatCurrency(values.amount)}`
        };

        router.post('/admin/transactions/add-money', submitData, {
            onSuccess: () => {
                message.success(`Đã ${values.amount > 0 ? 'cộng' : 'trừ'} ${formatCurrency(Math.abs(values.amount))} cho ${selectedUser.username}!`);
                onClose();
                reset();
            },
            onError: (errors) => {
                console.error('Submission errors:', errors);
                message.error('Có lỗi xảy ra. Vui lòng thử lại!');
            }
        });
    };

    const formatCurrency = (amount: number): string => {
        return new Intl.NumberFormat('vi-VN', {
            style: 'currency',
            currency: 'VND'
        }).format(amount);
    };

    const renderUserCard = (user: IUser, isSelected = false) => (
        <Card
            key={user.id}
            size="small"
            className={`cursor-pointer transition-all ${isSelected ? 'border-blue-500 bg-blue-50' : 'hover:border-gray-400'}`}
            onClick={() => handleSelectUser(user)}
        >
            <div className="flex items-center gap-3">
                <Avatar
                    size={40}
                    src={user.avatar}
                    icon={<UserOutlined />}
                    className="bg-blue-100"
                />
                <div className="flex-1">
                    <div className="font-medium text-gray-900">{user.username}</div>
                    <div className="text-sm text-gray-500">ID: {user.id}</div>
                    {user.email && (
                        <div className="text-xs text-gray-400">{user.email}</div>
                    )}
                    {user.balance !== undefined && (
                        <div className="text-sm font-medium text-green-600">
                            Số dư: {formatNumber(user.balance)}
                        </div>
                    )}
                </div>
            </div>
        </Card>
    );

    return (
        <Modal
            open
            onCancel={onClose}
            footer={null}
            width={600}
            title={
                <div className="flex items-center gap-2">
                    <DollarOutlined className="text-green-600" />
                    <span>Điều chỉnh số dư User</span>
                </div>
            }
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
                disabled={processing}
                className="space-y-4"
            >
                {/* Tìm kiếm User */}
                {!selectedUser && (
                    <Form.Item
                        label="Tìm kiếm User"
                        name="username"
                        rules={[{ required: !selectedUser, message: 'Vui lòng chọn user!' }]}
                    >
                        <Input
                            placeholder="Nhập tên user để tìm kiếm..."
                            prefix={<SearchOutlined />}
                            value={searchValue}
                            onChange={(e) => setSearchValue(e.target.value)}
                            // loading={searchLoading}
                            allowClear
                        />
                    </Form.Item>
                )}

                {/* Kết quả tìm kiếm */}
                {searchResults.length > 0 && (
                    <div className="space-y-2 max-h-64 overflow-y-auto">
                        <Text strong className="text-sm text-gray-600">Kết quả tìm kiếm:</Text>
                        {searchResults.map(user => renderUserCard(user))}
                    </div>
                )}

                {/* User đã chọn */}
                {selectedUser && (
                    <div>
                        <Text strong className="text-sm text-gray-600 block mb-2">User đã chọn:</Text>
                        {renderUserCard(selectedUser, true)}
                        <Button
                            type="link"
                            size="small"
                            onClick={() => {
                                setSelectedUser(null);
                                setData('user_id', null);
                                form.resetFields(['username']);
                            }}
                            className="mt-2 p-0"
                        >
                            Chọn user khác
                        </Button>
                    </div>
                )}

                <Divider />

                {/* Loại giao dịch */}
                <Form.Item
                    label="Loại giao dịch"
                    name="type"
                    initialValue="bonus"
                    rules={[{ required: true, message: 'Vui lòng chọn loại giao dịch!' }]}
                >
                    <Select
                        placeholder="Chọn loại giao dịch"
                        onChange={(value) => setData('type', value)}
                    >
                        <Select.Option value="bonus">
                            <Space>
                                <PlusOutlined className="text-green-600" />
                                <span>Thưởng/Tặng tiền</span>
                            </Space>
                        </Select.Option>
                        <Select.Option value="admin_adjust">
                            <Space>
                                <MinusOutlined className="text-orange-600" />
                                <span>Điều chỉnh số dư</span>
                            </Space>
                        </Select.Option>
                    </Select>
                </Form.Item>

                {/* Số tiền */}
                <Form.Item
                    label="Số tiền"
                    name="amount"
                    rules={[
                        { required: true, message: 'Vui lòng nhập số tiền!' },
                        { type: 'number', min: -999999999, max: 999999999, message: 'Số tiền không hợp lệ!' }
                    ]}
                    extra="Số dương để cộng tiền, số âm để trừ tiền"
                >
                    <InputNumber
                        placeholder="Nhập số tiền (VND)"
                        style={{ width: '100%' }}
                        formatter={(value) => `${value}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                        parser={(value) => value!.replace(/\$\s?|(,*)/g, '')}
                        precision={0}
                        controls={false}
                        addonAfter="VND"
                        onChange={(value) => setData('amount', Number(value || 0))}
                    />
                </Form.Item>

                {/* Mô tả */}
                <Form.Item
                    label="Mô tả"
                    name="description"
                    rules={[
                        { max: 500, message: 'Mô tả không được vượt quá 500 ký tự!' }
                    ]}
                >
                    <TextArea
                        placeholder="Nhập mô tả cho giao dịch này (tùy chọn)"
                        rows={3}
                        showCount
                        maxLength={500}
                        onChange={(e) => setData('description', e.target.value)}
                    />
                </Form.Item>

                {/* Submit Buttons */}
                <Form.Item className="mb-0 pt-4">
                    <div className="flex gap-3 justify-end">
                        <Button
                            size="large"
                            onClick={onClose}
                            disabled={processing}
                        >
                            Hủy
                        </Button>
                        <Button
                            type="primary"
                            size="large"
                            htmlType="submit"
                            loading={processing}
                            disabled={!selectedUser}
                            icon={<DollarOutlined />}
                        >
                            Thực hiện
                        </Button>
                    </div>
                </Form.Item>
            </Form>
        </Modal>
    );
}