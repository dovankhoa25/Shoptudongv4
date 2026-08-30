<?php
// app/Console/Commands/ComputeSellerStats.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Exception;

class ComputeSellerStats extends Command
{
    protected $signature = 'stats:compute';
    protected $description = 'Tính toán thống kê bán hàng cho từng seller theo category';

    public function handle()
    {
        try {
            $this->info("Bắt đầu tính toán thống kê theo category...");

            $this->computeStats();

            $this->info("Hoàn thành tính toán thống kê theo category");
        } catch (Exception $e) {
            $this->error("Lỗi khi tính toán thống kê: " . $e->getMessage());
            return 1;
        }

        return 0;
    }

    private function computeStats()
    {
        $today = now()->toDateString();

        // Xóa dữ liệu cũ của ngày hôm nay
        DB::table('seller_category_stats')->where('stat_date', $today)->delete();

        // Lấy danh sách sellers (users có role seller/ctv)
        $sellerIds = $this->getSellerIds();
        $this->info("Tìm thấy " . count($sellerIds) . " sellers/CTV");

        if (empty($sellerIds)) {
            $this->info("Không có seller nào để xử lý");
            return;
        }

        // Tính nick stats theo category (từ bảng nicks)
        $nickStats = $this->getNickStatsByCategory($sellerIds);
        $this->info("Tìm thấy " . count($nickStats) . " (seller, category) có nicks");

        // Tính service orders stats theo category  
        $serviceStats = $this->getServiceStatsByCategory($sellerIds);
        $this->info("Tìm thấy " . count($serviceStats) . " (seller, category) có service orders");

        // Merge dữ liệu
        $allStats = $this->mergeStats($nickStats, $serviceStats, $today);

        if (empty($allStats)) {
            $this->info("Không có dữ liệu để xử lý");
            return;
        }

        // Insert dữ liệu
        DB::table('seller_category_stats')->insert($allStats);

        $this->info("Đã xử lý " . count($allStats) . " records (seller x category)");
    }

    private function getSellerIds(): array
    {
        // Lấy tất cả users có role (không phải guest)
        return DB::table('users')
            ->join('model_has_roles', 'users.id', '=', 'model_has_roles.model_id')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', 'App\\Models\\User')
            ->distinct()
            ->pluck('users.id')
            ->toArray();
    }

    private function getNickStatsByCategory(array $sellerIds): array
    {
        // Thống kê từ bảng nicks (bao gồm cả chưa bán)
        return DB::table('nicks')
            ->select([
                'user_id as seller_id',
                'category_id',
                DB::raw('COUNT(*) as nick_total_count'),
                DB::raw('SUM(price) as nick_total_revenue'),

                // Thống kê theo status
                DB::raw("SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as nick_sold_count"),
                DB::raw("SUM(CASE WHEN status = 'sold' THEN price ELSE 0 END) as nick_sold_revenue"),
                DB::raw("SUM(CASE WHEN status = 'deleted' THEN 1 ELSE 0 END) as nick_deleted_count"),
                DB::raw("SUM(CASE WHEN status = 'deleted' THEN price ELSE 0 END) as nick_deleted_amount"),
                DB::raw("SUM(CASE WHEN status = 'return' THEN 1 ELSE 0 END) as nick_returned_count"),
                DB::raw("SUM(CASE WHEN status = 'return' THEN price ELSE 0 END) as nick_returned_amount"),
                DB::raw("SUM(CASE WHEN status = 'not_sold' THEN 1 ELSE 0 END) as nick_available_count"),
                DB::raw("SUM(CASE WHEN status = 'not_sold' THEN price ELSE 0 END) as nick_available_value"),

                // Tính commission từ nick_orders cho những nick đã bán
                DB::raw('(SELECT COALESCE(SUM(commission), 0) FROM nick_orders WHERE nick_orders.nick_id IN (
                    SELECT id FROM nicks n2 WHERE n2.user_id = nicks.user_id AND n2.category_id = nicks.category_id
                )) as nick_total_commission')
            ])
            ->whereIn('user_id', $sellerIds)
            ->groupBy('user_id', 'category_id')
            ->get()
            ->map(function ($row) {
                return [
                    'seller_id' => $row->seller_id,
                    'category_id' => $row->category_id,
                    'nick_total_count' => $row->nick_total_count,
                    'nick_total_revenue' => $row->nick_total_revenue ?? 0,
                    'nick_total_commission' => $row->nick_total_commission ?? 0,
                    'nick_sold_count' => $row->nick_sold_count,
                    'nick_sold_revenue' => $row->nick_sold_revenue ?? 0,
                    'nick_deleted_count' => $row->nick_deleted_count,
                    'nick_deleted_amount' => $row->nick_deleted_amount ?? 0,
                    'nick_returned_count' => $row->nick_returned_count,
                    'nick_returned_amount' => $row->nick_returned_amount ?? 0,
                    'nick_available_count' => $row->nick_available_count,
                    'nick_available_value' => $row->nick_available_value ?? 0,
                ];
            })
            ->toArray();
    }

    private function getServiceStatsByCategory(array $sellerIds): array
    {
        // Service orders có thể thuộc nhiều categories qua pivot table
        return DB::table('service_orders')
            ->join('category_service', 'service_orders.service_id', '=', 'category_service.service_id')
            ->select([
                'service_orders.user_id as seller_id',
                'category_service.category_id',
                DB::raw('COUNT(*) as service_total_count'),
                DB::raw('SUM(service_orders.service_price) as service_total_revenue'),

                // Thống kê theo status
                DB::raw("SUM(CASE WHEN service_orders.status = 'completed' THEN 1 ELSE 0 END) as service_completed_count"),
                DB::raw("SUM(CASE WHEN service_orders.status = 'completed' THEN service_orders.service_price ELSE 0 END) as service_completed_revenue"),
                DB::raw("SUM(CASE WHEN service_orders.status = 'rejected' THEN 1 ELSE 0 END) as service_rejected_count"),
                DB::raw("SUM(CASE WHEN service_orders.status = 'rejected' THEN service_orders.service_price ELSE 0 END) as service_rejected_revenue"),
                DB::raw("SUM(CASE WHEN service_orders.status = 'pending' THEN 1 ELSE 0 END) as service_pending_count"),
                DB::raw("SUM(CASE WHEN service_orders.status = 'approved' THEN 1 ELSE 0 END) as service_approved_count")
            ])
            ->whereIn('service_orders.user_id', $sellerIds)
            ->groupBy('service_orders.user_id', 'category_service.category_id')
            ->get()
            ->map(function ($row) {
                return [
                    'seller_id' => $row->seller_id,
                    'category_id' => $row->category_id,
                    'service_total_count' => $row->service_total_count,
                    'service_total_revenue' => $row->service_total_revenue ?? 0,
                    'service_completed_count' => $row->service_completed_count,
                    'service_completed_revenue' => $row->service_completed_revenue ?? 0,
                    'service_rejected_count' => $row->service_rejected_count,
                    'service_rejected_revenue' => $row->service_rejected_revenue ?? 0,
                    'service_pending_count' => $row->service_pending_count,
                    'service_approved_count' => $row->service_approved_count,
                ];
            })
            ->toArray();
    }

    private function mergeStats(array $nickStats, array $serviceStats, string $date): array
    {
        $merged = [];

        // Tạo key từ nick stats
        foreach ($nickStats as $nick) {
            $key = $nick['seller_id'] . '_' . $nick['category_id'];
            $merged[$key] = array_merge($nick, [
                'stat_date' => $date,
                'service_total_count' => 0,
                'service_total_revenue' => 0,
                'service_completed_count' => 0,
                'service_completed_revenue' => 0,
                'service_rejected_count' => 0,
                'service_rejected_revenue' => 0,
                'service_pending_count' => 0,
                'service_approved_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Merge service stats
        foreach ($serviceStats as $service) {
            $key = $service['seller_id'] . '_' . $service['category_id'];

            if (isset($merged[$key])) {
                // Đã có nick stats, merge thêm service stats
                $merged[$key]['service_total_count'] = $service['service_total_count'];
                $merged[$key]['service_total_revenue'] = $service['service_total_revenue'];
                $merged[$key]['service_completed_count'] = $service['service_completed_count'];
                $merged[$key]['service_completed_revenue'] = $service['service_completed_revenue'];
                $merged[$key]['service_rejected_count'] = $service['service_rejected_count'];
                $merged[$key]['service_rejected_revenue'] = $service['service_rejected_revenue'];
                $merged[$key]['service_pending_count'] = $service['service_pending_count'];
                $merged[$key]['service_approved_count'] = $service['service_approved_count'];
            } else {
                // Chỉ có service stats, tạo mới
                $merged[$key] = array_merge($service, [
                    'stat_date' => $date,
                    'nick_total_count' => 0,
                    'nick_total_revenue' => 0,
                    'nick_total_commission' => 0,
                    'nick_sold_count' => 0,
                    'nick_sold_revenue' => 0,
                    'nick_deleted_count' => 0,
                    'nick_deleted_amount' => 0,
                    'nick_returned_count' => 0,
                    'nick_returned_amount' => 0,
                    'nick_available_count' => 0,
                    'nick_available_value' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return array_values($merged);
    }
}
