import { useEffect, useState } from 'react';
import axios from 'axios';
import { Alert, Button, Card, Collapse, Input, InputNumber, Modal, Select, Space, Table, Tag } from 'antd';
import NroSnapshotPanel, { NroIcon, type NroSnapshot } from '@/Components/Nro/NroSnapshot';
import { base, NickSaleSummary, searchText } from '../shared';
import type { Account, Capabilities, Category, Inventory, Server } from '../types';

const LOCATION: Record<string, string> = { bag: 'Túi', chest: 'Rương', equipped: 'Đang mặc' };

export default function AccountDetailModal({
    open,
    account,
    snapshot,
    inventory,
    selected,
    setSelected,
    servers,
    categories,
    caps,
    salePolicy,
    shopUrl,
    busy,
    canPublish,
    onClose,
    onReload,
    onPublish,
    onOpenPolicy,
    run,
}: {
    open: boolean;
    account: Account | null;
    snapshot: NroSnapshot | null;
    inventory: Inventory[];
    selected: Record<number, number>;
    setSelected: (next: Record<number, number> | ((s: Record<number, number>) => Record<number, number>)) => void;
    servers: Server[];
    categories: Category[];
    caps: Capabilities;
    salePolicy: { enabled: boolean; ids: number[] };
    shopUrl: string | null;
    busy: boolean;
    canPublish: (a: Account) => boolean;
    onClose: () => void;
    onReload: (a: Account) => void;
    onPublish: (a: Account) => void;
    onOpenPolicy: () => void;
    run: (action: () => Promise<unknown>, success?: string) => Promise<void>;
}) {
    const [itemSearch, setItemSearch] = useState('');
    const [itemFilter, setItemFilter] = useState('available');
    const [itemSort, setItemSort] = useState('name');

    useEffect(() => {
        setSelected(previous => Object.fromEntries(Object.entries(previous).flatMap(([id, quantity]) => {
            const item = inventory.find(i => i.id === Number(id));
            return item?.sellable && item.selectable > 0 ? [[id, Math.min(quantity, item.selectable)]] : [];
        })));
    }, [inventory, setSelected]);

    const filteredInventory = inventory
        .filter(
            i =>
                (itemFilter === 'all' || (itemFilter === 'available' ? i.sellable && i.selectable > 0 : itemFilter === 'allocated' ? i.listed > 0 || i.reserved > 0 : itemFilter === 'sellable' ? i.sellable : !i.sellable)) &&
                searchText(`${i.item.name} ${i.item.templateId} ${i.item.optionLabels?.join(' ') || ''}`).includes(
                    searchText(itemSearch.trim()),
                ),
        )
        .sort((a, b) =>
            itemSort === 'quantity_desc'
                ? b.selectable - a.selectable
                : itemSort === 'quantity_asc'
                  ? a.selectable - b.selectable
                  : a.item.name.localeCompare(b.item.name, 'vi'),
        );

    const inventoryReady = snapshot?.completeness.bag === true && snapshot?.completeness.chest === true;
    const isWarehouse = account?.usage_type === 'warehouse';

    return (
        <Modal
            width={1000}
            title={
                account
                    ? `${account.account_name} · ${servers.find(s => s.id === account.server_id)?.name_view || 'Chưa cấu hình server'}`
                    : ''
            }
            open={open && !!account}
            onCancel={onClose}
            footer={null}
        >
            {account?.usage_type === 'nick' && (
                <div className="mb-4">
                    <NickSaleSummary account={account} shopUrl={shopUrl} categories={categories} />
                </div>
            )}

            {snapshot ? (
                isWarehouse ? (
                    <Collapse
                        items={[
                            {
                                key: 'snapshot',
                                label: 'Xem toàn bộ dữ liệu game (trang bị, túi, rương…)',
                                children: <NroSnapshotPanel snapshot={snapshot} />,
                            },
                        ]}
                    />
                ) : (
                    <NroSnapshotPanel snapshot={snapshot} />
                )
            ) : (
                <Alert message="Chưa có dữ liệu game. Bấm Lấy dữ liệu và chờ tool xử lý." />
            )}

            {isWarehouse && account && (
                <Card title="Chọn món và số lượng cho gói" className="mt-4">
                    <p className="mb-3 text-sm">
                        Phần trên dùng để xem chỉ số. Tích ô ở bảng dưới để chọn nhiều món, nhập số lượng rồi bấm Tạo gói
                        để đặt tên và giá chung.
                    </p>
                    <Space wrap className="mb-3">
                        <Input.Search
                            allowClear
                            aria-label="Tìm đồ"
                            placeholder="Tên, ID, chỉ số (ví dụ: 5 sao)"
                            value={itemSearch}
                            onChange={e => setItemSearch(e.target.value)}
                            style={{ width: 270 }}
                        />
                        <Select
                            aria-label="Lọc đồ được phép bán"
                            value={itemFilter}
                            onChange={setItemFilter}
                            options={[
                                { value: 'available', label: `Còn để tạo gói (${inventory.filter(i => i.sellable && i.selectable > 0).length})` },
                                { value: 'allocated', label: 'Đã đăng / giữ cho đơn' },
                                { value: 'sellable', label: `Được phép bán (${inventory.filter(i => i.sellable).length})` },
                                {
                                    value: 'blocked',
                                    label: `Không được phép bán (${inventory.filter(i => !i.sellable).length})`,
                                },
                                { value: 'all', label: 'Tất cả vật phẩm' },
                            ]}
                        />
                        <Select
                            aria-label="Sắp xếp đồ"
                            value={itemSort}
                            onChange={setItemSort}
                            options={[
                                { value: 'name', label: 'Tên A–Z' },
                                { value: 'quantity_desc', label: 'Còn chọn: nhiều → ít' },
                                { value: 'quantity_asc', label: 'Còn chọn: ít → nhiều' },
                            ]}
                        />
                        <Button disabled={busy} onClick={() => onReload(account)}>
                            Tải lại kho
                        </Button>
                        {caps.salePolicy && <Button onClick={onOpenPolicy}>Cấu hình ID bán</Button>}
                    </Space>
                    <p className="mb-3 text-xs text-slate-500">
                        Món đã phân hết được ẩn khỏi bộ lọc mặc định. Còn chọn = tồn kho − đang đăng gói − giữ cho đơn. Đã chọn {Object.keys(selected).length} loại, kể
                        cả món đang ẩn bởi bộ lọc. {!salePolicy.enabled && 'Chưa bật giới hạn ID.'}
                    </p>

                    {!inventoryReady && (
                        <Alert
                            className="mb-3"
                            type="warning"
                            showIcon
                            message="Chưa đủ dữ liệu để tạo gói đồ"
                            description={
                                <div className="space-y-2">
                                    <p>
                                        {!snapshot
                                            ? 'Acc chưa có dữ liệu game.'
                                            : `Tool chưa xác nhận đầy đủ: ${[
                                                  snapshot.completeness.bag !== true && 'hành trang',
                                                  snapshot.completeness.chest !== true && 'rương',
                                              ]
                                                  .filter(Boolean)
                                                  .join(', ')}. Các món phía trên mới là dữ liệu xem, chưa được xác nhận thành tồn kho bán.`}
                                    </p>
                                    <p>Bật tool, yêu cầu lấy lại dữ liệu rồi tải lại danh sách sau khi tool hoàn tất.</p>
                                    <Space wrap>
                                        {caps.manageAccounts && account.status === 'active' && (
                                            <Button
                                                loading={busy}
                                                onClick={() =>
                                                    run(
                                                        () => axios.post(`${base}/accounts/${account.id}/scan`),
                                                        'Đã yêu cầu lấy dữ liệu. Chờ tool hoàn tất rồi tải lại danh sách.',
                                                    )
                                                }
                                            >
                                                Yêu cầu lấy lại dữ liệu
                                            </Button>
                                        )}
                                        <Button disabled={busy} onClick={() => onReload(account)}>
                                            Tải lại danh sách món
                                        </Button>
                                    </Space>
                                </div>
                            }
                        />
                    )}

                    <Table
                        rowKey="id"
                        dataSource={filteredInventory}
                        locale={{
                            emptyText: inventoryReady
                                ? 'Không có vật phẩm khớp bộ lọc. Kiểm tra danh sách ID hoặc chọn Tất cả vật phẩm.'
                                : 'Chưa có tồn kho đã xác nhận để chọn bán.',
                        }}
                        pagination={{ pageSize: 8 }}
                        rowSelection={{
                            preserveSelectedRowKeys: true,
                            selectedRowKeys: Object.keys(selected).map(Number),
                            getCheckboxProps: i => ({
                                disabled:
                                    !inventoryReady ||
                                    !caps.manageListings ||
                                    account.status !== 'active' ||
                                    !i.sellable ||
                                    i.selectable <= 0,
                            }),
                            onChange: keys =>
                                setSelected(Object.fromEntries(keys.map(k => [Number(k), selected[Number(k)] || 1]))),
                        }}
                        columns={[
                            {
                                title: 'Món',
                                render: (_, i: Inventory) => (
                                    <div className="flex gap-2">
                                        <NroIcon item={i.item} />
                                        <div>
                                            {i.item.name}
                                            <span className="ml-2 text-xs text-slate-500">ID {i.item.templateId}</span>
                                            <div className="text-xs text-green-700 dark:text-green-300">
                                                {i.item.optionLabels?.join(' · ')}
                                            </div>
                                            <div className="text-xs text-slate-500">
                                                {i.locations
                                                    .map(l => `${LOCATION[l.location] || l.location} ô ${l.slot}`)
                                                    .join(', ')}
                                            </div>
                                        </div>
                                    </div>
                                ),
                            },
                            {
                                title: 'Còn chọn',
                                width: 190,
                                render: (_, i: Inventory) => (
                                    <>
                                        <strong>{i.selectable.toLocaleString('vi-VN')}</strong>
                                        {i.selectable === 0 && (i.listed > 0 || i.reserved > 0) && <Tag className="ml-2">Đã phân hết</Tag>}
                                        <div className="text-xs text-slate-500">
                                            Tồn {i.quantity} · Đang đăng {i.listed} · Giữ đơn {i.reserved}
                                        </div>
                                        {!i.sellable && <Tag>Không được phép bán</Tag>}
                                    </>
                                ),
                            },
                            {
                                title: 'SL trong gói',
                                width: 130,
                                render: (_, i: Inventory) => (
                                    <InputNumber
                                        min={1}
                                        max={Math.max(1, i.selectable)}
                                        precision={0}
                                        disabled={!inventoryReady || !i.sellable || !selected[i.id]}
                                        value={selected[i.id] || 1}
                                        onChange={v => setSelected(s => ({ ...s, [i.id]: v || 1 }))}
                                    />
                                ),
                            },
                        ]}
                    />
                </Card>
            )}

            {snapshot && account && canPublish(account) && (
                <Button
                    type="primary"
                    className="mt-4"
                    disabled={isWarehouse && (!inventoryReady || !Object.keys(selected).length)}
                    onClick={() => onPublish(account)}
                >
                    {account.usage_type === 'nick'
                        ? account.nick
                            ? 'Sửa tin bán'
                            : 'Đăng bán'
                        : `Tạo gói ${Object.keys(selected).length} loại đồ`}
                </Button>
            )}
        </Modal>
    );
}
