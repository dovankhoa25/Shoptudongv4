<?php

namespace App\Console\Commands;

use App\Models\Nick;
use App\Models\NickAttribute;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanOldNicksCommand extends Command
{
    protected $signature = 'nicks:clean-old {--days=30 : Số ngày để xác định nick cũ}';

    protected $description = 'Dọn dẹp nick_attributes và ảnh của các nick đã sold/deleted/return sau 7 ngày';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoffDate = now()->subDays($days);

        $this->info("🧹 Bắt đầu dọn dẹp dữ liệu...");
        $this->info("📅 Dọn dẹp nick có status deleted/sold/return cũ hơn {$days} ngày");
        $this->line("   Trước ngày: {$cutoffDate->format('d/m/Y H:i:s')}");
        $this->newLine();

        // Tìm các nick cần dọn dẹp
        $nicks = Nick::whereIn('status', ['sold', 'return'])
            ->where('updated_at', '<', $cutoffDate)
            ->get();

        if ($nicks->isEmpty()) {
            $this->info('✅ Không có nick nào cần dọn dẹp.');
            return Command::SUCCESS;
        }

        $this->info("🔍 Tìm thấy {$nicks->count()} nick cần dọn dẹp:");

        $totalAttributes = 0;
        $totalMediaFiles = 0;
        $totalImageFiles = 0;

        foreach ($nicks as $nick) {
            $this->line("📦 Nick #{$nick->id} - {$nick->account_name} ({$nick->status})");

            // Đếm và xóa nick_attributes
            $attributesCount = NickAttribute::where('nick_id', $nick->id)->count();
            if ($attributesCount > 0) {
                $this->line("   └─ Xóa {$attributesCount} nick_attributes");
                NickAttribute::where('nick_id', $nick->id)->delete();
                $totalAttributes += $attributesCount;
            }

            // Xóa media collection 'images' CẢ FILES VẬT LÝ
            if (method_exists($nick, 'getMedia')) {
                $mediaItems = $nick->getMedia('images');
                $mediaCount = $mediaItems->count();

                if ($mediaCount > 0) {
                    $this->line("   └─ Xóa {$mediaCount} media files");

                    // Xóa từng media item để đảm bảo xóa cả file vật lý
                    foreach ($mediaItems as $media) {
                        $media->delete(); // Xóa cả database record và file vật lý
                    }

                    $totalMediaFiles += $mediaCount;
                }
            }

            // Xóa ảnh từ trường image
            if ($nick->image && Storage::exists($nick->image)) {
                $this->line("   └─ Xóa image: {$nick->image}");
                Storage::delete($nick->image);
                $nick->update(['image' => null]);
                $totalImageFiles++;
            }

            // Kiểm tra nếu không có gì để xóa
            if ($attributesCount == 0 && $mediaCount == 0 && !$nick->image) {
                $this->line("   └─ Không có dữ liệu cần xóa");
            }
        }

        $this->newLine();
        $this->info("📊 Kết quả dọn dẹp:");
        $this->info("✅ Đã dọn dẹp {$nicks->count()} nick(s)");
        $this->info("✅ Đã xóa {$totalAttributes} nick_attribute(s)");
        $this->info("✅ Đã xóa {$totalMediaFiles} media file(s)");
        $this->info("✅ Đã xóa {$totalImageFiles} image file(s)");
        $this->info("📝 Nick records được giữ lại");
        $this->info("🎉 Hoàn thành!");

        return Command::SUCCESS;
    }
}
