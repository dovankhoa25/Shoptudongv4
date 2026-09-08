<?php
namespace App\Services;

use App\Models\Category;
use App\Models\NroAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class NroAccountRegistration
{
    public function validate(User $user, array $input): array
    {
        abort_unless($user->can('nro-accounts.manage'), 403);
        $v = Validator::make($input, [
            'username' => 'required|string|max:141', 'password' => 'required|string|max:64',
            'serverId' => 'required|integer|exists:servers,id', 'serverGameId' => 'required|integer|exists:server_game_login,id', 'usageType' => 'required|in:nick,warehouse',
            'categoryId' => 'exclude_unless:usageType,nick|required|integer|exists:categories,id',
            'price' => 'exclude_unless:usageType,nick|required|integer|min:1|max:9999999999',
            'description' => 'exclude_unless:usageType,nick|nullable|string|max:10000',
            'imageUrl' => 'exclude_unless:usageType,nick|nullable|url:http,https|max:2048',
            'attributeSelections' => 'exclude_unless:usageType,nick|sometimes|array|max:100', 'attributeSelections.*' => 'nullable|integer',
            'images' => 'exclude_unless:usageType,nick|sometimes|array|max:8', 'images.*' => 'image|mimes:jpg,jpeg,png,webp|max:5120',
        ])->validate();
        $v['username'] = trim($v['username']);
        NroShopService::require($v['username'] !== '', 'Tài khoản không được để trống.');
        if ($v['usageType'] === 'nick') {
            abort_unless($user->can('nicks.create') || $user->can('nicks.manage'), 403);
            $category = Category::findOrFail($v['categoryId']);
            abort_unless($category->template === 'default' && $category->status === 'active' && ($user->canViewAllAdminData() || $user->categories()->where('categories.id', $category->id)->wherePivot('can_post', true)->exists()), 403);
            $preview = app(NroNickAttributeService::class)->preview(new NroAccount(['server_id' => $v['serverId']]), $category);
            app(NroAutoPublishService::class)->validateSelections($preview, $v['attributeSelections'] ?? []);
            foreach ($preview['fields'] as $field) {
                if ($field['source'] === 'default' && !isset($v['attributeSelections'][$field['id']])) $v['attributeSelections'][$field['id']] = $field['selectedId'];
            }
        }
        return $v;
    }

    public function available(string $username, int $serverGameId, ?int $exceptId = null): void
    {
        DB::table('server_game_login')->where('id', $serverGameId)->lockForUpdate()->firstOrFail();
        $duplicates = NroAccount::withTrashed()->where('account_name', $username)->where(function ($q) use ($serverGameId) {
            $q->where('server_game_id', $serverGameId)->orWhere(fn ($legacy) => $legacy->whereNull('server_game_id')->where('server', 'login'.$serverGameId));
        })->where('status', '!=', 'sold');
        if ($exceptId !== null) $duplicates->where('id', '!=', $exceptId);
        NroShopService::require(!$duplicates->lockForUpdate()->first(), 'Acc trên server này vẫn đang được quản lý hoặc chưa bán. Chỉ thêm lại sau khi acc đã bán.');
    }

    public function create(User $user, array $input): NroAccount
    {
        $v = $this->validate($user, $input);
        return DB::transaction(function () use ($user, $v) {
            $this->available($v['username'], (int) $v['serverGameId']);
            $account = NroAccount::create(['user_id' => $user->id, 'account_name' => $v['username'], 'game_password' => $v['password'],
                'server' => 'login'.$v['serverGameId'], 'server_index' => 0, 'server_id' => $v['serverId'], 'server_game_id' => $v['serverGameId'], 'usage_type' => $v['usageType'], 'status' => 'active',
                'auto_publish' => $v['usageType'] === 'nick', 'publish_status' => $v['usageType'] === 'nick' ? 'waiting_snapshot' : null,
                'publish_config' => $v['usageType'] === 'nick' ? ['categoryId' => (int) $v['categoryId'], 'price' => (int) $v['price'], 'description' => $v['description'] ?? '', 'imageUrl' => $v['imageUrl'] ?? null,
                    'attributeSelections' => collect($v['attributeSelections'] ?? [])->map(fn ($value) => $value === null ? null : (int) $value)->all()] : null]);
            foreach ($v['images'] ?? [] as $image) $account->addMedia($image)->toMediaCollection('listing-images');
            // Both nick and warehouse imports get their initial data without another click.
            DB::table('nro_worker_jobs')->insert(['account_id' => $account->id, 'type' => 'snapshot', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
            return $account;
        });
    }
}
