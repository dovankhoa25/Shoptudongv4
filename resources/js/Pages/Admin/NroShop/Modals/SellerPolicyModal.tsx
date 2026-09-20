import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Alert, Button, Form, Input, Modal, Switch, message } from 'antd';
import { base } from '../shared';

type SellerPolicy = { userId: number; username: string; sellingEnabled: boolean; allowIds: number[] | null; denyIds: number[] };
const parseIds = (text?: string) => {
    const tokens = (text || '').trim().split(/[\s,;]+/).filter(Boolean);
    if (tokens.some(id => !/^\d+$/.test(id) || Number(id) > 100000)) throw new Error('Danh sách chỉ gồm ID số, ngăn cách bằng dấu phẩy hoặc xuống dòng.');
    return [...new Set(tokens.map(Number))];
};
export default function SellerPolicyModal({ onClose }: { onClose: () => void }) {
    const [form] = Form.useForm();
    const [query, setQuery] = useState('');
    const [seller, setSeller] = useState<SellerPolicy | null>(null);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const lookup = useRef<AbortController | null>(null);
    useEffect(() => () => { lookup.current?.abort(); }, []);
    const findSeller = async () => {
        if (loading || lookup.current) return;
        const q = query.trim();
        if (!q) { setError('Nhập username hoặc ID người dùng cần chỉnh quyền.'); return; }
        const request = new AbortController(); lookup.current = request;
        setLoading(true); setError('');
        try {
            const { data } = await axios.get<SellerPolicy>(`${base}/seller-policy`, { params: { q }, signal: request.signal });
            if (request.signal.aborted) return;
            form.setFieldsValue({ sellingEnabled: data.sellingEnabled, allow: (data.allowIds || []).join(', '), deny: (data.denyIds || []).join(', ') });
            setSeller(data);
        } catch (e) {
            if (!axios.isCancel(e)) setError(axios.isAxiosError(e) ? e.response?.data?.message || 'Không tải được quyền bán. Vui lòng thử lại.' : 'Không tải được quyền bán.');
        } finally {
            lookup.current = null;
            if (!request.signal.aborted) setLoading(false);
        }
    };
    const allow = Form.useWatch('allow', form) || '';
    const deny = Form.useWatch('deny', form) || '';
    let overlap: number[] = [];
    try { const blocked = parseIds(deny); overlap = parseIds(allow).filter(id => blocked.includes(id)); } catch { /* Validated on save. */ }
    return <Modal open width={600} title={seller ? `Quyền bán vật phẩm · ${seller.username}` : 'Quyền bán vật phẩm'} onCancel={() => { if (!saving) onClose(); }}
        {...(!seller ? { footer: null } : {})}
        okText="Lưu quyền bán" cancelText="Đóng" confirmLoading={saving} onOk={async () => {
            if (saving || !seller) return;
            try {
                const v = await form.validateFields(); const allowIds = parseIds(v.allow); const denyIds = parseIds(v.deny);
                setSaving(true); setError('');
                await axios.patch(`${base}/sellers/${seller.userId}/policy`, { sellingEnabled: v.sellingEnabled, allowIds, denyIds });
                message.success(`Đã lưu quyền bán cho ${seller.username}. Áp dụng mọi acc kho của người dùng này.`);
                window.dispatchEvent(new Event('admin:refresh-if-offline')); onClose();
            } catch (e) { setError(axios.isAxiosError(e) ? e.response?.data?.message || 'Không lưu được quyền bán.' : e instanceof Error ? e.message : 'Kiểm tra dữ liệu đã nhập.'); }
            finally { setSaving(false); }
        }}>
        {!seller ? <div className="space-y-3">
            <p className="text-sm text-slate-500">Tìm người dùng để chỉnh quyền bán cho tất cả acc kho hiện tại và thêm sau này.</p>
            <label htmlFor="nro-seller-search" className="block text-sm">Username hoặc ID người dùng</label>
            <Input.Search id="nro-seller-search" autoFocus value={query} maxLength={255} disabled={loading} loading={loading}
                placeholder="Ví dụ: ctvhoang hoặc #123" enterButton="Mở quyền" onChange={e => setQuery(e.target.value)} onSearch={() => void findSeller()} />
            <p className="text-xs text-slate-500">Dùng username đầy đủ hoặc ID người dùng, không phải ID acc game. Username toàn số có thể nhập @username.</p>
        </div> : <>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-300 p-3 dark:border-slate-700">
                <div className="min-w-0 text-sm"><strong className="break-all">{seller.username}</strong><span className="ml-2 text-slate-500">ID: {seller.userId}</span></div>
                <Button size="small" disabled={saving} onClick={() => { setSeller(null); setError(''); form.resetFields(); }}>Chọn người khác</Button>
            </div>
            <p className="mb-4 text-sm text-slate-500">Áp dụng cho mọi acc kho của người dùng này, kể cả acc thêm sau. ID bị cấm luôn được ưu tiên; cải trang không được đăng bán. Đơn đã mua vẫn được giao.</p>
            <Form form={form} layout="vertical" disabled={saving}>
                <Form.Item name="sellingEnabled" label="Cho phép bán vật phẩm" valuePropName="checked"><Switch /></Form.Item>
                <Form.Item name="allow" label="Chỉ được bán các ID" extra="Để trống = tất cả vật phẩm hợp lệ, trừ danh sách cấm."><Input.TextArea rows={3} placeholder="Ví dụ: 220, 221, 222, 223, 224" /></Form.Item>
                <Form.Item name="deny" label="Không được bán các ID" extra="Không hiện trong bảng chọn đăng. Tin chứa món bị cấm sẽ ngừng nhận đơn mới."><Input.TextArea rows={3} placeholder="Ví dụ: 441, 442" /></Form.Item>
            </Form>
            {!!overlap.length && <Alert className="mb-3" type="warning" message={`ID nằm trong cả hai danh sách sẽ bị cấm: ${overlap.join(', ')}`} />}
        </>}
        {error && <Alert className="mt-3" type="error" showIcon message={error} />}
    </Modal>;
}
