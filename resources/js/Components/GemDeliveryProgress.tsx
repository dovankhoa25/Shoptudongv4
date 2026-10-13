import type { IGemOrder } from '@/InterFaces/gemorder';

export default function GemDeliveryProgress({ order }: { order: IGemOrder }) {
    if (order.processing_version !== 3) return <span className="text-xs text-gray-400">—</span>;
    const spent = Number(order.bot_gem_spent_qty ?? 0);
    const quantity = Number(order.gem_qty ?? 0);
    const received = Number(order.delivered_gem_qty ?? 0);
    const ticket = order.delivery_method === 'gift_ticket';

    return <div className="space-y-1 rounded-lg border border-purple-200 bg-purple-50 px-2.5 py-2 text-xs dark:border-purple-800 dark:bg-purple-950/20">
        <p className="font-semibold text-purple-800 dark:text-purple-200">{order.delivery_phase_label ?? (order.status === 'completed' ? 'Đã hoàn thành' : 'Chờ xử lý')}</p>
        <p className="text-gray-600 dark:text-gray-300">Bot đã chi {spent.toLocaleString('vi-VN')} / {quantity.toLocaleString('vi-VN')} ngọc</p>
        {quantity > 0 && <div className="h-1 overflow-hidden rounded bg-purple-200 dark:bg-purple-900"><div className="h-full bg-purple-500" style={{ width: `${Math.max(0, Math.min(100, spent / quantity * 100))}%` }} /></div>}
        {ticket
            ? <p className="text-gray-600 dark:text-gray-300">Đã nhận {received.toLocaleString('vi-VN')} ngọc · {Number(order.tickets_used_qty ?? 0).toLocaleString('vi-VN')} vé đã dùng</p>
            : <p className="text-gray-600 dark:text-gray-300">Đã mua đồ ký {spent.toLocaleString('vi-VN')} ngọc</p>}
        {!ticket && <p className={order.equipment_delivered ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-500'}>{order.equipment_delivered ? 'Đã giao đồ ký cho khách' : 'Chưa xác nhận giao đồ ký'}</p>}
    </div>;
}
