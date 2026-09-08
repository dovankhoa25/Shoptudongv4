'use client';

import { useId, useState } from 'react';

export interface NroItem {
    location?: string;
    templateId: number; slot?: number; quantity: number; name: string; iconId?: number | null;
    type?: number | null; description?: string; info?: string; content?: string;
    options: { optionId: number; param: number }[]; optionLabels?: string[];
}
export interface NroSummary {
    character: { name?: string; power?: number; gender?: number }; serverIndex: number; serverId?: number; serverName?: string;
    disciple?: { exists?: boolean; hasDetails?: boolean; name?: string; power?: number } | null;
    itemPreviews?: Partial<Record<'equipped' | 'bag' | 'chest', { count: number; items: Pick<NroItem, 'templateId' | 'iconId' | 'name' | 'quantity'>[] }>>;
    capturedAt: string; highlights: NroItem[];
}
type Stats = { name?: string; power?: number; potential?: number; hpMax?: number; mpMax?: number; damage?: number; armor?: number; critical?: number; gender?: number; gold?: number; gem?: number; lockedGem?: number };
type Skill = { skillId: number; templateId?: number; iconId?: number; name?: string; level?: number; moreInfo?: string };
type Quest = { id?: number; name?: string; detail?: string; currentStep?: number; currentCount?: number; steps?: { name: string; detail?: string; objectiveType?: number; mapId?: number; requiredCount?: number }[] };
export interface NroSnapshot {
    summary: NroSummary;
    completeness: Record<string, boolean | string>;
    data: {
        capturedAt: string; character: Stats; equipped: NroItem[]; bag: NroItem[]; chest: NroItem[]; collectionChest: NroItem[];
        skills?: Skill[]; intrinsic?: { info?: string } | null;
        currentTask?: Quest | null;
        disciple?: (Stats & { exists?: boolean; hasDetails?: boolean; equipped?: NroItem[]; skills?: Skill[] }) | null;
        collectionBook?: { id: number; name: string; iconId: number; amount: number; maxAmount: number; info?: string }[];
    };
}
const number = (value?: number) => value == null ? 'Chưa rõ' : value.toLocaleString('vi-VN');
export const planet = (gender?: number) => ['Trái đất', 'Namec', 'Xayda'][gender ?? -1] ?? 'Chưa rõ';
export function NroIcon({ item, size = 40 }: { item: Pick<NroItem, 'iconId' | 'name'>; size?: number }) {
    const [failed, setFailed] = useState(false);
    return <span className="inline-flex max-w-full shrink-0 items-center justify-center rounded-lg bg-slate-950/50" style={{ width: size, height: size }}>
        {!failed && item.iconId != null && item.iconId >= 0 ? <img src={`/images/nro/${item.iconId}.png`} alt={item.name} width={size - (size <= 20 ? 4 : 8)} height={size - (size <= 20 ? 4 : 8)} loading="lazy" onError={() => setFailed(true)} style={{ maxWidth: '100%', objectFit: 'contain', imageRendering: 'pixelated' }} /> : <span className="text-slate-400" aria-label={item.name}>?</span>}
    </span>;
}
export function NroSummaryCard({ summary, compact = false }: { summary: NroSummary; compact?: boolean }) {
    const server = summary.serverName || (summary.serverId ? 'Server #' + summary.serverId : 'Server ' + (summary.serverIndex + 1));
    const groups = [['equipped', 'Mặc'], ['bag', 'Túi'], ['chest', 'Rương']] as const;
    return <div className={`${compact ? 'px-2 text-[10px]' : 'p-4 text-xs'} text-slate-100 space-y-1`}>
        <p className="truncate leading-4 text-slate-300" title={`${summary.character.name || ''} · ${server} · ${planet(summary.character.gender)}`}><strong className="text-purple-200">{summary.character.name || 'Nhân vật'}</strong> · {server} · {planet(summary.character.gender)}</p>
        <dl className="text-[10px] leading-3"><div className="flex justify-between gap-1"><dt className="shrink-0 text-slate-400">SM sư phụ</dt><dd className="truncate font-bold tabular-nums text-amber-300">{number(summary.character.power)}</dd></div><div className="flex justify-between gap-1"><dt className="shrink-0 text-slate-400">SM đệ tử</dt><dd className="truncate font-semibold tabular-nums text-sky-200">{summary.disciple?.exists === false ? 'Chưa có' : number(summary.disciple?.power)}</dd></div></dl>
        <div>{groups.map(([key, label]) => {
            const group = summary.itemPreviews?.[key] ?? (key === 'equipped' ? { count: summary.highlights?.length ?? 0, items: summary.highlights ?? [] } : undefined);
            const items = group?.items.slice(0, 6) ?? [];
            const remaining = Math.max(0, (group?.count ?? 0) - items.length);
            return <div key={key} className="grid h-5 grid-cols-[30px_minmax(0,1fr)_26px] items-center gap-1" aria-label={`${label}: ${group ? group.count + ' ô đồ trong snapshot' : 'chưa có dữ liệu'}`}>
                <span className="text-[9px] text-slate-400">{label}</span>
                {items.length ? <div className="grid grid-cols-6 gap-0.5">{items.map((item, index) => <span key={`${item.templateId}:${index}`} className="min-w-0" title={`${item.name} · SL ${number(item.quantity)}`}><NroIcon item={item} size={20} /></span>)}</div> : <span className="truncate text-[9px] text-slate-500">{group ? 'Trống' : 'Chưa rõ'}</span>}
                <span className="text-right text-[9px] text-purple-300" title={remaining ? `Còn ${remaining} ô đồ; mở Xem đồ để xem đầy đủ` : undefined}>{remaining > 0 ? `+${remaining}` : ''}</span>
            </div>;
        })}</div>
    </div>;
}
function StatGrid({ stats }: { stats: Stats }) {
    const fields: [keyof Stats, string][] = [['power', 'Sức mạnh'], ['potential', 'Tiềm năng'], ['hpMax', 'HP'], ['mpMax', 'KI'], ['damage', 'Sức đánh'], ['armor', 'Giáp'], ['critical', 'Chí mạng'], ['gold', 'Vàng'], ['gem', 'Ngọc'], ['lockedGem', 'Ngọc khóa']];
    return <dl className="divide-y divide-slate-700/40">{fields.filter(([key]) => stats[key] != null).map(([key, label]) => <div key={key} className="flex items-baseline justify-between gap-2 py-1 text-[11px] leading-4 sm:text-xs"><dt className="text-slate-400">{label}</dt><dd className={`text-right font-semibold tabular-nums ${key === 'power' ? 'text-amber-300' : 'text-slate-100'}`}>{number(stats[key] as number)}{key === 'critical' ? '%' : ''}</dd></div>)}</dl>;
}
function SkillList({ skills }: { skills?: Skill[] }) {
    if (!skills?.length) return <p className="text-sm text-slate-400">Chưa có dữ liệu kỹ năng.</p>;
    return <div className="divide-y divide-slate-700/60">{skills.map((skill, index) => <div key={`${skill.skillId}:${index}`} className="flex items-start gap-3 py-3">
        <NroIcon key={skill.iconId ?? skill.skillId} item={{ iconId: skill.iconId, name: skill.name || `Kỹ năng ${skill.skillId}` }} size={44} />
        <div className="min-w-0 flex-1"><div className="flex flex-wrap items-center justify-between gap-2"><strong className="text-sm text-slate-100">{skill.name || (skill.skillId >= 0 ? `Kỹ năng ${skill.skillId}` : 'Kỹ năng chưa mở')}</strong>{skill.level != null && skill.level >= 0 && <span className="text-xs text-purple-300">Cấp {skill.level}</span>}</div>{skill.moreInfo && <p className="mt-1 text-sm text-slate-400 whitespace-pre-line">{skill.moreInfo}</p>}</div>
    </div>)}</div>;
}
export function NroItemGrid({ items }: { items: NroItem[] }) {
    const [selected, setSelected] = useState<number | null>(null);
    return <div className="space-y-3">
        {items.length === 0 && <p className="text-slate-400 py-5">Không có vật phẩm trong dữ liệu đã lấy.</p>}
        <div className="grid grid-cols-1 min-[420px]:grid-cols-2 xl:grid-cols-3 gap-2">{items.map((item, index) => <button key={index} type="button" aria-expanded={selected === index} onClick={() => setSelected(selected === index ? null : index)} className={`text-left rounded-xl border p-3 flex gap-2 items-center ${selected === index ? 'border-purple-400 bg-purple-950/50' : 'border-slate-700 bg-slate-900/60 hover:border-purple-400'}`}>
            <NroIcon item={item} /><span className="min-w-0"><span className="block text-sm text-white break-words">{item.name}</span><span className="text-xs text-slate-400">SL {number(item.quantity)} · Xem chỉ số</span></span>
        </button>)}</div>
        {selected !== null && items[selected] && <div className="rounded-xl border border-purple-400/50 bg-slate-950 p-4" aria-live="polite">
            <div className="flex items-center justify-between gap-3"><strong className="text-amber-300">{items[selected].name}</strong><button className="text-slate-300 text-sm" onClick={() => setSelected(null)}>Đóng</button></div>
            <p className="text-sm text-slate-400 mt-2">{items[selected].description}</p>
            {items[selected].location && <p className="text-xs text-slate-400 mt-2">Vị trí: {items[selected].location}{items[selected].slot != null ? ` · ô ${items[selected].slot}` : ''}</p>}
            <ul className="text-sm text-green-300 mt-2 space-y-1">{(items[selected].optionLabels ?? items[selected].options.map(o => `Option ${o.optionId}: ${o.param}`)).map((label, i) => <li key={i}>{label}</li>)}</ul>
            {items[selected].info && <p className="text-sm text-slate-300 mt-2 whitespace-pre-line">{items[selected].info}</p>}
            {items[selected].content && <p className="text-sm text-slate-300 mt-2 whitespace-pre-line">{items[selected].content}</p>}
        </div>}
    </div>;
}
function QuestPanel({ quest, fallback }: { quest?: Quest | null; fallback?: string }) {
    if (!quest) return <p className="text-sm text-slate-300 whitespace-pre-line">{fallback || 'Snapshot chưa có dữ liệu nhiệm vụ. Lấy lại dữ liệu để cập nhật.'}</p>;
    const steps = quest.steps ?? []; const current = quest.currentStep ?? -1;
    const active = steps[current]; const count = quest.currentCount;
    return <section className="space-y-3 text-sm">
        <div><h3 className="font-semibold text-purple-200">{quest.name || 'Nhiệm vụ hiện tại'}</h3>{quest.detail && <p className="mt-1 whitespace-pre-line text-slate-300">{quest.detail}</p>}</div>
        {!active && current >= 0 && <p className="text-xs text-purple-300">Bước hiện tại: {current + 1}{count != null && count >= 0 ? ` · Tiến độ: ${number(count)}` : ''}</p>}
        {active && <div className="rounded-lg border border-purple-500/40 bg-purple-950/30 p-3"><p className="text-xs text-purple-300">Đang làm · Bước {current + 1}/{steps.length}</p><p className="mt-1 font-medium">{active.name}</p>{count != null && count >= 0 && <p className="mt-1 text-xs text-amber-300">Tiến độ: {number(count)}{active.requiredCount != null && active.requiredCount > 0 ? ` / ${number(active.requiredCount)}` : ''}</p>}</div>}
        {!steps.length ? <p className="text-xs text-slate-400">Snapshot này chưa lưu các bước nhiệm vụ. Lấy lại dữ liệu bằng tool mới để bổ sung.</p> : <ol className="divide-y divide-slate-700/70">{steps.map((step, index) => <li key={index} className="flex gap-3 py-2.5"><span className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs ${index < current ? 'bg-emerald-950 text-emerald-300' : index === current ? 'bg-purple-600 text-white' : 'bg-slate-800 text-slate-400'}`}>{index < current ? '✓' : index + 1}</span><div className="min-w-0"><p className={index === current ? 'font-medium text-purple-200' : 'text-slate-300'}>{step.name}</p>{step.detail && <p className="mt-0.5 whitespace-pre-line text-xs text-slate-400">{step.detail}</p>}{step.requiredCount != null && step.requiredCount > 0 && <p className="text-xs text-slate-400">Mục tiêu: {number(step.requiredCount)}</p>}</div></li>)}</ol>}
    </section>;
}
export default function NroSnapshotPanel({ snapshot }: { snapshot: NroSnapshot }) {
    const [tab, setTab] = useState('equipped');
    const [search, setSearch] = useState('');
    const panelId = useId();
    const { data } = snapshot;
    const tabs = [['equipped', 'Đang mặc'], ['bag', 'Hành trang'], ['chest', 'Rương'], ['costumes', 'Cải trang'], ['disciple', 'Đệ tử'], ['skills', 'Kỹ năng'], ['task', 'Nhiệm vụ'], ['collection', 'Sưu tập']];
    const costumes = [
        ...data.equipped.map(i => ({ ...i, location: 'Đang mặc' })), ...data.bag.map(i => ({ ...i, location: 'Hành trang' })),
        ...data.chest.map(i => ({ ...i, location: 'Rương' })), ...data.collectionChest.map(i => ({ ...i, location: 'Rương sưu tập' })),
    ].filter(i => i.type === 5);
    const items = tab === 'costumes' ? costumes : tab === 'collection' ? data.collectionChest : tab === 'disciple' ? data.disciple?.equipped ?? [] : (data[tab as 'bag' | 'chest' | 'equipped'] ?? []);
    return <section className="rounded-2xl border border-slate-700 bg-slate-900/80 p-3 sm:p-4 space-y-3">
        <p className="text-[11px] text-slate-400">{snapshot.summary.serverName || (snapshot.summary.serverId ? 'Server #' + snapshot.summary.serverId : 'Server ' + (snapshot.summary.serverIndex + 1))} · {planet(data.character.gender)} · Cập nhật {new Date(data.capturedAt).toLocaleString('vi-VN', { timeZone: 'Asia/Ho_Chi_Minh' })}</p>
        <div className="grid grid-cols-1 min-[480px]:grid-cols-2 gap-2 sm:gap-3">
            <section className="min-w-0 rounded-xl border border-slate-700/70 bg-slate-950/30 p-2.5 sm:p-3"><h2 className="mb-2 text-xs font-bold text-purple-200">Sư phụ{data.character.name ? ` · ${data.character.name}` : ''}</h2><StatGrid stats={data.character} /></section>
            <section className="min-w-0 rounded-xl border border-slate-700/70 bg-slate-950/30 p-2.5 sm:p-3"><h2 className="mb-2 text-xs font-bold text-sky-200">Đệ tử{data.disciple?.name ? ` · ${data.disciple.name}` : ''}</h2>{!data.disciple ? <p className="text-xs text-slate-400">Chưa có dữ liệu xác nhận về đệ tử.</p> : data.disciple.exists === false ? <p className="text-xs text-slate-400">Nhân vật chưa có đệ tử.</p> : <>{!data.disciple.hasDetails && <p className="mb-2 text-[11px] text-amber-300">Chưa lấy đủ thông tin.</p>}<StatGrid stats={data.disciple} /></>}</section>
        </div>
        <div role="tablist" aria-label="Thông tin nhân vật" className="flex gap-2 overflow-x-auto pb-1">{tabs.map(([id, label], index) => <button key={id} id={`${panelId}-${id}`} type="button" role="tab" aria-controls={`${panelId}-content`} aria-selected={tab === id} tabIndex={tab === id ? 0 : -1} onKeyDown={e => {
            const next = e.key === 'ArrowRight' ? (index + 1) % tabs.length : e.key === 'ArrowLeft' ? (index - 1 + tabs.length) % tabs.length : e.key === 'Home' ? 0 : e.key === 'End' ? tabs.length - 1 : null;
            if (next !== null) { e.preventDefault(); setTab(tabs[next][0]); setSearch(''); document.getElementById(`${panelId}-${tabs[next][0]}`)?.focus(); }
        }} onClick={() => { setTab(id); setSearch(''); }} className={`shrink-0 px-3 py-2 rounded-lg text-sm ${tab === id ? 'bg-purple-600 text-white' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'}`}>{label}</button>)}</div>
        <div role="tabpanel" id={`${panelId}-content`} aria-labelledby={`${panelId}-${tab}`} className="space-y-4">
        {(tab === 'bag' || tab === 'chest') && snapshot.completeness[tab] !== true && <p className="text-amber-300 text-sm">Chưa lấy đủ dữ liệu mục này.</p>}
        {tab === 'disciple' && data.disciple && data.disciple.exists !== false && <div><h3 className="text-xs font-semibold text-sky-200">Kỹ năng đệ tử</h3><SkillList skills={data.disciple.skills} /></div>}
        {tab === 'task' ? <QuestPanel quest={data.currentTask} fallback={(data.character as Stats & { task?: string }).task} /> : tab === 'skills' ? <div className="space-y-3"><p className="text-amber-300 text-sm">{data.intrinsic?.info || 'Chưa có dữ liệu nội tại'}</p><SkillList skills={data.skills} /></div> : <>
            <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Tìm tên vật phẩm…" aria-label="Tìm vật phẩm" className="w-full rounded-lg border border-slate-600 bg-slate-950 px-3 py-2 text-sm text-white" />
            <NroItemGrid key={`${tab}:${search}`} items={items.filter(i => i.name.toLocaleLowerCase('vi').includes(search.toLocaleLowerCase('vi')))} />
            {tab === 'collection' && data.collectionBook?.map(i => <div key={i.id} className="flex gap-3 items-center text-sm text-slate-200"><NroIcon item={i} /><span>{i.name} · {i.amount}/{i.maxAmount}<span className="block text-slate-400">{i.info}</span></span></div>)}
        </>}
        </div>
    </section>;
}
