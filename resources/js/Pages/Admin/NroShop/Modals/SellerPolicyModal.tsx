import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Alert, Button, Form, Input, Modal, Switch, Table, Tag, message } from 'antd';
import { base } from '../shared';

type SellerPolicy = { userId: number; username: string; sellingEnabled: boolean; allowIds: number[] | null; denyIds: number[] };
type SellerRow = { userId: number; username: string; roles: string[]; warehouses: number; listings: number; sellingEnabled: boolean };
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
    const [listError, setListError] = useState('');
    const [listLoading, setListLoading] = useState(false);
    const [listQuery, setListQuery] = useState('');
    const [page, setPage] = useState(1);
    const [listVersion, setListVersion] = useState(0);
    const [sellers, setSellers] = useState<{ data: SellerRow[]; total: number; perPage: number }>({ data: [], total: 0, perPage: 12 });
    useEffect(() => {
        const timer = setTimeout(() => { setPage(1); setListQuery(query.trim()); }, 300);
        return () => clearTimeout(timer);
    }, [query]);
    useEffect(() => {
        const controller = new AbortController();
        setListLoading(true); setListError('');
        axios.get(`${base}/sellers`, { params: { q: listQuery || undefined, page }, signal: controller.signal })
            .then(({ data }) => { if (!controller.signal.aborted) setSellers(data); })
            .catch(e => { if (!axios.isCancel(e)) setListError('Không tải được danh sách người bán.'); })
            .finally(() => { if (!controller.signal.aborted) setListLoading(false); });
        return () => controller.abort();
    }, [listQuery, page, listVersion]);
    const lookup = useRef<AbortController | null>(null);
    useEffect(() => () => { lookup.current?.abort(); }, []);
    const findSeller = async (term = query) => {
        if (loading || lookup.current) return;
        const q = term.trim();
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
    return <Modal open width={760} title={seller ? `Quyền bán vật phẩm · ${seller.username}` : 'Quyền bán vật phẩm'} onCancel={() => { if (!saving) onClose(); }}
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
                placeholder="Tìm người bán theo username hoặc #ID" enterButton="Mở quyền" onChange={e => setQuery(e.target.value)} onSearch={() => void findSeller()} />
            <p className="text-xs text-slate-500">Dùng username đầy đủ hoặc ID người dùng, không phải ID acc game. Username toàn số có thể nhập @username.</p>
            <div className="flex items-center justify-between gap-2"><p className="text-xs text-slate-500">Người có quyền bán vật phẩm và có acc kho. Chọn một người để mở quyền.</p><Button size="small" onClick={() => setListVersion(v => v + 1)}>Làm mới</Button></div>
            {listError && <Alert type="error" showIcon message={listError} />}
            <Table<SellerRow> size="small" rowKey="userId" loading={listLoading} dataSource={sellers.data} scroll={{ x: 540 }}
                locale={{ emptyText: 'Không có người bán phù hợp. Bạn vẫn có thể nhập username đầy đủ hoặc #ID để mở quyền.' }}
                pagination={{ current: page, total: sellers.total, pageSize: sellers.perPage, showSizeChanger: false, onChange: setPage, showTotal: total => `${total} người bán` }}
                columns={[
                    { title: 'Người bán', render: (_, user) => <><Button type="link" className="!h-auto !p-0" disabled={loading} onClick={() => void findSeller(`#${user.userId}`)}>{user.username}</Button><div className="text-xs text-slate-500">#{user.userId} · {user.roles.join(', ') || 'Quyền riêng'}</div></> },
                    { title: 'Acc kho', dataIndex: 'warehouses', width: 85 },
                    { title: 'Tin đang bật', dataIndex: 'listings', width: 95 },
                    { title: 'Quyền bán', width: 115, render: (_, user) => <Tag color={user.sellingEnabled ? 'green' : 'red'}>{user.sellingEnabled ? 'Cho phép' : 'Đã tắt'}</Tag> },
                    { title: '', width: 90, render: (_, user) => <Button size="small" disabled={loading} onClick={() => void findSeller(`#${user.userId}`)}>Mở quyền</Button> },
                ]} />

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
