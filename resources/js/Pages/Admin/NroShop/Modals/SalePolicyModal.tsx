import axios from 'axios';
import { Button, Form, Input, Modal, Switch } from 'antd';
import type { FormInstance } from 'antd';
import { base } from '../shared';

const idList = (value: unknown) => String(value || '').trim().split(/[\s,;]+/).filter(Boolean);
const validIds = (parts: string[]) => parts.every(id => /^\d+$/.test(id) && Number(id) <= 100000);

export default function SalePolicyModal({
    open,
    form,
    busy,
    onClose,
    onSaved,
    run,
}: {
    open: boolean;
    form: FormInstance;
    busy: boolean;
    onClose: () => void;
    onSaved: () => void | Promise<void>;
    run: (action: () => Promise<unknown>, success?: string) => Promise<void>;
}) {
    return (
        <Modal
            zIndex={1200}
            title="Danh sách ID đồ được phép bán"
            open={open}
            onCancel={onClose}
            footer={null}
            destroyOnHidden
        >
            <p className="mb-3 text-sm">
                Áp dụng chung cho các kho và gói chưa mua. Đơn đã mua vẫn được giữ để giao. Đây là danh sách shop cho phép
                đăng, không thay thế điều kiện giao dịch trong game.
            </p>
            <Form
                form={form}
                layout="vertical"
                onFinish={v =>
                    run(async () => {
                        const parts = idList(v.ids);
                        if (!validIds(parts)) throw new Error('invalid IDs');
                        await axios.patch(`${base}/sale-policy`, {
                            enabled: !!v.enabled,
                            ids: [...new Set(parts.map(Number))],
                        });
                        onClose();
                        await onSaved();
                    }, 'Đã lưu danh sách ID')
                }
            >
                <Form.Item name="enabled" label="Chỉ cho phép các ID bên dưới" valuePropName="checked">
                    <Switch />
                </Form.Item>
                <Form.Item
                    name="ids"
                    label="ID mẫu vật phẩm (template ID)"
                    extra="Ngăn cách bằng dấu phẩy, khoảng trắng hoặc xuống dòng. Bật giới hạn và để trống sẽ không cho đăng món nào."
                    rules={[
                        {
                            validator: (_, value) =>
                                validIds(idList(value))
                                    ? Promise.resolve()
                                    : Promise.reject(new Error('Nhập ID số nguyên từ 0 đến 100000.')),
                        },
                    ]}
                >
                    <Input.TextArea rows={6} placeholder="Nhập ID của các món được phép bán" />
                </Form.Item>
                <Button type="primary" htmlType="submit" loading={busy}>
                    Lưu danh sách ID
                </Button>
            </Form>
        </Modal>
    );
}
