import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Bot, Gem, MapPin, Server, Ticket, Package, Clock, Power } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import TradingBotActivity from '@/Components/TradingBotActivity';
import type { PageProps } from '@/types';
import type { IGemBot } from '@/InterFaces/gembot';

interface Props extends PageProps {
    gemBot: IGemBot | { data: IGemBot };
}

export default function GemBotShow() {
    const raw = usePage<Props>().props.gemBot;
    const gemBot = 'data' in raw ? raw.data : raw;
    const items = [
        ['Tài khoản', gemBot.account_name, Bot],
        ['Server', gemBot.server?.name ?? '—', Server],
        ['Ngọc xanh', Number(gemBot.gem_qty || 0).toLocaleString('vi-VN'), Gem],
        ['Vé ngọc', gemBot.gift_ticket_qty == null ? 'Chưa đồng bộ' : `${Number(gemBot.gift_ticket_qty).toLocaleString('vi-VN')} vé`, Ticket],
        ['Vé chưa giữ chỗ', `${Number(gemBot.gift_unreserved_gems ?? 0).toLocaleString('vi-VN')} ngọc`, Ticket],
        ['Đồ ký giao dịch được', gemBot.consign_item_qty == null ? 'Chưa đồng bộ' : Number(gemBot.consign_item_qty).toLocaleString('vi-VN'), Package],
        ['Bật bot trên web', gemBot.status ? 'ON' : 'OFF', Power],
        ['Điểm đứng được cấu hình', [gemBot.map_info?.map_name, `Map ${gemBot.map_info?.map_id}`, `Khu ${gemBot.map_info?.area_number}`, gemBot.map_info?.coordinates].filter(Boolean).join(' · ') || '—', MapPin],
        ['Đồng bộ tồn gần nhất', gemBot.last_synced_at ? new Date(gemBot.last_synced_at).toLocaleString('vi-VN') : 'Chưa đồng bộ', Clock],
    ] as const;

    return (
        <>
            <Head title={`Bot ngọc #${gemBot.id}`} />
            <Link href="/admin/gem-bots" className="inline-flex items-center gap-2 text-sm text-blue-600"><ArrowLeft className="h-4 w-4" /> Quay lại</Link>
            <h1 className="my-4 text-2xl font-bold text-gray-900 dark:text-white">{gemBot.name || gemBot.account_name}</h1>
            <div className="mb-4"><p className="mb-2 text-xs font-semibold text-gray-500">Hoạt động hiện tại</p><TradingBotActivity botId={gemBot.id} /></div>
            <div className="grid gap-4 rounded-xl bg-white p-6 shadow dark:bg-gray-800 md:grid-cols-2">
                {items.map(([label, value, Icon]) => <div key={label} className="flex gap-3 rounded-lg border p-4 dark:border-gray-700"><Icon className="h-5 w-5 text-purple-500" /><div><p className="text-xs uppercase text-gray-500">{label}</p><p className="font-medium dark:text-white">{value}</p></div></div>)}
            </div>
        </>
    );
}

GemBotShow.layout = (page: React.ReactElement<Props>) => {
    const raw = page.props.gemBot;
    const value = 'data' in raw ? raw.data : raw;
    return <AdminLayout title={`Bot ngọc #${value.id}`}>{page}</AdminLayout>;
};
