// Admin/ServiceOrders/ServiceOrderDetailModal.tsx
import React from 'react';
import { Modal, Descriptions, Tag, Button, Card, Typography, Space, Tooltip } from 'antd';
import {
    FileText, CheckCircle, Truck, CreditCard, Calendar,
    Settings, Users, User, Clock, Shield, Copy, XCircle
} from 'lucide-react';
import { formatDate, formatPrice } from '@/Utils/currencyHelper';
import { useToast } from "@/Components/ToastProvider";

const { Text, Title } = Typography;

interface IUser {
    id: number;
    username: string;
}

interface IService {
    id: number;
    name: string;
    processing_time: string;
    warranty: string;
}

interface IServiceOrder {
    id: number;
    service_id: number;
    service_price: number;
    account: string;
    password?: string; // Add optional password field
    description: string;
    field_values_json: any;
    status: string;
    user: IUser;
    receiver: IUser;
    service: IService;
    created_at: string;
    updated_at: string;
}

interface Props {
    open: boolean;
    order: IServiceOrder | null;
    onClose: () => void;
    onAccept?: (order: IServiceOrder) => void;
    onCompleted?: (order: IServiceOrder) => void; // Add completed action
    onCancel?: (order: IServiceOrder) => void;    // Add cancel action
    showPassword?: boolean; // Add prop to control password visibility
}

export default function ServiceOrderDetailModal({
    open,
    order,
    onClose,
    onAccept,
    onCompleted,
    onCancel,
    showPassword = false
}: Props) {
    const toast = useToast();

    // Status configuration
    const statusConfig = {
        pending: {
            label: 'Chờ xử lý',
            tagColor: 'orange'
        },
        approved: {
            label: 'Đang xử lý',
            tagColor: 'blue'
        },
        completed: {
            label: 'Hoàn thành',
            tagColor: 'green'
        },
        rejected: {
            label: 'Đã hủy',
            tagColor: 'red'
        },

    };

    const copyToClipboard = (text: string) => {
        navigator.clipboard.writeText(text).then(() => {
            toast.success('Đã sao chép!');
        });
    };

    const handleAccept = () => {
        if (order && onAccept) {
            onAccept(order);
            onClose();
        }
    };

    const handleCompleted = () => {
        if (order && onCompleted) {
            onCompleted(order);
            onClose();
        }
    };

    const handleCancel = () => {
        if (order && onCancel) {
            onCancel(order);
            onClose();
        }
    };

    const renderModalFieldValues = (fieldValues: any) => {
        try {
            let values = typeof fieldValues === 'string' ? JSON.parse(fieldValues) : fieldValues;

            if (!values) {
                return <Text type="secondary">Không có dữ liệu trường</Text>;
            }

            if (Array.isArray(values)) {
                if (values.length === 0) {
                    return <Text type="secondary">Không có dữ liệu trường</Text>;
                }

                return (
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                        {values.map((item, index) => (
                            <Card key={index} size="small" className="shadow-sm">
                                <div className="space-y-2">
                                    <Text strong className="text-gray-700">
                                        {(item.label || item.key).replace(':', '').trim()}
                                    </Text>
                                    <div className="flex items-center justify-between">
                                        <Text code className="bg-blue-50 text-blue-800 px-2 py-1 rounded">
                                            {String(item.value)}
                                        </Text>
                                        <Tooltip title="Sao chép">
                                            <Button
                                                type="text"
                                                size="small"
                                                icon={<Copy className="w-3 h-3" />}
                                                onClick={() => copyToClipboard(String(item.value))}
                                            />
                                        </Tooltip>
                                    </div>
                                </div>
                            </Card>
                        ))}
                    </div>
                );
            }

            // Handle object format
            return (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                    {Object.entries(values).map(([key, val], index) => (
                        <Card key={index} size="small" className="shadow-sm">
                            <div className="space-y-2">
                                <Text strong className="text-gray-700">{key}</Text>
                                <div className="flex items-center justify-between">
                                    <Text code className="bg-blue-50 text-blue-800 px-2 py-1 rounded">
                                        {String(val)}
                                    </Text>
                                    <Tooltip title="Sao chép">
                                        <Button
                                            type="text"
                                            size="small"
                                            icon={<Copy className="w-3 h-3" />}
                                            onClick={() => copyToClipboard(String(val))}
                                        />
                                    </Tooltip>
                                </div>
                            </div>
                        </Card>
                    ))}
                </div>
            );
        } catch (error) {
            return <Text type="danger">Dữ liệu không hợp lệ</Text>;
        }
    };

    if (!order) return null;

    return (
        <Modal
            title={
                <div className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-lg bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center">
                        <FileText className="w-5 h-5 text-white" />
                    </div>
                    <div>
                        <Title level={4} className="mb-0">
                            Chi tiết đơn hàng #{order.id}
                        </Title>
                        <Text type="secondary" className="text-sm">
                            Thông tin đầy đủ về đơn hàng dịch vụ
                        </Text>
                    </div>
                </div>
            }
            open={open}
            onCancel={onClose}
            width={900}
            footer={
                <Space>
                    {order.status === 'pending' && onAccept && (
                        <Button
                            type="primary"
                            icon={<CheckCircle className="w-4 h-4" />}
                            onClick={handleAccept}
                        >
                            Nhận đơn hàng
                        </Button>
                    )}
                    {['approved'].includes(order.status) && onCompleted && (
                        <Button
                            type="primary"
                            icon={<CheckCircle className="w-4 h-4" />}
                            onClick={handleCompleted}
                            className="bg-green-600 hover:bg-green-700 border-green-600"
                        >
                            Xác nhận hoàn thành
                        </Button>
                    )}
                    {['approved'].includes(order.status) && onCancel && (
                        <Button
                            danger
                            icon={<XCircle className="w-4 h-4" />}
                            onClick={handleCancel}
                        >
                            Hủy đơn hàng
                        </Button>
                    )}
                    <Button onClick={onClose}>Đóng</Button>
                </Space>
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
            <div className="space-y-6">
                {/* Status & Basic Info */}
                <Card className="border-l-4 border-l-blue-500">
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div className="text-center">
                            <div className="w-16 h-16 rounded-full bg-blue-100 flex items-center justify-center mx-auto mb-2">
                                <Truck className="w-8 h-8 text-blue-600" />
                            </div>
                            <Text strong>Đơn hàng #{order.id}</Text>
                            <div className="mt-1">
                                <Tag
                                    color={statusConfig[order.status as keyof typeof statusConfig]?.tagColor || 'default'}
                                    className="px-3 py-1"
                                >
                                    {statusConfig[order.status as keyof typeof statusConfig]?.label || order.status}
                                </Tag>
                            </div>
                        </div>
                        <div className="text-center">
                            <div className="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center mx-auto mb-2">
                                <CreditCard className="w-8 h-8 text-green-600" />
                            </div>
                            <Text strong>Giá trị đơn hàng</Text>
                            <div className="mt-1">
                                <Text className="text-2xl font-bold text-green-600">
                                    {formatPrice(order.service_price)}
                                </Text>
                            </div>
                        </div>
                        <div className="text-center">
                            <div className="w-16 h-16 rounded-full bg-purple-100 flex items-center justify-center mx-auto mb-2">
                                <Calendar className="w-8 h-8 text-purple-600" />
                            </div>
                            <Text strong>Ngày tạo</Text>
                            <div className="mt-1">
                                <Text>{formatDate(order.created_at)}</Text>
                            </div>
                        </div>
                    </div>
                </Card>

                {/* Service & Users Info */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {/* Service Info */}
                    <Card title={
                        <div className="flex items-center gap-2">
                            <Settings className="w-5 h-5 text-blue-600" />
                            <span>Thông tin dịch vụ</span>
                        </div>
                    }>
                        <Descriptions column={1} size="small">
                            <Descriptions.Item label="Tên dịch vụ">
                                <Text strong>{order.service?.name || 'N/A'}</Text>
                            </Descriptions.Item>
                            <Descriptions.Item label="Thời gian xử lý">
                                <Tag icon={<Clock className="w-3 h-3" />}>
                                    {order.service?.processing_time || 'N/A'}
                                </Tag>
                            </Descriptions.Item>
                            <Descriptions.Item label="Bảo hành">
                                <Tag icon={<Shield className="w-3 h-3" />}>
                                    {order.service?.warranty || 'N/A'}
                                </Tag>
                            </Descriptions.Item>
                            <Descriptions.Item label="Tài khoản">
                                <div className="flex items-center gap-2">
                                    <Text code className="bg-gray-100 px-2 py-1 rounded">
                                        {order.account}
                                    </Text>
                                    <Tooltip title="Sao chép tài khoản">
                                        <Button
                                            type="text"
                                            size="small"
                                            icon={<Copy className="w-3 h-3" />}
                                            onClick={() => copyToClipboard(order.account)}
                                        />
                                    </Tooltip>
                                </div>
                            </Descriptions.Item>
                            {showPassword && order.password && (
                                <Descriptions.Item label="Mật khẩu">
                                    <div className="flex items-center gap-2">
                                        <Text code className="bg-red-50 text-red-800 px-2 py-1 rounded border border-red-200">
                                            {order.password}
                                        </Text>
                                        <Tooltip title="Sao chép mật khẩu">
                                            <Button
                                                type="text"
                                                size="small"
                                                icon={<Copy className="w-3 h-3" />}
                                                onClick={() => copyToClipboard(order.password || '')}
                                                className="text-red-600 hover:text-red-800"
                                            />
                                        </Tooltip>
                                    </div>
                                </Descriptions.Item>
                            )}
                        </Descriptions>
                    </Card>

                    {/* Users Info */}
                    <Card title={
                        <div className="flex items-center gap-2">
                            <Users className="w-5 h-5 text-green-600" />
                            <span>Thông tin người dùng</span>
                        </div>
                    }>
                        <div className="space-y-4">
                            <div>
                                <Text strong className="text-green-600">Khách hàng</Text>
                                <div className="mt-1 p-3 bg-green-50 rounded">
                                    <div className="flex items-center gap-2">
                                        <User className="w-4 h-4 text-green-600" />
                                        <Text strong>{order.user?.username || 'N/A'}</Text>
                                    </div>
                                    <Text type="secondary" className="text-sm">
                                        ID: {order.user?.id}
                                    </Text>
                                </div>
                            </div>

                            <div>
                                <Text strong className="text-blue-600">Người nhận</Text>
                                <div className="mt-1 p-3 bg-blue-50 rounded">
                                    <div className="flex items-center gap-2">
                                        <User className="w-4 h-4 text-blue-600" />
                                        <Text strong>{order.receiver?.username || 'N/A'}</Text>
                                    </div>
                                    <Text type="secondary" className="text-sm">
                                        ID: {order.receiver?.id}
                                    </Text>
                                </div>
                            </div>
                        </div>
                    </Card>
                </div>

                {/* Description */}
                {order.description && (
                    <Card title="Mô tả đơn hàng" size="small">
                        <Text>{order.description}</Text>
                    </Card>
                )}

                {/* Field Values */}
                <Card title={
                    <div className="flex items-center gap-2">
                        <FileText className="w-5 h-5 text-purple-600" />
                        <span>Thông tin trường dữ liệu</span>
                    </div>
                }>
                    {renderModalFieldValues(order.field_values_json)}
                </Card>

                {/* Timeline */}
                <Card title="Thông tin thời gian" size="small">
                    <Descriptions column={2}>
                        <Descriptions.Item label="Ngày tạo">
                            {new Date(order.created_at).toLocaleString('vi-VN')}
                        </Descriptions.Item>
                        <Descriptions.Item label="Cập nhật lần cuối">
                            {new Date(order.updated_at).toLocaleString('vi-VN')}
                        </Descriptions.Item>
                    </Descriptions>
                </Card>
            </div>
        </Modal>
    );
}