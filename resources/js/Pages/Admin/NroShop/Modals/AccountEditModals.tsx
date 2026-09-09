import { useState } from 'react';
import axios from 'axios';
import { Alert, Button, Form, Input, InputNumber, Modal, Select } from 'antd';
import type { FormInstance } from 'antd';
import { base } from '../shared';
import type { Account, LoginServer, Server } from '../types';

type Run = (action: () => Promise<unknown>, success?: string) => Promise<void>;

/** Change the game credentials and server of a nick account; the tin is re-published afterwards. */
export function EditAccountModal({
    account,
    form,
    servers,
    loginServers,
    busy,
    onClose,
    run,
}: {
    account: Account | null;
    form: FormInstance;
    servers: Server[];
    loginServers: LoginServer[];
    busy: boolean;
    onClose: () => void;
    run: Run;
}) {
    return (
        <Modal title="Sửa tài khoản / server" open={!!account} onCancel={onClose} footer={null} destroyOnHidden>
            <Alert
                type="info"
                showIcon
                className="mb-4"
                message="Tin sẽ được ẩn và giữ nguyên mã nick. Tool lấy dữ liệu mới rồi tự đăng lại; thuộc tính game được tính lại theo dữ liệu mới."
            />
            <Form
                form={form}
                layout="vertical"
                onFinish={v =>
                    run(async () => {
                        await axios.patch(`${base}/accounts/${account!.id}`, v);
                        onClose();
                    }, 'Đã lưu, đang chờ tool lấy dữ liệu và đăng lại')
                }
            >
                <Form.Item name="username" label="Tài khoản game" rules={[{ required: true }]}>
                    <Input maxLength={141} />
                </Form.Item>
                <Form.Item name="password" label="Mật khẩu mới (để trống để giữ nguyên)">
                    <Input.Password autoComplete="new-password" maxLength={64} />
                </Form.Item>
                <Form.Item name="serverId" label="Server hiển thị" rules={[{ required: true }]}>
                    <Select options={servers.map(s => ({ value: s.id, label: s.name_view || s.name }))} />
                </Form.Item>
                <Form.Item name="serverGameId" label="Server đăng nhập" rules={[{ required: true }]}>
                    <Select options={loginServers.map(s => ({ value: s.id, label: s.name }))} />
                </Form.Item>
                <Button type="primary" htmlType="submit" loading={busy}>
                    Lưu và đăng lại
                </Button>
            </Form>
        </Modal>
    );
}

export function PasswordModal({
    account,
    busy,
    onClose,
    run,
}: {
    account: Account | null;
    busy: boolean;
    onClose: () => void;
    run: Run;
}) {
    const [password, setPassword] = useState('');

    return (
        <Modal
            title={`Cập nhật mật khẩu: ${account?.account_name || ''}`}
            open={!!account}
            onCancel={() => {
                onClose();
                setPassword('');
            }}
            confirmLoading={busy}
            okButtonProps={{ disabled: !password }}
            onOk={() =>
                run(async () => {
                    await axios.patch(`${base}/accounts/${account?.id}/password`, { password });
                    onClose();
                    setPassword('');
                }, 'Đã cập nhật mật khẩu')
            }
        >
            <p className="mb-3">
                Nhập mật khẩu hiện tại trong game. Thao tác này cập nhật thông tin đăng nhập đã lưu trên web, không đổi
                mật khẩu tại game.
            </p>
            <Input.Password
                value={password}
                maxLength={64}
                autoComplete="new-password"
                onChange={e => setPassword(e.target.value)}
            />
        </Modal>
    );
}

export function DeliverySettingsModal({
    account,
    form,
    servers,
    loginServers,
    busy,
    onClose,
    run,
}: {
    account: Account | null;
    form: FormInstance;
    servers: Server[];
    loginServers: LoginServer[];
    busy: boolean;
    onClose: () => void;
    run: Run;
}) {
    return (
        <Modal title="Cấu hình giao đồ riêng cho acc" open={!!account} onCancel={onClose} footer={null} destroyOnHidden>
            <Form
                form={form}
                layout="vertical"
                onFinish={v =>
                    run(async () => {
                        await axios.patch(`${base}/accounts/${account?.id}/settings`, v);
                        onClose();
                    }, 'Đã lưu cấu hình')
                }
            >
                <Form.Item name="server_id" label="Server hiển thị" rules={[{ required: true }]}>
                    <Select options={servers.map(s => ({ value: s.id, label: s.name_view || s.name }))} />
                </Form.Item>
                <Form.Item name="server_game_id" label="Server đăng nhập (IP/port)" rules={[{ required: true }]}>
                    <Select options={loginServers.map(s => ({ value: s.id, label: s.name }))} />
                </Form.Item>
                <Form.Item name="delivery_map" label="Map giao đồ (5 = Đảo Kame)" rules={[{ required: true }]}>
                    <InputNumber min={0} max={255} />
                </Form.Item>
                <Form.Item name="delivery_zone_mode" label="Chọn khu">
                    <Select
                        options={[
                            { value: 'auto', label: 'Tự chọn khu ít người từ 4–15' },
                            { value: 'fixed', label: 'Khu cố định' },
                        ]}
                    />
                </Form.Item>
                <Form.Item name="delivery_zone" label="Khu khi dùng chế độ cố định" rules={[{ required: true }]}>
                    <InputNumber min={0} max={255} />
                </Form.Item>
                <Form.Item name="wait_minutes" label="Phút chờ từ khi bot sẵn sàng" rules={[{ required: true }]}>
                    <InputNumber min={1} max={60} />
                </Form.Item>
                <Button htmlType="submit" type="primary" loading={busy}>
                    Lưu cấu hình
                </Button>
            </Form>
        </Modal>
    );
}
