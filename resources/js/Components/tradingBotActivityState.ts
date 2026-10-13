export interface TradingBotActivityEvent {
    bot_id: number;
    bot_type: string;
    code: string;
    text: string;
    map_id?: number | null;
    zone?: number | null;
    x?: number | null;
    y?: number | null;
    online_seconds?: number | null;
    wait_seconds?: number | null;
    observed_at: string;
    expires_at: string;
}

export function nextTradingBotActivity(previous: TradingBotActivityEvent | null, event: TradingBotActivityEvent, botId: number, botType: string): TradingBotActivityEvent | null {
    if (event.bot_type !== botType || Number(event.bot_id) !== botId) return previous;
    const observed = Date.parse(event.observed_at);
    const expires = Date.parse(event.expires_at);
    if (!Number.isFinite(observed) || !Number.isFinite(expires) || expires <= observed) return previous;
    return previous && Date.parse(previous.observed_at) > observed ? previous : event;
}

function known(value: number | null | undefined): value is number {
    return typeof value === 'number' && Number.isFinite(value) && value >= 0;
}

export function tradingBotActivityView(activity: TradingBotActivityEvent | null, now: number) {
    const observed = activity ? Date.parse(activity.observed_at) : NaN;
    const expires = activity ? Date.parse(activity.expires_at) : NaN;
    if (!activity || !Number.isFinite(observed) || !Number.isFinite(expires) || expires <= observed || expires <= now) return { fresh: false, onlineSeconds: 0, waitSeconds: 0, location: '' };
    const age = Math.max(0, Math.floor((now - observed) / 1000));
    // A zero is not proof of an online session, so only advance a positive server observation.
    const onlineSeconds = known(activity.online_seconds) && activity.online_seconds > 0 ? activity.online_seconds + age : 0;
    const waitSeconds = known(activity.wait_seconds) ? Math.max(0, activity.wait_seconds - age) : 0;
    const location = [
        known(activity.map_id) ? `Map ${activity.map_id}` : null,
        known(activity.zone) ? `Khu ${activity.zone}` : null,
        known(activity.x) && known(activity.y) ? `${activity.x}-${activity.y}` : null,
    ].filter(Boolean).join(' · ');
    return { fresh: true, onlineSeconds, waitSeconds, location };
}
