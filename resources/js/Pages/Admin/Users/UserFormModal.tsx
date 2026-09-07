import { useState } from 'react';
import { Button, ConfigProvider, Form, Input, InputNumber, Modal, message, theme } from 'antd';
import { IdcardOutlined, LockOutlined, MailOutlined, PictureOutlined, UserOutlined } from '@ant-design/icons';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { IUser } from '@/InterFaces/user';
import { useTheme } from '@/Providers/ThemeProvider';

interface Props { user?: IUser | null; onClose: () => void; onSaved?: () => void }

export default function UserFormModal({ user, onClose, onSaved }: Props) {
    const { darkMode } = useTheme();
    const [form] = Form.useForm();
    const [submitting, setSubmitting] = useState(false);
    const editing = Boolean(user);

    const submit = async (values: Record<string, any>) => {
        setSubmitting(true);
        const payload = {
            username: values.username.trim(),
            chat_display_name: values.chat_display_name?.trim() || null,
            email: values.email?.trim() || null,
            avatar: values.avatar?.trim() || null,
            ...(!editing ? { password: values.password, balance: values.balance ?? 0 } : {}),
            ...(editing && values.password ? { password: values.password } : {}),
        };

        try {
            editing && user
                ? await axios.put(`/admin/users/${user.id}`, payload)
                : await axios.post('/admin/users', payload);
            message.success(editing ? 'Đã cập nhật người dùng.' : 'Đã tạo người dùng.');
            onClose();
            if (onSaved) onSaved();
            else router.reload({ only: ['users', 'can'] });
        } catch (error: any) {
            const errors = error?.response?.data?.errors;
            if (errors) {
                form.setFields(Object.entries(errors).map(([name, messages]) => ({ name, errors: messages as string[] })));
            } else {
                message.error(error?.response?.data?.message || 'Không thể lưu người dùng.');
            }
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <ConfigProvider theme={{ algorithm: darkMode ? theme.darkAlgorithm : theme.defaultAlgorithm }}>
            <Modal open footer={null} width={560} onCancel={onClose}
                title={editing ? `Sửa người dùng: ${user?.username}` : 'Thêm người dùng'}>
                <Form form={form} layout="vertical" disabled={submitting} onFinish={submit} initialValues={{
                    username: user?.username,
                    chat_display_name: user?.chat_display_name,
                    email: user?.email,
                    avatar: user?.avatar,
                    balance: 0,
                }}>
                    <Form.Item name="username" label="Tên đăng nhập" rules={[
                        { required: true, whitespace: true, message: 'Vui lòng nhập tên đăng nhập.' },
                        { max: 191, message: 'Tên đăng nhập tối đa 191 ký tự.' },
                    ]}>
                        <Input prefix={<UserOutlined />} autoComplete="off" placeholder="username" />
                    </Form.Item>
                    <Form.Item
                        name="chat_display_name"
                        label="Tên hiển thị trong chat"
                        extra="Tên khách hàng nhìn thấy khi Admin/CTV trả lời. Để trống sẽ dùng tên đăng nhập."
                        rules={[{ max: 80, message: 'Tên hiển thị tối đa 80 ký tự.' }]}
                    >
                        <Input prefix={<IdcardOutlined />} maxLength={80} showCount placeholder="Ví dụ: Hỗ trợ viên Linh" />
                    </Form.Item>
                    <Form.Item name="email" label="Email" rules={[{ type: 'email', message: 'Email không hợp lệ.' }]}>
                        <Input prefix={<MailOutlined />} autoComplete="off" placeholder="email@example.com" />
                    </Form.Item>
                    <Form.Item name="password" label={editing ? 'Mật khẩu mới' : 'Mật khẩu'} dependencies={['password_confirmation']} rules={[
                        ...(editing ? [] : [{ required: true, message: 'Vui lòng nhập mật khẩu.' }]),
                        { min: 6, message: 'Mật khẩu tối thiểu 6 ký tự.' },
                    ]}>
                        <Input.Password prefix={<LockOutlined />} autoComplete="new-password" placeholder={editing ? 'Để trống nếu không thay đổi' : 'Tối thiểu 6 ký tự'} />
                    </Form.Item>
                    <Form.Item name="password_confirmation" label="Xác nhận mật khẩu" dependencies={['password']} rules={[
                        ({ getFieldValue }) => ({ validator(_, value) {
                            if (!getFieldValue('password') || value === getFieldValue('password')) return Promise.resolve();
                            return Promise.reject(new Error('Mật khẩu xác nhận không khớp.'));
                        }}),
                    ]}>
                        <Input.Password prefix={<LockOutlined />} autoComplete="new-password" />
                    </Form.Item>
                    <Form.Item name="avatar" label="Ảnh đại diện (URL)" rules={[{ type: 'url', message: 'URL ảnh không hợp lệ.' }]}>
                        <Input prefix={<PictureOutlined />} placeholder="https://..." />
                    </Form.Item>
                    {!editing && <Form.Item name="balance" label="Số dư ban đầu" rules={[{ type: 'number', min: 0, message: 'Số dư không được âm.' }]}>
                        <InputNumber className="w-full" min={0} precision={0} addonAfter="VND"
                            formatter={value => `${value ?? ''}`.replace(/\B(?=(\d{3})+(?!\d))/g, '.')}
                            parser={value => Number((value || '').replace(/\./g, '')) as any} />
                    </Form.Item>}
                    <div className="flex justify-end gap-3 pt-2">
                        <Button onClick={onClose}>Hủy</Button>
                        <Button type="primary" htmlType="submit" loading={submitting}>{editing ? 'Lưu thay đổi' : 'Tạo người dùng'}</Button>
                    </div>
                </Form>
            </Modal>
        </ConfigProvider>
    );
}
