import React, { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { Button, Card, Form, Input, InputNumber, Select, Switch, message } from 'antd';

export interface TradingAsset {
    asset_type: 'pure' | 'standard' | 'nrj' | 'radar';
    label: string;
    note: string;
}

export interface TradingPolicy {
    server_id: number;
    asset_type: TradingAsset['asset_type'];
    sell_enabled: boolean;
    import_enabled: boolean;
    import_stock_limit_bars: number | null;
}

export interface BotStock {
    server_id: number;
    pure: number | string;
    standard: number | string;
    nrj: number | string;
    radar: number | string;
    radar_room?: number;
    radar_physical_room?: number;
    radar_unknown?: number;
}

type PolicyForm = { gold_radar_enabled: boolean; policies: Omit<TradingPolicy, 'server_id'>[] };

export default function GoldTradingPolicyPanel({ servers, policies, assets, stocks, canManage }: {
    servers: { id: number; name: string; gold_radar_enabled?: boolean; radar_supported?: boolean }[];
    policies: TradingPolicy[];
    assets: TradingAsset[];
    stocks: BotStock[];
    canManage: boolean;
}) {
    const [serverId, setServerId] = useState<number | undefined>(servers[0]?.id);
    const [saving, setSaving] = useState(false);
    const [form] = Form.useForm<PolicyForm>();
    const radarMode = Form.useWatch('gold_radar_enabled', form);
    const selected = servers.find(server => server.id === serverId);
    const stock = stocks.find(row => Number(row.server_id) === serverId);

    useEffect(() => {
        form.setFieldsValue({ gold_radar_enabled: !!servers.find(server => server.id === serverId)?.gold_radar_enabled, policies: assets.map(asset => {
            const policy = policies.find(row => row.server_id === serverId && row.asset_type === asset.asset_type);
            return {
                asset_type: asset.asset_type,
                sell_enabled: policy?.sell_enabled ?? !['pure', 'radar'].includes(asset.asset_type),
                import_enabled: policy?.import_enabled ?? asset.asset_type !== 'radar',
                import_stock_limit_bars: policy?.import_stock_limit_bars ?? null,
            };
        }) });
    }, [serverId, policies, assets, form, servers]);

    const save = (values: PolicyForm) => {
        if (!serverId) return;
        setSaving(true);
        router.put(`/admin/gold-prices/servers/${serverId}/trading-policy`, {
            gold_radar_enabled: values.gold_radar_enabled,
            policies: values.policies.map(policy => ({ ...policy,
                import_stock_limit_bars: policy.asset_type === 'pure' ? null : policy.import_stock_limit_bars ?? null,
            })),
        }, {
            preserveScroll: true,
            onSuccess: () => message.success('Đã lưu cấu hình nhập/bán vàng.'),
            onError: errors => {
                Object.entries(errors).forEach(([name, error]) => {
                    const field = /^policies\.(\d+)\.(asset_type|sell_enabled|import_enabled|import_stock_limit_bars)$/.exec(name);
                    if (field) form.setFields([{
                        name: ['policies', Number(field[1]), field[2] as keyof PolicyForm['policies'][number]],
                        errors: [error],
                    }]);
                });
                message.error('Không thể lưu. Vui lòng kiểm tra cấu hình.');
            },
            onFinish: () => setSaving(false),
        });
    };

    return (
        <Card title="Nhập / bán theo server và loại vàng" className="mb-6">
            <p className="mb-4 text-sm text-gray-600">
                Áp dụng cho đơn mới. Giới hạn nhập tính tổng thỏi trên tất cả bot của server, kể cả bot tạm tắt.
                Vàng tươi chỉ hiện ở trang mua khi bật bán cho server đó.
            </p>
            <div className="mb-4 flex flex-wrap items-center gap-3">
                <Select aria-label="Server cấu hình nhập bán" value={serverId} onChange={setServerId}
                    disabled={saving} showSearch optionFilterProp="label" placeholder="Chọn server"
                    options={servers.map(server => ({ value: server.id, label: server.name }))}
                    style={{ minWidth: 240 }} />
                <Button onClick={() => router.reload({ only: ['botStocks'] })} disabled={saving}>Cập nhật tồn</Button>
            </div>
            <Form form={form} layout="vertical" onFinish={save} disabled={saving || !canManage || !serverId}>
                <Form.Item label="Giao dịch bằng rada trên Super 1–3" name="gold_radar_enabled" valuePropName="checked"
                    extra={selected?.radar_supported ? 'Khi bật, server chỉ nhận/bán rada. Cần bật riêng hai công tắc nhập/bán bên dưới.' : 'Cấu hình endpoint hoặc bot đúng Super 1–3 trước khi bật.'}>
                    <Switch disabled={!selected?.radar_supported} />
                </Form.Item>
                <div className="grid gap-4 lg:grid-cols-3">
                    {assets.map((asset, index) => (
                        <Card key={asset.asset_type} size="small" title={asset.label} style={{ display: (asset.asset_type === 'radar') === !!radarMode ? undefined : 'none' }}>
                            <p className="mb-3 text-sm text-gray-600">{asset.note}</p>
                            <p className="mb-4 font-semibold">
                                Tổng tồn: {Number(stock?.[asset.asset_type] ?? 0).toLocaleString('vi-VN')}
                                {asset.asset_type === 'pure' ? ' vàng' : asset.asset_type === 'radar' ? ' rada' : ' thỏi'}
                            </p>
                            {asset.asset_type === 'radar' && <p className="mb-3 text-sm text-gray-600">
                                Chính + phụ nhập còn {Number(stock?.radar_physical_room ?? 0).toLocaleString('vi-VN')} ô trống;
                                hiện nhận thêm tối đa {Number(stock?.radar_room ?? 0).toLocaleString('vi-VN')} rada theo trần tồn.
                                {!!stock?.radar_unknown && <> Có {stock.radar_unknown} bot chưa đồng bộ sức chứa.</>}
                            </p>}
                            <Form.Item name={['policies', index, 'asset_type']} hidden><Input /></Form.Item>
                            <div className="flex gap-8">
                                <Form.Item label="Bán" name={['policies', index, 'sell_enabled']} valuePropName="checked">
                                    <Switch checkedChildren="Bật" unCheckedChildren="Tắt" />
                                </Form.Item>
                                <Form.Item label="Nhập" name={['policies', index, 'import_enabled']} valuePropName="checked">
                                    <Switch checkedChildren="Bật" unCheckedChildren="Tắt" />
                                </Form.Item>
                            </div>
                            {asset.asset_type !== 'pure' && (
                                <Form.Item label="Trần tồn để nhận đơn nhập" name={['policies', index, 'import_stock_limit_bars']}
                                    extra={`Để trống: không giới hạn. Nhập 0: không nhận thêm ${asset.asset_type === 'radar' ? 'rada' : 'thỏi'}.`}
                                    rules={[{ type: 'number', min: 0, max: 9_000_000_000_000_000 }]}>
                                    <InputNumber min={0} max={9_000_000_000_000_000} precision={0}
                                        placeholder="Không giới hạn" addonAfter={asset.asset_type === 'radar' ? 'rada' : 'thỏi'} style={{ width: '100%' }} />
                                </Form.Item>
                            )}
                        </Card>
                    ))}
                </div>
                <Button className="mt-4" type="primary" htmlType="submit" loading={saving}
                    disabled={!canManage || !serverId}>Lưu cấu hình server</Button>
            </Form>
        </Card>
    );
}
