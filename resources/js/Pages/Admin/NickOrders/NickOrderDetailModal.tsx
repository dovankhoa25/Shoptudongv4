// Components/Modals/NickOrderDetailModal.tsx
import React from 'react';
import { Modal, Image, Button, Tag, Space, Typography, Card, Row, Col, Divider, Tooltip } from 'antd';
import {
    ShoppingCartOutlined,
    UserOutlined,
    DollarOutlined,
    CalendarOutlined,
    TagOutlined,
    CrownOutlined,
    PictureOutlined,
    CheckCircleOutlined,
    ClockCircleOutlined,
    UndoOutlined,
    CopyOutlined
} from '@ant-design/icons';
import { formatDate, formatPrice } from '@/Utils/currencyHelper';
import { INickOrder } from '@/InterFaces/nickOrder';

const { Title, Text, Paragraph } = Typography;

interface NickOrderDetailModalProps {
    isOpen: boolean;
    onClose: () => void;
    order: INickOrder | null;
    onStatusChange?: (order: INickOrder, newStatus: string) => void;
}

export default function NickOrderDetailModal({
    isOpen,
    onClose,
    order,
    onStatusChange
}: NickOrderDetailModalProps) {
    if (!order) return null;

    const getStatusConfig = (status: string) => {
        const statusConfig = {
            pending: {
                color: 'warning',
                text: 'Chờ xử lý',
                icon: <ClockCircleOutlined />
            },
            completed: {
                color: 'success',
                text: 'Hoàn thành',
                icon: <CheckCircleOutlined />
            },
            refunded: {
                color: 'error',
                text: 'Đã hoàn tiền',
                icon: <UndoOutlined />
            }
        };

        return statusConfig[status as keyof typeof statusConfig] || statusConfig.pending;
    };

    const renderAttributes = (attributeJson: string) => {
        try {
            const attributes = JSON.parse(attributeJson);
            return (
                <Row gutter={[8, 8]}>
                    {Object.entries(attributes).map(([key, value], index) => (
                        <Col span={12} key={index}>
                            <div className="bg-gray-50 px-3 py-2 rounded">
                                <div className="flex justify-between items-center">
                                    <Text strong className="text-gray-700 text-xs">{key}:</Text>
                                    <Text code className="text-xs">{value as string}</Text>
                                </div>
                            </div>
                        </Col>
                    ))}
                </Row>
            );
        } catch {
            return <Text type="danger">Dữ liệu không hợp lệ</Text>;
        }
    };

    const copyToClipboard = (text: string) => {
        navigator.clipboard.writeText(text);
    };

    const statusConfig = getStatusConfig(order.status);

    // Action buttons for status change
    const renderActionButtons = () => {
        if (!onStatusChange) return null;

        const buttons = [];

        if (order.status === 'pending') {
            buttons.push(
                <Button
                    key="complete"
                    type="primary"
                    icon={<CheckCircleOutlined />}
                    onClick={() => onStatusChange(order, 'completed')}
                    className="bg-green-600 hover:bg-green-700"
                >
                    Hoàn thành
                </Button>
            );
        }

        if (order.status === 'completed') {
            buttons.push(
                <Button
                    key="refund"
                    danger
                    icon={<UndoOutlined />}
                    onClick={() => onStatusChange(order, 'refunded')}
                >
                    Hoàn tiền
                </Button>
            );
        }

        return buttons;
    };

    return (
        <Modal
            title={
                <div className="flex items-center gap-3">
                    <div className="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                        <ShoppingCartOutlined className="text-blue-600 text-lg" />
                    </div>
                    <div>
                        <Title level={4} className="mb-0">
                            Chi tiết đơn hàng #{order.id}
                        </Title>
                        <Text type="secondary" className="text-sm">
                            Tạo lúc: {formatDate(order.created_at)}
                        </Text>
                    </div>
                </div>
            }
            open={isOpen}
            onCancel={onClose}
            width={1000}
            footer={[
                <Button key="close" onClick={onClose}>
                    Đóng
                </Button>,
            ]}
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
                {/* Status */}
                <div className="flex items-center justify-between">
                    <div>
                        <Text strong className="text-gray-500 block mb-2">Trạng thái</Text>
                        <Tag
                            color={statusConfig.color}
                            icon={statusConfig.icon}
                            className="px-3 py-1"
                        >
                            {statusConfig.text}
                        </Tag>
                    </div>
                </div>

                <Divider />

                {/* Main Content */}
                <Row gutter={24}>
                    {/* Left Column - Nick Info */}
                    <Col xs={24} lg={12}>
                        <Space direction="vertical" size="middle" className="w-full">
                            {/* Nick Details */}
                            <Card
                                title={
                                    <Space>
                                        <TagOutlined />
                                        <span>Thông tin Nick</span>
                                    </Space>
                                }
                                size="small"
                            >
                                {order.nick && (
                                    <Space direction="vertical" size="middle" className="w-full">
                                        {/* Nick Image & Name */}
                                        <div className="flex items-center gap-4">
                                            <div className="w-16 h-16 rounded-lg overflow-hidden border border-gray-200 bg-gray-100">
                                                {order.nick.image ? (
                                                    <Image
                                                        src={order.nick.image}
                                                        alt={order.nick.account_name}
                                                        width={64}
                                                        height={64}
                                                        className="object-cover"
                                                        fallback="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAMIAAADDCAYAAADQvc6UAAABRWlDQ1BJQ0MgUHJvZmlsZQAAKJFjYGASSSwoyGFhYGDIzSspCnJ3UoiIjFJgf8LAwSDCIMogwMCcmFxc4BgQ4ANUwgCjUcG3awyMIPqyLsis7PPOq3QdDFcvjV3jOD1boQVTPQrgSkktTgbSf4A4LbmgqISBgTEFyFYuLykAsTuAbJEioKOA7DkgdjqEvQHEToKwj4DVhAQ5A9k3gGyB5IxEoBmML4BsnSQk8XQkNtReEOBxcfXxUQg1Mjc0dyHgXNJBSWpFCYh2zi+oLMpMzyhRcASGUqqCZ16yno6CkYGRAQMDKMwhqj/fAIcloxgHQqxAjIHBEugw5sUIsSQpBobtQPdLciLEVJYzMPBHMDBsayhILEqEO4DxG0txmrERhM29nYGBddr//5/DGRjYNRkY/l7////39v///y4Dmn+LgeHANwDrkl1AuO+pmgAAADhlWElmTU0AKgAAAAgAAYdpAAQAAAABAAAAGgAAAAAAAqACAAQAAAABAAAAwqADAAQAAAABAAAAwwAAAAD9b/HnAAAHlklEQVR4Ae3dP3Ik1RnG4W+FgYxN..."
                                                    />
                                                ) : (
                                                    <div className="w-full h-full flex items-center justify-center">
                                                        <PictureOutlined className="text-2xl text-gray-400" />
                                                    </div>
                                                )}
                                            </div>

                                            <div className="flex-1">
                                                <div className="mt-1">
                                                    <Text type="secondary" className="text-sm">
                                                        Mã Số: #{order.nick.id}
                                                    </Text>
                                                </div>
                                                <div className="flex items-center gap-2 mb-1">
                                                    <Text strong>
                                                        TK: {order.nick.account_name}
                                                    </Text>
                                                    {order.nick.listing_type === 'vip' && (
                                                        <CrownOutlined className="text-yellow-500" />
                                                    )}
                                                </div>
                                                <Text strong className="text-red-600">
                                                    MK: {order.nick.account_password || 'Chưa có thông tin'}
                                                </Text>

                                                <div className="mt-1">
                                                    <Text type="secondary" className="text-sm">
                                                        Danh mục: {order.nick.category?.name || 'Chưa phân loại'}
                                                    </Text>
                                                </div>
                                                <div className="mt-1">
                                                    <Text type="success" strong>
                                                        Giá gốc: {formatPrice(order.nick.price)}
                                                    </Text>
                                                </div>
                                            </div>
                                        </div>

                                        {/* Nick Attributes */}
                                        {order.nick.attribute_cache_json && (
                                            <div>
                                                <Text strong className="block mb-2">Thuộc tính:</Text>
                                                {renderAttributes(order.nick.attribute_cache_json)}
                                            </div>
                                        )}
                                    </Space>
                                )}
                            </Card>

                            {/* Transaction Details */}
                            <Card
                                title={
                                    <Space>
                                        <DollarOutlined />
                                        <span>Chi tiết giao dịch</span>
                                    </Space>
                                }
                                size="small"
                            >
                                <Space direction="vertical" size="small" className="w-full">
                                    <div className="flex justify-between items-center">
                                        <Text>Giá bán:</Text>
                                        <Text strong className="text-green-600 text-lg">
                                            {formatPrice(order.price)}
                                        </Text>
                                    </div>

                                    {order.commission && (
                                        <div className="flex justify-between items-center">
                                            <Text>Hoa hồng:</Text>
                                            <Text strong className="text-orange-600">
                                                {formatPrice(order.commission)}
                                            </Text>
                                        </div>
                                    )}

                                    <Divider className="my-2" />

                                    <div className="flex justify-between items-center">
                                        <Text>Người bán nhận:</Text>
                                        <Text strong className="text-blue-600 text-lg">
                                            {formatPrice(order.price - (order.commission || 0))}
                                        </Text>
                                    </div>
                                </Space>
                            </Card>
                        </Space>
                    </Col>

                    {/* Right Column - User Info */}
                    <Col xs={24} lg={12}>
                        <Space direction="vertical" size="middle" className="w-full">
                            {/* Buyer Info */}
                            <Card
                                title={
                                    <Space>
                                        <UserOutlined />
                                        <span>Người mua</span>
                                    </Space>
                                }
                                size="small"
                                className="border-green-200 bg-green-50"
                            >
                                {order.buyer && (
                                    <Space direction="vertical" size="small" className="w-full">
                                        <div className="flex justify-between items-center">
                                            <Text>Tên:</Text>
                                            <Text strong>{order.buyer.name}</Text>
                                        </div>
                                        <div className="flex justify-between items-center">
                                            <Text>ID:</Text>
                                            <Space>
                                                <Text code>#{order.buyer.id}</Text>
                                                <Tooltip title="Sao chép ID">
                                                    <Button
                                                        type="text"
                                                        size="small"
                                                        icon={<CopyOutlined />}
                                                        onClick={() => copyToClipboard(order.buyer!.id.toString())}
                                                    />
                                                </Tooltip>
                                            </Space>
                                        </div>
                                        {order.buyer.email && (
                                            <div className="flex justify-between items-start">
                                                <Text>Email:</Text>
                                                <Text strong className="text-right max-w-48 break-words">
                                                    {order.buyer.email}
                                                </Text>
                                            </div>
                                        )}
                                    </Space>
                                )}
                            </Card>

                            {/* Seller Info */}
                            <Card
                                title={
                                    <Space>
                                        <UserOutlined />
                                        <span>Người bán</span>
                                    </Space>
                                }
                                size="small"
                                className="border-blue-200 bg-blue-50"
                            >
                                {order.seller && (
                                    <Space direction="vertical" size="small" className="w-full">
                                        <div className="flex justify-between items-center">
                                            <Text>Tên:</Text>
                                            <Text strong>{order.seller.name}</Text>
                                        </div>
                                        <div className="flex justify-between items-center">
                                            <Text>ID:</Text>
                                            <Space>
                                                <Text code>#{order.seller.id}</Text>
                                                <Tooltip title="Sao chép ID">
                                                    <Button
                                                        type="text"
                                                        size="small"
                                                        icon={<CopyOutlined />}
                                                        onClick={() => copyToClipboard(order.seller!.id.toString())}
                                                    />
                                                </Tooltip>
                                            </Space>
                                        </div>
                                        {order.seller.email && (
                                            <div className="flex justify-between items-start">
                                                <Text>Email:</Text>
                                                <Text strong className="text-right max-w-48 break-words">
                                                    {order.seller.email}
                                                </Text>
                                            </div>
                                        )}
                                    </Space>
                                )}
                            </Card>

                            {/* Timeline */}
                            <Card
                                title={
                                    <Space>
                                        <CalendarOutlined />
                                        <span>Thời gian</span>
                                    </Space>
                                }
                                size="small"
                            >
                                <Space direction="vertical" size="small" className="w-full">
                                    <div className="flex justify-between items-center">
                                        <Text>Tạo đơn:</Text>
                                        <Text strong>{formatDate(order.created_at)}</Text>
                                    </div>
                                    <div className="flex justify-between items-center">
                                        <Text>Cập nhật:</Text>
                                        <Text strong>{formatDate(order.updated_at)}</Text>
                                    </div>
                                </Space>
                            </Card>
                        </Space>
                    </Col>
                </Row>
            </div>
        </Modal>
    );
}