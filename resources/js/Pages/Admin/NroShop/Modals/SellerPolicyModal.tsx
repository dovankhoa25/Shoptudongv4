import { useEffect, useState } from 'react';
import axios from 'axios';
import { Alert, Form, Input, Modal, Switch, message } from 'antd';
import { base } from '../shared';
import type { Account } from '../types';

const parseIds = (text?: string) => {
    const tokens = (text || '').trim().split(/[\s,;]+/).filter(Boolean);
    if (tokens.some(id => !/^\d+$/.test(id) || Number(id) > 100000)) throw new Error('Danh sách chỉ gồm ID số, ngăn cách bằng dấu phẩy hoặc xuống dòng.');
    return [...new Set(tokens.map(Number))];
};
export default function SellerPolicyModal({ account, onClose }: { account: Account; onClose: () => void }) {
    const [form] = Form.useForm();
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    useEffect(() => {
        let active = true;
        axios.get(`${base}/accounts/${account.id}/seller-policy`).then(({ data }) => {
            if (active) { form.setFieldsValue({ sellingEnabled: data.sellingEnabled, allow: (data.allowIds || []).join(', '), deny: (data.denyIds || []).join(', ') }); setLoading(false); }
        }).catch(() => { if (active) setError('Không tải được quyền bán. Đóng rồi mở lại để thử lại.'); });
        return () => { active = false; };
    }, [account.id, form]);
    const allow = Form.useWatch('allow', form) || '';
    const deny = Form.useWatch('deny', form) || '';
    let overlap: number[] = [];
    try { const blocked = parseIds(deny); overlap = parseIds(allow).filter(id => blocked.includes(id)); } catch { /* Validated on save. */ }
    return <Modal open width={600} title={`Quyền bán vật phẩm · ${account.ownerUsername || `CTV #${account.ownerId}`}`} onCancel={() => { if (!saving) onClose(); }}
        okText="Lưu quyền bán" cancelText="Đóng" confirmLoading={saving} okButtonProps={{ disabled: loading }} onOk={async () => {
            if (saving || loading) return;
            try {
                const v = await form.validateFields(); const allowIds = parseIds(v.allow); const denyIds = parseIds(v.deny);
                setSaving(true); setError('');
                await axios.patch(`${base}/accounts/${account.id}/seller-policy`, { sellingEnabled: v.sellingEnabled, allowIds, denyIds });
                message.success('Đã áp dụng cho tất cả kho của CTV. Đơn đã mua vẫn được giao.');
                window.dispatchEvent(new Event('admin:refresh-if-offline')); onClose();
            } catch (e) { setError(axios.isAxiosError(e) ? e.response?.data?.message || 'Không lưu được quyền bán.' : e instanceof Error ? e.message : 'Kiểm tra dữ liệu đã nhập.'); }
            finally { setSaving(false); }
        }}>
        <p className="mb-4 text-sm text-slate-500">Áp dụng cho mọi acc kho của người đăng này. ID bị cấm luôn được ưu tiên; cải trang không được đăng bán.</p>
        <Form form={form} layout="vertical" disabled={loading || saving}>
            <Form.Item name="sellingEnabled" label="Cho phép bán vật phẩm" valuePropName="checked"><Switch /></Form.Item>
            <Form.Item name="allow" label="Chỉ được bán các ID" extra="Để trống = tất cả vật phẩm hợp lệ, trừ danh sách cấm."><Input.TextArea rows={3} placeholder="Ví dụ: 220, 221, 222, 223, 224" /></Form.Item>
            <Form.Item name="deny" label="Không được bán các ID" extra="Không hiện trong bảng chọn đăng. Tin chứa món bị cấm sẽ ngừng nhận đơn mới."><Input.TextArea rows={3} placeholder="Ví dụ: 441, 442" /></Form.Item>
        </Form>
        {!!overlap.length && <Alert className="mb-3" type="warning" message={`ID nằm trong cả hai danh sách sẽ bị cấm: ${overlap.join(', ')}`} />}
        {error && <Alert type="error" showIcon message={error} />}
    </Modal>;
}
