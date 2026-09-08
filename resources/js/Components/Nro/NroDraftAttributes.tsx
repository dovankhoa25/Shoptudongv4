import { useEffect, useState } from 'react';
import axios from 'axios';
import { Alert, Form, FormInstance, Select, Spin } from 'antd';

type Field = { source?: string; id: number; name: string; autoFill: boolean; selectedId: number | null; options: { id: number; label: string }[] };
export default function NroDraftAttributes({ form, onReady }: { form: FormInstance; onReady: (ready: boolean) => void }) {
    const categoryId = Form.useWatch('categoryId', form);
    const serverId = Form.useWatch('serverId', form);
    const [fields, setFields] = useState<Field[]>([]);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    useEffect(() => {
        const controller = new AbortController();
        onReady(false); setFields([]); setError('');
        form.setFieldValue('attributeSelections', {});
        if (!categoryId) { setLoading(false); return () => controller.abort(); }
        setLoading(true);
        axios.get('/admin/nro-shop/nick-attribute-fields', { params: { categoryId, serverId }, signal: controller.signal }).then(({ data }) => {
            if (controller.signal.aborted) return;
            setFields(data.fields);
            form.setFieldValue('attributeSelections', Object.fromEntries(data.fields.map((f: Field) => [String(f.id), f.selectedId])));
            onReady(true);
        }).catch(e => {
            if (!controller.signal.aborted) setError(axios.isAxiosError(e) ? e.response?.data?.message || 'Không tải được thuộc tính. Chọn lại danh mục để thử lại.' : 'Không tải được thuộc tính.');
        }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
        return () => { controller.abort(); onReady(false); };
    }, [categoryId, serverId, form, onReady]);
    return <div className="mb-3">
        {loading && <Spin><div className="h-10" /></Spin>}
        {error && <Alert type="error" message={error} showIcon />}
        {fields.length > 0 && <p className="mb-3 text-xs text-slate-500">Thuộc tính theo danh mục. Có thể chọn trước; trường bỏ trống chỉ được tự điền khi có một giá trị khớp dữ liệu game.</p>}
        <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2">{fields.map(field => <Form.Item key={field.id} name={['attributeSelections', String(field.id)]} label={field.name} extra={field.source === 'default' ? 'Mặc định Ảo theo option của danh mục.' : field.autoFill ? 'Tự đối chiếu sau khi lấy snapshot nếu để trống.' : 'Chọn nếu biết; tool không xác định thông tin này.'}>
            <Select allowClear showSearch optionFilterProp="label" placeholder={field.autoFill ? 'Tự điền từ dữ liệu game' : 'Chưa chọn'} options={field.options.map(o => ({ value: o.id, label: o.label }))} />
        </Form.Item>)}</div>
    </div>;
}
