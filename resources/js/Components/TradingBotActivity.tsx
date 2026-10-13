import { useEffect, useState } from 'react';
import { useEchoPublic } from '@laravel/echo-react';
interface Activity { bot_id: number; bot_type: string; code: string; text: string; map_id?: number; zone?: number; x?: number; y?: number; online_seconds?: number; observed_at: string; expires_at: string }
export default function TradingBotActivity({ botId, botType = 'gem_bot' }: { botId: number; botType?: 'gem_bot' | 'bot' }) {
    const [activity, setActivity] = useState<Activity | null>(null);
    const [now, setNow] = useState(Date.now());
    useEchoPublic<Activity>('Trading.Bots', '.TradingBotActivity', event => {
        if (event.bot_type === botType && Number(event.bot_id) === botId) setActivity(previous => previous && Date.parse(previous.observed_at) > Date.parse(event.observed_at) ? previous : event);
    }, [botId, botType]);
    useEffect(() => { setActivity(null); }, [botId, botType]);
    useEffect(() => { const tick = setInterval(() => setNow(Date.now()), 1000); return () => clearInterval(tick); }, []);
    if (!activity || Date.parse(activity.expires_at) <= now) return <span className="inline-block rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-400 dark:border-slate-700 dark:bg-slate-900">Chưa có cập nhật hoạt động</span>;
    const elapsed = activity.online_seconds ? activity.online_seconds + Math.max(0, Math.floor((now - Date.parse(activity.observed_at)) / 1000)) : 0;
    const tone = ['review_required', 'error', 'insufficient_gems', 'out_of_items', 'out_of_tickets', 'insufficient_bars', 'out_of_bars', 'source_empty', 'capacity_full'].includes(activity.code)
        ? 'border-rose-300 bg-rose-50 text-rose-700 dark:border-rose-800 dark:bg-rose-950/30 dark:text-rose-300'
        : activity.code === 'ready' ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-300'
        : activity.code === 'gifting' ? 'border-violet-300 bg-violet-50 text-violet-700 dark:border-violet-800 dark:bg-violet-950/30 dark:text-violet-300'
        : ['warmup', 'login_wait', 'waiting_customer', 'waiting_consignment', 'waiting_stock', 'reserve_idle', 'syncing', 'reconnecting', 'reconciling', 'api_wait'].includes(activity.code)
            ? 'border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300'
            : 'border-sky-300 bg-sky-50 text-sky-700 dark:border-sky-800 dark:bg-sky-950/30 dark:text-sky-300';
    return <div className={`min-w-48 space-y-1 rounded-lg border px-3 py-2 text-xs ${tone}`} aria-live="polite">
        <div className="flex items-center gap-2 font-semibold"><span className="h-2 w-2 shrink-0 rounded-full bg-current" />{activity.text}</div>
        {Number(activity.map_id) >= 0 && <div className="text-slate-500">Map {activity.map_id} · Khu {activity.zone} · {activity.x}-{activity.y}</div>}
        {elapsed > 0 && <div className="text-slate-500">Đã online {Math.floor(elapsed / 3600)}h {Math.floor(elapsed % 3600 / 60)}p</div>}
    </div>;
}
