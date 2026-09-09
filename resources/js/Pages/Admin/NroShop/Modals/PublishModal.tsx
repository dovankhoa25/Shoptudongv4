import { useEffect, useState } from 'react';
import axios from 'axios';
import { Alert, Button, Collapse, Form, Input, InputNumber, Modal, Select, Space, message } from 'antd';
import type { FormInstance } from 'antd';
import NroSnapshotPanel, { type NroSnapshot } from '@/Components/Nro/NroSnapshot';
import NroNickAttributes from '@/Components/Nro/NroNickAttributes';
import { base } from '../shared';
import type { Account, Capabilities, Category, Server } from '../types';

export default function PublishModal({
    open,
    account,
    snapshot,
    selected,
    form,
    servers,
    categories,
    caps,
    busy,
    onBack,
    onDone,
    run,
}: {
    open: boolean;
    account: Account | null;
    snapshot: NroSnapshot | null;
    selected: Record<number, number>;
    form: FormInstance;
    servers: Server[];
    categories: Category[];
    caps: Capabilities;
    busy: boolean;
    onBack: () => void;
    onDone: () => void;
    run: (action: () => Promise<unknown>, success?: string) => Promise<void>;
}) {
    const [attributesReady, setAttributesReady] = useState(false);
    const isNick = account?.usage_type === 'nick';

    // The modal body is destroyed while hidden but this state is not, so a previous account's
    // "attributes are valid" verdict would otherwise enable the submit button for the next one.
    useEffect(() => {
        if (open) setAttributesReady(false);
    }, [open, account?.id]);

    return (
        <Modal
            width={760}
            title={isNick ? (account?.nick ? `Sửa tin bán #${account.nick.id}` : 'Đăng nick bằng dữ liệu game') : 'Đăng gói đồ'}
            open={open}
            onCancel={onBack}
            footer={null}
        >
            {account && (
                <p className="mb-3">
                    Acc: <strong>{account.account_name}</strong> ·{' '}
                    {servers.find(s => s.id === account.server_id)?.name_view || 'Chưa cấu hình server'}
                </p>
            )}
            {isNick && snapshot && (
                <Collapse
                    className="mb-4"
                    items={[
                        {
                            key: 'snapshot',
                            label: 'Xem dữ liệu game dùng cho tin bán',
                            children: <NroSnapshotPanel snapshot={snapshot} />,
                        },
                    ]}
                />
            )}
            <Form
                form={form}
                layout="vertical"
                onFinish={v =>
                    run(async () => {
                        const endpoint = isNick ? 'nick' : 'listings';
                        const { data } = await axios.post(`${base}/accounts/${account?.id}/${endpoint}`, {
                            ...v,
                            items: Object.entries(selected).map(([id, quantity]) => ({ id: Number(id), quantity })),
                        });
                        message.success(account?.nick ? `Đã cập nhật tin #${data.id}` : `Đã đăng mã #${data.id}`);
                        onDone();
                    })
                }
            >
                {isNick ? (
                    <>
                        {account?.nick && (
                            <Form.Item name="nickId" hidden>
                                <InputNumber />
                            </Form.Item>
                        )}
                        <Form.Item name="categoryId" label="Danh mục bán nick" rules={[{ required: true }]}>
                            <Select options={categories.map(c => ({ value: c.id, label: c.name }))} />
                        </Form.Item>
                        {account?.nick && !categories.some(c => c.id === account.nick?.categoryId) && (
                            <Alert
                                className="mb-3"
                                type="warning"
                                message="Danh mục hiện tại không còn được phép đăng. Chọn danh mục đang được phân quyền để lưu."
                            />
                        )}
                        {!account?.nick && caps.editNick && (
                            <Collapse
                                className="mb-4"
                                items={[
                                    {
                                        key: 'link',
                                        label: 'Gắn vào tin đã đăng bằng ảnh (tùy chọn)',
                                        children: (
                                            <Form.Item name="nickId" label="Mã nick cần gắn dữ liệu game">
                                                <InputNumber min={1} className="w-full" />
                                            </Form.Item>
                                        ),
                                    },
                                ]}
                            />
                        )}
                    </>
                ) : (
                    <Form.Item name="title" label="Tên gói đồ" rules={[{ required: true }]}>
                        <Input maxLength={180} />
                    </Form.Item>
                )}
                <Form.Item
                    name="price"
                    label={isNick ? 'Giá nick (đ)' : 'Giá toàn bộ gói (đ)'}
                    rules={[{ required: true }]}
                >
                    <InputNumber min={1} max={9999999999} className="w-full" />
                </Form.Item>
                {open && isNick && account && (
                    <NroNickAttributes
                        accountId={account.id}
                        initialConfig={!account.nick ? account.publishConfig : undefined}
                        form={form}
                        onReady={setAttributesReady}
                    />
                )}
                <Form.Item name="description" label="Mô tả">
                    <Input.TextArea rows={3} />
                </Form.Item>
                <Space>
                    <Button onClick={onBack}>Quay lại dữ liệu</Button>
                    <Button
                        htmlType="submit"
                        type="primary"
                        loading={busy}
                        disabled={isNick && !attributesReady}
                    >
                        {account?.nick ? 'Lưu thay đổi' : 'Đăng bán'}
                    </Button>
                </Space>
            </Form>
        </Modal>
    );
}
