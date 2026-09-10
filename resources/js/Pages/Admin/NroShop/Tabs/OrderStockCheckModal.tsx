import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Alert, Button, Checkbox, Form, Input, InputNumber, Modal, Select, Spin, Table, Tag, message } from 'antd';
import { NroIcon } from '@/Components/Nro/NroSnapshot';
import type { Order } from '../types';
import { base, dateTime, statusName } from '../shared';

type StockLine = Order['items'][number] & { remaining: number; inStock: number | null; heldForOthers: number; available: number | null; missing: number | null };
type Report = {
    order: Order; complete: boolean; checking: boolean; items: StockLine[];
    check: { id: number; status: string; requestedAt: string; requestedBy: string; capturedAt?: string; message?: string } | null;
    reviewJobs: { id: number; lease_until?: string }[];
};

export default function OrderStockCheckModal({ order, canReconcile, onClose, onChanged }: {
    order: Order; canReconcile: boolean; onClose: () => void; onChanged: () => void;
}) {
    const [report, setReport] = useState<Report | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [stopped, setStopped] = useState(false);
    const [form] = Form.useForm();
    const initialized = useRef(false);
    const requestId = useRef(0);
    const url = `${base}/orders/${order.id}/stock-check`;
    const apply = (data: Report) => {
        setReport(data); setError('');
        if (!initialized.current && data.reviewJobs.length) {
            initialized.current = true;
            form.setFieldsValue({ jobId: data.reviewJobs[0].id, items: data.items.map(i => ({ id: i.id, delivered: i.delivered })) });
        }
    };
    useEffect(() => {
        const controller = new AbortController(); let timer: ReturnType<typeof setTimeout>;
        const load = async () => {
            const id = ++requestId.current;
            try { const { data } = await axios.get<Report>(url, { signal: controller.signal }); if (id === requestId.current) apply(data); }
            catch (e: any) { if (!controller.signal.aborted && id === requestId.current) setError(e.response?.data?.message || 'Không tải được kết quả kiểm tra.'); }
            finally { if (!controller.signal.aborted) timer = setTimeout(load, 5000); }
        };
        void load();
        return () => { controller.abort(); clearTimeout(timer); };
    }, [url]);
    const check = async () => {
        setBusy(true); const id = ++requestId.current;
        try { const { data } = await axios.post<Report>(url, { confirmedStopped: stopped }); if (id === requestId.current) apply(data); message.success('Đã yêu cầu tool kiểm tra kho'); onChanged(); }
        catch (e: any) { message.error(e.response?.data?.message || 'Không tạo được yêu cầu kiểm tra.'); }
        finally { setBusy(false); }
    };
    const cancelCheck = async () => {
        setBusy(true); const id = ++requestId.current;
        try { const { data } = await axios.delete<Report>(url, { data: { confirmedStopped: true } }); if (id === requestId.current) apply(data); message.success('Đã hủy yêu cầu kiểm tra'); onChanged(); }
        catch (e: any) { message.error(e.response?.data?.message || 'Chưa hủy được yêu cầu kiểm tra.'); }
        finally { setBusy(false); }
    };
    const missing = report?.complete ? report.items.reduce((n, i) => n + (i.missing || 0), 0) : null;
    return <Modal title={`Đối soát / kiểm tra kho · Đơn #${order.id}`} open width={900} onCancel={() => !busy && onClose()} footer={<Button onClick={onClose} disabled={busy}>Đóng</Button>}>
        <p className="mb-3 text-sm">Acc kho: <strong>{order.accountName || `#${order.accountId}`}</strong> · Người mua: {order.buyerUsername || '—'}</p>
        <Alert type="info" showIcon message="Tool chỉ lấy dữ liệu, không giao đồ hoặc hoàn tiền."
            description="Tồn kho dùng để kiểm tra khả năng giao phần còn lại. Đồ còn hay mất không tự chứng minh khách đã nhận; hãy đối chiếu lịch sử giao trước khi chốt." />
        {error && <Alert className="mt-3" type="error" message={error} />}
        {!report && !error && <div className="py-6 text-center"><Spin /></div>}
        {report && <>
            <div className="my-3 rounded-lg border border-slate-300 p-3 dark:border-slate-700">
                {report.check && <p className="mb-2 text-xs">Kiểm tra #{report.check.id} · <Tag>{statusName[report.check.status] || report.check.status}</Tag> · {report.check.requestedBy} · {dateTime(report.check.requestedAt)}</p>}
                {report.checking ? <p className="text-sm text-sky-600">Đang chờ tool / đang kiểm tra kho. Cửa sổ tự cập nhật mỗi 5 giây. Nếu tool đang tắt, hãy bật để nhận công việc quét.</p> : <>
                    {['review', 'awaiting_receipt'].includes(report.order.status) && <>
                        <Checkbox checked={stopped} onChange={e => setStopped(e.target.checked)}>Tôi đã dừng phiên game cũ của acc kho, không có lượt giao đang diễn ra.</Checkbox>
                        <div className="mt-2"><Button type="primary" loading={busy} disabled={!stopped} onClick={check}>{report.check ? 'Kiểm tra kho lại bằng tool' : 'Kiểm tra acc bằng tool'}</Button></div>
                        <p className="mt-2 text-xs text-slate-500">Tool cần chạy và bật quét dữ liệu. Nếu quyền giữ phiên cũ chưa hết hạn, hệ thống sẽ báo để chờ trước khi đăng nhập lại.</p>
                    </>}
                </>}
                {report.check?.message && <p className="mt-2 text-xs text-amber-600">{report.check.message}</p>}
                {report.checking && report.check && ['queued','processing'].includes(report.check.status) && <div className="mt-2">
                    {report.check.status === 'processing' && <p className="mb-2 text-xs text-slate-500">Muốn hủy khi tool mất kết nối: dừng phiên game cũ và đợi quyền giữ phiên hết hạn.</p>}
                    <Button size="small" danger loading={busy} onClick={cancelCheck}>Hủy yêu cầu kiểm tra</Button>
                </div>}
            </div>
            {report.complete ? <Alert className="mb-3" type={missing ? 'warning' : 'success'} showIcon
                message={missing ? `Thiếu ${missing} vật phẩm cho phần chưa giao` : 'Tồn kho tại lần kiểm tra đủ cho phần chưa giao'}
                description={`Dữ liệu lúc ${dateTime(report.check?.capturedAt)}. Đã trừ số lượng giữ cho đơn khác; không tự thay đổi kết quả giao.`} />
                : <Alert className="mb-3" type="warning" showIcon message="Chưa có kết quả kiểm tra đầy đủ túi, rương và trang bị. Chưa thể kết luận còn hay thiếu đồ." />}
            <Table<StockLine> size="small" rowKey="id" dataSource={report.items} pagination={false} scroll={{ x: 650 }} columns={[
                { title: 'Món đúng chỉ số', width: 250, render: (_, i) => <div className="flex gap-2"><NroIcon item={i.item} size={30} /><div><strong className="text-xs">{i.item.name || `ID ${i.item.templateId}`}</strong><div className="text-xs text-slate-500">ID {i.item.templateId}</div><div className="text-xs text-green-600">{i.item.options?.map((o: any) => o.text || `${o.optionId}: ${o.param}`).join(' · ')}</div></div></div> },
                { title: 'Cần giao', dataIndex: 'remaining', width: 75 },
                { title: 'Kho có', dataIndex: 'inStock', width: 70, render: v => v ?? '—' },
                { title: 'Giữ đơn khác', dataIndex: 'heldForOthers', width: 95 },
                { title: 'Còn thiếu', dataIndex: 'missing', width: 80, render: v => v == null ? '—' : <span className={v > 0 ? 'font-semibold text-red-500' : 'text-green-600'}>{v}</span> },
            ]} />
            {canReconcile && report.reviewJobs.length > 0 && <Form form={form} layout="vertical" className="mt-5 border-t border-slate-300 pt-4 dark:border-slate-700" onFinish={async v => {
                setBusy(true); ++requestId.current;
                try {
                    const { jobId, ...body } = v; await axios.post(`${base}/jobs/${jobId}/reconcile`, body);
                    message.success('Đã chốt đối soát. Có thể nhận lại hoặc hoàn tiền nếu còn phần chưa giao.');
                    onChanged(); onClose();
                } catch (e: any) { message.error(e.response?.data?.message || 'Chưa chốt được đối soát.'); }
                finally { setBusy(false); }
            }}>
                <p className="mb-3 font-medium">Chốt kết quả đã kiểm tra</p>
                <Form.Item name="jobId" label="Lượt giao cần đối soát" rules={[{ required: true }]}><Select options={report.reviewJobs.map(j => ({ value: j.id, label: `Job #${j.id}` }))} /></Form.Item>
                <Form.Item name="resolution" label="Kết quả thực tế" rules={[{ required: true, message: 'Chọn kết quả đã xác minh' }]}><Select options={[
                    { value: 'not_delivered', label: 'Ghi nhận số đã giao dưới đây, cho nhận lại phần còn lại' },
                    { value: 'delivered', label: 'Đã xác minh khách nhận đủ toàn bộ đơn' },
                ]} /></Form.Item>
                <Form.List name="items">{fields => <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2">{fields.map((field, n) => <div key={field.key}><Form.Item name={[field.name, 'id']} hidden><InputNumber /></Form.Item><Form.Item name={[field.name, 'delivered']} label={`Tổng đã giao · ${report.items[n]?.item.name || `Món ${n + 1}`}`} rules={[{ required: true }]}>
                    <InputNumber className="!w-full" min={report.items[n]?.delivered || 0} max={report.items[n]?.quantity || 0} precision={0} />
                </Form.Item></div>)}</div>}</Form.List>
                <Form.Item name="note" label="Bằng chứng / lý do đối soát" rules={[{ required: true, min: 10, max: 250, message: 'Nhập nội dung kiểm tra từ 10–250 ký tự' }]}><Input.TextArea maxLength={250} rows={2} /></Form.Item>
                <p className="mb-3 text-xs text-amber-600">Chỉ chốt khi đã xác minh số đồ khách thực nhận. Kiểm tra tồn kho không thay thế lịch sử giao. Nếu chưa rõ, giữ trạng thái đối soát.</p>
                <Button type="primary" htmlType="submit" disabled={report.checking} loading={busy}>Xác nhận đối soát</Button>
            </Form>}
            {!report.reviewJobs.length && report.order.status === 'awaiting_receipt' && <p className="mt-3 text-sm">Đơn đang chờ nhận. Khách có thể yêu cầu nhận lại; admin có thể đóng cửa sổ và chọn Hoàn tiền sau khi đã kiểm tra.</p>}
        </>}
    </Modal>;
}
