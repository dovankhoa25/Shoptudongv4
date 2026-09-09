import { useState } from 'react';
import axios from 'axios';
import { Alert, Button, Form, Input, InputNumber, Modal, Select, Space, Table, Tag } from 'antd';
import { base, dateTime, statusName } from '../shared';
import { usePagedTab } from '../usePagedTab';
import type { Job } from '../types';

const jobType = (type: string) => (type === 'snapshot' ? 'Lấy dữ liệu' : type === 'delivery' ? 'Giao đồ' : type);

/** result_json is worker-written; show the message if there is one, and say so plainly if not. */
function jobResult(job: Job) {
    if (!job.result_json) return <span className="text-slate-400">—</span>;
    try {
        const parsed = JSON.parse(job.result_json);
        const text = parsed?.message ?? parsed?.resolution?.note;
        return text ? (
            <span className="text-xs">{String(text)}</span>
        ) : (
            <span className="text-xs text-slate-500">Không có mô tả</span>
        );
    } catch {
        return <span className="text-xs text-amber-600 dark:text-amber-400">Kết quả không đọc được</span>;
    }
}

export default function JobsTab({
    canReconcile,
    run,
    busy,
    dataVersion,
}: {
    canReconcile: boolean;
    run: (action: () => Promise<unknown>, success?: string) => Promise<void>;
    busy: boolean;
    dataVersion: number;
}) {
    const { rows, loading, filters, apply, reload } = usePagedTab<Job>(
        '/jobs',
        'Không tải được danh sách công việc tool',
        dataVersion,
    );
    const [reconcile, setReconcile] = useState<Job | null>(null);
    const [reconcileForm] = Form.useForm();

    const openReconcile = (job: Job) => {
        setReconcile(job);
        reconcileForm.resetFields();
        reconcileForm.setFieldsValue({
            items: job.order?.items.map(i => ({ id: i.id, delivered: i.delivered })) || [],
        });
    };

    return (
        <>
            <div className="mb-3 flex flex-wrap gap-2">
                <Select
                    aria-label="Lọc trạng thái công việc"
                    className="min-w-40"
                    placeholder="Tất cả trạng thái"
                    allowClear
                    value={(filters.status as string) || undefined}
                    onChange={status => apply({ ...filters, status })}
                    options={['queued', 'processing', 'review', 'completed', 'failed', 'expired'].map(value => ({
                        value,
                        label: statusName[value] || value,
                    }))}
                />
                <Select
                    aria-label="Lọc loại công việc"
                    className="min-w-36"
                    placeholder="Tất cả loại"
                    allowClear
                    value={(filters.type as string) || undefined}
                    onChange={type => apply({ ...filters, type })}
                    options={[
                        { value: 'snapshot', label: 'Lấy dữ liệu' },
                        { value: 'delivery', label: 'Giao đồ' },
                    ]}
                />
                <Button onClick={() => apply({})}>Xóa lọc</Button>
                <Button onClick={reload}>Làm mới</Button>
            </div>
            <Table
                rowKey="id"
                loading={loading}
                dataSource={rows.data}
                scroll={{ x: 800 }}
                pagination={{
                    current: rows.page,
                    total: rows.total,
                    pageSize: rows.perPage,
                    showSizeChanger: false,
                    showTotal: total => `${total} công việc`,
                    onChange: page => apply(filters, page),
                }}
                columns={[
                    { title: 'Mã', dataIndex: 'id', width: 80 },
                    {
                        title: 'Acc',
                        width: 170,
                        render: (_, j: Job) => (
                            <>
                                <div>{j.accountName || `#${j.account_id}`}</div>
                                {j.order_id && (
                                    <div className="text-xs text-slate-500 dark:text-slate-400">Đơn #{j.order_id}</div>
                                )}
                            </>
                        ),
                    },
                    { title: 'Loại', dataIndex: 'type', width: 120, render: jobType },
                    {
                        title: 'Trạng thái',
                        dataIndex: 'status',
                        width: 130,
                        render: v => (
                            <Tag color={v === 'review' ? 'orange' : v === 'completed' ? 'green' : undefined}>
                                {statusName[v] || v}
                            </Tag>
                        ),
                    },
                    { title: 'Cập nhật', dataIndex: 'updated_at', width: 150, render: v => dateTime(v) },
                    { title: 'Kết quả', render: (_, j: Job) => jobResult(j) },
                    {
                        title: '',
                        width: 110,
                        render: (_, j: Job) =>
                            canReconcile && j.status === 'review' ? (
                                <Button danger onClick={() => openReconcile(j)}>
                                    Đối soát
                                </Button>
                            ) : null,
                    },
                ]}
            />
            <Modal
                title={reconcile ? `Đối soát công việc #${reconcile.id}` : 'Đối soát công việc'}
                open={!!reconcile}
                onCancel={() => setReconcile(null)}
                footer={null}
                destroyOnHidden
            >
                <Alert
                    className="mb-3"
                    type="warning"
                    message="Dừng tool và kiểm tra lịch sử giao dịch thực tế trước khi xác nhận. Chỉ cho nhận lại khi đã xác minh không có đồ giao thêm ngoài tiến độ được ghi nhận."
                />
                <Form
                    form={reconcileForm}
                    layout="vertical"
                    onFinish={v =>
                        run(async () => {
                            await axios.post(`${base}/jobs/${reconcile?.id}/reconcile`, v);
                            setReconcile(null);
                        }, 'Đã ghi nhận kết quả đối soát')
                    }
                >
                    <Form.Item name="resolution" label="Kết quả đã kiểm tra" rules={[{ required: true }]}>
                        <Select
                            options={[
                                ...(reconcile?.order_id ? [{ value: 'delivered', label: 'Đã giao đủ toàn bộ đơn' }] : []),
                                {
                                    value: 'not_delivered',
                                    label: reconcile?.order_id
                                        ? 'Chưa giao thêm — giữ đồ và cho nhận lại'
                                        : 'Đã dừng tool — cho phép quét lại',
                                },
                            ]}
                        />
                    </Form.Item>
                    <Form.List name="items">
                        {fields => (
                            <>
                                {fields.map((field, index) => {
                                    const item = reconcile?.order?.items[index];

                                    return (
                                        <Space key={field.key}>
                                            <Form.Item name={[field.name, 'id']} hidden>
                                                <InputNumber />
                                            </Form.Item>
                                            <Form.Item
                                                name={[field.name, 'delivered']}
                                                label={`${item?.item.name || 'Vật phẩm'} — tổng đã giao (tối đa ${item?.quantity ?? 0})`}
                                            >
                                                <InputNumber min={item?.delivered || 0} max={item?.quantity || 0} />
                                            </Form.Item>
                                        </Space>
                                    );
                                })}
                            </>
                        )}
                    </Form.List>
                    <Form.Item name="note" label="Nội dung đối soát" rules={[{ required: true, min: 10 }]}>
                        <Input.TextArea rows={3} maxLength={250} showCount />
                    </Form.Item>
                    <Button danger htmlType="submit" loading={busy}>
                        Xác nhận kết quả
                    </Button>
                </Form>
            </Modal>
        </>
    );
}
