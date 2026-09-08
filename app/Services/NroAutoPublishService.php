<?php
namespace App\Services;

use App\Helpers\AccountEncrypt;
use App\Models\Category;
use App\Models\Nick;
use App\Models\NroAccount;
use App\Models\NroAccountSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NroAutoPublishService
{
    public function __construct(private NroNickAttributeService $attributes) {}

    public function validateSelections(array $preview, array $selections): void
    {
        $fields = collect($preview['fields'])->keyBy('id');
        foreach ($selections as $id => $option) {
            $field = $fields->get($id);
            if (!$field || ($option !== null && $option !== '' && !collect($field['options'])->contains('id', (int) $option))) {
                throw ValidationException::withMessages(['attributeSelections' => 'Thuộc tính hoặc giá trị đã chọn không còn thuộc danh mục. Chọn lại danh mục và thuộc tính.']);
            }
        }
    }

    public function attachImages(NroAccount $account, Nick $nick): void
    {
        $imageUrl = $account->publish_config['imageUrl'] ?? null;
        if (!$nick->image && $imageUrl) $nick->forceFill(['image' => $imageUrl])->save();
        // Persist source IDs on copied media so a retry cannot duplicate gallery entries.
        $existing = $nick->getMedia('images');
        foreach ($account->getMedia('listing-images') as $source) {
            if ($existing->contains(fn ($media) => (int) $media->getCustomProperty('nro_source_media_id') === $source->id)) continue;
            $media = $source->copy($nick, 'images');
            $media->setCustomProperty('nro_source_media_id', $source->id)->save();
            if (!$nick->image) $nick->forceFill(['image' => $media->getUrl()])->save();
        }
    }

    /** Worker completion already holds the account lock; the savepoint isolates publication errors from snapshot ingestion. */
    public function publish(int $accountId): void
    {
        try {
            DB::transaction(function () use ($accountId) {
                $account = NroAccount::whereKey($accountId)->lockForUpdate()->firstOrFail();
                if (!$account->auto_publish || $account->usage_type !== 'nick') return;
                NroShopService::require($account->status === 'active', 'Acc đã bán hoặc ngừng hoạt động.');
                $owner = User::find($account->user_id);
                NroShopService::require($owner && !$owner->isLocked() && ($owner->can('nicks.create') || $owner->can('nicks.manage')), 'Người đăng không còn quyền đăng nick hoặc đã bị khóa.');
                $config = $account->publish_config ?? [];
                $category = Category::find($config['categoryId'] ?? 0);
                NroShopService::require($category && $category->status === 'active' && $category->template === 'default'
                    && ($owner->canViewAllAdminData() || $owner->categories()->where('categories.id', $category->id)->wherePivot('can_post', true)->exists()), 'Danh mục đã bị ẩn hoặc người đăng không còn quyền đăng vào danh mục.');
                NroShopService::require(($config['price'] ?? 0) >= 1, 'Cần bổ sung giá bán.');
                $snapshot = NroAccountSnapshot::where('account_id', $accountId)->find($account->latest_snapshot_id);
                NroShopService::require($snapshot && collect(['bag', 'chest', 'equipped'])->every(fn ($key) => ($snapshot->completeness_json[$key] ?? false) === true), 'Chưa lấy đủ hành trang, rương và trang bị. Yêu cầu lấy lại dữ liệu.');
                NroShopService::require(is_numeric(data_get($snapshot->data_json, 'character.power')) && in_array(data_get($snapshot->data_json, 'character.gender'), [0, 1, 2], true), 'Snapshot thiếu sức mạnh hoặc hành tinh. Yêu cầu lấy lại dữ liệu.');
                NroShopService::require($snapshot->captured_at->between(now()->subMinutes(15), now()->addMinutes(5)), 'Snapshot quá cũ. Yêu cầu lấy lại dữ liệu trước khi đăng.');
                $nick = Nick::withoutUserOwnedScope()->where('game_account_id', $accountId)->lockForUpdate()->first() ?? new Nick;
                NroShopService::require(!$nick->exists || ($nick->user_id === $account->user_id && $nick->status === 'not_sold'), 'Tin liên kết đã bán hoặc đã ngừng bán, không tự mở bán lại.');
                $preview = $this->attributes->preview($account, $category);
                $selections = $config['attributeSelections'] ?? [];
                $this->validateSelections($preview, $selections);
                // Explicit choices win; otherwise use only a unique match from the snapshot.
                foreach ($preview['fields'] as $field) {
                    if (!isset($selections[$field['id']])) $selections[$field['id']] = $field['selectedId'];
                }
                $nick->forceFill(['game_account_id' => $accountId, 'snapshot_id' => $snapshot->id, 'user_id' => $account->user_id,
                    'category_id' => $category->id, 'account_name' => $account->account_name,
                    'account_password' => AccountEncrypt::encrypt($account->game_password), 'price' => $config['price'],
                    'description' => $config['description'] ?? '', 'status' => 'not_sold', 'listing_type' => $nick->listing_type ?? 'normal']);
                $nick->save();
                $this->attributes->sync($nick, $preview, $selections);
                $this->attachImages($account, $nick);
                $account->update(['publish_status' => 'published', 'publish_error' => null]);
            });
        } catch (ValidationException $e) {
            NroAccount::whereKey($accountId)->update(['publish_status' => 'needs_attention', 'publish_error' => collect($e->errors())->flatten()->first()]);
        } catch (\Throwable $e) {
            report($e);
            NroAccount::whereKey($accountId)->update(['publish_status' => 'publish_failed', 'publish_error' => 'Đã lưu snapshot nhưng chưa đăng được tin. Mở Đăng bán để kiểm tra và thử lại.']);
        }
    }
}
