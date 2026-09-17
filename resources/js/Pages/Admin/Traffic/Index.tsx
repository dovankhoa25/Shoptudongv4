import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import type { PageProps } from '@/types';

type TrafficRow = { ip: string; source: string; route: string; method: string; status: number; group: string; count: number; total_ms: number; max_ms: number; last_seen: number };
type Filter = { minutes?: number; ip?: string; group?: string; status?: string };
type Data = PageProps & {
    filters: Filter;
    canManage: boolean;
    traffic: { enabled: boolean; available: boolean; minutes: number; from: number; to: number; overflow: number; series: number; store: string;
        totals: { count: number; options: number; limited: number; auth_errors: number; not_found: number; server_errors: number; total_ms: number; max_ms: number };
        ips: { key: string; count: number }[]; endpoints: { key: string; count: number }[]; rows: TrafficRow[] };
};
const number = (value: number) => value.toLocaleString('vi-VN');
const time = (value: number) => new Date(value * 1000).toLocaleTimeString('vi-VN');

export default function TrafficPage() {
    const { traffic, filters, canManage } = usePage<Data>().props;
    const [form, setForm] = useState<Filter>(filters);
    const [saving, setSaving] = useState(false);
    const [switchError, setSwitchError] = useState('');
    const toggleRecording = () => {
        setSwitchError('');
        router.patch('/admin/traffic', { enabled: !traffic.enabled }, {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onError: errors => setSwitchError(errors.enabled ?? 'Không đổi được trạng thái ghi nhận.'),
        });
    };
    const apply = (next: Filter = form) => {
        setForm(next);
        router.get('/admin/traffic', next, { preserveScroll: true, preserveState: true });
    };
    const { totals } = traffic;
    return <>
        <Head title="Lưu lượng & API" />
        <div className="space-y-4 p-4 text-gray-900 dark:text-gray-100">
            <div><h1 className="text-xl font-semibold">Lưu lượng & API</h1>
                {traffic.enabled && <p className="mt-1 text-sm text-gray-500">Yêu cầu tới Laravel · {time(traffic.from)} – {time(traffic.to)} · bấm IP để xem API đã gọi.</p>}
            </div>
            <div className="flex flex-wrap items-center justify-between gap-3 rounded border border-gray-200 p-3 dark:border-gray-700">
                <div>
                    <div className="font-medium">{traffic.enabled ? 'Đang ghi nhận lưu lượng' : 'Đã tắt ghi nhận lưu lượng'}</div>
                    <p className="mt-1 text-sm text-gray-500">{traffic.enabled
                        ? 'Tắt khi không cần theo dõi để giảm tải cho website.'
                        : 'Không thu thập request hoặc đọc/ghi bộ đếm thống kê. Bật lại khi cần kiểm tra.'}</p>
                </div>
                {canManage && <button type="button" disabled={saving} onClick={toggleRecording}
                    className={`rounded px-4 py-2 text-sm font-medium disabled:opacity-50 ${traffic.enabled ? 'border border-gray-300 dark:border-gray-600' : 'bg-blue-600 text-white'}`}>
                    {saving ? 'Đang lưu…' : traffic.enabled ? 'Tắt ghi nhận' : 'Bật ghi nhận'}
                </button>}
            </div>
            {switchError && <p role="alert" className="text-sm text-red-500">{switchError}</p>}
            {traffic.enabled && <>
            <form onSubmit={event => { event.preventDefault(); apply(); }} className="flex flex-wrap gap-2">
                <select aria-label="Khoảng thời gian" value={form.minutes ?? 5} onChange={event => setForm({ ...form, minutes: Number(event.target.value) })} className="rounded border border-gray-300 bg-white dark:border-gray-700 dark:bg-gray-900">
                    <option value={1}>1 phút</option><option value={5}>5 phút</option><option value={15}>15 phút</option>
                </select>
                <input aria-label="Địa chỉ IP" placeholder="Lọc đúng IP" value={form.ip ?? ''} onChange={event => setForm({ ...form, ip: event.target.value })} className="rounded border border-gray-300 bg-white dark:border-gray-700 dark:bg-gray-900" />
                <select aria-label="Nhóm trang" value={form.group ?? ''} onChange={event => setForm({ ...form, group: event.target.value })} className="rounded border border-gray-300 bg-white dark:border-gray-700 dark:bg-gray-900">
                    <option value="">Mọi nhóm</option>{['api', 'admin', 'worker', 'webhook', 'web'].map(group => <option key={group}>{group}</option>)}
                </select>
                <input aria-label="Mã HTTP" type="number" min={100} max={599} placeholder="HTTP: 429..." value={form.status ?? ''} onChange={event => setForm({ ...form, status: event.target.value })} className="w-32 rounded border border-gray-300 bg-white dark:border-gray-700 dark:bg-gray-900" />
                <button className="rounded bg-blue-600 px-4 py-2 text-white">Lọc / Làm mới</button>
                <button type="button" onClick={() => apply({ minutes: 5 })} className="rounded border px-3">Xóa lọc</button>
            </form>
            {!traffic.available && <p role="alert" className="rounded bg-amber-100 p-3 text-amber-900">Không đọc được bộ nhớ thống kê. Kiểm tra cache store.</p>}
            <div className="grid grid-cols-2 gap-2 lg:grid-cols-6">
                {[['Request ghi nhận', totals.count], ['Bị giới hạn · 429', totals.limited], ['401 / 403', totals.auth_errors], ['Không tìm thấy · 404', totals.not_found], ['Lỗi máy chủ · 5xx', totals.server_errors], ['Trung bình (ms)', Math.round(totals.total_ms / Math.max(1, totals.count))]].map(([label, value]) =>
                    <div key={String(label)} className="rounded border border-gray-200 p-3 dark:border-gray-700"><div className="text-xs text-gray-500">{label}</div><div className="mt-1 text-xl font-semibold">{number(Number(value))}</div></div>)}
            </div>
            <div className="grid gap-4 lg:grid-cols-2">
                <p className="text-sm text-gray-500 lg:col-span-2">
                    {number(totals.count - totals.options)} lượt gọi khác · {number(totals.options)} OPTIONS.
                    {' '}[preflight] là bước kiểm tra CORS trước khi gọi API, chưa chạy xử lý nghiệp vụ; không phải lỗi 404.
                </p>
                <section className="rounded border border-gray-200 p-3 dark:border-gray-700"><h2 className="mb-2 font-semibold">IP gọi nhiều nhất</h2>
                    {traffic.ips.map(row => <button key={row.key} onClick={() => apply({ ...form, ip: row.key === 'unknown' ? '' : row.key })} className="flex w-full justify-between gap-3 py-1 text-left text-sm hover:text-blue-500"><span className="break-all font-mono">{row.key}</span><span>{number(row.count)}</span></button>)}
                    {!traffic.ips.length && <p className="text-sm text-gray-500">Chưa có dữ liệu trong bộ lọc này.</p>}
                </section>
                <section className="rounded border border-gray-200 p-3 dark:border-gray-700"><h2 className="mb-2 font-semibold">API / trang gọi nhiều nhất</h2>
                    {traffic.endpoints.map(row => <div key={row.key} className="flex justify-between gap-3 py-1 text-sm"><span className="break-all font-mono">{row.key}</span><span>{number(row.count)}</span></div>)}
                </section>
            </div>
            <div className="overflow-x-auto rounded border border-gray-200 dark:border-gray-700">
                <table className="w-full text-left text-sm"><thead className="bg-gray-50 text-xs dark:bg-gray-800"><tr>
                    {['IP / nguồn', 'API / trang', 'HTTP', 'Lượt gọi', 'TB / tối đa (ms)', 'Lần cuối'].map(label => <th key={label} className="p-3">{label}</th>)}
                </tr></thead><tbody>{traffic.rows.map((row, index) => <tr key={index} className="border-t border-gray-200 dark:border-gray-700">
                    <td className="p-3"><button className="font-mono text-blue-500" onClick={() => apply({ ...form, ip: row.ip === 'unknown' ? '' : row.ip })}>{row.ip}</button><div className="text-xs text-gray-500">{row.source === 'cloudflare' ? 'Cloudflare đã xác thực' : 'IP kết nối trực tiếp'}</div></td>
                    <td className="max-w-md break-all p-3 font-mono">{row.method} /{row.route}<div className="text-xs text-gray-500">{row.group}</div></td>
                    <td className={`p-3 font-semibold ${row.status >= 400 ? 'text-red-500' : ''}`}>{row.status}</td>
                    <td className="p-3">{number(row.count)}</td><td className="p-3">{number(Math.round(row.total_ms / row.count))} / {number(row.max_ms)}</td><td className="whitespace-nowrap p-3">{time(row.last_seen)}</td>
                </tr>)}</tbody></table>
                {!traffic.rows.length && <p className="p-4 text-sm text-gray-500">Chưa có request được ghi nhận.</p>}
            </div>
            <p className="text-xs leading-5 text-gray-500">
                Hiện tối đa 100/{traffic.series} nhóm request. Bộ đếm gần đúng, lưu tối đa 15 phút; có thể bỏ qua khi cache bận hoặc lỗi.
                {traffic.overflow > 0 && ` Đã gộp ngoài giới hạn chi tiết: ${number(traffic.overflow)} request trong toàn cửa sổ.`}
                {' '}Không chứa nội dung gửi lên, token hoặc query string. Request bị Cloudflare chặn trước khi tới Laravel không xuất hiện.
                {' '}Thời gian đo tại ứng dụng, không gồm độ trễ mạng. IP kết nối có thể là proxy nội bộ nếu máy chủ chưa nhận trực tiếp từ Cloudflare.
            </p>
            </>}
        </div>
    </>;
}

TrafficPage.layout = (page: React.ReactNode) => <AdminLayout title="Lưu lượng & API">{page}</AdminLayout>;
