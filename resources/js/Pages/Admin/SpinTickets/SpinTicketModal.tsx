// Admin/SpinTickets/SpinTicketModal.tsx
import React, { useState, useEffect } from 'react';
import { Modal, Form, Select, InputNumber, Card, Alert, Button, Spin, Avatar, Typography } from 'antd';
import { Ticket, User, Gift, Save } from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import axios from 'axios';
import { router } from '@inertiajs/react';
import { formatNumber } from '@/Utils/currencyHelper';

const { Text } = Typography;

interface SpinTicketModalProps {
    open: boolean;
    onClose: () => void;
    ticketId?: number | null;
    spins: Array<{ id: number; name: string }>;
}

interface ISpinTicketData {
    id: number;
    user_id: number;
    spin_id: number;
    turns_remaining: number;
    user?: {
        id: number;
        name: string;
        email: string;
        username?: string;
        balance?: number;
        avatar?: string;
    };
    spin?: {
        id: number;
        name: string;
    };
}

interface IUserSearchResult {
    id: number;
    username: string;
    email: string;
    avatar?: string | null;
    balance?: number;
}

const SpinTicketModal: React.FC<SpinTicketModalProps> = ({
    open,
    onClose,
    ticketId,
    spins
}) => {
    const [form] = Form.useForm();
    const [loading, setLoading] = useState(false);
    const [loadingData, setLoadingData] = useState(false);
    const [ticketData, setTicketData] = useState<ISpinTicketData | null>(null);
    const [searchingUsers, setSearchingUsers] = useState(false);
    const [users, setUsers] = useState<IUserSearchResult[]>([]);
    const [searchValue, setSearchValue] = useState('');
    const toast = useToast();

    const isEditMode = !!ticketId;

    // Fetch ticket data when editing
    useEffect(() => {
        if (open && ticketId) {
            fetchTicketData();
        } else if (open && !ticketId) {
            form.resetFields();
            setUsers([]);
            setSearchValue('');
        }
    }, [open, ticketId]);

    const fetchTicketData = async () => {
        if (!ticketId) return;

        setLoadingData(true);
        try {
            const response = await axios.get(`/admin/spin-tickets/${ticketId}/edit`);
            const ticket = response.data.ticket;

            setTicketData(ticket);

            form.setFieldsValue({
                user_id: ticket.user_id,
                spin_id: ticket.spin_id,
                turns_remaining: ticket.turns_remaining,
            });

            // Set user for select
            if (ticket.user) {
                setUsers([{
                    id: ticket.user.id,
                    username: ticket.user.username || ticket.user.name,
                    email: ticket.user.email,
                    avatar: ticket.user.avatar || null,
                    balance: ticket.user.balance
                }]);
            }

        } catch (error) {
            console.error('Error fetching ticket data:', error);
            toast.error('Không thể tải thông tin lượt quay!');
            onClose();
        } finally {
            setLoadingData(false);
        }
    };

    // ✅ Sử dụng API search có sẵn
    const handleSearchUsers = async (search: string) => {
        if (!search || search.length < 2) {
            setUsers([]);
            return;
        }

        setSearchingUsers(true);
        try {
            // ✅ Sử dụng API /admin/users/search với param 'q'
            const response = await axios.get('/admin/users/search', {
                params: { q: search }
            });

            // ✅ API trả về { users: [...] }
            const userData = response.data.users || response.data.data || [];
            setUsers(userData);
        } catch (error) {
            console.error('Error searching users:', error);
            toast.error('Không thể tìm kiếm người dùng!');
            setUsers([]);
        } finally {
            setSearchingUsers(false);
        }
    };

    // ✅ Debounce search
    useEffect(() => {
        const timer = setTimeout(() => {
            if (searchValue) {
                handleSearchUsers(searchValue);
            } else {
                setUsers([]);
            }
        }, 500);

        return () => clearTimeout(timer);
    }, [searchValue]);

    const handleSubmit = async (values: any) => {
        if (!values.user_id) {
            toast.error('Vui lòng chọn người dùng!');
            return;
        }

        setLoading(true);

        try {
            const data = {
                user_id: values.user_id,
                spin_id: values.spin_id,
                turns_remaining: values.turns_remaining,
            };

            if (isEditMode) {
                await axios.put(`/admin/spin-tickets/${ticketId}`, data);
                toast.success('Cập nhật lượt quay thành công!');
            } else {
                await axios.post('/admin/spin-tickets', data);
                toast.success('Cấp lượt quay thành công!');
            }

            onClose();
            router.reload();

        } catch (error: any) {
            console.error('Submit error:', error);
            const errorMessage = error.response?.data?.message ||
                (isEditMode ? 'Cập nhật lượt quay thất bại!' : 'Cấp lượt quay thất bại!');
            toast.error(errorMessage);

            if (error.response?.data?.errors) {
                Object.entries(error.response.data.errors).forEach(([key, messages]: [string, any]) => {
                    if (Array.isArray(messages)) {
                        messages.forEach(msg => toast.error(msg));
                    }
                });
            }
        } finally {
            setLoading(false);
        }
    };

    const handleClose = () => {
        form.resetFields();
        setUsers([]);
        setTicketData(null);
        setSearchValue('');
        onClose();
    };

    if (loadingData) {
        return (
            <Modal
                title="Đang tải..."
                open={open}
                onCancel={handleClose}
                footer={null}
                width={600}
                centered
            >
                <div className="flex justify-center items-center py-20">
                    <Spin size="large" />
                    <span className="ml-3 text-gray-600">Đang tải dữ liệu...</span>
                </div>
            </Modal>
        );
    }

    return (
        <Modal
            title={
                <div className="flex items-center gap-2">
                    <Ticket className="w-5 h-5 text-blue-500" />
                    <span>{isEditMode ? "Chỉnh sửa lượt quay" : "Cấp lượt quay mới"}</span>
                </div>
            }
            open={open}
            onCancel={handleClose}
            width={600}
            footer={[
                <Button key="cancel" onClick={handleClose} disabled={loading}>
                    Hủy
                </Button>,
                <Button
                    key="submit"
                    type="primary"
                    icon={<Save className="w-4 h-4" />}
                    onClick={() => form.submit()}
                    loading={loading}
                >
                    {isEditMode ? 'Cập nhật' : 'Cấp lượt'}
                </Button>
            ]}
            className="spin-ticket-modal"
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
                size="large"
                className="mt-4"
                preserve={false}
                initialValues={{
                    turns_remaining: 1
                }}
            >
                <Card size="small">
                    {/* User Search */}
                    <Form.Item
                        label="Người dùng"
                        name="user_id"
                        rules={[{ required: true, message: 'Vui lòng chọn người dùng!' }]}
                    >
                        <Select
                            showSearch
                            placeholder="Tìm kiếm người dùng theo tên..."
                            filterOption={false}
                            onSearch={setSearchValue}
                            searchValue={searchValue}
                            loading={searchingUsers}
                            disabled={isEditMode}
                            notFoundContent={
                                searchingUsers ? (
                                    <div className="text-center py-4">
                                        <Spin size="small" />
                                        <div className="text-gray-500 text-sm mt-2">Đang tìm kiếm...</div>
                                    </div>
                                ) : searchValue && searchValue.length >= 2 && users.length === 0 ? (
                                    <div className="text-center py-4 text-gray-500">
                                        Không tìm thấy người dùng
                                    </div>
                                ) : (
                                    <div className="text-center py-4 text-gray-400 text-sm">
                                        Nhập ít nhất 2 ký tự để tìm kiếm
                                    </div>
                                )
                            }
                            options={users.map(user => ({
                                label: (
                                    <div className="flex items-center gap-3 py-1">
                                        <Avatar
                                            size={36}
                                            src={user.avatar}
                                            icon={<User className="w-4 h-4" />}
                                            className="bg-blue-100 flex-shrink-0"
                                        />
                                        <div className="flex-1 min-w-0">
                                            <div className="font-medium text-gray-900 truncate">
                                                {user.username}
                                            </div>
                                            <div className="text-xs text-gray-500 truncate">
                                                {user.email}
                                            </div>
                                            {user.balance !== undefined && (
                                                <div className="text-xs text-green-600 font-medium">
                                                    Số dư: {formatNumber(user.balance)}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                ),
                                value: user.id
                            }))}
                        />
                    </Form.Item>

                    {/* Show selected user info in edit mode */}
                    {isEditMode && ticketData?.user && (
                        <Alert
                            message={
                                <div className="flex items-center gap-3">
                                    <Avatar
                                        size={32}
                                        src={ticketData.user.avatar}
                                        icon={<User className="w-4 h-4" />}
                                    />
                                    <div>
                                        <div className="font-medium">
                                            {ticketData.user.username || ticketData.user.name}
                                        </div>
                                        <div className="text-xs text-gray-500">
                                            {ticketData.user.email}
                                        </div>
                                    </div>
                                </div>
                            }
                            type="info"
                            showIcon
                            className="mb-4"
                        />
                    )}

                    {/* Spin Select */}
                    <Form.Item
                        label="Vòng quay"
                        name="spin_id"
                        rules={[{ required: true, message: 'Vui lòng chọn vòng quay!' }]}
                    >
                        <Select
                            placeholder="Chọn vòng quay"
                            showSearch
                            optionFilterProp="children"
                            disabled={isEditMode}
                            options={spins.map(spin => ({
                                label: (
                                    <div className="flex items-center gap-2">
                                        <Gift className="w-4 h-4 text-purple-500" />
                                        <span>{spin.name}</span>
                                    </div>
                                ),
                                value: spin.id
                            }))}
                        />
                    </Form.Item>

                    {/* Show spin info in edit mode */}
                    {isEditMode && ticketData?.spin && (
                        <Alert
                            message={`Vòng quay: ${ticketData.spin.name}`}
                            type="info"
                            showIcon
                            className="mb-4"
                        />
                    )}

                    {/* Turns Input */}
                    <Form.Item
                        label={isEditMode ? "Số lượt còn lại" : "Số lượt cấp"}
                        name="turns_remaining"
                        rules={[
                            { required: true, message: 'Vui lòng nhập số lượt!' },
                            { type: 'number', min: 0, message: 'Số lượt phải lớn hơn hoặc bằng 0!' }
                        ]}
                        help={isEditMode
                            ? "Cập nhật số lượt còn lại của người dùng"
                            : "Số lượt này sẽ được CỘNG vào lượt hiện có của người dùng (nếu có)"
                        }
                    >
                        <InputNumber
                            placeholder={isEditMode ? "Nhập số lượt còn lại" : "Nhập số lượt cấp"}
                            className="w-full"
                            min={0}
                            step={1}
                            prefix={<Ticket className="w-4 h-4 text-gray-400" />}
                        />
                    </Form.Item>

                    {/* Info Box */}
                    <div className="bg-blue-50 border border-blue-200 rounded-lg p-3">
                        <div className="text-xs text-blue-700 space-y-1">
                            <div className="font-semibold mb-2">💡 Lưu ý:</div>
                            {isEditMode ? (
                                <>
                                    <div>• Không thể thay đổi người dùng hoặc vòng quay</div>
                                    <div>• Chỉ có thể cập nhật số lượt còn lại</div>
                                    <div>• Số lượt sẽ được SET về giá trị mới (không cộng dồn)</div>
                                </>
                            ) : (
                                <>
                                    <div>• Nhập tối thiểu 2 ký tự để tìm kiếm người dùng</div>
                                    <div>• Nếu người dùng đã có lượt cho vòng quay này, số lượt sẽ được CỘNG thêm</div>
                                    <div>• Nếu chưa có, sẽ tạo mới với số lượt đã nhập</div>
                                    <div>• Mỗi người dùng chỉ có 1 ticket cho mỗi vòng quay</div>
                                </>
                            )}
                        </div>
                    </div>
                </Card>
            </Form>
        </Modal>
    );
};

export default SpinTicketModal;