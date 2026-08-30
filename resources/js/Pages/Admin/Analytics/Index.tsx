// resources/js/Pages/Admin/Analytics/Index.tsx

import React, { useState, useEffect } from 'react';
import { router, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronRight, Calendar, TrendingUp, Users, Package, DollarSign, ShoppingCart, Settings, BarChart3 } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { PageProps } from '@/types';
import { useToast } from '@/Components/ToastProvider';
import { AnalyticsPageProps, CategoryStats, AdminAnalytics, SellerAnalytics, SellerCategoryStats, SellerStats } from '@/InterFaces/analytics';

interface AnalyticsPageData extends PageProps {
    analytics: AdminAnalytics | SellerAnalytics;
    statDate: string;
    isAdmin: boolean;
    availableDates: string[];
}

export default function AnalyticsPage() {
    const { analytics, statDate, isAdmin, availableDates, flash } = usePage<AnalyticsPageData>().props;

    const [expandedCategories, setExpandedCategories] = useState<Set<number>>(new Set());
    const [selectedDate, setSelectedDate] = useState(statDate);
    const toast = useToast();

    // Flash messages
    useEffect(() => {
        if (flash?.success) {
            toast.success(flash.success);
        }
        if (flash?.error) {
            toast.error(flash.error);
        }
        if (flash?.info) {
            toast.info(flash.info);
        }
    }, [flash]);

    const toggleCategory = (categoryId: number) => {
        const newExpanded = new Set(expandedCategories);
        if (newExpanded.has(categoryId)) {
            newExpanded.delete(categoryId);
        } else {
            newExpanded.add(categoryId);
        }
        setExpandedCategories(newExpanded);
    };

    const handleDateChange = (date: string) => {
        setSelectedDate(date);
        router.get(route('admin.analytics.index'), { date }, {
            preserveState: true,
            preserveScroll: true
        });
    };

    const formatCurrency = (amount: number): string => {
        return new Intl.NumberFormat('vi-VN', {
            style: 'currency',
            currency: 'VND',
            minimumFractionDigits: 0,
            maximumFractionDigits: 0,
        }).format(amount);
    };

    const formatNumber = (num: number): string => {
        return new Intl.NumberFormat('vi-VN').format(num);
    };

    // Determine category type based on data
    const getCategoryType = (categoryData: any): 'nick' | 'service' | 'hybrid' => {
        const hasNick = categoryData.nick_total_count > 0 || categoryData.nick_sold_count > 0 || categoryData.nick_available_count > 0;
        const hasService = categoryData.service_completed_count > 0 || categoryData.service_total_count > 0;

        if (hasNick && hasService) return 'hybrid';
        if (hasService) return 'service';
        return 'nick';
    };

    const getCategoryIcon = (type: 'nick' | 'service' | 'hybrid') => {
        switch (type) {
            case 'service': return <Settings className="h-6 w-6 text-purple-600" />;
            case 'nick': return <ShoppingCart className="h-6 w-6 text-blue-600" />;
            case 'hybrid': return <BarChart3 className="h-6 w-6 text-green-600" />;
        }
    };

    const getCategoryBadge = (type: 'nick' | 'service' | 'hybrid') => {
        switch (type) {
            case 'service':
                return <span className="px-3 py-1 bg-purple-100 text-purple-800 text-xs font-semibold rounded-full">DỊCH VỤ</span>;
            case 'nick':
                return <span className="px-3 py-1 bg-blue-100 text-blue-800 text-xs font-semibold rounded-full">NICK GAME</span>;
            case 'hybrid':
                return <span className="px-3 py-1 bg-green-100 text-green-800 text-xs font-semibold rounded-full">NICK + DỊCH VỤ</span>;
        }
    };

    // Smart stats grid that only shows relevant metrics
    const renderStatsGrid = (data: any, isCompact: boolean = false) => {
        const stats = [];

        // Nick stats (only show if has nick data)
        if (data.nick_total_count > 0) {
            stats.push(
                <div key="nick-count" className="bg-blue-50 border border-blue-200 rounded-lg p-3 min-w-0">
                    <div className={`${isCompact ? 'text-base' : 'text-lg'} font-bold text-blue-700 truncate`}>
                        {formatNumber(data.nick_total_count)}
                    </div>
                    <div className="text-xs text-blue-600">Nick tổng</div>
                </div>
            );
        }

        if (data.nick_total_revenue > 0) {
            stats.push(
                <div key="nick-value" className="bg-green-50 border border-green-200 rounded-lg p-3 min-w-0">
                    <div className="text-sm font-bold text-green-700 truncate">
                        {formatCurrency(data.nick_total_revenue)}
                    </div>
                    <div className="text-xs text-green-600">Trị giá Nick</div>
                </div>
            );
        }

        if (data.nick_sold_count > 0) {
            stats.push(
                <div key="nick-sold" className="bg-yellow-50 border border-yellow-200 rounded-lg p-3 min-w-0">
                    <div className={`${isCompact ? 'text-base' : 'text-lg'} font-bold text-yellow-700 truncate`}>
                        {formatNumber(data.nick_sold_count)}
                    </div>
                    <div className="text-xs text-yellow-600">Nick đã bán</div>
                </div>
            );
        }

        if (data.nick_sold_revenue > 0) {
            stats.push(
                <div key="nick-revenue" className="bg-red-50 border border-red-200 rounded-lg p-3 min-w-0">
                    <div className="text-sm font-bold text-red-700 truncate">
                        {formatCurrency(data.nick_sold_revenue)}
                    </div>
                    <div className="text-xs text-red-600">Doanh thu Nick</div>
                </div>
            );
        }

        if (data.nick_available_count > 0) {
            stats.push(
                <div key="nick-available" className="bg-cyan-50 border border-cyan-200 rounded-lg p-3 min-w-0">
                    <div className={`${isCompact ? 'text-base' : 'text-lg'} font-bold text-cyan-700 truncate`}>
                        {formatNumber(data.nick_available_count)}
                    </div>
                    <div className="text-xs text-cyan-600">Nick còn lại</div>
                </div>
            );
        }

        // Service stats (only show if has service data)
        if (data.service_completed_count > 0) {
            stats.push(
                <div key="service-count" className="bg-purple-50 border border-purple-200 rounded-lg p-3 min-w-0">
                    <div className={`${isCompact ? 'text-base' : 'text-lg'} font-bold text-purple-700 truncate`}>
                        {formatNumber(data.service_completed_count)}
                    </div>
                    <div className="text-xs text-purple-600">Dịch vụ hoàn thành</div>
                </div>
            );
        }

        if (data.service_completed_revenue > 0) {
            stats.push(
                <div key="service-revenue" className="bg-indigo-50 border border-indigo-200 rounded-lg p-3 min-w-0">
                    <div className="text-sm font-bold text-indigo-700 truncate">
                        {formatCurrency(data.service_completed_revenue)}
                    </div>
                    <div className="text-xs text-indigo-600">Doanh thu DV</div>
                </div>
            );
        }

        if (data.service_total_count > 0 && data.service_total_count !== data.service_completed_count) {
            stats.push(
                <div key="service-total" className="bg-violet-50 border border-violet-200 rounded-lg p-3 min-w-0">
                    <div className={`${isCompact ? 'text-base' : 'text-lg'} font-bold text-violet-700 truncate`}>
                        {formatNumber(data.service_total_count)}
                    </div>
                    <div className="text-xs text-violet-600">Tổng DV</div>
                </div>
            );
        }

        // Deleted stats (only show if has deleted data)
        if (data.nick_deleted_count > 0) {
            stats.push(
                <div key="deleted-count" className="bg-orange-50 border border-orange-200 rounded-lg p-3 min-w-0">
                    <div className={`${isCompact ? 'text-base' : 'text-lg'} font-bold text-orange-700 truncate`}>
                        {formatNumber(data.nick_deleted_count)}
                    </div>
                    <div className="text-xs text-orange-600">Đã xóa</div>
                </div>
            );
        }

        if (data.nick_deleted_amount > 0) {
            stats.push(
                <div key="deleted-amount" className="bg-pink-50 border border-pink-200 rounded-lg p-3 min-w-0">
                    <div className="text-sm font-bold text-pink-700 truncate">
                        {formatCurrency(data.nick_deleted_amount)}
                    </div>
                    <div className="text-xs text-pink-600">Tiền xóa</div>
                </div>
            );
        }

        // Total revenue (always show)
        stats.push(
            <div key="total-revenue" className="bg-emerald-50 border-2 border-emerald-300 rounded-lg p-3 min-w-0">
                <div className={`${isCompact ? 'text-sm' : 'text-sm'} font-bold text-emerald-700 truncate`}>
                    {formatCurrency(data.total_revenue)}
                </div>
                <div className="text-xs text-emerald-600">Tổng doanh thu</div>
            </div>
        );

        return (
            <div className={`grid gap-2 ${isCompact ? 'grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6' : 'grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6'}`}>
                {stats}
            </div>
        );
    };

    const renderAdminView = (data: AdminAnalytics) => (
        <div className="space-y-4">
            {data.categories.map((category: CategoryStats) => {
                const categoryType = getCategoryType(category.totals);
                const isExpanded = expandedCategories.has(category.category_id);

                return (
                    <div key={category.category_id} className="bg-white rounded-xl shadow-lg border-2 border-gray-100 overflow-hidden">
                        {/* Category Header */}
                        <div
                            className="flex flex-col lg:flex-row lg:items-center lg:justify-between p-4 md:p-6 cursor-pointer hover:bg-gray-50 transition-all duration-200 border-b-2 border-gray-100 gap-4"
                            onClick={() => toggleCategory(category.category_id)}
                        >
                            <div className="flex items-center space-x-4 min-w-0 flex-1">
                                <div className="flex-shrink-0">
                                    {isExpanded ? (
                                        <ChevronDown className="h-6 w-6 text-blue-500" />
                                    ) : (
                                        <ChevronRight className="h-6 w-6 text-blue-500" />
                                    )}
                                </div>
                                <div className="flex items-center space-x-3 min-w-0">
                                    {getCategoryIcon(categoryType)}
                                    <div className="min-w-0">
                                        <div className="flex flex-col sm:flex-row sm:items-center gap-2">
                                            <h3 className="font-bold text-lg text-gray-900 truncate">{category.category_name}</h3>
                                            {getCategoryBadge(categoryType)}
                                        </div>
                                        <p className="text-sm text-gray-500 mt-1">
                                            {category.sellers.length} CTV • Click để xem chi tiết
                                        </p>
                                    </div>
                                </div>
                            </div>

                            {/* Category Stats */}
                            <div className="flex-shrink-0 w-full lg:w-auto">
                                {renderStatsGrid(category.totals)}
                            </div>
                        </div>

                        {/* Expanded Sellers */}
                        {isExpanded && (
                            <div className="bg-gray-50 border-t-2 border-gray-200">
                                <div className="p-4 md:p-6">
                                    <h4 className="font-semibold text-gray-700 mb-4 flex items-center">
                                        <Users className="h-5 w-5 mr-2 text-gray-600" />
                                        Danh sách cộng tác viên ({category.sellers.length})
                                    </h4>
                                    <div className="space-y-3">
                                        {category.sellers.map((seller: SellerStats) => (
                                            <div key={seller.seller_id} className="bg-white rounded-lg border border-gray-200 p-4 hover:shadow-md transition-all duration-200">
                                                <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                                                    <div className="flex items-center space-x-3 min-w-0 flex-1">
                                                        <div className="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-bold flex-shrink-0">
                                                            {seller.seller_username.charAt(0).toUpperCase()}
                                                        </div>
                                                        <div className="min-w-0">
                                                            <div className="font-semibold text-gray-900 truncate">@{seller.seller_username}</div>
                                                            <div className="text-sm text-gray-500 truncate">{seller.seller_email || 'Chưa có email'}</div>
                                                        </div>
                                                    </div>

                                                    {/* Seller Stats */}
                                                    <div className="flex-shrink-0 w-full lg:w-auto">
                                                        {renderStatsGrid(seller, true)}
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>
                );
            })}

            {/* Grand Total */}
            <div className="bg-gradient-to-r from-blue-600 via-purple-600 to-blue-800 text-white rounded-xl shadow-xl border-4 border-blue-200 overflow-hidden">
                <div className="p-4 md:p-6">
                    <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                        <div className="flex items-center space-x-4">
                            <TrendingUp className="h-6 md:h-8 w-6 md:w-8 flex-shrink-0" />
                            <div>
                                <h3 className="font-bold text-xl md:text-2xl">Tổng cộng tất cả</h3>
                                <p className="text-blue-100">Thống kê toàn hệ thống</p>
                            </div>
                        </div>

                        <div className="flex-shrink-0 w-full lg:w-auto">
                            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-2 md:gap-3">
                                {data.grand_total.nick_total_count > 0 && (
                                    <div className="text-center bg-white/20 backdrop-blur rounded-lg p-3 border border-white/30">
                                        <div className="text-lg md:text-xl font-bold truncate">{formatNumber(data.grand_total.nick_total_count)}</div>
                                        <div className="text-xs text-blue-100">Nick tổng</div>
                                    </div>
                                )}
                                {data.grand_total.nick_sold_count > 0 && (
                                    <div className="text-center bg-white/20 backdrop-blur rounded-lg p-3 border border-white/30">
                                        <div className="text-lg md:text-xl font-bold truncate">{formatNumber(data.grand_total.nick_sold_count)}</div>
                                        <div className="text-xs text-blue-100">Nick bán</div>
                                    </div>
                                )}
                                {data.grand_total.nick_sold_revenue > 0 && (
                                    <div className="text-center bg-white/20 backdrop-blur rounded-lg p-3 border border-white/30">
                                        <div className="text-sm font-bold truncate">{formatCurrency(data.grand_total.nick_sold_revenue)}</div>
                                        <div className="text-xs text-blue-100">Doanh thu Nick</div>
                                    </div>
                                )}
                                {data.grand_total.service_completed_count > 0 && (
                                    <div className="text-center bg-white/20 backdrop-blur rounded-lg p-3 border border-white/30">
                                        <div className="text-lg md:text-xl font-bold truncate">{formatNumber(data.grand_total.service_completed_count)}</div>
                                        <div className="text-xs text-blue-100">Dịch vụ</div>
                                    </div>
                                )}
                                {data.grand_total.service_completed_revenue > 0 && (
                                    <div className="text-center bg-white/20 backdrop-blur rounded-lg p-3 border border-white/30">
                                        <div className="text-sm font-bold truncate">{formatCurrency(data.grand_total.service_completed_revenue)}</div>
                                        <div className="text-xs text-blue-100">Doanh thu DV</div>
                                    </div>
                                )}
                                <div className="text-center bg-gradient-to-br from-yellow-400 to-orange-500 rounded-lg p-3 border-2 border-yellow-300">
                                    <div className="text-sm md:text-lg font-bold text-white truncate">{formatCurrency(data.grand_total.total_revenue)}</div>
                                    <div className="text-xs text-yellow-100">Tổng doanh thu</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );

    const renderSellerView = (data: SellerAnalytics) => (
        <div className="space-y-4">
            {data.categories.map((category: SellerCategoryStats) => {
                const categoryType = getCategoryType(category);

                return (
                    <div key={category.category_id} className="bg-white rounded-xl shadow-lg border-2 border-gray-100 overflow-hidden">
                        <div className="p-4 md:p-6">
                            <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                                <div className="flex items-center space-x-4 min-w-0 flex-1">
                                    {getCategoryIcon(categoryType)}
                                    <div className="min-w-0">
                                        <div className="flex flex-col sm:flex-row sm:items-center gap-2">
                                            <h3 className="font-bold text-lg text-gray-900 truncate">{category.category_name}</h3>
                                            {getCategoryBadge(categoryType)}
                                        </div>
                                        <p className="text-sm text-gray-500 mt-1">Thống kê của bạn trong danh mục này</p>
                                    </div>
                                </div>

                                {/* Category Stats */}
                                <div className="flex-shrink-0 w-full lg:w-auto">
                                    {renderStatsGrid(category)}
                                </div>
                            </div>
                        </div>
                    </div>
                );
            })}

            {/* Seller Total */}
            <div className="bg-gradient-to-r from-green-600 via-emerald-600 to-teal-600 text-white rounded-xl shadow-xl border-4 border-green-200 overflow-hidden">
                <div className="p-4 md:p-6">
                    <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                        <div className="flex items-center space-x-4">
                            <DollarSign className="h-6 md:h-8 w-6 md:w-8 flex-shrink-0" />
                            <div>
                                <h3 className="font-bold text-xl md:text-2xl">Tổng cộng của bạn</h3>
                                <p className="text-green-100">Thống kê cá nhân</p>
                            </div>
                        </div>

                        <div className="flex-shrink-0 w-full lg:w-auto">
                            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-2 md:gap-3">
                                {data.seller_total.nick_total_count > 0 && (
                                    <div className="text-center bg-white/20 backdrop-blur rounded-lg p-3 border border-white/30">
                                        <div className="text-lg md:text-xl font-bold truncate">{formatNumber(data.seller_total.nick_total_count)}</div>
                                        <div className="text-xs text-green-100">Nick tổng</div>
                                    </div>
                                )}
                                {data.seller_total.nick_sold_count > 0 && (
                                    <div className="text-center bg-white/20 backdrop-blur rounded-lg p-3 border border-white/30">
                                        <div className="text-lg md:text-xl font-bold truncate">{formatNumber(data.seller_total.nick_sold_count)}</div>
                                        <div className="text-xs text-green-100">Nick bán</div>
                                    </div>
                                )}
                                {data.seller_total.service_completed_count > 0 && (
                                    <div className="text-center bg-white/20 backdrop-blur rounded-lg p-3 border border-white/30">
                                        <div className="text-lg md:text-xl font-bold truncate">{formatNumber(data.seller_total.service_completed_count)}</div>
                                        <div className="text-xs text-green-100">Dịch vụ</div>
                                    </div>
                                )}
                                <div className="text-center bg-gradient-to-br from-yellow-400 to-orange-500 rounded-lg p-3 border-2 border-yellow-300">
                                    <div className="text-sm md:text-lg font-bold text-white truncate">{formatCurrency(data.seller_total.total_revenue)}</div>
                                    <div className="text-xs text-yellow-100">Tổng doanh thu</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );

    return (
        <div className="space-y-6 bg-gray-50 min-h-screen p-4 md:p-6">
            {/* Header Card */}
            <div className="bg-white rounded-xl shadow-lg border-2 border-gray-100 p-4 md:p-6">
                <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                    <div>
                        <h1 className="text-2xl md:text-3xl font-bold text-gray-900 flex items-center">
                            <TrendingUp className="h-6 md:h-8 w-6 md:w-8 mr-3 text-blue-600" />
                            {isAdmin ? 'Thống kê & Báo cáo' : 'Thống kê của tôi'}
                        </h1>
                        <p className="text-base md:text-lg text-gray-600 mt-2">
                            {isAdmin ? 'Xem tổng quan hoạt động của tất cả cộng tác viên' : 'Xem chi tiết hoạt động của bạn theo từng danh mục'}
                        </p>
                    </div>

                    {/* Date Picker */}
                    <div className="flex items-center space-x-3 bg-gray-50 border-2 border-gray-200 rounded-xl p-3 md:p-4">
                        <Calendar className="h-5 md:h-6 w-5 md:w-6 text-blue-500" />
                        <select
                            value={selectedDate}
                            onChange={(e) => handleDateChange(e.target.value)}
                            className="border-2 border-blue-200 rounded-lg px-3 md:px-4 py-2 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white shadow-sm"
                        >
                            {availableDates.map(date => (
                                <option key={date} value={date}>
                                    {new Date(date).toLocaleDateString('vi-VN', {
                                        year: 'numeric',
                                        month: 'long',
                                        day: 'numeric'
                                    })}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>
            </div>

            {/* Content */}
            {isAdmin ? renderAdminView(analytics as AdminAnalytics) : renderSellerView(analytics as SellerAnalytics)}

            {/* Empty State */}
            {((isAdmin && (analytics as AdminAnalytics).categories.length === 0) ||
                (!isAdmin && (analytics as SellerAnalytics).categories.length === 0)) && (
                    <div className="bg-white rounded-xl shadow-lg border-2 border-gray-100 p-12 text-center">
                        <div className="text-gray-400 text-6xl mb-4">📊</div>
                        <h3 className="text-2xl font-bold text-gray-900 mb-4">Chưa có dữ liệu thống kê</h3>
                        <p className="text-lg text-gray-600">
                            {isAdmin
                                ? 'Chưa có hoạt động nào được ghi nhận trong ngày này.'
                                : 'Bạn chưa có hoạt động nào trong ngày này.'
                            }
                        </p>
                    </div>
                )}
        </div>
    );
}

// Layout theo cấu trúc project
AnalyticsPage.layout = (page: React.ReactNode) => (
    <AdminLayout title="Analytics Management" children={page} />
);