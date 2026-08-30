// resources/js/Pages/Admin/Dashboard.tsx
import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import AdminLayout from "@/Layouts/AdminLayout";
import { AlertCircle, TrendingUp, Package, Coins } from 'lucide-react';

interface GoldData {
    total_gold_bar: number;
    total_gold: number;
    total_gold_raw: number;
    price_per_gold: number;
    total_value: number;
    bot_count: number;
    has_price: boolean;
}

interface GemData {
    total_gems: number;
    price_per_gem: number;
    multiplier: number;
    multiplier_display: string;
    gems_per_10k: number;
    total_value: number;
    bot_count: number;
    has_price: boolean;
}

interface ServerAsset {
    server_id: number;
    server_name: string;
    gold: GoldData;
    gem: GemData;
    total_value: number;
    reconciliation: ReconciliationData;
}

interface ReconciliationData {
    gem: {
        opening: number;
        adjustment: number;
        adjustment_details: MovementDetail[];
        sold: number;
        order_count: number;
        expected: number;
        actual: number;
        difference: number;
        is_matched: boolean;
    };
    gold: {
        opening_converted: number;
        sold_converted: number;
        imported_converted: number;
        adjustment: number;
        adjustment_details: MovementDetail[];
        order_count: number;
        import_count: number;
        expected_converted: number;
        actual_converted: number;
        difference: number;
        is_matched: boolean;
    };
}

interface MovementDetail {
    bot_id: number;
    type: string;
    delta: number;
    note: string | null;
    time: string | null;
}

interface GrandTotal {
    total_gold_value: number;
    total_gem_value: number;
    total_value: number;
}

interface Props {
    servers: ServerAsset[];
    grandTotal: GrandTotal;
    reconciliationDate: string;
    ledgerStartedAt: string | null;
}

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('vi-VN', {
        style: 'currency',
        currency: 'VND',
    }).format(amount);
};

const formatNumber = (num: number) => {
    return new Intl.NumberFormat('vi-VN').format(num);
};

const formatCompact = (num: number) => {
    if (num >= 1000000000) {
        return (num / 1000000000).toFixed(1) + 'B';
    }
    if (num >= 1000000) {
        return (num / 1000000).toFixed(1) + 'M';
    }
    if (num >= 1000) {
        return (num / 1000).toFixed(1) + 'K';
    }
    return num.toString();
};

function Dashboard({ servers, grandTotal, reconciliationDate, ledgerStartedAt }: Props) {
    const [viewMode, setViewMode] = useState<'table' | 'cards'>('table');

    // Filter servers có data
    const activeServers = servers.filter(s => s.gold.bot_count > 0 || s.gem.bot_count > 0);

    // Đếm số server chưa có giá
    const serversWithoutPrice = activeServers.filter(s =>
        (s.gold.bot_count > 0 && !s.gold.has_price) ||
        (s.gem.bot_count > 0 && !s.gem.has_price)
    ).length;
    const mismatchCount = activeServers.filter(
        server => !server.reconciliation.gem.is_matched || !server.reconciliation.gold.is_matched
    ).length;

    const changeReconciliationDate = (date: string) => {
        router.get(route('admin.dashboard'), { date }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    return (
        <>
            <Head title="Dashboard - Tổng quan tài sản" />

            <div className="space-y-4">
                {/* Header with Toggle */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-900">
                            Tổng quan tài sản Bot
                        </h1>
                        <p className="mt-1 text-sm text-gray-600">
                            Kiểm soát và theo dõi tài sản của {activeServers.length} servers
                        </p>
                    </div>

                    {/* View Mode Toggle */}
                    <div className="flex items-center gap-2 bg-white rounded-lg shadow-sm p-1">
                        <button
                            onClick={() => setViewMode('table')}
                            className={`px-4 py-2 rounded text-sm font-medium transition-colors ${viewMode === 'table'
                                    ? 'bg-blue-600 text-white'
                                    : 'text-gray-700 hover:bg-gray-100'
                                }`}
                        >
                            📊 Bảng
                        </button>
                        <button
                            onClick={() => setViewMode('cards')}
                            className={`px-4 py-2 rounded text-sm font-medium transition-colors ${viewMode === 'cards'
                                    ? 'bg-blue-600 text-white'
                                    : 'text-gray-700 hover:bg-gray-100'
                                }`}
                        >
                            🎴 Cards
                        </button>
                    </div>
                </div>

                {/* Warning Alert */}
                {serversWithoutPrice > 0 && (
                    <div className="bg-yellow-50 border-l-4 border-yellow-400 p-3">
                        <div className="flex items-center">
                            <AlertCircle className="h-4 w-4 text-yellow-400" />
                            <p className="ml-2 text-sm text-yellow-800">
                                <span className="font-medium">{serversWithoutPrice} servers</span> chưa cấu hình giá
                            </p>
                        </div>
                    </div>
                )}

                {/* Grand Total Summary - Compact */}
                <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div className="bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg p-4 text-white shadow-sm">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-xs font-medium opacity-90">Tổng Servers</p>
                                <p className="mt-1 text-2xl font-bold">{activeServers.length}</p>
                            </div>
                            <Package className="h-8 w-8 opacity-80" />
                        </div>
                    </div>

                    <div className="bg-gradient-to-br from-yellow-500 to-yellow-600 rounded-lg p-4 text-white shadow-sm">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-xs font-medium opacity-90">Tổng Vàng</p>
                                <p className="mt-1 text-2xl font-bold">
                                    {formatCurrency(grandTotal.total_gold_value).replace('₫', '').trim()}đ
                                </p>
                            </div>
                            <Coins className="h-8 w-8 opacity-80" />
                        </div>
                    </div>

                    <div className="bg-gradient-to-br from-purple-500 to-purple-600 rounded-lg p-4 text-white shadow-sm">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-xs font-medium opacity-90">Tổng Ngọc</p>
                                <p className="mt-1 text-2xl font-bold">
                                    {formatCurrency(grandTotal.total_gem_value).replace('₫', '').trim()}đ
                                </p>
                            </div>
                            <div className="text-3xl opacity-80">💎</div>
                        </div>
                    </div>

                    <div className="bg-gradient-to-br from-green-500 to-green-600 rounded-lg p-4 text-white shadow-sm">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-xs font-medium opacity-90">Tổng Tài Sản</p>
                                <p className="mt-1 text-2xl font-bold">
                                    {formatCurrency(grandTotal.total_value).replace('₫', '').trim()}đ
                                </p>
                            </div>
                            <TrendingUp className="h-8 w-8 opacity-80" />
                        </div>
                    </div>
                </div>

                <DailyReconciliation
                    servers={activeServers}
                    date={reconciliationDate}
                    mismatchCount={mismatchCount}
                    ledgerStartedAt={ledgerStartedAt}
                    onDateChange={changeReconciliationDate}
                />

                {/* Content Based on View Mode */}
                {viewMode === 'table' ? (
                    <TableView servers={activeServers} />
                ) : (
                    <CardsView servers={activeServers} />
                )}
            </div>
        </>
    );
}

function DifferenceBadge({ value }: { value: number }) {
    if (value === 0) {
        return <span className="font-semibold text-green-700">Khớp</span>;
    }

    return (
        <span className="font-semibold text-red-700">
            {value > 0 ? 'Thừa ' : 'Thiếu '}{formatNumber(Math.abs(value))}
        </span>
    );
}

function AdjustmentCell({ value, details }: { value: number; details: MovementDetail[] }) {
    const tooltip = details.map(detail =>
        `${detail.time ?? ''} Bot #${detail.bot_id}: ${detail.delta >= 0 ? '+' : ''}${formatNumber(detail.delta)} (${detail.note ?? detail.type})`
    ).join('\n');

    return (
        <div title={tooltip || undefined}>
            <span className={value === 0 ? 'text-gray-400' : 'font-semibold text-blue-700'}>
                {value >= 0 ? '+' : ''}{formatNumber(value)}
            </span>
            {details.length > 0 && <div className="text-[11px] text-gray-500">{details.length} thay đổi — rê chuột xem</div>}
        </div>
    );
}

function DailyReconciliation({
    servers,
    date,
    mismatchCount,
    ledgerStartedAt,
    onDateChange,
}: {
    servers: ServerAsset[];
    date: string;
    mismatchCount: number;
    ledgerStartedAt: string | null;
    onDateChange: (date: string) => void;
}) {
    return (
        <div className="overflow-hidden rounded-lg bg-white shadow-sm">
            <div className="flex flex-col gap-3 border-b border-gray-200 bg-gray-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="text-sm font-semibold uppercase text-gray-800">Kiểm kê tài sản theo ngày</h2>
                    <p className="mt-1 text-xs text-gray-500">
                        Tồn đầu + nhập - bán ± thêm/sửa/chuyển bot = tồn lý thuyết. Vàng đã quy đổi 1 thỏi = 37.000.000.
                    </p>
                    {ledgerStartedAt && (
                        <p className="mt-1 text-xs text-blue-700">
                            Sổ kho bắt đầu ghi nhận từ {new Date(ledgerStartedAt).toLocaleString('vi-VN')}; dữ liệu trước thời điểm này không được dùng để kết luận lệch.
                        </p>
                    )}
                </div>
                <div className="flex items-center gap-3">
                    <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${mismatchCount === 0 ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
                        {mismatchCount === 0 ? 'Tất cả đã khớp' : `${mismatchCount} server bị lệch`}
                    </span>
                    <input
                        type="date"
                        value={date}
                        max={new Date().toISOString().slice(0, 10)}
                        onChange={event => onDateChange(event.target.value)}
                        className="rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                    />
                </div>
            </div>

            <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200 text-sm">
                    <thead className="bg-gray-50 text-xs uppercase text-gray-600">
                        <tr>
                            <th className="px-4 py-3 text-left">Server</th>
                            <th className="px-4 py-3 text-left">Tài sản</th>
                            <th className="px-4 py-3 text-right">Tồn đầu</th>
                            <th className="px-4 py-3 text-right">Nhập</th>
                            <th className="px-4 py-3 text-right">Bán</th>
                            <th className="px-4 py-3 text-right">Điều chỉnh bot</th>
                            <th className="px-4 py-3 text-right">Lý thuyết</th>
                            <th className="px-4 py-3 text-right">Thực tế</th>
                            <th className="px-4 py-3 text-right">Chênh lệch</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {servers.flatMap(server => {
                            const gem = server.reconciliation.gem;
                            const gold = server.reconciliation.gold;

                            return [
                                <tr key={`${server.server_id}-gem`} className={!gem.is_matched ? 'bg-red-50' : ''}>
                                    <td className="px-4 py-3 font-semibold text-gray-900">{server.server_name}</td>
                                    <td className="px-4 py-3 text-purple-700">Ngọc ({gem.order_count} đơn)</td>
                                    <td className="px-4 py-3 text-right">{formatNumber(gem.opening)}</td>
                                    <td className="px-4 py-3 text-right text-gray-400">—</td>
                                    <td className="px-4 py-3 text-right">{formatNumber(gem.sold)}</td>
                                    <td className="px-4 py-3 text-right"><AdjustmentCell value={gem.adjustment} details={gem.adjustment_details} /></td>
                                    <td className="px-4 py-3 text-right">{formatNumber(gem.expected)}</td>
                                    <td className="px-4 py-3 text-right font-semibold">{formatNumber(gem.actual)}</td>
                                    <td className="px-4 py-3 text-right"><DifferenceBadge value={gem.difference} /></td>
                                </tr>,
                                <tr key={`${server.server_id}-gold`} className={!gold.is_matched ? 'bg-red-50' : ''}>
                                    <td className="px-4 py-3 text-gray-400">↳</td>
                                    <td className="px-4 py-3 text-yellow-700">
                                        Vàng quy đổi ({gold.order_count} bán, {gold.import_count} nhập)
                                    </td>
                                    <td className="px-4 py-3 text-right">{formatNumber(gold.opening_converted)}</td>
                                    <td className="px-4 py-3 text-right">{formatNumber(gold.imported_converted)}</td>
                                    <td className="px-4 py-3 text-right">{formatNumber(gold.sold_converted)}</td>
                                    <td className="px-4 py-3 text-right"><AdjustmentCell value={gold.adjustment} details={gold.adjustment_details} /></td>
                                    <td className="px-4 py-3 text-right">{formatNumber(gold.expected_converted)}</td>
                                    <td className="px-4 py-3 text-right font-semibold">{formatNumber(gold.actual_converted)}</td>
                                    <td className="px-4 py-3 text-right"><DifferenceBadge value={gold.difference} /></td>
                                </tr>,
                            ];
                        })}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

// Table View Component
function TableView({ servers }: { servers: ServerAsset[] }) {
    return (
        <div className="bg-white rounded-lg shadow-sm overflow-hidden">
            <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                Server
                            </th>
                            <th className="px-4 py-3 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                Bots
                            </th>
                            <th className="px-4 py-3 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                💰 Thỏi
                            </th>
                            <th className="px-4 py-3 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                💰 Vàng Tươi
                            </th>
                            <th className="px-4 py-3 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                💰 Giá Vàng
                            </th>
                            <th className="px-4 py-3 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                💎 Ngọc
                            </th>
                            <th className="px-4 py-3 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                💎 Multiplier
                            </th>
                            <th className="px-4 py-3 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider bg-green-50">
                                Tổng Giá Trị
                            </th>
                        </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-gray-200">
                        {servers.map((server) => (
                            <tr key={server.server_id} className="hover:bg-gray-50 transition-colors">
                                <td className="px-4 py-3 whitespace-nowrap">
                                    <div className="flex items-center">
                                        <div className="text-sm font-semibold text-gray-900">
                                            {server.server_name}
                                        </div>
                                        {(!server.gold.has_price || !server.gem.has_price) && (
                                            <span className="ml-2 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700">
                                                ⚠️
                                            </span>
                                        )}
                                    </div>
                                </td>
                                <td className="px-4 py-3 whitespace-nowrap text-center">
                                    <div className="text-sm">
                                        <span className="text-yellow-600 font-medium">{server.gold.bot_count}</span>
                                        <span className="text-gray-400 mx-1">|</span>
                                        <span className="text-purple-600 font-medium">{server.gem.bot_count}</span>
                                    </div>
                                </td>
                                <td className="px-4 py-3 whitespace-nowrap text-right text-sm text-gray-900">
                                    {formatCompact(server.gold.total_gold_bar)}
                                </td>
                                <td className="px-4 py-3 whitespace-nowrap text-right text-sm font-medium text-gray-900">
                                    {formatCompact(server.gold.total_gold_raw)}
                                </td>
                                <td className="px-4 py-3 whitespace-nowrap text-right">
                                    {server.gold.has_price ? (
                                        <span className="text-sm text-gray-900">
                                            {formatCurrency(server.gold.total_value)}
                                        </span>
                                    ) : (
                                        <span className="text-xs text-red-600 font-medium">Chưa có giá</span>
                                    )}
                                </td>
                                <td className="px-4 py-3 whitespace-nowrap text-right text-sm font-medium text-gray-900">
                                    {formatCompact(server.gem.total_gems)}
                                </td>
                                <td className="px-4 py-3 whitespace-nowrap text-center">
                                    {server.gem.has_price ? (
                                        <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">
                                            {server.gem.multiplier_display}
                                        </span>
                                    ) : (
                                        <span className="text-xs text-red-600 font-medium">N/A</span>
                                    )}
                                </td>
                                <td className="px-4 py-3 whitespace-nowrap text-right bg-green-50">
                                    <span className={`text-sm font-bold ${server.total_value > 0 ? 'text-green-600' : 'text-gray-400'}`}>
                                        {server.total_value > 0 ? formatCurrency(server.total_value) : 'N/A'}
                                    </span>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot className="bg-gray-50 border-t-2 border-gray-300">
                        <tr>
                            <td colSpan={7} className="px-4 py-3 text-right text-sm font-bold text-gray-700">
                                TỔNG CỘNG:
                            </td>
                            <td className="px-4 py-3 whitespace-nowrap text-right bg-green-100">
                                <span className="text-base font-bold text-green-700">
                                    {formatCurrency(servers.reduce((sum, s) => sum + s.total_value, 0))}
                                </span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    );
}

// Cards View Component (Original Design)
function CardsView({ servers }: { servers: ServerAsset[] }) {
    return (
        <div className="bg-white rounded-lg shadow-sm overflow-hidden">
            <div className="px-4 py-3 border-b border-gray-200 bg-gray-50">
                <h2 className="text-sm font-semibold text-gray-700 uppercase">
                    Chi tiết theo Server
                </h2>
            </div>

            <div className="divide-y divide-gray-200">
                {servers.map((server) => (
                    <div key={server.server_id} className="p-4 hover:bg-gray-50 transition-colors">
                        {/* Server Name & Total */}
                        <div className="flex items-center justify-between mb-3">
                            <div className="flex items-center gap-2">
                                <h3 className="text-base font-semibold text-gray-900">
                                    🖥️ {server.server_name}
                                </h3>
                                {(!server.gold.has_price || !server.gem.has_price) && (
                                    <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">
                                        <AlertCircle className="w-3 h-3 mr-1" />
                                        Chưa có giá
                                    </span>
                                )}
                            </div>
                            <div className="text-right">
                                <span className={`text-lg font-bold ${server.total_value > 0 ? 'text-green-600' : 'text-gray-400'}`}>
                                    {formatCurrency(server.total_value)}
                                </span>
                            </div>
                        </div>

                        {/* Data Grid */}
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            {/* Gold Section */}
                            {server.gold.bot_count > 0 && (
                                <div className="bg-yellow-50 rounded border border-yellow-200 p-3">
                                    <div className="flex items-center justify-between mb-2 pb-2 border-b border-yellow-300">
                                        <div className="flex items-center gap-2">
                                            <span className="text-lg">💰</span>
                                            <span className="text-sm font-semibold text-yellow-900">
                                                Vàng ({server.gold.bot_count} bots)
                                            </span>
                                        </div>
                                        {!server.gold.has_price && (
                                            <span className="text-xs bg-red-100 text-red-700 px-1.5 py-0.5 rounded">
                                                Chưa có giá
                                            </span>
                                        )}
                                    </div>
                                    <div className="space-y-1.5 text-xs">
                                        <div className="flex justify-between">
                                            <span className="text-gray-600">Thỏi:</span>
                                            <span className="font-medium text-gray-900">
                                                {formatNumber(server.gold.total_gold_bar)}
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-gray-600">Vàng tươi:</span>
                                            <span className="font-medium text-gray-900">
                                                {formatNumber(server.gold.total_gold)}
                                            </span>
                                        </div>
                                        <div className="flex justify-between pt-1 border-t border-yellow-300">
                                            <span className="text-gray-700 font-semibold">Tổng vàng tươi:</span>
                                            <span className="font-bold text-yellow-800">
                                                {formatNumber(server.gold.total_gold_raw)}
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-gray-600">Giá/vàng:</span>
                                            <span className={`font-medium ${server.gold.has_price ? 'text-gray-900' : 'text-red-600'}`}>
                                                {server.gold.has_price
                                                    ? server.gold.price_per_gold.toFixed(2)
                                                    : '⚠️ Chưa cấu hình'
                                                }
                                            </span>
                                        </div>
                                        <div className={`flex justify-between pt-1.5 mt-1.5 border-t border-yellow-400 -mx-3 -mb-3 px-3 py-2 ${server.gold.has_price ? 'bg-yellow-100' : 'bg-gray-100'
                                            }`}>
                                            <span className="font-bold text-yellow-900">Giá trị:</span>
                                            <span className={`font-bold ${server.gold.has_price ? 'text-yellow-900' : 'text-gray-500'}`}>
                                                {server.gold.has_price
                                                    ? formatCurrency(server.gold.total_value)
                                                    : 'N/A'
                                                }
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            )}

                            {/* Gem Section */}
                            {server.gem.bot_count > 0 && (
                                <div className="bg-purple-50 rounded border border-purple-200 p-3">
                                    <div className="flex items-center justify-between mb-2 pb-2 border-b border-purple-300">
                                        <div className="flex items-center gap-2">
                                            <span className="text-lg">💎</span>
                                            <span className="text-sm font-semibold text-purple-900">
                                                Ngọc ({server.gem.bot_count} bots)
                                            </span>
                                        </div>
                                        {!server.gem.has_price && (
                                            <span className="text-xs bg-red-100 text-red-700 px-1.5 py-0.5 rounded">
                                                Chưa có giá
                                            </span>
                                        )}
                                    </div>
                                    <div className="space-y-1.5 text-xs">
                                        <div className="flex justify-between">
                                            <span className="text-gray-600">Tổng ngọc:</span>
                                            <span className="font-medium text-gray-900">
                                                {formatNumber(server.gem.total_gems)}
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-gray-600">Multiplier:</span>
                                            <span className={`font-semibold ${server.gem.has_price ? 'text-purple-700' : 'text-red-600'}`}>
                                                {server.gem.multiplier_display}
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-gray-600">Ngọc/10k:</span>
                                            <span className="font-medium text-gray-900">
                                                {server.gem.has_price ? formatNumber(server.gem.gems_per_10k) : 'N/A'}
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-gray-600">Giá/ngọc:</span>
                                            <span className={`font-medium ${server.gem.has_price ? 'text-gray-900' : 'text-red-600'}`}>
                                                {server.gem.has_price
                                                    ? formatCurrency(server.gem.price_per_gem)
                                                    : '⚠️ Chưa cấu hình'
                                                }
                                            </span>
                                        </div>
                                        <div className={`flex justify-between pt-1.5 mt-1.5 border-t border-purple-400 -mx-3 -mb-3 px-3 py-2 ${server.gem.has_price ? 'bg-purple-100' : 'bg-gray-100'
                                            }`}>
                                            <span className="font-bold text-purple-900">Giá trị:</span>
                                            <span className={`font-bold ${server.gem.has_price ? 'text-purple-900' : 'text-gray-500'}`}>
                                                {server.gem.has_price
                                                    ? formatCurrency(server.gem.total_value)
                                                    : 'N/A'
                                                }
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

Dashboard.layout = (page: React.ReactNode) => (
    <AdminLayout title="Dashboard - Tổng quan tài sản" children={page} />
);

export default Dashboard;
