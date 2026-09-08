import { useEffect, useState } from 'react';
import axios from 'axios';
import { Alert, Form, FormInstance, InputNumber, Select, Spin } from 'antd';

type Field = { id: number; name: string; options: { id: number; label: string }[]; suggestedIds: number[]; selectedId: number | null; source: string };

export default function NroNickAttributes({ accountId, form, onReady, initialConfig }: { initialConfig?: { categoryId: number; attributeSelections?: Record<string, number | null> }; accountId: number; form: FormInstance; onReady: (ready: boolean) => void }) {
    const categoryId = Form.useWatch('categoryId', form);
    const nickId = Form.useWatch('nickId', form);
    const [fields, setFields] = useState<Field[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    useEffect(() => {
        const controller = new AbortController();
        onReady(false); setFields([]); setError('');
        form.setFieldValue('snapshotId', undefined);
        form.setFieldValue('attributeSelections', {});
        if (!categoryId) { setLoading(false); return () => controller.abort(); }
        setLoading(true);
        axios.get(`/admin/nro-shop/accounts/${accountId}/nick-attributes`, {
            params: { categoryId, nickId: nickId || undefined }, signal: controller.signal,
        }).then(({ data }) => {
            if (controller.signal.aborted) return;
            setFields(data.fields);
            form.setFieldValue('attributeSelections', Object.fromEntries(data.fields.map((f: Field) => [String(f.id), initialConfig?.categoryId === categoryId && f.options.some(o => o.id === initialConfig?.attributeSelections?.[String(f.id)]) ? initialConfig?.attributeSelections?.[String(f.id)] : f.selectedId])));
            form.setFieldValue('snapshotId', data.snapshotId);
            onReady(true);
        }).catch(e => {
            if (!controller.signal.aborted) setError(axios.isAxiosError(e) ? e.response?.data?.message || 'Không tải được thuộc tính. Chọn lại danh mục để thử lại.' : 'Không tải được thuộc tính.');
        }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
        return () => { controller.abort(); onReady(false); };
    }, [accountId, categoryId, nickId, form, onReady, initialConfig]);

    return <div className="mb-4">
        <Form.Item name="snapshotId" hidden rules={[{ required: true }]}><InputNumber /></Form.Item>
        {loading && <Spin tip="Đang đối chiếu snapshot"><div className="h-12" /></Spin>}
        {error && <Alert type="error" showIcon message={error} />}
        {!loading && !error && categoryId && <>
            <Alert className="mb-3" type="info" showIcon message="Kiểm tra thuộc tính trước khi đăng" description="Hành tinh, server và cải trang được gợi ý khi đối chiếu được. Đăng kí mặc định Ảo nếu danh mục có option tương ứng; bạn có thể chỉnh lại. Mỗi thuộc tính chọn một giá trị cho bộ lọc; chi tiết nick vẫn hiển thị toàn bộ đồ trong snapshot." />
            {fields.map(field => <Form.Item key={field.id} name={['attributeSelections', String(field.id)]} label={field.name}
                extra={field.source === 'default' ? 'Mặc định Đăng kí: Ảo, dùng ID option của danh mục.' : field.source === 'existing' ? 'Giữ giá trị của nick đã đăng; bạn có thể chỉnh lại.' : field.source === 'snapshot' ? 'Đã điền gợi ý từ dữ liệu game. Bạn có thể chỉnh lại.' : field.suggestedIds.length > 1 ? 'Có nhiều giá trị khớp snapshot, hãy chọn giá trị để lọc.' : 'Chưa có giá trị đối chiếu được; chọn thủ công nếu có thông tin.'}>
                <Select allowClear showSearch optionFilterProp="label" placeholder="Chưa chọn" options={field.options.map(o => ({ value: o.id, label: o.label + (field.suggestedIds.includes(o.id) ? field.source === 'default' ? ' · Mặc định' : ' · Có trong dữ liệu game' : '') }))} />
            </Form.Item>)}
            {!fields.length && <p className="text-slate-500 mt-2">Danh mục này chưa cấu hình thuộc tính lọc.</p>}
        </>}
    </div>;
}
