import { useEffect, useState } from 'react';
import axios from 'axios';
import { Alert, Button, Form, Input, InputNumber, Modal, Select, message } from 'antd';
import type { FormInstance } from 'antd';
import NroDraftAttributes from '@/Components/Nro/NroDraftAttributes';
import { base } from '../shared';
import type { Capabilities, Category, LoginServer, Server } from '../types';

export default function AddAccountModal({
    open,
    form,
    caps,
    servers,
    loginServers,
    categories,
    busy,
    onClose,
    run,
}: {
    open: boolean;
    form: FormInstance;
    caps: Capabilities;
    servers: Server[];
    loginServers: LoginServer[];
    categories: Category[];
    busy: boolean;
    onClose: () => void;
    run: (action: () => Promise<unknown>, success?: string) => Promise<void>;
}) {
    const [images, setImages] = useState<File[]>([]);
    const [draftReady, setDraftReady] = useState(false);
    const usageType = Form.useWatch('usageType', form);

    // Start every "Thêm acc game" from scratch: the modal body is destroyed while hidden but this
    // state is not, so a cancelled attempt would otherwise carry its images and its "draft is
    // valid" verdict into the next account.
    useEffect(() => {
        if (!open) return;
        setImages([]);
        setDraftReady(false);
    }, [open]);

    const submit = (v: Record<string, unknown>) =>
        run(
            async () => {
                const body = new FormData();
                for (const key of ['username', 'password', 'serverId', 'serverGameId', 'usageType']) {
                    body.append(key, String(v[key]));
                }
                if (v.usageType === 'nick') {
                    for (const key of ['categoryId', 'price', 'description']) {
                        if (v[key] != null) body.append(key, String(v[key]));
                    }
                    Object.entries((v.attributeSelections as Record<string, unknown>) || {}).forEach(([id, option]) => {
                        if (option != null) body.append(`attributeSelections[${id}]`, String(option));
                    });
                    images.forEach(file => body.append('images[]', file));
                }
                await axios.post(`${base}/accounts`, body);
                onClose();
                form.resetFields();
                setImages([]);
            },
            v.usageType === 'nick'
                ? 'Đã thêm acc và xếp hàng lấy dữ liệu để tự đăng.'
                : 'Đã thêm acc kho và xếp hàng lấy dữ liệu.',
        );

    return (
        <Modal
            width={640}
            rootClassName="nro-account-modal"
            title="Thêm acc game"
            open={open}
            onCancel={onClose}
            footer={null}
            destroyOnHidden
        >
            <p className="mb-4 text-xs text-slate-500 dark:text-slate-400">
                Nhập thông tin đăng nhập hiện tại. Acc đã bán có thể nhập lại thành lần bán mới; lịch sử cũ vẫn được giữ.
            </p>
            <Form form={form} layout="vertical" onFinish={submit}>
                <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
                    <Form.Item
                        name="username"
                        label="Tài khoản game"
                        rules={[{ required: true, whitespace: true, message: 'Nhập tài khoản game.' }]}
                    >
                        <Input
                            prefix={<span className="text-slate-400">@</span>}
                            placeholder="Email hoặc tài khoản"
                            maxLength={141}
                            autoComplete="off"
                        />
                    </Form.Item>
                    <Form.Item
                        name="password"
                        label="Mật khẩu hiện tại"
                        rules={[{ required: true, message: 'Nhập mật khẩu game hiện tại.' }]}
                    >
                        <Input.Password placeholder="Mật khẩu đăng nhập game" maxLength={64} autoComplete="new-password" />
                    </Form.Item>
                    <Form.Item name="serverId" label="Server hiển thị" rules={[{ required: true }]}>
                        <Select
                            placeholder="Server trên shop"
                            options={servers.map(s => ({ value: s.id, label: s.name_view || s.name }))}
                        />
                    </Form.Item>
                    <Form.Item name="serverGameId" label="Server đăng nhập" rules={[{ required: true }]}>
                        <Select
                            placeholder="Server tool đăng nhập"
                            options={loginServers.map(s => ({ value: s.id, label: s.name }))}
                        />
                    </Form.Item>
                </div>
                <Form.Item name="usageType" label="Dùng acc để" rules={[{ required: true }]}>
                    <Select
                        options={[
                            { value: 'nick', label: 'Bán nguyên nick', disabled: !caps.publishNick },
                            { value: 'warehouse', label: 'Chứa đồ và giao cho khách' },
                        ]}
                    />
                </Form.Item>
                {usageType === 'nick' && (
                    <>
                        <Alert
                            className="mb-4"
                            type="info"
                            showIcon
                            message="Lấy dữ liệu game và tự đăng bán"
                            description="Sau khi lưu, tool tự lấy dữ liệu rồi đăng vào danh mục bên dưới. Nếu tool chưa bật, acc sẽ nằm chờ; dữ liệu thiếu hoặc lỗi sẽ chưa đăng lên shop."
                        />
                        <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
                            <Form.Item
                                name="categoryId"
                                label="Danh mục đăng nick"
                                rules={[{ required: true, message: 'Chọn danh mục đăng bán.' }]}
                            >
                                <Select
                                    showSearch
                                    optionFilterProp="label"
                                    placeholder="Chọn danh mục được phép đăng"
                                    options={categories.map(c => ({ value: c.id, label: c.name }))}
                                />
                            </Form.Item>
                            <Form.Item name="price" label="Giá bán (đ)" rules={[{ required: true }]}>
                                <InputNumber min={1} max={9999999999} precision={0} className="w-full" />
                            </Form.Item>
                        </div>
                        <NroDraftAttributes form={form} onReady={setDraftReady} />
                        <Form.Item name="description" label="Mô tả (tùy chọn)">
                            <Input.TextArea rows={2} maxLength={10000} />
                        </Form.Item>
                        <Form.Item
                            label="Ảnh đăng bán (tùy chọn)"
                            extra="Tối đa 8 ảnh JPG, PNG hoặc WebP, 5 MB/ảnh. Ảnh đầu làm đại diện; không chọn ảnh sẽ dùng giao diện dữ liệu game."
                        >
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                multiple
                                className="max-w-full text-xs"
                                onChange={e => {
                                    const files = Array.from(e.target.files || []);
                                    if (files.length > 8 || files.some(f => f.size > 5 * 1024 * 1024)) {
                                        message.error('Chọn tối đa 8 ảnh, mỗi ảnh không quá 5 MB.');
                                        e.target.value = '';
                                        setImages([]);
                                        return;
                                    }
                                    setImages(files);
                                }}
                            />
                        </Form.Item>
                    </>
                )}
                <div className="flex justify-end gap-2 border-t border-slate-200 pt-3 dark:border-slate-700">
                    <Button onClick={onClose}>Hủy</Button>
                    <Button
                        type="primary"
                        htmlType="submit"
                        loading={busy}
                        disabled={usageType === 'nick' && (!caps.publishNick || !draftReady)}
                    >
                        {usageType === 'nick' ? 'Thêm acc & tự đăng' : 'Thêm acc'}
                    </Button>
                </div>
            </Form>
        </Modal>
    );
}
