import { Pencil } from 'lucide-react';
import { useState } from 'react';
import axios from 'axios';
import { Alert, Button, InputNumber, Modal, message } from 'antd';
import { base, money } from '../shared';
import type { Listing } from '../types';

export default function EditListingPrice({ listing, disabled, onSaved }: {
    listing: Listing; disabled?: boolean; onSaved: () => void | Promise<void>;
}) {
    const [open, setOpen] = useState(false);
    const [price, setPrice] = useState<number | null>(null);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    return <>
        <Button title="Sửa giá" aria-label="Sửa giá" icon={<Pencil size={16} />} disabled={disabled || listing.status === 'sold'} onClick={() => {
            setPrice(Number(listing.price)); setError(''); setOpen(true);
        }} />
        <Modal title={`Sửa giá gói #${listing.id}`} open={open} confirmLoading={saving}
            okText="Lưu giá" cancelText="Đóng" okButtonProps={{ disabled: !price || price < 1 }}
            onCancel={() => { if (!saving) setOpen(false); }} onOk={async () => {
                if (!price || saving) return;
                setSaving(true); setError('');
                try {
                    await axios.patch(`${base}/listings/${listing.id}`, { price });
                    setOpen(false); message.success('Đã cập nhật giá bán'); await onSaved();
                } catch (e) {
                    setError(axios.isAxiosError(e) ? e.response?.data?.message || 'Không lưu được giá.' : 'Không lưu được giá.');
                } finally { setSaving(false); }
            }}>
            <p className="mb-3">{listing.title} · Giá hiện tại {money(listing.price)}</p>
            <label className="mb-1 block text-sm" htmlFor={`price-${listing.id}`}>Giá mới cho cả gói (đ)</label>
            <InputNumber id={`price-${listing.id}`} className="!w-full" min={1} max={9999999999}
                precision={0} value={price} onChange={setPrice} disabled={saving} />
            <p className="mt-2 text-xs text-slate-500">Giá mới chỉ áp dụng khi gói chưa có người mua.</p>
            {error && <Alert className="mt-3" type="error" showIcon message={error} />}
        </Modal>
    </>;
}
