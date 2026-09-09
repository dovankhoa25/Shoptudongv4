import EditListingPrice from '../Modals/EditListingPrice';
import axios from 'axios';
import { Eye, MoreVertical } from 'lucide-react';
import { Dropdown, Button, Input, Select, Space, Table, Tag } from 'antd';
import { base, ListingAvailability, ItemStrip, money, statusName } from '../shared';
import { usePagedTab } from '../usePagedTab';
import type { Capabilities, Listing } from '../types';

export default function ListingsTab({
    caps,
    shopUrl,
    run,
    busy,
    dataVersion,
}: {
    caps: Capabilities;
    shopUrl: string | null;
    run: (action: () => Promise<unknown>, success?: string) => Promise<void>;
    busy: boolean;
    dataVersion: number;
}) {
    const { rows, loading, filters, setFilters, apply, reload } = usePagedTab<Listing>(
        '/listings',
        'Không tải được danh sách gói đồ',
        dataVersion,
    );

    return (
        <>
            <div className="mb-3 flex flex-wrap gap-2">
                <Input.Search
                    aria-label="Tìm gói đồ"
                    placeholder="Tên gói hoặc mã gói"
                    className="!w-64"
                    allowClear
                    value={(filters.q as string) || ''}
                    onChange={e => setFilters({ ...filters, q: e.target.value })}
                    onSearch={() => apply()}
                />
                <Select
                    aria-label="Lọc trạng thái gói"
                    className="min-w-40"
                    placeholder="Tất cả trạng thái"
                    allowClear
                    value={(filters.status as string) || undefined}
                    onChange={status => apply({ ...filters, status })}
                    options={[
                        { value: 'active', label: 'Đang bán' },
                        { value: 'paused', label: 'Tạm dừng' },
                        { value: 'sold', label: 'Đã bán' },
                    ]}
                />
                <Button type="primary" onClick={() => apply()}>
                    Lọc
                </Button>
                <Button onClick={() => apply({})}>Xóa lọc</Button>
                <Button onClick={reload}>Làm mới</Button>
            </div>
            <Table
                rowKey="id"
                loading={loading}
                dataSource={rows.data}
                scroll={{ x: 900 }}
                pagination={{
                    current: rows.page,
                    total: rows.total,
                    pageSize: rows.perPage,
                    showSizeChanger: false,
                    showTotal: total => `${total} gói`,
                    onChange: page => apply(filters, page),
                }}
                columns={[
                    {
                        title: 'Gói đồ',
                        width: 220,
                        render: (_, l: Listing) => (
                            <>
                                <strong>
                                    #{l.id} {l.title}
                                </strong>
                                {l.description && (
                                    <p className="mt-1 whitespace-pre-line text-xs text-slate-500 dark:text-slate-400">
                                        {l.description}
                                    </p>
                                )}
                                <ItemStrip items={l.items} />
                            </>
                        ),
                    },
                    { title: 'Người đăng', dataIndex: 'ownerUsername', width: 130, render: v => v || '—' },
                    {
                        title: 'Acc kho',
                        width: 140,
                        render: (_, l: Listing) => l.accountName || `#${l.accountId}`,
                    },
                    { title: 'Giá gói', dataIndex: 'price', width: 120, render: money },
                    { title: 'Tồn kho / khả dụng', width: 200, render: (_, l: Listing) => <ListingAvailability listing={l} /> },
                    {
                        title: 'Trạng thái',
                        width: 120,
                        render: (_, l: Listing) =>
                            l.shopHidden && l.status === 'active' ? <Tag color="orange">Tạm ẩn theo acc</Tag> : l.lastOrderStatus === 'refunded' ? (
                                <Tag color="orange">Đã hoàn tiền</Tag>
                            ) : (
                                <Tag color={l.status === 'active' ? 'green' : undefined}>
                                    {statusName[l.status] || l.status}
                                </Tag>
                            ),
                    },
                    {
                        title: 'Thao tác',
                        width: 130,
                        render: (_, l: Listing) => (
                            <Space wrap>
                                {caps.manageListings && l.status !== 'sold' && <EditListingPrice listing={l} disabled={busy} onSaved={reload} />}
                                {shopUrl && l.status !== 'sold' && (
                                    <Button title="Xem trên shop" aria-label="Xem trên shop" icon={<Eye size={16} />} href={`${shopUrl}/mua-do/${l.id}`} target="_blank" rel="noopener noreferrer" />
                                )}
                                {caps.manageListings && l.status !== 'sold' && (
                                    <Dropdown trigger={['click']} menu={{ items: [{
                                        key: 'toggle', label: l.status === 'active' ? 'Tạm dừng gói' : 'Đăng lại gói', disabled: busy,
                                        onClick: () => run(async () => {
                                            await axios.patch(`${base}/listings/${l.id}`, { status: l.status === 'active' ? 'paused' : 'active' });
                                            await reload();
                                        }),
                                    }] }}><Button title="Thao tác khác" aria-label="Thao tác khác" icon={<MoreVertical size={16} />} disabled={busy} /></Dropdown>
                                )}
                            </Space>
                        ),
                    },
                ]}
            />
        </>
    );
}
