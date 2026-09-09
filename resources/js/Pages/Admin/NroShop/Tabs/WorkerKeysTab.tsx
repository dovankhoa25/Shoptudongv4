import { useEffect, useState } from 'react';
import axios from 'axios';
import { Alert, Button, Input, Modal, Space, Table, Tag, message } from 'antd';
import { base, dateTime } from '../shared';
import type { WorkerKey } from '../types';

export default function WorkerKeysTab({
    run,
    busy,
}: {
    run: (action: () => Promise<unknown>, success?: string) => Promise<void>;
    busy: boolean;
}) {
    const [keys, setKeys] = useState<WorkerKey[]>([]);
    const [loading, setLoading] = useState(false);
    const [keyName, setKeyName] = useState('Máy NRO');
    const [newKey, setNewKey] = useState('');

    const load = async () => {
        setLoading(true);
        try {
            const { data } = await axios.get(`${base}/worker-keys`);
            setKeys(data.data);
        } catch {
            message.error('Không tải được danh sách API key');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        void load();
    }, []);

    return (
        <div className="space-y-4">
            <Alert
                type="info"
                showIcon
                message="Mỗi máy dùng một API key riêng. Key cấp quyền lấy thông tin đăng nhập và xử lý đơn; chỉ admin được tạo hoặc thu hồi."
            />
            <Space wrap>
                <Input
                    aria-label="Tên máy"
                    value={keyName}
                    maxLength={100}
                    onChange={e => setKeyName(e.target.value)}
                    style={{ width: 240 }}
                />
                <Button
                    type="primary"
                    loading={busy}
                    onClick={() =>
                        run(async () => {
                            const { data } = await axios.post(`${base}/worker-keys`, { name: keyName });
                            setNewKey(data.token);
                            await load();
                        }, 'Đã tạo API key')
                    }
                >
                    Tạo key
                </Button>
                <Button onClick={load}>Làm mới</Button>
            </Space>
            <Table
                rowKey="id"
                loading={loading}
                dataSource={keys}
                pagination={false}
                scroll={{ x: 600 }}
                columns={[
                    { title: 'Tên máy', dataIndex: 'name' },
                    {
                        title: 'Lần kết nối gần nhất',
                        dataIndex: 'last_used_at',
                        render: v => (v ? dateTime(v) : 'Chưa kết nối'),
                    },
                    {
                        title: 'Trạng thái',
                        render: (_, k: WorkerKey) =>
                            k.revoked_at ? (
                                <Tag>Đã thu hồi</Tag>
                            ) : k.accepts_delivery ? (
                                <Tag color="green">Quét và giao đồ</Tag>
                            ) : (
                                <Tag color="blue">Chỉ quét / chưa kết nối</Tag>
                            ),
                    },
                    {
                        title: '',
                        width: 110,
                        render: (_, k: WorkerKey) =>
                            !k.revoked_at && (
                                <Button
                                    danger
                                    onClick={() =>
                                        Modal.confirm({
                                            title: 'Thu hồi API key?',
                                            content:
                                                'Tool dùng key này sẽ bị ngắt quyền truy cập. Đơn đang giao cần kiểm tra kết quả trước khi chạy lại.',
                                            onOk: () =>
                                                run(async () => {
                                                    await axios.delete(`${base}/worker-keys/${k.id}`);
                                                    await load();
                                                }, 'Đã thu hồi key'),
                                        })
                                    }
                                >
                                    Thu hồi
                                </Button>
                            ),
                    },
                ]}
            />
            <Modal
                title="API key mới — chỉ hiển thị lần này"
                open={!!newKey}
                onCancel={() => setNewKey('')}
                onOk={() => setNewKey('')}
            >
                <p className="mb-3">
                    Sao chép vào dấu nhắc API key của NroShopWorker. Không đưa vào frontend hoặc gửi cho CTV.
                </p>
                <Input.TextArea readOnly value={newKey} rows={3} />
                <Button
                    className="mt-3"
                    onClick={async () => {
                        try {
                            await navigator.clipboard.writeText(newKey);
                            message.success('Đã sao chép');
                        } catch {
                            message.info('Hãy chọn và sao chép key trong ô trên');
                        }
                    }}
                >
                    Sao chép key
                </Button>
            </Modal>
        </div>
    );
}
