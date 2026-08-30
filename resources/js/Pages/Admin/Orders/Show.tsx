import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Bot, Coins, Server, User } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import type { PageProps } from '@/types';

interface GoldOrder {
    id: number;
    type: 'order' | 'import';
    character_name: string;
    amount_vnd_formatted: string;
    gold_qty_formatted: string;
    gold_bar_qty_formatted: string;
    pure_gold_qty_formatted: string;
    price_formatted: string;
    status_label: string;
    updated_by: string;
    created_at: string;
    user?: { id: number; username: string; email: string };
    server?: { id: number; name: string };
    bot?: { id: number; name: string };
}

interface Props extends PageProps {
    order: GoldOrder | { data: GoldOrder };
}

export default function GoldOrderShow() {
    const rawOrder = usePage<Props>().props.order;
    const order = 'data' in rawOrder ? rawOrder.data : rawOrder;
    const backUrl = order.type === 'import' ? '/admin/imports' : '/admin/orders';
    const title = order.type === 'import' ? 'Chi tiết đơn nhập vàng' : 'Chi tiết đơn mua vàng';

    const rows = [
        ['Khách hàng', order.user ? `${order.user.username} (${order.user.email})` : '—', User],
        ['Server', order.server?.name ?? '—', Server],
        ['Nhân vật', order.character_name, User],
        ['Bot', order.bot?.name ?? 'Chưa gán', Bot],
        ['Số tiền', order.amount_vnd_formatted, Coins],
        ['Tổng vàng', order.gold_qty_formatted, Coins],
        ['Thỏi vàng', order.gold_bar_qty_formatted, Coins],
        ['Vàng tươi', order.pure_gold_qty_formatted, Coins],
        ['Giá giao dịch', order.price_formatted, Coins],
    ] as const;

    return (
        <AdminLayout title={`${title} #${order.id}`}>
            <Head title={`${title} #${order.id}`} />
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <Link href={backUrl} className="mb-2 inline-flex items-center gap-2 text-sm text-blue-600 hover:text-blue-800">
                            <ArrowLeft className="h-4 w-4" /> Quay lại danh sách
                        </Link>
                        <h1 className="text-2xl font-bold text-gray-900 dark:text-white">{title} #{order.id}</h1>
                    </div>
                    <span className="rounded-full bg-blue-100 px-3 py-1 text-sm font-semibold text-blue-700">
                        {order.status_label}
                    </span>
                </div>

                <div className="grid gap-4 rounded-xl bg-white p-6 shadow dark:bg-gray-800 md:grid-cols-2">
                    {rows.map(([label, value, Icon]) => (
                        <div key={label} className="flex items-start gap-3 rounded-lg border border-gray-100 p-4 dark:border-gray-700">
                            <Icon className="mt-0.5 h-5 w-5 text-blue-500" />
                            <div>
                                <p className="text-xs uppercase tracking-wide text-gray-500">{label}</p>
                                <p className="mt-1 font-medium text-gray-900 dark:text-white">{value}</p>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </AdminLayout>
    );
}
