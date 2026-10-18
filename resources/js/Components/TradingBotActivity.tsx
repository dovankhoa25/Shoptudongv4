import { useEffect, useState } from 'react';
import { useEchoPublic } from '@laravel/echo-react';
import { nextTradingBotActivity, tradingBotActivityView, type TradingBotActivityEvent } from './tradingBotActivityState';

const tones = {
    neutral: 'border-slate-200 bg-slate-50 text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400',
    problem: 'border-rose-300 bg-rose-50 text-rose-700 dark:border-rose-800 dark:bg-rose-950/30 dark:text-rose-300',
    ready: 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-300',
    gift: 'border-violet-300 bg-violet-50 text-violet-700 dark:border-violet-800 dark:bg-violet-950/30 dark:text-violet-300',
    waiting: 'border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300',
    working: 'border-sky-300 bg-sky-50 text-sky-700 dark:border-sky-800 dark:bg-sky-950/30 dark:text-sky-300',
};

function tone(code: string): string {
    if (['disabled', 'paused', 'reserve_idle', 'storage_idle', 'checker_idle'].includes(code)) return tones.neutral;
    if (['out_of_items', 'insufficient_gems', 'out_of_tickets', 'insufficient_bars', 'out_of_bars', 'source_empty', 'capacity_full'].includes(code)) return tones.problem;
    if (['ready', 'presence_ready'].includes(code)) return tones.ready;
    if (code === 'gifting') return tones.gift;
    if (['connecting', 'reconnecting', 'reconciling', 'review_required', 'error', 'warmup', 'login_wait', 'waiting_customer', 'waiting_consignment', 'waiting_stock', 'syncing', 'api_wait', 'name_mismatch', 'customer_offline', 'trade_cancelled', 'presence_wait', 'packet_stalled', 'presence_unverified'].includes(code)) return tones.waiting;
    return tones.working;
}

function duration(seconds: number): string {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor(seconds % 3600 / 60);
    return hours > 0 ? `${hours}h ${minutes}p` : minutes > 0 ? `${minutes}p ${seconds % 60}s` : `${seconds}s`;
}

export default function TradingBotActivity({ botId, botType = 'gem_bot' }: { botId: number; botType?: 'gem_bot' | 'bot' }) {
    const [activity, setActivity] = useState<TradingBotActivityEvent | null>(null);
    const [now, setNow] = useState(Date.now());
    useEchoPublic<TradingBotActivityEvent>('Trading.Bots', '.TradingBotActivity', event => {
        setActivity(previous => nextTradingBotActivity(previous, event, botId, botType));
    }, [botId, botType]);
    useEffect(() => { setActivity(null); }, [botId, botType]);
    useEffect(() => {
        const tick = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(tick);
    }, []);

    const base = 'min-h-[4rem] min-w-[13rem] max-w-[18rem] space-y-1 rounded-lg border px-2.5 py-2 text-xs';
    const explanation = 'Hoạt động realtime từ tool, độc lập với trạng thái bật/tắt bot trên web.';
    const view = tradingBotActivityView(activity, now);
    if (!activity || !view.fresh) {
        return <div className={`${base} ${tones.neutral}`} title={explanation}>
            <div className="flex items-center gap-1.5 font-medium"><span className="h-1.5 w-1.5 shrink-0 rounded-full bg-current" />{activity ? 'Chờ cập nhật hoạt động mới' : 'Chưa có cập nhật hoạt động'}</div>
            <div className="text-[11px] opacity-80">{activity ? 'Thông tin trước đã hết hạn' : 'Từ tool đang quản lý bot'}</div>
        </div>;
    }

    return <div className={`${base} ${tone(activity.code)}`} title={explanation}>
        <div className="flex items-start gap-1.5 font-semibold" aria-live="polite"><span className="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-current" /><span>{activity.text}</span></div>
        {view.location && <div className="opacity-80">{view.location}</div>}
        {(view.onlineSeconds > 0 || view.waitSeconds > 0) && <div className="flex flex-wrap gap-x-2 text-[11px] opacity-80">
            {view.onlineSeconds > 0 && <span>Online {duration(view.onlineSeconds)}</span>}
            {view.waitSeconds > 0 && <span>Chờ {view.waitSeconds}s</span>}
        </div>}
    </div>;
}
