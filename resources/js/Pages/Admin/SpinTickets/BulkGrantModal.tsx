// Admin/SpinTickets/BulkGrantModal.tsx
import React, { useState } from 'react';
import { Modal, Form, Select, InputNumber, Card, Button, Transfer } from 'antd';
import { Users, Gift, Save } from 'lucide-react';
import { useToast } from "@/Components/ToastProvider";
import axios from 'axios';
import { router } from '@inertiajs/react';

interface BulkGrantModalProps {
    open: boolean;
    onClose: () => void;
    spins: Array<{ id: number; name: string }>;
}

interface IUser {
    id: number;
    name: string;
    email: string;
}

const BulkGrantModal: React.FC<BulkGrantModalProps> = ({ open, onClose, spins }) => {
    const [form] = Form.useForm();
    const [loading, setLoading] = useState(false);
    const [users, setUsers] = useState<IUser[]>([]);
    const [selectedUsers, setSelectedUsers] = useState<number[]>([]);
    const toast = useToast();

    const handleSearchUsers = async (search: string) => {
        if (!search || search.length < 2) return;

        try {
            const response = await axios.get('/admin/users/search', {
                params: { search }
            });
            setUsers(response.data.data || []);
        } catch (error) {
            toast.error('Không thể tìm kiếm người dùng!');
        }
    };

    const handleSubmit = async (values: any) => {
        if (selectedUsers.length === 0) {
            toast.error('Vui lòng chọn ít nhất 1 người dùng!');
            return;
        }

        setLoading(true);
        try {
            await axios.post('/admin/spin-tickets/bulk-grant', {
                spin_id: values.spin_id,
                user_ids: selectedUsers,
                turns: values.turns
            });

            toast.success(`Đã cấp lượt quay cho ${selectedUsers.length} người dùng!`);
            onClose();
            router.reload();
        } catch (error: any) {
            toast.error(error.response?.data?.message || 'Cấp lượt hàng loạt thất bại!');
        } finally {
            setLoading(false);
        }
    };

    return (
        <Modal
            title={
                <div className="flex items-center gap-2">
                    <Users className="w-5 h-5 text-blue-500" />
                    <span>Cấp lượt quay hàng loạt</span>
                </div>
            }
            open={open}
            onCancel={onClose}
            width={700}
            footer={[
                <Button key="cancel" onClick={onClose} disabled={loading}>
                    Hủy
                </Button>,
                <Button
                    key="submit"
                    type="primary"
                    icon={<Save className="w-4 h-4" />}
                    onClick={() => form.submit()}
                    loading={loading}
                >
                    Cấp lượt ({selectedUsers.length} người)
                </Button>
            ]}
        >
            <Form
                form={form}
                layout="vertical"
                onFinish={handleSubmit}
                size="large"
            >
                <Form.Item
                    label="Vòng quay"
                    name="spin_id"
                    rules={[{ required: true, message: 'Vui lòng chọn vòng quay!' }]}
                >
                    <Select
                        placeholder="Chọn vòng quay"
                        options={spins.map(spin => ({
                            label: spin.name,
                            value: spin.id
                        }))}
                    />
                </Form.Item>

                <Form.Item
                    label="Số lượt cấp cho mỗi người"
                    name="turns"
                    rules={[
                        { required: true, message: 'Vui lòng nhập số lượt!' },
                        { type: 'number', min: 1, message: 'Số lượt phải lớn hơn 0!' }
                    ]}
                >
                    <InputNumber
                        placeholder="Nhập số lượt"
                        className="w-full"
                        min={1}
                        step={1}
                    />
                </Form.Item>

                <Form.Item label="Tìm kiếm người dùng">
                    <Select
                        mode="multiple"
                        placeholder="Tìm theo tên hoặc email"
                        filterOption={false}
                        onSearch={handleSearchUsers}
                        value={selectedUsers}
                        onChange={setSelectedUsers}
                        options={users.map(user => ({
                            label: `${user.name} (${user.email})`,
                            value: user.id
                        }))}
                    />
                </Form.Item>
            </Form>
        </Modal>
    );
};

export default BulkGrantModal;