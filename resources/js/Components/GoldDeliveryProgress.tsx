import type { IGoldTransaction } from '@/InterFaces/goldtransaction';

const phases: Record<string, string> = {
    waiting_customer: 'Chờ khách giao dịch', waiting_stock: 'Chờ kho phụ bơm thỏi',
    giving_bars: 'Đang giao thỏi', receiving_bars: 'Đang nhận thỏi', selling: 'Đang giao thỏi', importing: 'Đang nhận thỏi',
    transferring: 'Đang chuyển kho', storage: 'Đang cất thỏi', refilling: 'Đang bơm thỏi',
    syncing: 'Chờ web xác nhận', reconciling: 'Đang tự đối chiếu', completed: 'Đã giao dịch đủ',
};

export default function GoldDeliveryProgress({ order }: { order: IGoldTransaction }) {
    if (!order.delivery_phase && !order.delivery_data?.progress) return <span className="text-xs text-slate-400">—</span>;
    const done = Number(order.delivery_data?.progress?.bars ?? 0);
    const requested = Number(order.gold_bar_qty ?? 0);
    return <div className="space-y-1 rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-2 text-xs dark:border-amber-800 dark:bg-amber-950/20">
        <p className="font-semibold text-amber-800 dark:text-amber-200">{phases[order.delivery_phase ?? ''] ?? 'Đang xử lý'}</p>
        <p className="text-slate-600 dark:text-slate-300">{done.toLocaleString('vi-VN')} / {requested.toLocaleString('vi-VN')} thỏi</p>
        {requested > 0 && <div className="h-1 overflow-hidden rounded bg-amber-200 dark:bg-amber-900"><div className="h-full bg-amber-500" style={{ width: `${Math.max(0, Math.min(100, done / requested * 100))}%` }} /></div>}
        {Number(order.pure_gold_qty) > 0 && <p className="text-slate-500">Tươi: {Number(order.delivery_data?.progress?.pure ?? 0).toLocaleString('vi-VN')} / {Number(order.pure_gold_qty).toLocaleString('vi-VN')}</p>}
    </div>;
}
