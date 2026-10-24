import React, { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { Button, Card, Form, Input, InputNumber, Select, Switch, message } from 'antd';

export interface TradingAsset {
    asset_type: 'pure' | 'standard' | 'nrj';
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
}

type PolicyForm = { policies: Omit<TradingPolicy, 'server_id'>[] };

export default function GoldTradingPolicyPanel({ servers, policies, assets, stocks, canManage }: {
    servers: { id: number; name: string }[];
    policies: TradingPolicy[];
    assets: TradingAsset[];
    stocks: BotStock[];
    canManage: boolean;
}) {
    const [serverId, setServerId] = useState<number | undefined>(servers[0]?.id);
    const [saving, setSaving] = useState(false);
    const [form] = Form.useForm<PolicyForm>();
    const stock = stocks.find(row => Number(row.server_id) === serverId);

    useEffect(() => {
        form.setFieldsValue({ policies: assets.map(asset => {
            const policy = policies.find(row => row.server_id === serverId && row.asset_type === asset.asset_type);
            return {
                asset_type: asset.asset_type,
                sell_enabled: policy?.sell_enabled ?? asset.asset_type !== 'pure',
                import_enabled: policy?.import_enabled ?? true,
                import_stock_limit_bars: policy?.import_stock_limit_bars ?? null,
            };
        }) });
    }, [serverId, policies, assets, form]);

    const save = (values: PolicyForm) => {
        if (!serverId) return;
        setSaving(true);
        router.put(`/admin/gold-prices/servers/${serverId}/trading-policy`, {
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
                <div className="grid gap-4 lg:grid-cols-3">
                    {assets.map((asset, index) => (
                        <Card key={asset.asset_type} size="small" title={asset.label}>
                            <p className="mb-3 text-sm text-gray-600">{asset.note}</p>
                            <p className="mb-4 font-semibold">
                                Tổng tồn: {Number(stock?.[asset.asset_type] ?? 0).toLocaleString('vi-VN')}
                                {asset.asset_type === 'pure' ? ' vàng' : ' thỏi'}
                            </p>
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
                                    extra="Để trống: không giới hạn. Nhập 0: không nhận thêm thỏi."
                                    rules={[{ type: 'number', min: 0, max: 9_000_000_000_000_000 }]}>
                                    <InputNumber min={0} max={9_000_000_000_000_000} precision={0}
                                        placeholder="Không giới hạn" addonAfter="thỏi" style={{ width: '100%' }} />
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
