import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Bot as BotIcon, Coins, MapPin, Server, Clock } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import TradingBotActivity from '@/Components/TradingBotActivity';
import type { PageProps } from '@/types';

interface BotDetail {
    id: number;
    name?: string;
    account_name: string;
    type_labels: string[];
    server_name?: string;
    gold_qty_formatted: string;
    gold_bar_qty_formatted: string;
    map_name?: string;
    map_id?: number;
    area_number?: string;
    coordinates?: string;
    status_label: string;
    status: boolean;
    last_synced_at?: string;
}

interface Props extends PageProps {
    bot: BotDetail | { data: BotDetail };
}

export default function BotShow() {
    const rawBot = usePage<Props>().props.bot;
    const bot = 'data' in rawBot ? rawBot.data : rawBot;

    return (
        <>
            <Head title={`Bot #${bot.id}`} />
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <Link href="/admin/bots" className="mb-2 inline-flex items-center gap-2 text-sm text-blue-600 hover:text-blue-800">
                            <ArrowLeft className="h-4 w-4" /> Quay lại danh sách
                        </Link>
                        <h1 className="text-2xl font-bold text-gray-900 dark:text-white">{bot.name || bot.account_name}</h1>
                    </div>
                    <span className={`rounded-full border px-3 py-1 text-sm font-semibold ${bot.status ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-gray-200 bg-gray-50 text-gray-600'}`}>Web {bot.status ? 'ON' : 'OFF'}</span>
                </div>

                <div><p className="mb-2 text-xs font-semibold text-gray-500">Hoạt động hiện tại</p><TradingBotActivity botId={bot.id} botType="bot" /></div>

                <div className="grid gap-4 rounded-xl bg-white p-6 shadow dark:bg-gray-800 md:grid-cols-2">
                    <Info icon={BotIcon} label="Tài khoản" value={bot.account_name} />
                    <Info icon={Server} label="Server" value={bot.server_name || '—'} />
                    <Info icon={Coins} label="Vàng tươi" value={bot.gold_qty_formatted} />
                    <Info icon={Coins} label="Thỏi trong hành trang" value={`${bot.gold_bar_qty_formatted} / 999`} />
                    <Info icon={MapPin} label="Điểm đứng được cấu hình" value={[bot.map_name, `Map ${bot.map_id}`, `Khu ${bot.area_number}`, bot.coordinates].filter(Boolean).join(' · ') || '—'} />
                    <Info icon={BotIcon} label="Vai trò" value={bot.type_labels.join(', ') || '—'} />
                    <Info icon={Clock} label="Đồng bộ tồn gần nhất" value={bot.last_synced_at || 'Chưa đồng bộ'} />
                </div>
            </div>
        </>
    );
}

BotShow.layout = (page: React.ReactElement<Props>) => {
    const raw = page.props.bot;
    const value = 'data' in raw ? raw.data : raw;
    return <AdminLayout title={`Bot #${value.id}`}>{page}</AdminLayout>;
};

function Info({ icon: Icon, label, value }: { icon: typeof BotIcon; label: string; value: string }) {
    return (
        <div className="flex items-start gap-3 rounded-lg border border-gray-100 p-4 dark:border-gray-700">
            <Icon className="mt-0.5 h-5 w-5 text-blue-500" />
            <div>
                <p className="text-xs uppercase tracking-wide text-gray-500">{label}</p>
                <p className="mt-1 font-medium text-gray-900 dark:text-white">{value}</p>
            </div>
        </div>
    );
}
