import { useEffect, useState } from 'react';
import axios from 'axios';
import { Package } from 'lucide-react';
import { Alert, Button, Collapse, Form, Input, InputNumber, Modal, Select, Space, message } from 'antd';
import type { FormInstance } from 'antd';
import NroSnapshotPanel, { type NroSnapshot } from '@/Components/Nro/NroSnapshot';
import NroNickAttributes from '@/Components/Nro/NroNickAttributes';
import { base } from '../shared';
import type { Account, Capabilities, Category, Inventory, Server } from '../types';

export default function PublishModal({
    open,
    account,
    snapshot,
    selected,
    inventory,
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
    inventory: Inventory[];
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
    const selectedRows = inventory.filter(row => selected[row.id]);
    const stackable = selectedRows.length > 0 && selectedRows.every(row => row.stackable);
    const maxPackages = selectedRows.length ? Math.min(...selectedRows.map(row => Math.floor(row.selectable / selected[row.id]))) : 1;
    const stockMode = Form.useWatch('stockMode', form) || 'fixed';
    const packageCount = Form.useWatch('packageCount', form) || 1;
    const defaultTitle = [...new Set(Object.keys(selected).map(id => inventory.find(row => row.id === Number(id))).filter((row): row is Inventory => !!row).map(row => row.item.name?.trim() || `Vật phẩm #${row.item.templateId}`))].join(', ').slice(0, 180);

    // The modal body is destroyed while hidden but this state is not, so a previous account's
    // "attributes are valid" verdict would otherwise enable the submit button for the next one.
    useEffect(() => {
        if (open) setAttributesReady(false);
    }, [open, account?.id]);

    return (
        <Modal
            width={760}
            rootClassName="nro-account-modal"
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
                            ...(!isNick ? { stockMode: stackable ? stockMode : 'fixed', packageCount: stackable && stockMode === 'fixed' ? packageCount : 1 } : {}),
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
                    <Form.Item name="title" label="Tên gói đồ (tùy chọn)" extra={defaultTitle ? `Để trống sẽ dùng: ${defaultTitle}` : 'Để trống để tự lấy tên vật phẩm.'}>
                        <Input prefix={<Package size={14} className="text-slate-400" aria-hidden="true" />} maxLength={180} placeholder={defaultTitle || 'Tên vật phẩm'} autoComplete="off" />
                    </Form.Item>
                )}
                <Form.Item
                    name="price"
                    label={isNick ? 'Giá nick (đ)' : 'Giá mỗi gói (đ)'}
                    rules={[{ required: true }]}
                >
                    <InputNumber min={1} max={9999999999} className="w-full" />
                </Form.Item>
                {!isNick && <div className="mb-4 rounded-lg border border-slate-300 p-3 dark:border-slate-700">
                    {stackable ? <>
                        <Form.Item name="stockMode" label="Số gói mở bán" initialValue="fixed">
                            <Select options={[{ value: 'fixed', label: 'Cố định số gói' }, { value: 'auto', label: 'Tự động theo tồn kho' }]} />
                        </Form.Item>
                        {stockMode === 'fixed' && <Form.Item name="packageCount" label="Số gói đăng bán" initialValue={1} rules={[{ required: true }]}>
                            <InputNumber min={1} max={Math.max(1, maxPackages)} precision={0} className="!w-full" />
                        </Form.Item>}
                        <p className="text-xs text-slate-500">{stockMode === 'auto' ? `Hiện có thể bán ${maxPackages.toLocaleString('vi-VN')} gói. Tồn tự cập nhật sau mỗi lần lấy đủ dữ liệu kho.` : `Tối đa ${maxPackages.toLocaleString('vi-VN')} gói. Nhập thêm đồ không tự tăng số gói.`}</p>
                    </> : <p className="text-sm">Gói có trang bị: đăng một gói, mỗi trang bị một món.</p>}
                    <p className="mt-2 text-xs text-slate-500">Giá ở trên là giá mỗi gói; khách chọn số gói khi mua. Đồ giữ cho đơn khác đã được trừ.</p>
                </div>}
                {open && isNick && account && (
                    <NroNickAttributes
                        accountId={account.id}
                        initialConfig={!account.nick ? account.publishConfig : undefined}
                        form={form}
                        onReady={setAttributesReady}
                    />
                )}
                <Form.Item name="description" label={isNick ? "Mô tả" : "Mô tả công khai trên shop"}>
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
