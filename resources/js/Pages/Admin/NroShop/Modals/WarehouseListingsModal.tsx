import EditListingPrice from './EditListingPrice';
import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Eye, MoreVertical } from 'lucide-react';
import { Dropdown, Button, Modal, Space, Table, Tag, message } from 'antd';
import { base, ItemStrip, ListingAvailability, money, statusName } from '../shared';
import type { Account, Capabilities, Listing, Paged } from '../types';

export default function WarehouseListingsModal({
    account,
    caps,
    shopUrl,
    busy,
    onClose,
    run,
}: {
    account: Account | null;
    caps: Capabilities;
    shopUrl: string | null;
    busy: boolean;
    onClose: () => void;
    run: (action: () => Promise<unknown>, success?: string) => Promise<void>;
}) {
    const [rows, setRows] = useState<Paged<Listing>>({ data: [], total: 0, page: 1, perPage: 20 });
    const [loading, setLoading] = useState(false);
    const request = useRef(0);

    const load = async (page = 1) => {
        if (!account) return;
        const id = ++request.current;
        setLoading(true);
        try {
            const { data } = await axios.get(`${base}/accounts/${account.id}/listings`, { params: { page } });
            if (id === request.current) setRows(data);
        } catch {
            if (id === request.current) message.error('Không tải được gói đồ của acc');
        } finally {
            if (id === request.current) setLoading(false);
        }
    };

    useEffect(() => {
        if (!account) return;
        setRows({ data: [], total: 0, page: 1, perPage: 20 });
        void load(1);
        // Reload whenever a different account's packages are opened.
    }, [account?.id]);

    return (
        <Modal
            width={900}
            title={account ? `Gói đồ của ${account.account_name}` : 'Gói đồ của acc'}
            open={!!account}
            onCancel={() => {
                request.current++;
                onClose();
            }}
            footer={null}
        >
            <Table
                rowKey="id"
                loading={loading}
                dataSource={rows.data}
                scroll={{ x: 700 }}
                pagination={{
                    current: rows.page,
                    total: rows.total,
                    pageSize: rows.perPage,
                    showSizeChanger: false,
                    showTotal: total => `${total} gói`,
                    onChange: page => load(page),
                }}
                columns={[
                    {
                        title: 'Gói đồ',
                        render: (_, l: Listing) => (
                            <>
                                <strong>
                                    #{l.id} · {l.title}
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
                    { title: 'Người đăng', dataIndex: 'ownerUsername', width: 130, render: v => v || account?.ownerUsername || '—' },
                    { title: 'Giá', dataIndex: 'price', width: 110, render: money },
                    {
                        title: 'Trạng thái',
                        dataIndex: 'status',
                        width: 120,
                        render: (v, l: Listing) => l.shopHidden && v === 'active' ? <Tag color="orange">Tạm ẩn theo acc</Tag> : <Tag color={v === 'active' ? 'green' : undefined}>{statusName[v] || v}</Tag>,
                    },
                    {
                        title: 'Tồn kho / khả dụng',
                        width: 200,
                        render: (_, l: Listing) => <ListingAvailability listing={l} />,
                    },
                    {
                        title: 'Thao tác',
                        width: 130,
                        render: (_, l: Listing) => (
                            <Space wrap>
                                {caps.manageListings && l.status !== 'sold' && <EditListingPrice listing={l} disabled={busy} onSaved={() => load(rows.page)} />}
                                {shopUrl && l.status !== 'sold' && (
                                    <Button title="Xem trên shop" aria-label="Xem trên shop" icon={<Eye size={16} />} href={`${shopUrl}/mua-do/${l.id}`} target="_blank" rel="noopener noreferrer" />
                                )}
                                {caps.manageListings && l.status !== 'sold' && (
                                    <Dropdown trigger={['click']} menu={{ items: [{
                                        key: 'toggle', label: l.status === 'active' ? 'Tạm dừng gói' : 'Đăng lại gói', disabled: busy,
                                        onClick: () => run(async () => {
                                            await axios.patch(`${base}/listings/${l.id}`, { status: l.status === 'active' ? 'paused' : 'active' });
                                            await load(rows.page);
                                        }),
                                    }] }}><Button title="Thao tác khác" aria-label="Thao tác khác" icon={<MoreVertical size={16} />} disabled={busy} /></Dropdown>
                                )}
                            </Space>
                        ),
                    },
                ]}
            />
        </Modal>
    );
}
