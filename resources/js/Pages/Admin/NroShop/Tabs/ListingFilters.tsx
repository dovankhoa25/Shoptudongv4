import { useEffect, useState } from 'react';
import { Button, Input, InputNumber, Select } from 'antd';
import { ChevronDown, ChevronUp, SlidersHorizontal } from 'lucide-react';
import type { Server } from '../types';

type Option = { value: string; label: string };
export type FilterMetadata = {
    groups: (Option & { filterMode: 'basic' | 'items' | 'equipment' })[];
    equipmentTypes: Option[];
    stats: Option[];
    itemsByGroup: Record<string, Option[]>;
};
export type ListingMetadata = { filters: FilterMetadata; clearedFilters: string[] };
type Values = Record<string, unknown>;

export default function ListingFilters({ filters, metadata, servers, setFilters, apply, reload }: {
    filters: Values; metadata?: FilterMetadata; servers: Server[];
    setFilters: (filters: Values) => void; apply: (filters?: Values) => void; reload: () => void;
}) {
    const [expanded, setExpanded] = useState(true);
    const [prices, setPrices] = useState<{ minPrice: number | null; maxPrice: number | null }>({ minPrice: null, maxPrice: null });
    useEffect(() => { setPrices({ minPrice: filters.minPrice == null ? null : Number(filters.minPrice), maxPrice: filters.maxPrice == null ? null : Number(filters.maxPrice) }); }, [filters.minPrice, filters.maxPrice]);
    const invalidPrice = prices.minPrice != null && prices.maxPrice != null && prices.minPrice > prices.maxPrice;
    const mode = metadata?.groups.find(g => g.value === filters.group)?.filterMode;
    const change = (key: string, value: unknown) => apply({ ...filters, [key]: value });
    const savePrices = () => {
        if (!invalidPrice && (prices.minPrice !== (filters.minPrice ?? null) || prices.maxPrice !== (filters.maxPrice ?? null))) apply({ ...filters, ...prices });
    };
    const select = (key: string, label: string, options: { value: string | number; label: string }[]) => <label className="flex min-w-0 flex-col gap-1 text-xs text-slate-500">
        {label}<Select aria-label={label} className="w-full" allowClear showSearch optionFilterProp="label" placeholder="Tất cả"
            value={filters[key] as string | number | undefined} options={options} onChange={value => change(key, value)} />
    </label>;
    return <div className="mb-3 space-y-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
        <div className="flex flex-wrap items-center gap-2">
            <Input.Search aria-label="Tìm gói đồ" placeholder="Tên, acc, CTV · #id · #tk:acc · #ctv:tên" className="min-w-56 flex-1" allowClear
                value={(filters.q as string) || ''} onChange={e => setFilters({ ...filters, q: e.target.value })} onSearch={() => apply()} />
            <Select aria-label="Trạng thái tin bán" className="min-w-40" placeholder="Tất cả trạng thái" allowClear value={filters.status as string | undefined}
                onChange={value => change('status', value)} options={[
                    { value: 'active', label: 'Đang bán' }, { value: 'paused', label: 'Tạm dừng' }, { value: 'sold', label: 'Đã bán hết số gói' },
                    { value: 'draft', label: 'Bản nháp' }, { value: 'archived', label: 'Đã thu hồi' }, { value: 'blocked', label: 'Bị chặn quyền bán' },
                ]} />
            <Select aria-label="Sắp xếp tin bán" className="min-w-36" value={(filters.sort as string) || 'newest'} onChange={value => change('sort', value)} options={[
                { value: 'newest', label: 'Mới đăng trước' }, { value: 'price_asc', label: 'Giá tăng dần' }, { value: 'price_desc', label: 'Giá giảm dần' },
            ]} />
            <Button onClick={() => apply({})}>Xóa lọc</Button><Button onClick={reload}>Làm mới</Button>
        </div>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <label className="flex min-w-0 flex-col gap-1 text-xs text-slate-500">Nhóm vật phẩm
                <Select aria-label="Nhóm vật phẩm" placeholder="Tất cả vật phẩm" allowClear showSearch optionFilterProp="label"
                    value={filters.group as string | undefined} options={metadata?.groups || []} onChange={group => apply({ ...filters, group, equipmentType: undefined, gender: undefined, minStars: undefined, stat: undefined, itemId: undefined })} />
            </label>
            {select('server', 'Server', servers.map(s => ({ value: s.id, label: s.name_view || s.name })))}
            {select('bundle', 'Kiểu gói', [{ value: 'single', label: 'Một loại đồ' }, { value: 'combo', label: 'Combo nhiều món' }])}
        </div>
        <button type="button" aria-expanded={expanded} aria-controls="nro-admin-listing-details" onClick={() => setExpanded(!expanded)} className="flex w-full items-center justify-between rounded-md bg-violet-500/10 px-3 py-2 text-xs font-semibold text-violet-600 dark:text-violet-300">
            <span className="flex items-center gap-2"><SlidersHorizontal size={14} />Lọc chi tiết · Giá{mode === 'equipment' ? ', loại trang bị, hành tinh, sao, chỉ số' : mode === 'items' ? ', vật phẩm cụ thể' : ''}</span>
            <span className="ml-2 flex shrink-0 items-center gap-1">{expanded ? 'Thu gọn' : 'Mở bộ lọc'}{expanded ? <ChevronUp size={14} /> : <ChevronDown size={14} />}</span>
        </button>
        {expanded && <div id="nro-admin-listing-details" className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <label className="flex min-w-0 flex-col gap-1 text-xs text-slate-500">Giá gói từ (đ)<InputNumber aria-label="Giá gói từ" className="!w-full" min={0} max={1000000000000} placeholder="Không giới hạn"
                value={prices.minPrice} onChange={value => setPrices({ ...prices, minPrice: value })} onBlur={savePrices} onPressEnter={savePrices} status={invalidPrice ? 'error' : undefined} /></label>
            <label className="flex min-w-0 flex-col gap-1 text-xs text-slate-500">Giá gói đến (đ)<InputNumber aria-label="Giá gói đến" className="!w-full" min={0} max={1000000000000} placeholder="Không giới hạn"
                value={prices.maxPrice} onChange={value => setPrices({ ...prices, maxPrice: value })} onBlur={savePrices} onPressEnter={savePrices} status={invalidPrice ? 'error' : undefined} /></label>
            {mode === 'items' && select('itemId', 'Vật phẩm', metadata?.itemsByGroup[String(filters.group)] || [])}
            {mode === 'equipment' && <>
                {select('equipmentType', 'Loại trang bị', metadata?.equipmentTypes || [])}
                {select('gender', 'Phù hợp hành tinh', [{ value: '0', label: 'Trái Đất' }, { value: '1', label: 'Namec' }, { value: '2', label: 'Xayda' }])}
                {select('minStars', 'Số sao pha lê', Array.from({ length: 9 }, (_, i) => ({ value: String(i + 1), label: `Từ ${i + 1} sao` })))}
                {select('stat', 'Chỉ số trên món', metadata?.stats || [])}
            </>}
            {invalidPrice && <p role="alert" className="col-span-2 text-xs text-red-500">Giá đến phải lớn hơn hoặc bằng giá từ.</p>}
        </div>}
        {mode === 'equipment' && expanded && <p className="text-xs text-slate-500">Số sao gồm ô đã ép và chưa ép. Các điều kiện trang bị cùng áp dụng trên một món trong combo.</p>}
    </div>;
}
