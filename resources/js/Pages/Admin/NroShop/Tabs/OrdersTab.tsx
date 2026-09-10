import { Alert, Button, Form, Input, InputNumber, Modal, Progress, Select, Table, Tag, message } from 'antd';
import { useState } from 'react';
import axios from 'axios';
import { dateTime, ItemStrip, money, statusName } from '../shared';
import { usePagedTab } from '../usePagedTab';
import type { Order } from '../types';

const OPEN = ['queued', 'awaiting_receipt', 'processing', 'review'];

const ORDER_STATUSES = [
    'queued',
    'awaiting_receipt',
    'processing',
    'review',
    'completed',
    'refunded',
    'failed',
    'expired',
];

export default function OrdersTab({ dataVersion, canRefund }: { dataVersion: number; canRefund: boolean }) {
    const { rows, loading, filters, setFilters, apply, reload } = usePagedTab<Order>(
        '/orders',
        'Không tải được danh sách đơn giao đồ',
        dataVersion,
    );

    const [refund, setRefund] = useState<Order | null>(null);
    const [saving, setSaving] = useState(false);
    const [form] = Form.useForm();
    const amount = Form.useWatch('amount', form);
    const partial = !!refund?.items.some(i => i.delivered > 0);

    return (
        <>
            <div className="mb-3 flex flex-wrap gap-2">
                <Input.Search
                    aria-label="Tìm đơn"
                    placeholder="Mã đơn, người mua, người đăng, acc kho"
                    className="!w-72"
                    allowClear
                    value={(filters.q as string) || ''}
                    onChange={e => setFilters({ ...filters, q: e.target.value })}
                    onSearch={() => apply()}
                />
                <Select
                    aria-label="Lọc trạng thái đơn"
                    className="min-w-44"
                    placeholder="Tất cả trạng thái"
                    allowClear
                    value={(filters.status as string) || undefined}
                    onChange={status => apply({ ...filters, status })}
                    options={ORDER_STATUSES.map(value => ({ value, label: statusName[value] || value }))}
                />
                <Button type="primary" onClick={() => apply()}>
                    Lọc
                </Button>
                <Button onClick={() => apply({})}>Xóa lọc</Button>
                <Button onClick={reload}>Làm mới</Button>
            </div>
            <p className="mb-2 text-xs text-slate-500 dark:text-slate-400">Tự cập nhật mỗi 5 giây khi đang mở tab. Mỗi bot chỉ giao một lượt; các đơn còn lại tiếp tục chờ.</p>
            <Table
                rowKey="id"
                loading={loading}
                dataSource={rows.data}
                size="small"
                scroll={{ x: 1200 }}
                pagination={{
                    current: rows.page,
                    total: rows.total,
                    pageSize: rows.perPage,
                    showSizeChanger: false,
                    showTotal: total => `${total} đơn`,
                    onChange: page => apply(filters, page),
                }}
                columns={[
                    {
                        title: 'Đơn',
                        width: 190,

                        render: (_, o: Order) => (
                            <>
                                <strong>
                                    #{o.id} · {o.title}
                                </strong>
                                <div className="text-xs text-slate-500 dark:text-slate-400">
                                    Tạo lúc {dateTime(o.createdAt)}
                                </div>
                                <ItemStrip items={o.items} />
                            </>
                        ),
                    },
                    {
                        title: 'Người nhận',
                        width: 190,
                        render: (_, o: Order) => (
                            <>
                                <strong>{o.buyerUsername || '—'}</strong>
                                <div className="text-xs">Nhân vật: {o.recipientName || 'Chưa chọn nhân vật'}</div>
                                <div className="text-xs text-slate-500 dark:text-slate-400">
                                    {o.serverName || 'Chưa cấu hình server'}
                                </div>
                            </>
                        ),
                    },
                    {
                        title: 'Kho giao / Người đăng', width: 200,
                        render: (_, o: Order) => <div className="space-y-1">
                            <strong>{o.session?.position?.name || o.botName || 'Chưa vào game'}</strong>
                            <div className="text-xs text-slate-500">Acc: {o.accountName || `#${o.accountId}`}</div>
                            <div className="text-xs text-slate-500">Người đăng: {o.ownerUsername || '—'}</div>
                            {!o.botActivity?.preparing && o.session?.position && <div className="text-xs">{o.session.position.mapName} · Khu {o.session.position.zone}</div>}
                            {o.botActivity?.preparing && <div className="text-xs text-sky-700 dark:text-sky-300">{o.botActivity.message} · Tạm dừng đồng hồ chờ</div>}
                            {o.botActivity?.servingOther && <Tag color="gold">Bot đang giao đơn khác</Tag>}
                            {!!o.botActivity?.waitingCount && <div className="text-xs text-slate-500">{o.botActivity.waitingCount} phiên đang chờ bot</div>}
                        </div>,
                    },
                    { title: 'Giá', dataIndex: 'price', width: 110, render: money },
                    {
                        title: 'Tiến độ giao',
                        width: 250,
                        render: (_, o: Order) => {
                            const done = o.items.reduce((n, i) => n + i.delivered, 0);
                            const all = o.items.reduce((n, i) => n + i.quantity, 0);

                            return (
                                <div className="space-y-1">
                                    <Tag
                                        color={
                                            o.status === 'review' ? 'orange' : OPEN.includes(o.status) ? 'blue' : undefined
                                        }
                                    >
                                        {statusName[o.status] || o.status}
                                    </Tag>
                                    {o.refundRequested && <Tag color="volcano">Cần xem xét hoàn tiền</Tag>}
                                    {!!o.refundAmount && <div className="text-xs">Đã hoàn {money(o.refundAmount)} · {o.refundActor || 'Admin'} · {dateTime(o.refundedAt)}<div>{o.refundNote}</div></div>}
                                    <Progress
                                        percent={all ? Math.round((done / all) * 100) : 0}
                                        size="small"
                                        status={o.status === 'refunded' ? 'exception' : undefined}
                                    />
                                    <div className="text-xs text-slate-500 dark:text-slate-400">
                                        Đã nhận {done} / {all} món
                                    </div>
                                    {o.session && (
                                        <div className="text-xs text-slate-500 dark:text-slate-400">
                                            Phiên: {o.session.mode === 'auto' ? 'Tool nhận hộ' : 'Khách tự nhận'} ·{' '}
                                            {({ preparing: 'Chuẩn bị', ready: 'Chờ khách', trading: 'Đang giao', suspended: 'Tạm dừng phiên nhận' } as Record<string, string>)[o.session.status] || statusName[o.session.status] || o.session.status}
                                        </div>
                                    )}
                                    {o.session?.status === 'trading' && <div className="text-xs font-medium text-cyan-700 dark:text-cyan-300">
                                        {o.session.tradePhase === 'confirming' ? 'Đã khóa · Chờ hoàn tất' : 'Đang giao · Chờ khách khóa'}
                                        {o.session.phaseDeadline && <div>Hạn bước này: {dateTime(o.session.phaseDeadline)}</div>}
                                    </div>}
                                    {o.session?.retryAt && o.session.status === 'suspended' && <div className="text-xs text-amber-600">Nhận lại từ {dateTime(o.session.retryAt)}</div>}
                                    {o.message && (
                                        <div className="text-xs text-amber-600 dark:text-amber-400">{o.message}</div>
                                    )}
                                </div>
                            );
                        },
                    },
                    ...(canRefund ? [{
                        title: 'Xử lý', width: 140,
                        render: (_: unknown, o: Order) => ['awaiting_receipt', 'review', 'processing', 'queued'].includes(o.status) ? <div>
                            <Button size="small" danger disabled={o.status !== 'awaiting_receipt' || (!!o.session && ['queued','preparing','ready','trading','review'].includes(o.session.status))}
                                onClick={() => { form.resetFields(); form.setFieldsValue({ amount: o.items.some(i => i.delivered > 0) ? undefined : Number(o.price) }); setRefund(o); }}>Hoàn tiền</Button>
                            {(o.status !== 'awaiting_receipt' || (!!o.session && ['queued','preparing','ready','trading','review'].includes(o.session.status))) && <div className="mt-1 text-xs text-slate-500">Kết thúc phiên / đối soát trước</div>}
                        </div> : null,
                    }] : []),
                ]}
            />
            <Modal title={`Hoàn tiền đơn #${refund?.id || ''}`} open={!!refund} onCancel={() => !saving && setRefund(null)}
                okText="Xác nhận hoàn tiền" cancelText="Đóng" confirmLoading={saving} onOk={() => form.submit()}>
                <p className="mb-3">Người mua: <strong>{refund?.buyerUsername}</strong> · Tiền đơn: {money(refund?.price || 0)}</p>
                <Alert type="warning" showIcon message={partial ? 'Đơn đã giao một phần: nhập số tiền hoàn sau khi kiểm tra lịch sử giao.' : 'Chưa giao món nào: hoàn đủ tiền đơn.'}
                    description="Hoàn tiền sẽ kết thúc đơn và giải phóng số đồ chưa giao. Tool không tự hoàn tiền." />
                <Form form={form} layout="vertical" className="mt-4" onFinish={async v => {
                    setSaving(true);
                    try { await axios.post(`/admin/nro-shop/orders/${refund?.id}/refund`, v); message.success('Đã hoàn tiền và kết thúc đơn'); setRefund(null); reload(); }
                    catch (e: any) { message.error(e.response?.data?.message || 'Không hoàn được tiền. Hãy kiểm tra trạng thái phiên.'); }
                    finally { setSaving(false); }
                }}>
                    <Form.Item name="amount" label="Số tiền hoàn (đ)" rules={[{ required: true, message: 'Nhập số tiền cần hoàn' }]}>
                        <InputNumber className="!w-full" min={1} max={Number(refund?.price || 0)} precision={0} disabled={!partial} />
                    </Form.Item>
                    {partial && amount > 0 && <p className="mb-3 text-sm">Người bán nhận phần còn lại: <strong>{money(Math.max(0, Number(refund?.price || 0) - amount))}</strong>.</p>}
                    <Form.Item name="note" label="Lý do / kết quả kiểm tra" rules={[{ required: true, min: 10, max: 250, message: 'Nhập lý do từ 10–250 ký tự' }]}>
                        <Input.TextArea rows={3} maxLength={250} showCount />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
