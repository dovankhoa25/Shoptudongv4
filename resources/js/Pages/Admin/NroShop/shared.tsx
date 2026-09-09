import { Tag } from 'antd';
import { NroIcon, type NroItem } from '@/Components/Nro/NroSnapshot';
import type { Account, Category, Listing } from './types';

export const base = '/admin/nro-shop';

/** One label per state. Two phrasings for the same state is what made the old page confusing. */
export const statusName: Record<string, string> = {
    awaiting_receipt: 'Chưa nhận đồ',
    expired: 'Hết phiên chờ',
    queued: 'Chờ tool',
    processing: 'Đang xử lý',
    completed: 'Hoàn tất',
    review: 'Chờ đối soát',
    failed: 'Thất bại',
    refunded: 'Đã hoàn tiền',
    active: 'Đang bán',
    paused: 'Tạm dừng',
    draft: 'Nháp',
    sold: 'Đã bán',
};

export const publishStatusName: Record<string, string> = {
    waiting_snapshot: 'Chờ lấy dữ liệu → tự đăng',
    needs_attention: 'Thiếu dữ liệu, chờ bổ sung',
    scan_failed: 'Lấy dữ liệu thất bại',
    publish_failed: 'Đăng tin thất bại',
    published: 'Đã đăng bán',
    login_blocked: 'Bị chặn đăng nhập',
};

export const money = (price: string | number) => `${Number(price).toLocaleString('vi-VN')}đ`;

export const dateTime = (value?: string | null) => (value ? new Date(value).toLocaleString('vi-VN') : '—');

export const isSold = (a: Account) => a.status === 'sold' || a.nick?.status === 'sold';

/** Accent-insensitive search, so "sao" matches "sáo" and "do" matches "đồ". */
export const searchText = (s: string) =>
    s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd');

export function ItemStrip({ items }: { items: { item: NroItem; quantity: number }[] }) {
    if (!items.length) return null;

    return (
        <div className="mt-1 flex flex-wrap gap-1">
            {items.map((i, n) => (
                <span key={n} title={`${i.item.name} × ${i.quantity}`}>
                    <NroIcon item={i.item} size={30} />
                    <span className="block text-center text-xs">×{i.quantity.toLocaleString('vi-VN')}</span>
                </span>
            ))}
        </div>
    );
}

export function ListingAvailability({ listing }: { listing: Listing }) {
    if (listing.status === 'sold') {
        return (
            <span className="text-xs text-slate-500 dark:text-slate-400">
                {listing.lastOrderStatus === 'refunded'
                    ? 'Đơn đã hoàn tiền · Gói vẫn ẩn vì tồn kho cần đồng bộ lại'
                    : 'Đã ẩn khỏi shop · Theo dõi tại Đơn giao đồ'}
            </span>
        );
    }

    return (
        <div className="min-w-40 text-xs">
            <strong className="text-sm">{listing.available} gói có thể mua</strong>
            <div className="mt-1 text-slate-500 dark:text-slate-400">
                Tồn chưa giữ cho đơn: {listing.stockAvailable ?? listing.available} gói
            </div>
            {listing.unavailableReasons?.map(reason => (
                <div key={reason} className="mt-1 text-amber-600 dark:text-amber-400">
                    {reason}
                </div>
            ))}
        </div>
    );
}

export function NickSaleSummary({
    account: a,
    shopUrl,
    categories,
}: {
    account: Account;
    shopUrl: string | null;
    categories: Category[];
}) {
    const nick = a.nick;

    if (!nick && a.publishStatus && !isSold(a)) {
        return (
            <div className="max-w-sm space-y-1">
                <Tag color={a.publishStatus === 'waiting_snapshot' ? 'blue' : 'orange'}>
                    {publishStatusName[a.publishStatus] || 'Chưa đăng bán'}
                </Tag>
                {a.publishConfig && (
                    <div className="text-xs">
                        {categories.find(c => c.id === a.publishConfig?.categoryId)?.name ||
                            `Danh mục #${a.publishConfig.categoryId}`}{' '}
                        · {money(a.publishConfig.price)}
                    </div>
                )}
                {a.publishError && <p className="text-xs text-amber-600 dark:text-amber-400">{a.publishError}</p>}
            </div>
        );
    }

    if (!nick) {
        return (
            <Tag color={isSold(a) ? 'default' : a.latest_snapshot_id ? 'gold' : undefined}>
                {isSold(a) ? 'Đã bán' : a.latest_snapshot_id ? 'Chưa đăng bán' : 'Chưa lấy dữ liệu'}
            </Tag>
        );
    }

    return (
        <div className="space-y-1">
            <div>
                <Tag color={isSold(a) ? 'default' : nick.status === 'not_sold' ? 'green' : 'orange'}>
                    {isSold(a) ? 'Đã bán' : nick.status === 'not_sold' ? 'Đang bán' : 'Ngừng bán'}
                </Tag>
                <strong>Nick #{nick.id}</strong>
                {a.publishStatus === 'waiting_snapshot' && (
                    <Tag className="ml-1" color="blue">
                        {publishStatusName.waiting_snapshot}
                    </Tag>
                )}
            </div>
            <div className="text-sm">
                {nick.categoryName || 'Danh mục không còn tồn tại'} · <strong>{money(nick.price)}</strong>
            </div>
            {!nick.categoryActive && (
                <div className="text-xs text-amber-600">Danh mục đang ẩn hoặc không còn hoạt động</div>
            )}
            {!isSold(a) && nick.snapshotId !== a.latest_snapshot_id && (
                <div className="text-xs text-amber-600">Có dữ liệu game mới chưa cập nhật vào tin</div>
            )}
            {shopUrl && (
                <a
                    className="text-xs text-blue-500"
                    href={`${shopUrl}/nick/${nick.id}`}
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    Xem tin trên shop ↗
                </a>
            )}
        </div>
    );
}
