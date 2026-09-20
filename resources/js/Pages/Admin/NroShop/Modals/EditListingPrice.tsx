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
    const [count, setCount] = useState(1);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    return <>
        <Button title="Sửa giá / số gói" aria-label="Sửa giá / số gói" icon={<Pencil size={16} />} disabled={disabled || listing.status === 'archived'} onClick={() => {
            setCount(listing.packagesRemaining ?? 1); setPrice(Number(listing.price)); setError(''); setOpen(true);
        }} />
        <Modal title={`Giá và số gói · #${listing.id}`} open={open} confirmLoading={saving}
            okText="Lưu thay đổi" cancelText="Đóng" okButtonProps={{ disabled: !price || price < 1 }}
            onCancel={() => { if (!saving) setOpen(false); }} onOk={async () => {
                if (!price || saving) return;
                setSaving(true); setError('');
                try {
                    await axios.patch(`${base}/listings/${listing.id}`, { price, ...(listing.stockMode !== 'auto' && count !== (listing.packagesRemaining ?? 1) ? { packageCount: count } : {}) });
                    setOpen(false); message.success('Đã cập nhật tin bán'); await onSaved();
                } catch (e) {
                    setError(axios.isAxiosError(e) ? e.response?.data?.message || 'Không lưu được giá.' : 'Không lưu được giá.');
                } finally { setSaving(false); }
            }}>
            <p className="mb-3">{listing.title} · Giá hiện tại {money(listing.price)}</p>
            <label className="mb-1 block text-sm" htmlFor={`price-${listing.id}`}>Giá mới cho mỗi gói (đ)</label>
            <InputNumber id={`price-${listing.id}`} className="!w-full" min={1} max={9999999999}
                precision={0} value={price} onChange={setPrice} disabled={saving} />
            <p className="mt-2 text-xs text-slate-500">Giá mới chỉ áp dụng cho đơn mua sau. Đơn đã thanh toán giữ nguyên giá.</p>
            {listing.stockMode !== 'auto' && <div className="mt-3"><label htmlFor={`count-${listing.id}`} className="mb-1 block text-sm">Số gói còn mở bán (không gồm đơn đã mua)</label><InputNumber id={`count-${listing.id}`} min={0} max={listing.quantityEnabled ? 1000000 : 1} precision={0} value={count} disabled={saving} onChange={v => setCount(v ?? 0)} className="!w-full" /></div>}
            {listing.stockMode === 'auto' && <p className="mt-3 text-xs">Tin tự động: số gói được tính lại sau khi cập nhật dữ liệu kho.</p>}
            {error && <Alert className="mt-3" type="error" showIcon message={error} />}
        </Modal>
    </>;
}
