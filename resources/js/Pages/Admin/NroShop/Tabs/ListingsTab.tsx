import { useEffect, useRef } from 'react';
import ListingFilters, { type ListingMetadata, type FilterMetadata } from './ListingFilters';
import EditListingPrice from '../Modals/EditListingPrice';
import axios from 'axios';
import { Eye, MoreVertical } from 'lucide-react';
import { Dropdown, Button, Modal, Space, Table, Tag } from 'antd';
import { base, ListingAvailability, ItemStrip, money, statusName } from '../shared';
import { usePagedTab } from '../usePagedTab';
import { LiveDataNotice } from '@/Realtime/LiveDataNotice';
import type { Capabilities, Listing, Server } from '../types';

export default function ListingsTab({
    caps,
    servers,
    shopUrl,
    run,
    busy,
    dataVersion,
}: {
    caps: Capabilities;
    servers: Server[];
    shopUrl: string | null;
    run: (action: () => Promise<unknown>, success?: string) => Promise<void>;
    busy: boolean;
    dataVersion: number;
}) {
    const { rows, meta, loading, filters, setFilters, apply, reload, error, warning } = usePagedTab<Listing, ListingMetadata>(
        '/listings',
        'Không tải được danh sách gói đồ',
        dataVersion,
    );

    const metadata = useRef<FilterMetadata>();
    if (meta?.filters) metadata.current = meta.filters;
    useEffect(() => {
        if (meta?.clearedFilters?.length) setFilters(current => {
            const next = { ...current }; for (const key of meta?.clearedFilters!) delete next[key]; return next;
        });
    }, [meta?.clearedFilters, setFilters]);

    return (
        <>
            <LiveDataNotice error={error} warning={warning} reload={reload} />
            <ListingFilters filters={filters} metadata={metadata.current} servers={servers} setFilters={setFilters} apply={apply} reload={reload} />
            <p className="mb-3 text-xs text-slate-500">Tin bán và số gói còn lại. Xem từng lần mua tại tab Đơn giao đồ. Tìm nâng cao: #tk:12, #ctv:username, #goi:254, #vp:223.</p>
            <Table
                size="small"
                rowKey="id"
                loading={loading}
                dataSource={rows.data}
                scroll={{ x: 900 }}
                pagination={{
                    current: rows.page,
                    total: rows.total,
                    pageSize: rows.perPage,
                    showSizeChanger: false,
                    showTotal: total => `${total} tin bán`,
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
                            l.shopHidden && l.status === 'active' ? <Tag color="orange">Tạm ẩn theo acc</Tag> : l.policyBlocked ? <Tag color="red">Bị chặn quyền bán</Tag> : (
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
                                {caps.manageListings && l.status !== 'archived' && <EditListingPrice listing={l} disabled={busy} onSaved={reload} />}
                                {shopUrl && l.status !== 'archived' && (
                                    <Button title="Xem trên shop" aria-label="Xem trên shop" icon={<Eye size={16} />} href={`${shopUrl}/ban-do-tu-dong/${l.id}`} target="_blank" rel="noopener noreferrer" />
                                )}
                                {caps.manageListings && l.status !== 'archived' && (
                                    <Dropdown trigger={['click']} menu={{ items: [{
                                        key: 'toggle', label: l.status === 'active' ? 'Tạm dừng gói' : 'Đăng lại gói', disabled: busy,
                                        onClick: () => run(async () => {
                                            await axios.patch(`${base}/listings/${l.id}`, { status: l.status === 'active' ? 'paused' : 'active' });
                                            await reload();
                                        }),
                                    }, {
                                        key: 'withdraw', label: 'Thu hồi tin, trả đồ chưa bán về kho', danger: true, disabled: busy,
                                        onClick: () => Modal.confirm({ title: 'Thu hồi tin bán?', content: 'Phần chưa bán được trả về kho để tạo tin khác. Đơn đã mua vẫn được giao.', okText: 'Thu hồi', cancelText: 'Đóng', onOk: () => run(async () => { await axios.patch(`${base}/listings/${l.id}`, { status: 'archived' }); window.dispatchEvent(new Event('admin:refresh-if-offline')); }) }),
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
