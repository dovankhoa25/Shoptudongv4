import React, { useState, useEffect } from 'react';
import { Modal, Form, Select, InputNumber, Button, Space, Alert, Divider, Descriptions, Badge, Spin, Table, Tag } from 'antd';
import { AlertCircle, Filter, RefreshCw, User, Package } from 'lucide-react';
import axios from 'axios';

interface BulkUpdateModalProps {
    visible: boolean;
    onClose: () => void;
    onSuccess: () => void;
    categories: Array<{ id: number; name: string }>;
}

interface PreviewData {
    total_records: number;
    status_breakdown: Record<string, number>;
    category_breakdown: Array<{
        category_id: number;
        category_name: string;
        total: number;
    }>;
    user_breakdown: Array<{
        user_id: number;
        username: string;
        total: number;
    }>;
    sample_nicks: Array<{
        id: number;
        account_name: string;
        price: number;
        status: string;
        category: string;
        user: string;
    }>;
    filters_applied: any;
}

interface CTVUser {
    id: number;
    username: string;
    email: string | null;
    roles: string;
    stats: {
        total_nicks: number;
        pending_nicks: number;
        hide_nicks: number;
        sold_nicks: number;
        not_sold_nicks: number;
    };
    label: string;
}

export const BulkUpdateModal: React.FC<BulkUpdateModalProps> = ({
    visible,
    onClose,
    onSuccess,
    categories
}) => {
    const [form] = Form.useForm();
    const [loading, setLoading] = useState(false);
    const [previewLoading, setPreviewLoading] = useState(false);
    const [previewData, setPreviewData] = useState<PreviewData | null>(null);
    const [accountPatterns, setAccountPatterns] = useState<Array<{ pattern: string; count: number; label: string }>>([]);
    const [ctvList, setCtvList] = useState<CTVUser[]>([]);

    useEffect(() => {
        if (visible) {
            loadAccountPatterns();
            loadCTVList();
        }
    }, [visible]);

    const loadAccountPatterns = async () => {
        try {
            const response = await axios.get('/admin/nicks/account-patterns');
            setAccountPatterns(response.data.data || []);
        } catch (error) {
            console.error('Failed to load account patterns:', error);
            setAccountPatterns([]);
        }
    };

    const loadCTVList = async () => {
        try {
            const response = await axios.get('/admin/nicks/ctv-list');
            const users = response.data.data || [];

            // Ensure data structure is correct
            const formattedUsers = users.map((user: any) => ({
                id: user.id,
                username: user.username,
                email: user.email,
                roles: user.roles,
                stats: {
                    total_nicks: user.stats?.total_nicks ?? 0,
                    pending_nicks: user.stats?.pending_nicks ?? 0,
                    hide_nicks: user.stats?.hide_nicks ?? 0,
                    sold_nicks: user.stats?.sold_nicks ?? 0,
                    not_sold_nicks: user.stats?.not_sold_nicks ?? 0
                },
                label: user.label
            }));

            setCtvList(formattedUsers);
        } catch (error) {
            console.error('Failed to load CTV list:', error);
            setCtvList([]);
        }
    };

    const handlePreview = async () => {
        try {
            setPreviewLoading(true);
            const values = form.getFieldsValue();

            const response = await axios.post('/admin/nicks/bulk-preview', values);
            setPreviewData(response.data.data);
        } catch (error: any) {
            Modal.warning({
                title: 'mess',
                content: error.response?.data?.message || 'Không thể tải preview'
            });
        } finally {
            setPreviewLoading(false);
        }
    };

    const handleSubmit = async () => {
        try {
            const values = await form.validateFields();

            if (!previewData || previewData.total_records === 0) {
                Modal.warning({
                    title: 'Cảnh báo',
                    content: 'Vui lòng xem preview trước khi cập nhật'
                });
                return;
            }

            Modal.confirm({
                title: 'Xác nhận cập nhật',
                content: (
                    <div>
                        <p>Bạn có chắc chắn muốn cập nhật <strong>{previewData.total_records}</strong> nick?</p>
                        <Alert
                            message="Cảnh báo"
                            description="Hành động này không thể hoàn tác!"
                            type="warning"
                            showIcon
                            className="mt-2"
                        />
                    </div>
                ),
                okText: 'Xác nhận',
                cancelText: 'Hủy',
                okButtonProps: { danger: true },
                onOk: async () => {
                    setLoading(true);
                    try {
                        const response = await axios.post('/admin/nicks/bulk-update', values);

                        Modal.success({
                            title: 'Thành công',
                            content: response.data.message
                        });

                        form.resetFields();
                        setPreviewData(null);
                        onSuccess();
                        onClose();
                    } catch (error: any) {
                        Modal.error({
                            title: 'Lỗi',
                            content: error.response?.data?.message || 'Cập nhật thất bại'
                        });
                    } finally {
                        setLoading(false);
                    }
                }
            });
        } catch (error) {
            console.error('Validation failed:', error);
        }
    };

    const getStatusBadge = (status: string, count: number) => {
        const config: Record<string, { color: string; text: string }> = {
            not_sold: { color: 'processing', text: 'Chưa bán' },
            sold: { color: 'success', text: 'Đã bán' },
            deleted: { color: 'error', text: 'Đã xóa' },
            return: { color: 'warning', text: 'Hoàn trả' },
            hide: { color: 'default', text: 'Tạm ẩn' },
            pending: { color: 'purple', text: 'Đang chờ' }
        };

        const statusConfig = config[status] || { color: 'default', text: status };

        return (
            <Tag color={statusConfig.color}>
                {statusConfig.text}: {count}
            </Tag>
        );
    };

    const sampleColumns = [
        {
            title: 'ID',
            dataIndex: 'id',
            key: 'id',
            width: 60,
        },
        {
            title: 'Tên nick',
            dataIndex: 'account_name',
            key: 'account_name',
        },
        {
            title: 'Giá',
            dataIndex: 'price',
            key: 'price',
            render: (price: number) => new Intl.NumberFormat('vi-VN', {
                style: 'currency',
                currency: 'VND'
            }).format(price)
        },
        {
            title: 'Trạng thái',
            dataIndex: 'status',
            key: 'status',
            render: (status: string) => {
                const config: any = {
                    not_sold: { color: 'processing', text: 'Chưa bán' },
                    sold: { color: 'success', text: 'Đã bán' },
                    hide: { color: 'default', text: 'Tạm ẩn' },
                    pending: { color: 'purple', text: 'Đang chờ' }
                };
                return <Tag color={config[status]?.color}>{config[status]?.text || status}</Tag>;
            }
        },
        {
            title: 'CTV',
            dataIndex: 'user',
            key: 'user',
        }
    ];

    return (
        <Modal
            title={<div className="flex items-center gap-2">
                <Filter className="w-5 h-5" />
                <span>Cập nhật hàng loạt Nick</span>
            </div>}
            open={visible}
            onCancel={onClose}
            width={1200}
            footer={null}
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
            <Alert
                message="Lưu ý quan trọng"
                description="Hãy cẩn thận khi cập nhật hàng loạt. Vui lòng preview kỹ trước khi thực hiện. Hành động này không thể hoàn tác!"
                type="warning"
                showIcon
                icon={<AlertCircle />}
                className="mb-4"
            />

            <Form
                form={form}
                layout="vertical"
                initialValues={{ limit: 100 }}
            >
                <div className="grid grid-cols-2 gap-4">
                    {/* Filter Section */}
                    <Form.Item
                        label="Lọc theo tên nick (account_name)"
                        name="account_name_pattern"
                        tooltip="Tìm kiếm nick có chứa từ khóa này"
                    >
                        <Select
                            placeholder="Nhập hoặc chọn pattern"
                            allowClear
                            showSearch
                            mode="tags"
                            maxCount={1}
                            options={accountPatterns.map(p => ({
                                label: p.label,
                                value: p.pattern
                            }))}
                        />
                    </Form.Item>

                    <Form.Item
                        label="Lọc theo CTV"
                        name="user_id"
                        tooltip="Chọn CTV để lọc nick của họ"
                    >
                        <Select
                            placeholder="Chọn CTV"
                            allowClear
                            showSearch
                            filterOption={(input, option) =>
                                (option?.label?.toString() ?? '').toLowerCase().includes(input.toLowerCase())
                            }
                        >
                            {ctvList.map(ctv => (
                                <Select.Option key={ctv.id} value={ctv.id} label={ctv.label}>
                                    <div className="flex justify-between items-center">
                                        <div className="flex flex-col">
                                            <span className="font-medium">{ctv.username}</span>
                                            <span className="text-xs text-gray-500">{ctv.roles}</span>
                                        </div>
                                        <Space size={4}>
                                            <Badge
                                                count={ctv.stats.total_nicks}
                                                showZero
                                                style={{ backgroundColor: '#52c41a' }}
                                                title="Tổng nick"
                                            />
                                            <Badge
                                                count={ctv.stats.pending_nicks}
                                                showZero
                                                style={{ backgroundColor: '#722ed1' }}
                                                title="Đang chờ"
                                            />
                                            <Badge
                                                count={ctv.stats.hide_nicks}
                                                showZero
                                                title="Tạm ẩn"
                                            />
                                        </Space>
                                    </div>
                                </Select.Option>
                            ))}
                        </Select>
                    </Form.Item>

                    <Form.Item
                        label="Lọc theo Danh mục"
                        name="category_id"
                    >
                        <Select
                            placeholder="Chọn danh mục"
                            allowClear
                            options={categories.map(cat => ({
                                label: cat.name,
                                value: cat.id
                            }))}
                        />
                    </Form.Item>

                    <Form.Item
                        label="Trạng thái hiện tại"
                        name="current_status"
                    >
                        <Select
                            placeholder="Chọn trạng thái"
                            allowClear
                            options={[
                                { label: 'Chưa bán', value: 'not_sold' },
                                { label: 'Đã bán', value: 'sold' },
                                { label: 'Đã xóa', value: 'deleted' },
                                { label: 'Hoàn trả', value: 'return' },
                                { label: 'Tạm ẩn', value: 'hide' },
                                { label: 'Đang chờ', value: 'pending' }
                            ]}
                        />
                    </Form.Item>

                    <Form.Item
                        label="Loại tin"
                        name="listing_type"
                    >
                        <Select
                            placeholder="Chọn loại tin"
                            allowClear
                            options={[
                                { label: 'VIP', value: 'vip' },
                                { label: 'Normal', value: 'normal' }
                            ]}
                        />
                    </Form.Item>

                    <Form.Item
                        label="Giá từ"
                        name="price_from"
                    >
                        <InputNumber
                            className="w-full"
                            placeholder="0"
                            min={0}
                            formatter={value => `${value}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                        />
                    </Form.Item>

                    <Form.Item
                        label="Giá đến"
                        name="price_to"
                    >
                        <InputNumber
                            className="w-full"
                            placeholder="0"
                            min={0}
                            formatter={value => `${value}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                        />
                    </Form.Item>

                    <Form.Item
                        label="Từ ngày"
                        name="date_from"
                    >
                        <input
                            type="date"
                            className="ant-input"
                        />
                    </Form.Item>

                    <Form.Item
                        label="Đến ngày"
                        name="date_to"
                    >
                        <input
                            type="date"
                            className="ant-input"
                        />
                    </Form.Item>
                </div>

                <Divider />

                {/* Action Section */}
                <div className="grid grid-cols-2 gap-4">
                    <Form.Item
                        label="Trạng thái mới"
                        name="new_status"
                        rules={[{ required: true, message: 'Vui lòng chọn trạng thái mới' }]}
                    >
                        <Select
                            placeholder="Chọn trạng thái mới"
                            options={[
                                { label: 'Chưa bán', value: 'not_sold' },
                                { label: 'Đã bán', value: 'sold' },
                                { label: 'Đã xóa', value: 'deleted' },
                                { label: 'Hoàn trả', value: 'return' },
                                { label: 'Tạm ẩn', value: 'hide' },
                                { label: 'Đang chờ', value: 'pending' }
                            ]}
                        />
                    </Form.Item>

                    <Form.Item
                        label="Giới hạn số lượng"
                        name="limit"
                        rules={[{ required: true, message: 'Vui lòng nhập giới hạn' }]}
                    >
                        <InputNumber
                            min={1}
                            max={1000}
                            className="w-full"
                            placeholder="Tối đa 1000"
                        />
                    </Form.Item>
                </div>

                <Button
                    type="dashed"
                    icon={<RefreshCw className="w-4 h-4" />}
                    onClick={handlePreview}
                    loading={previewLoading}
                    block
                    size="large"
                    className="mb-4"
                >
                    Xem Preview
                </Button>

                {/* Preview Section */}
                {previewLoading && (
                    <div className="text-center py-8">
                        <Spin tip="Đang tải preview..." size="large" />
                    </div>
                )}

                {previewData && !previewLoading && (
                    <div className="bg-gray-50 dark:bg-gray-800 p-4 rounded-lg">
                        <h4 className="font-semibold mb-3 flex items-center gap-2">
                            <Package className="w-5 h-5" />
                            Kết quả Preview:
                        </h4>

                        <Alert
                            message={`Tìm thấy ${previewData.total_records} nick phù hợp`}
                            type={previewData.total_records > 0 ? 'info' : 'warning'}
                            className="mb-3"
                        />

                        {previewData.total_records > 0 && (
                            <>
                                <div className="mb-3">
                                    <div className="font-medium mb-2">Phân bổ theo trạng thái:</div>
                                    <Space wrap>
                                        {Object.entries(previewData.status_breakdown).map(([status, count]) => (
                                            getStatusBadge(status, count as number)
                                        ))}
                                    </Space>
                                </div>

                                {previewData.user_breakdown && previewData.user_breakdown.length > 0 && (
                                    <div className="mb-3">
                                        <div className="font-medium mb-2">Phân bổ theo CTV:</div>
                                        <Space wrap>
                                            {previewData.user_breakdown.map((user, index) => (
                                                <Tag key={index} icon={<User className="w-3 h-3" />}>
                                                    {user.username}: {user.total}
                                                </Tag>
                                            ))}
                                        </Space>
                                    </div>
                                )}

                                {previewData.sample_nicks && previewData.sample_nicks.length > 0 && (
                                    <div className="mt-4">
                                        <div className="font-medium mb-2">Mẫu 10 nick đầu tiên:</div>
                                        <Table
                                            dataSource={previewData.sample_nicks}
                                            columns={sampleColumns}
                                            pagination={false}
                                            size="small"
                                            rowKey="id"
                                            scroll={{ x: 600 }}
                                        />
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                )}
            </Form>

            <div className="flex justify-end gap-2 mt-4">
                <Button onClick={onClose} size="large">
                    Hủy
                </Button>
                <Button
                    type="primary"
                    onClick={handleSubmit}
                    loading={loading}
                    disabled={!previewData || previewData.total_records === 0}
                    size="large"
                    danger
                >
                    Xác nhận cập nhật
                </Button>
            </div>
        </Modal>
    );
};