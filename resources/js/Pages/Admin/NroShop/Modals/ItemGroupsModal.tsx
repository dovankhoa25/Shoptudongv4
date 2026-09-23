import { useEffect, useState } from 'react';
import axios from 'axios';
import { Alert, Button, Input, Modal, Popconfirm, Select, Spin, Switch, Tag, message } from 'antd';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import { base } from '../shared';

type Group = { key: string; name: string; visible: boolean; position: number; filterMode: 'basic' | 'items' | 'equipment'; builtin: boolean; defaultIds: number[]; ids: number[] };
type Draft = Group & { idsText: string };
type Configuration = { revision: string; groups: Group[] };
const modes = [{ value: 'basic', label: 'Cơ bản — khoảng giá' }, { value: 'items', label: 'Theo vật phẩm — chọn từng món và giá' }, { value: 'equipment', label: 'Trang bị — loại đồ, hành tinh, sao, chỉ số và giá' }];
const errorText = (error: unknown) => axios.isAxiosError(error)
    ? Object.values(error.response?.data?.errors || {}).flat().join(' ') || error.response?.data?.message || 'Không kết nối được máy chủ. Hãy thử lại.'
    : error instanceof Error ? error.message : 'Không lưu được cấu hình.';

export default function ItemGroupsModal({ onClose }: { onClose: () => void }) {
    const [groups, setGroups] = useState<Draft[]>([]);
    const [revision, setRevision] = useState('');
    const [selected, setSelected] = useState('');
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [conflict, setConflict] = useState(false);
    const [loadVersion, setLoadVersion] = useState(0);
    useEffect(() => {
        const abort = new AbortController();
        setLoading(true); setError(''); setConflict(false);
        axios.get<Configuration>(`${base}/item-groups`, { signal: abort.signal }).then(({ data }) => {
            setRevision(data.revision);
            setGroups(data.groups.map(g => ({ ...g, idsText: g.ids.join(', ') })));
            setSelected(data.groups[0]?.key || '');
        }).catch(e => { if (!axios.isCancel(e)) setError(errorText(e)); })
            .finally(() => { if (!abort.signal.aborted) setLoading(false); });
        return () => abort.abort();
    }, [loadVersion]);

    const current = groups.find(g => g.key === selected);
    const index = groups.findIndex(g => g.key === selected);
    const patch = (values: Partial<Draft>) => setGroups(rows => rows.map(g => g.key === selected ? { ...g, ...values } : g));
    const move = (offset: number) => setGroups(rows => {
        const next = [...rows], to = index + offset;
        if (index < 0 || to < 0 || to >= next.length) return rows;
        [next[index], next[to]] = [next[to], next[index]];
        return next;
    });
    const save = async () => {
        setSaving(true); setError('');
        try {
            const seen = new Map<number, string>();
            const payload = groups.map((g, position) => {
                if (!g.name.trim()) { setSelected(g.key); throw new Error('Nhập tên cho tất cả nhóm.'); }
                const parts = g.idsText.trim().split(/[\s,;]+/).filter(Boolean);
                if (parts.some(id => !/^\d+$/.test(id) || Number(id) > 100000)) {
                    setSelected(g.key); throw new Error(`${g.name}: nhập ID số nguyên, cách nhau bằng dấu phẩy hoặc xuống dòng.`);
                }
                const ids = [...new Set(parts.map(Number))];
                for (const id of ids) {
                    if (seen.has(id)) { setSelected(g.key); throw new Error(`ID ${id} đã được nhập ở nhóm ${seen.get(id)}. Bỏ ID khỏi nhóm đó trước khi chuyển.`); }
                    seen.set(id, g.name);
                }
                return { key: g.key, name: g.name.trim(), visible: g.visible, position, filterMode: g.filterMode, ids };
            });
            await axios.patch(`${base}/item-groups`, { revision, groups: payload });
            message.success('Đã lưu nhóm và bộ lọc vật phẩm.'); onClose();
        } catch (e) {
            setError(errorText(e));
            if (axios.isAxiosError(e) && e.response?.status === 409) setConflict(true);
        } finally { setSaving(false); }
    };

    return <Modal open title="Nhóm & bộ lọc vật phẩm" width={940} zIndex={1200} onCancel={() => { if (!saving) onClose(); }} maskClosable={false}
        styles={{ body: { maxHeight: '72dvh', overflowY: 'auto' } }} footer={<div className="flex flex-wrap justify-between gap-2">
            <span className="self-center text-xs text-slate-500">Thay đổi chỉ áp dụng sau khi bấm lưu.</span>
            <div className="flex gap-2"><Button disabled={saving} onClick={onClose}>Đóng</Button><Button type="primary" loading={saving} disabled={loading || !revision || conflict} onClick={save}>Lưu cấu hình</Button></div>
        </div>}>
        <p className="mb-3 text-xs text-slate-500">Cấu hình nhóm trên trang mua đồ. Không thay đổi quyền bán, tồn kho hoặc đơn đã mua.</p>
        {error && <Alert className="mb-3" type="error" showIcon message={error} action={(conflict || !revision) ? <Button size="small" onClick={() => setLoadVersion(v => v + 1)}>Tải lại cấu hình</Button> : undefined} />}
        {loading ? <div className="py-12 text-center"><Spin /></div> : <div className="grid gap-4 md:grid-cols-[230px_minmax(0,1fr)]">
            <aside className="space-y-2">
                <div className="max-h-52 space-y-1 overflow-y-auto md:max-h-[52dvh]" aria-label="Danh sách nhóm">
                    {groups.map(g => <button key={g.key} disabled={saving} onClick={() => setSelected(g.key)} aria-pressed={selected === g.key}
                        className={`flex w-full items-center justify-between gap-2 rounded-lg border px-3 py-2 text-left text-xs ${selected === g.key ? 'border-blue-500 bg-blue-500/10' : 'border-slate-200 dark:border-slate-700'}`}>
                        <span className="min-w-0 truncate">{g.name || 'Nhóm mới'}</span>{!g.visible && <Tag className="m-0" bordered={false}>Ẩn</Tag>}
                    </button>)}
                </div>
                <Button block disabled={saving || groups.length >= 50} icon={<Plus size={14} />} onClick={() => {
                    const key = `group_${crypto.randomUUID().replaceAll('-', '').slice(0, 16)}`;
                    setGroups(rows => [...rows, { key, name: '', visible: true, position: rows.length, filterMode: 'items', builtin: false, defaultIds: [], ids: [], idsText: '' }]);
                    setSelected(key);
                }}>Thêm nhóm</Button>
            </aside>
            {current && <fieldset disabled={saving} className="min-w-0 space-y-4 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="text-xs text-slate-500">{current.builtin ? 'Nhóm mặc định' : 'Nhóm tùy chỉnh'}</span>
                    <div className="flex gap-1">
                        <Button size="small" title="Đưa lên" aria-label="Đưa nhóm lên" disabled={saving || index === 0} icon={<ArrowUp size={14} />} onClick={() => move(-1)} />
                        <Button size="small" title="Đưa xuống" aria-label="Đưa nhóm xuống" disabled={saving || index === groups.length - 1} icon={<ArrowDown size={14} />} onClick={() => move(1)} />
                        {!current.builtin && <Popconfirm title="Xóa nhóm này?" description="Các ID được gán sẽ trở về nhóm mặc định sau khi lưu." okText="Xóa nhóm" cancelText="Giữ lại" onConfirm={() => {
                            setGroups(rows => rows.filter(g => g.key !== selected)); setSelected(groups[0].key);
                        }}><Button size="small" danger disabled={saving} aria-label="Xóa nhóm" icon={<Trash2 size={14} />} /></Popconfirm>}
                    </div>
                </div>
                <div><label htmlFor="item-group-name" className="mb-1 block text-xs font-medium">Tên nhóm</label><Input className='dark:bg-black dark:text-white' id="item-group-name" value={current.name} maxLength={60} onChange={e => patch({ name: e.target.value })} placeholder="Ví dụ: Vật phẩm đua top" /></div>
                <div className="flex items-center justify-between gap-3 text-xs"><label htmlFor="item-group-visible">Hiển thị trong bộ lọc client</label><Switch id="item-group-visible" checked={current.visible} disabled={saving} onChange={visible => patch({ visible })} /></div>
                <p className="text-xs text-slate-500">Ẩn nhóm vẫn giữ cách phân loại; đồ vẫn có trong “Tất cả vật phẩm”. Nhóm mặc định có thể ẩn, không xóa.</p>
                <div><label htmlFor="item-group-mode" className="mb-1 block text-xs font-medium">Kiểu bộ lọc chi tiết</label><Select id="item-group-mode" className="w-full" value={current.filterMode} disabled={saving} options={modes} onChange={filterMode => patch({ filterMode })} /></div>
                <div><label htmlFor="item-group-ids" className="mb-1 block text-xs font-medium">ID vật phẩm gán riêng vào nhóm</label><Input.TextArea id="item-group-ids" value={current.idsText} onChange={e => patch({ idsText: e.target.value })} rows={5} placeholder="2089, 2090" /></div>
                <p className="text-xs leading-5 text-slate-500">Cách nhau bằng dấu phẩy, khoảng trắng hoặc xuống dòng. Gán riêng được ưu tiên hơn phân loại mặc định; bỏ ID để trở về mặc định. Mỗi ID chỉ gán riêng vào một nhóm.</p>
                {current.builtin && <p className="text-xs text-slate-500">{current.defaultIds.length ? `ID mặc định: ${current.defaultIds.join(', ')}.` : 'Nhóm này còn tự nhận vật phẩm theo loại trong catalog.'} Không cần nhập lại các ID mặc định.</p>}
                {current.filterMode === 'equipment' && <p className="text-xs text-amber-600 dark:text-amber-400">Kiểu Trang bị chỉ dùng cho nhóm chứa áo, quần, găng, giày, rada.</p>}
                {current.filterMode === 'items' && <p className="text-xs text-slate-500">Client tự lấy tên từ catalog để hiển thị danh sách chọn từng vật phẩm.</p>}
            </fieldset>}
        </div>}
    </Modal>;
}
