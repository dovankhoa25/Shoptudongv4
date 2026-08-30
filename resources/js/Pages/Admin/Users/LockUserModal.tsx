import { useState } from 'react';
import { ConfigProvider, Form, Input, Modal, Radio, message, theme } from 'antd';
import axios from 'axios';
import { IUser } from '@/InterFaces/user';
import { useTheme } from '@/Providers/ThemeProvider';

interface Props { user: IUser; onClose: () => void; onLocked: () => void }

export default function LockUserModal({ user, onClose, onLocked }: Props) {
    const { darkMode } = useTheme();
    const [form] = Form.useForm();
    const [submitting, setSubmitting] = useState(false);

    const submit = async () => {
        try {
            const values = await form.validateFields();
            setSubmitting(true);
            await axios.post(`/admin/users/${user.id}/lock`, values);
            message.success(`Đã khóa tài khoản ${user.username}`);
            onLocked();
        } catch (error: any) {
            if (!error?.errorFields) message.error(error?.response?.data?.message || 'Không thể khóa tài khoản.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <ConfigProvider theme={{ algorithm: darkMode ? theme.darkAlgorithm : theme.defaultAlgorithm }}>
            <Modal open title={`Khóa tài khoản: ${user.username}`} onCancel={onClose} onOk={submit}
                okText="Xác nhận khóa" cancelText="Hủy" confirmLoading={submitting} okButtonProps={{ danger: true }}>
                <p className="mb-4 text-sm text-slate-500 dark:text-slate-400">
                    Người dùng sẽ không thể đăng nhập cho đến khi được mở khóa.
                </p>
                <Form form={form} layout="vertical" initialValues={{ type: 'locked' }}>
                    <Form.Item name="type" label="Mức xử lý" rules={[{ required: true }]}>
                        <Radio.Group>
                            <Radio value="locked">Khóa tài khoản</Radio>
                            <Radio value="banned">Cấm vĩnh viễn</Radio>
                        </Radio.Group>
                    </Form.Item>
                    <Form.Item name="reason" label="Lý do" rules={[
                        { required: true, whitespace: true, message: 'Vui lòng nhập lý do.' },
                        { max: 500, message: 'Lý do tối đa 500 ký tự.' },
                    ]}>
                        <Input.TextArea rows={4} maxLength={500} showCount placeholder="Nhập lý do để phục vụ kiểm tra sau này..." />
                    </Form.Item>
                </Form>
            </Modal>
        </ConfigProvider>
    );
}
