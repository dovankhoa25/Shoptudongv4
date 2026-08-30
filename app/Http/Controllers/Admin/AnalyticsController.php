<?php

// app/Http/Controllers/Admin/AnalyticsController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function index(Request $request): Response
    {

        $user = $request->user();
        $isAdmin = $user->canViewAllAdminData();

        $statDate = $request->get('date') ??
            DB::table('seller_category_stats')->max('stat_date') ??
            now()->toDateString();

        if ($isAdmin) {
            $analytics = $this->getAdminAnalytics($statDate);
        } else {
            $analytics = $this->getSellerAnalytics($user->id, $statDate);
        }

        return Inertia::render('Admin/Analytics/Index', [
            'analytics' => $analytics,
            'statDate' => $statDate,
            'isAdmin' => $isAdmin,
            'availableDates' => $this->getAvailableDates(),
        ]);
    }

    private function getAdminAnalytics(string $statDate): array
    {
        // Get all stats for the date
        $stats = DB::table('seller_category_stats as scs')
            ->join('categories as c', 'scs.category_id', '=', 'c.id')
            ->join('users as u', 'scs.seller_id', '=', 'u.id')
            ->select([
                'scs.*', // Lấy tất cả fields từ seller_category_stats
                'c.name as category_name',
                'c.slug as category_slug',
                'u.username as seller_username',
                'u.email as seller_email',
            ])
            ->where('scs.stat_date', $statDate)
            ->orderBy('c.name')
            ->orderBy('u.username')
            ->get();

        // Group by categories
        $grouped = $stats->groupBy('category_id')->map(function ($categoryStats, $categoryId) {
            $firstStat = $categoryStats->first();

            // Calculate category totals
            $categoryTotal = [
                // Nick stats
                'nick_total_count' => $categoryStats->sum('nick_total_count'),
                'nick_total_revenue' => $categoryStats->sum('nick_total_revenue'),
                'nick_total_commission' => $categoryStats->sum('nick_total_commission'),

                // Nick theo status
                'nick_sold_count' => $categoryStats->sum('nick_sold_count'),
                'nick_sold_revenue' => $categoryStats->sum('nick_sold_revenue'),
                'nick_deleted_count' => $categoryStats->sum('nick_deleted_count'),
                'nick_deleted_amount' => $categoryStats->sum('nick_deleted_amount'),
                'nick_returned_count' => $categoryStats->sum('nick_returned_count'),
                'nick_returned_amount' => $categoryStats->sum('nick_returned_amount'),
                'nick_available_count' => $categoryStats->sum('nick_available_count'),
                'nick_available_value' => $categoryStats->sum('nick_available_value'),

                // Service stats
                'service_total_count' => $categoryStats->sum('service_total_count'),
                'service_total_revenue' => $categoryStats->sum('service_total_revenue'),
                'service_completed_count' => $categoryStats->sum('service_completed_count'),
                'service_completed_revenue' => $categoryStats->sum('service_completed_revenue'),
                'service_rejected_count' => $categoryStats->sum('service_rejected_count'),
                'service_rejected_revenue' => $categoryStats->sum('service_rejected_revenue'),
                'service_pending_count' => $categoryStats->sum('service_pending_count'),
                'service_approved_count' => $categoryStats->sum('service_approved_count'),
            ];

            // Calculate total revenue (nick_sold + service_completed)
            $categoryTotal['total_revenue'] = $categoryTotal['nick_sold_revenue'] + $categoryTotal['service_completed_revenue'];

            return [
                'category_id' => $categoryId,
                'category_name' => $firstStat->category_name,
                'category_slug' => $firstStat->category_slug,
                'totals' => $categoryTotal,
                'sellers' => $categoryStats->map(function ($stat) {
                    return [
                        'seller_id' => $stat->seller_id,
                        'seller_username' => $stat->seller_username,
                        'seller_email' => $stat->seller_email,

                        // Nick stats
                        'nick_total_count' => (int) $stat->nick_total_count,
                        'nick_total_revenue' => (float) $stat->nick_total_revenue,
                        'nick_total_commission' => (float) $stat->nick_total_commission,

                        // Nick theo status
                        'nick_sold_count' => (int) $stat->nick_sold_count,
                        'nick_sold_revenue' => (float) $stat->nick_sold_revenue,
                        'nick_deleted_count' => (int) $stat->nick_deleted_count,
                        'nick_deleted_amount' => (float) $stat->nick_deleted_amount,
                        'nick_returned_count' => (int) $stat->nick_returned_count,
                        'nick_returned_amount' => (float) $stat->nick_returned_amount,
                        'nick_available_count' => (int) $stat->nick_available_count,
                        'nick_available_value' => (float) $stat->nick_available_value,

                        // Service stats
                        'service_total_count' => (int) $stat->service_total_count,
                        'service_total_revenue' => (float) $stat->service_total_revenue,
                        'service_completed_count' => (int) $stat->service_completed_count,
                        'service_completed_revenue' => (float) $stat->service_completed_revenue,
                        'service_rejected_count' => (int) $stat->service_rejected_count,
                        'service_rejected_revenue' => (float) $stat->service_rejected_revenue,
                        'service_pending_count' => (int) $stat->service_pending_count,
                        'service_approved_count' => (int) $stat->service_approved_count,

                        'total_revenue' => (float) ($stat->nick_sold_revenue + $stat->service_completed_revenue),
                    ];
                })->values()->toArray(),
            ];
        })->values()->toArray();

        // Calculate grand total
        $grandTotal = [
            // Nick stats
            'nick_total_count' => $stats->sum('nick_total_count'),
            'nick_total_revenue' => $stats->sum('nick_total_revenue'),
            'nick_total_commission' => $stats->sum('nick_total_commission'),

            // Nick theo status
            'nick_sold_count' => $stats->sum('nick_sold_count'),
            'nick_sold_revenue' => $stats->sum('nick_sold_revenue'),
            'nick_deleted_count' => $stats->sum('nick_deleted_count'),
            'nick_deleted_amount' => $stats->sum('nick_deleted_amount'),
            'nick_returned_count' => $stats->sum('nick_returned_count'),
            'nick_returned_amount' => $stats->sum('nick_returned_amount'),
            'nick_available_count' => $stats->sum('nick_available_count'),
            'nick_available_value' => $stats->sum('nick_available_value'),

            // Service stats
            'service_total_count' => $stats->sum('service_total_count'),
            'service_total_revenue' => $stats->sum('service_total_revenue'),
            'service_completed_count' => $stats->sum('service_completed_count'),
            'service_completed_revenue' => $stats->sum('service_completed_revenue'),
            'service_rejected_count' => $stats->sum('service_rejected_count'),
            'service_rejected_revenue' => $stats->sum('service_rejected_revenue'),
            'service_pending_count' => $stats->sum('service_pending_count'),
            'service_approved_count' => $stats->sum('service_approved_count'),
        ];
        $grandTotal['total_revenue'] = $grandTotal['nick_sold_revenue'] + $grandTotal['service_completed_revenue'];

        return [
            'categories' => $grouped,
            'grand_total' => $grandTotal,
        ];
    }

    private function getSellerAnalytics(int $sellerId, string $statDate): array
    {
        // Get stats for specific seller
        $stats = DB::table('seller_category_stats as scs')
            ->join('categories as c', 'scs.category_id', '=', 'c.id')
            ->select([
                'scs.*', // Lấy tất cả fields từ seller_category_stats
                'c.name as category_name',
                'c.slug as category_slug',
            ])
            ->where('scs.seller_id', $sellerId)
            ->where('scs.stat_date', $statDate)
            ->orderBy('c.name')
            ->get();

        // Format for single seller
        $categories = $stats->map(function ($stat) {
            return [
                'category_id' => $stat->category_id,
                'category_name' => $stat->category_name,
                'category_slug' => $stat->category_slug,

                // Nick stats
                'nick_total_count' => (int) $stat->nick_total_count,
                'nick_total_revenue' => (float) $stat->nick_total_revenue,
                'nick_total_commission' => (float) $stat->nick_total_commission,

                // Nick theo status
                'nick_sold_count' => (int) $stat->nick_sold_count,
                'nick_sold_revenue' => (float) $stat->nick_sold_revenue,
                'nick_deleted_count' => (int) $stat->nick_deleted_count,
                'nick_deleted_amount' => (float) $stat->nick_deleted_amount,
                'nick_returned_count' => (int) $stat->nick_returned_count,
                'nick_returned_amount' => (float) $stat->nick_returned_amount,
                'nick_available_count' => (int) $stat->nick_available_count,
                'nick_available_value' => (float) $stat->nick_available_value,

                // Service stats
                'service_total_count' => (int) $stat->service_total_count,
                'service_total_revenue' => (float) $stat->service_total_revenue,
                'service_completed_count' => (int) $stat->service_completed_count,
                'service_completed_revenue' => (float) $stat->service_completed_revenue,
                'service_rejected_count' => (int) $stat->service_rejected_count,
                'service_rejected_revenue' => (float) $stat->service_rejected_revenue,
                'service_pending_count' => (int) $stat->service_pending_count,
                'service_approved_count' => (int) $stat->service_approved_count,

                'total_revenue' => (float) ($stat->nick_sold_revenue + $stat->service_completed_revenue),
            ];
        });

        // Calculate seller total
        $sellerTotal = [
            // Nick stats
            'nick_total_count' => $stats->sum('nick_total_count'),
            'nick_total_revenue' => $stats->sum('nick_total_revenue'),
            'nick_total_commission' => $stats->sum('nick_total_commission'),

            // Nick theo status
            'nick_sold_count' => $stats->sum('nick_sold_count'),
            'nick_sold_revenue' => $stats->sum('nick_sold_revenue'),
            'nick_deleted_count' => $stats->sum('nick_deleted_count'),
            'nick_deleted_amount' => $stats->sum('nick_deleted_amount'),
            'nick_returned_count' => $stats->sum('nick_returned_count'),
            'nick_returned_amount' => $stats->sum('nick_returned_amount'),
            'nick_available_count' => $stats->sum('nick_available_count'),
            'nick_available_value' => $stats->sum('nick_available_value'),

            // Service stats
            'service_total_count' => $stats->sum('service_total_count'),
            'service_total_revenue' => $stats->sum('service_total_revenue'),
            'service_completed_count' => $stats->sum('service_completed_count'),
            'service_completed_revenue' => $stats->sum('service_completed_revenue'),
            'service_rejected_count' => $stats->sum('service_rejected_count'),
            'service_rejected_revenue' => $stats->sum('service_rejected_revenue'),
            'service_pending_count' => $stats->sum('service_pending_count'),
            'service_approved_count' => $stats->sum('service_approved_count'),
        ];
        $sellerTotal['total_revenue'] = $sellerTotal['nick_sold_revenue'] + $sellerTotal['service_completed_revenue'];

        return [
            'categories' => $categories->toArray(),
            'seller_total' => $sellerTotal,
        ];
    }

    private function getAvailableDates(): array
    {
        return DB::table('seller_category_stats')
            ->select('stat_date')
            ->distinct()
            ->orderBy('stat_date', 'desc')
            ->limit(30) // Last 30 dates
            ->pluck('stat_date')
            ->toArray();
    }
}
