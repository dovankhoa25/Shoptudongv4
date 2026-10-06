<?php

namespace App\Services;

use App\Enums\Permission;
use App\Helpers\AccountEncrypt;
use App\Jobs\ProcessNickMedia;
use App\Models\Category;
use App\Models\Nick;
use App\Models\NickAttribute;
use App\Models\NickMediaFile;
use App\Models\NickPublication;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class NickPublicationService
{
    public function upload(User $user, UploadedFile $image): NickMediaFile
    {
        $id = (string) Str::uuid();
        // Reserve quota before writing, so concurrent uploads cannot exceed the limit.
        // Determine the path up front so even an interrupted write can be cleaned up.
        $path = $user->id.'/'.$id.'.'.$image->extension();
        $file = DB::transaction(function () use ($user, $image, $id, $path) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (NickMediaFile::where('user_id', $user->id)->whereNull('publication_id')->count() >= 200) {
                throw ValidationException::withMessages(['image' => 'Đã có 200 ảnh tạm. Hãy đăng hoặc xóa ảnh chưa dùng trước khi tải thêm.']);
            }

            return NickMediaFile::create([
                'id' => $id, 'user_id' => $user->id, 'path' => $path,
                'name' => Str::limit(basename($image->getClientOriginalName()), 240, ''),
                'status' => 'uploading',
            ]);
        });
        try {
            // No user-row lock or transaction is held during filesystem I/O.
            if ($image->storeAs(dirname($path), basename($path), 'nick-staging') !== $path) {
                throw new \RuntimeException('Could not store temporary image.');
            }
            $updated = NickMediaFile::whereKey($id)->whereNull('publication_id')->where('status', 'uploading')
                ->update(['status' => 'uploaded']);
            if ($updated !== 1) {
                throw ValidationException::withMessages(['image' => 'Lượt tải ảnh đã bị hủy hoặc hết hạn. Vui lòng thử lại.']);
            }
            $file->status = 'uploaded';

            return $file;
        } catch (Throwable $e) {
            Storage::disk('nick-staging')->delete($path);
            NickMediaFile::whereKey($id)->whereNull('publication_id')->delete();
            throw $e;
        }
    }

    public function submit(User $user, array $data): NickPublication
    {
        $hash = hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), (string) config('app.key'));
        $publication = DB::transaction(function () use ($user, $data, $hash) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = NickPublication::where('uuid', $data['request_id'])->first();
            if ($existing) {
                abort_unless((int) $existing->user_id === (int) $user->id, 404);
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Yêu cầu này đã được lưu với nội dung khác. Hãy kiểm tra danh sách xử lý.');

                return $existing;
            }
            $category = Category::findOrFail($data['category_id']);
            $this->assertCanPost($user, $category);
            $this->attributes($category, $data['attribute_cache_json']);
            $this->assertNoLiveDuplicate($user->id, $category->id, $data['account_name']);
            $key = hash('sha256', $user->id.'|'.$category->id.'|'.$data['account_name']);
            if (NickPublication::where('active_key', $key)->exists()) {
                throw ValidationException::withMessages(['account_name' => 'Nick này đang có bản đăng chờ xử lý. Hãy thử lại hoặc hủy bản đó trong danh sách xử lý ảnh.']);
            }
            $uploads = NickMediaFile::whereIn('id', $data['upload_ids'])
                ->where('user_id', $user->id)->whereNull('publication_id')->where('status', 'uploaded')
                ->where('created_at', '>', now()->subDay())->lockForUpdate()->get()->keyBy('id');
            if ($uploads->count() !== count($data['upload_ids'])) {
                throw ValidationException::withMessages(['upload_ids' => 'Ảnh tạm không hợp lệ, đã được dùng hoặc hết hạn. Vui lòng chọn tải lại ảnh.']);
            }
            $publication = NickPublication::create([
                'uuid' => $data['request_id'], 'user_id' => $user->id, 'category_id' => $category->id,
                'account_name' => $data['account_name'], 'payload' => $data,
                'request_hash' => $hash, 'active_key' => $key, 'status' => 'pending',
            ]);
            foreach ($data['upload_ids'] as $position => $id) {
                $uploads[$id]->update(['publication_id' => $publication->id, 'position' => $position, 'status' => 'pending']);
            }
            $urlRows = [];
            $now = now();
            foreach ($data['urls'] as $position => $url) {
                $urlRows[] = [
                    'id' => (string) Str::uuid(), 'user_id' => $user->id, 'publication_id' => $publication->id,
                    'position' => $position, 'source_url' => $url, 'name' => 'Ảnh URL '.($position + 1), 'status' => 'pending',
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
            if ($urlRows !== []) {
                NickMediaFile::insert($urlRows);
            }

            return $publication;
        });
        // DB rows are a durable dispatch log. A Redis outage must not lose an accepted draft.
        $this->dispatchPending($publication->id);
        $this->finalize($publication->id);

        return $publication->fresh();
    }

    public function assertCanPost(User $user, Category $category): void
    {
        abort_if($user->isLocked(), 403, 'Tài khoản đăng nick đã bị khóa.');
        abort_unless($user->hasRole('super-admin') || $user->hasAnyPermission([
            Permission::NicksCreate->value, Permission::NicksManage->value,
        ]), 403, 'Tài khoản không còn quyền đăng nick.');
        abort_unless($category->template === 'default' && ($user->canViewAllAdminData()
            || ($category->status === 'active' && $user->categories()->where('categories.id', $category->id)
                ->wherePivot('can_post', true)->exists())), 403, 'Bạn không có quyền đăng trong danh mục này.');
    }

    private function attributes(Category $category, array $selected): array
    {
        $attributes = $category->attributes()->with('options')->get()->keyBy('id');
        if ($attributes->count() !== count($selected)) {
            throw ValidationException::withMessages(['attribute_cache_json' => 'Hãy chọn đủ thuộc tính của danh mục.']);
        }
        $cache = [];
        $rows = [];
        foreach ($selected as $item) {
            $attribute = $attributes->get($item['attribute_id']);
            $option = $attribute?->options->firstWhere('id', $item['option_id']);
            if (! $option) {
                throw ValidationException::withMessages(['attribute_cache_json' => 'Thuộc tính hoặc tùy chọn không thuộc danh mục đã chọn.']);
            }
            $cache[$attribute->name] = $option->option_value;
            $rows[] = ['attribute_id' => $attribute->id, 'attribute_option_id' => $option->id];
        }

        return [$cache, $rows];
    }

    private function assertNoLiveDuplicate(int $userId, int $categoryId, string $account): void
    {
        if (Nick::withoutUserOwnedScope()->where('user_id', $userId)->where('category_id', $categoryId)
            ->where('account_name', $account)->where('status', 'not_sold')->exists()) {
            throw ValidationException::withMessages(['account_name' => 'Nick đã tồn tại trong danh sách đang bán.']);
        }
    }

    public function dispatchPending(?int $publicationId = null, ?string $fileId = null): void
    {
        $queuedAt = now();
        $files = DB::transaction(function () use ($publicationId, $fileId, $queuedAt) {
            $files = NickMediaFile::query()->select(['id', 'available_at'])->where('status', 'pending')
                ->whereIn('publication_id', NickPublication::where('status', 'pending')->select('id'))
                ->when($publicationId, fn ($q) => $q->where('publication_id', $publicationId))
                ->when($fileId, fn ($q) => $q->whereKey($fileId))
                ->where(fn ($q) => $q->whereNull('queued_at')->orWhere('queued_at', '<', now()->subMinutes(5)))
                ->orderBy('created_at')->limit(200)->lockForUpdate()->get();
            if ($files->isNotEmpty()) {
                NickMediaFile::whereIn('id', $files->modelKeys())->update(['queued_at' => $queuedAt]);
            }

            return $files;
        });
        // Claim a bounded batch in SQL, then contact Redis after releasing DB locks.
        foreach ($files as $index => $file) {
            try {
                ProcessNickMedia::dispatch($file->id)->delay($file->available_at);
            } catch (Throwable $e) {
                // Keep earlier successful dispatches; release this and the unattempted tail.
                NickMediaFile::whereIn('id', $files->slice($index)->modelKeys())
                    ->where('status', 'pending')->where('queued_at', $queuedAt)->update(['queued_at' => null]);
                Log::warning('Nick media dispatch unavailable', ['file_id' => $file->id, 'exception' => $e::class]);
                break; // Cron will dispatch these durable rows when Redis recovers.
            }
        }
    }

    public function processFile(string $id): void
    {
        $file = NickMediaFile::find($id);
        if (! $file || ! $file->publication_id) {
            return;
        }
        $publicationId = $file->publication_id;
        $token = (string) Str::uuid();
        $claimed = DB::transaction(function () use ($id, $publicationId, $token) {
            $publication = NickPublication::whereKey($publicationId)->lockForUpdate()->first();
            $file = NickMediaFile::whereKey($id)->lockForUpdate()->first();
            if (! $publication || $publication->status !== 'pending' || ! $file || $file->status !== 'pending'
                || $file->available_at?->isFuture()) {
                return null;
            }
            $file->update(['status' => 'processing', 'claim_token' => $token, 'processing_at' => now(),
                'queued_at' => null, 'attempts' => $file->attempts + 1, 'error' => null]);

            return $file;
        });
        if (! $claimed) {
            return;
        }
        $download = null;
        $retry = false;
        try {
            // Remote network I/O is deliberately outside the DB transaction.
            $source = $claimed->path
                ? Storage::disk('nick-staging')->path($claimed->path)
                : ($download = app(SafeImageDownloader::class)->getTempFile($claimed->source_url));
            DB::transaction(function () use ($id, $publicationId, $token, $source) {
                $publication = NickPublication::whereKey($publicationId)->lockForUpdate()->firstOrFail();
                $file = NickMediaFile::whereKey($id)->lockForUpdate()->firstOrFail();
                if ($publication->status !== 'pending' || $file->claim_token !== $token) {
                    return;
                }
                $mime = mime_content_type($source);
                $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
                    'image/webp' => 'webp', 'image/avif' => 'avif'][$mime] ?? null;
                if (! $extension || @getimagesize($source) === false) {
                    throw ValidationException::withMessages(['image' => 'Tệp không phải ảnh hợp lệ.']);
                }
                $media = $publication->addMedia($source)->preservingOriginal()
                    ->usingFileName($file->id.'.'.$extension)->usingName($file->name)
                    ->withProperties(['order_column' => $file->position + 1])
                    ->toMediaCollection('images');
                $file->update(['status' => 'ready', 'media_id' => $media->id, 'claim_token' => null,
                    'processing_at' => null, 'error' => null]);
            });
        } catch (Throwable $e) {
            $updated = NickMediaFile::whereKey($id)->where('claim_token', $token)->update([
                'status' => $claimed->attempts >= 3 ? 'failed' : 'pending',
                'error' => $e instanceof ValidationException ? 'Ảnh không hợp lệ hoặc nguồn URL không cho phép tải.' : 'Không tải/lưu được ảnh. Có thể thử lại.',
                'claim_token' => null, 'processing_at' => null, 'queued_at' => null,
                'available_at' => now()->addSeconds(15 * $claimed->attempts),
            ]);
            $retry = $updated === 1 && $claimed->attempts < 3;
            Log::warning('Nick media processing failed', ['file_id' => $id, 'exception' => $e::class]);
        } finally {
            if ($download && is_file($download)) {
                @unlink($download);
            }
        }
        $this->finalize($publicationId);
        if ($retry) {
            $this->dispatchPending($publicationId, $id);
        }
    }

    public function finalize(int $id): void
    {
        // Most calls are intermediate completions: do one indexed existence check,
        // without locking the user or loading every sibling image.
        if (NickMediaFile::where('publication_id', $id)->whereIn('status', ['pending', 'processing'])->exists()) {
            return;
        }
        $publication = NickPublication::find($id, ['id', 'user_id', 'status']);
        if (! $publication || $publication->status !== 'pending') {
            return;
        }
        try {
            DB::transaction(function () use ($id, $publication) {
                $user = User::whereKey($publication->user_id)->lockForUpdate()->firstOrFail();
                $publication = NickPublication::whereKey($id)->lockForUpdate()->firstOrFail();
                if ($publication->status !== 'pending') {
                    return;
                }
                $files = $publication->files()->lockForUpdate()->get();
                if ($files->contains(fn ($file) => in_array($file->status, ['pending', 'processing'], true))) {
                    return;
                }
                if ($files->contains('status', 'failed')) {
                    $publication->update(['status' => 'failed', 'error' => 'Có ảnh xử lý lỗi. Thử lại để tiếp tục những ảnh chưa hoàn tất.']);

                    return;
                }
                $data = $publication->payload;
                $category = Category::findOrFail($publication->category_id);
                $this->assertCanPost($user, $category);
                [$cache, $rows] = $this->attributes($category, $data['attribute_cache_json']);
                $this->assertNoLiveDuplicate($user->id, $category->id, $data['account_name']);
                $media = $publication->getMedia('images')->keyBy('id');
                foreach ($files as $file) {
                    if (! $media->has($file->media_id)
                        || ! Storage::disk($media[$file->media_id]->disk)->exists($media[$file->media_id]->getPathRelativeToRoot())) {
                        if ($media->has($file->media_id)) {
                            $media[$file->media_id]->delete();
                        }
                        $file->update(['status' => 'failed', 'media_id' => null, 'error' => 'Ảnh đã xử lý không còn trên storage. Hãy thử lại.']);
                        $publication->update(['status' => 'failed', 'error' => 'Thiếu ảnh trên storage. Thử lại để khôi phục ảnh trước khi mở bán.']);

                        return;
                    }
                }
                $nick = Nick::create([
                    'user_id' => $user->id, 'category_id' => $category->id,
                    'account_name' => $data['account_name'], 'account_password' => AccountEncrypt::encrypt($data['account_password']),
                    'price' => $data['price'], 'description' => $data['description'] ?? null,
                    'listing_type' => $data['listing_type'], 'status' => 'not_sold',
                    'attribute_cache_json' => json_encode($cache),
                    'image' => $files->isEmpty() ? null : $media[$files->first()->media_id]->getUrl(),
                ]);
                $now = now();
                if ($rows !== []) {
                    NickAttribute::insert(array_map(fn ($row) => $row + [
                        'nick_id' => $nick->id, 'created_at' => $now, 'updated_at' => $now,
                    ], $rows));
                }
                // Default Spatie paths depend on media ID, so ownership can change atomically
                // without copying files. Refuse an incompatible custom path generator.
                foreach ($files as $file) {
                    $image = $media[$file->media_id];
                    $path = $image->getPathRelativeToRoot();
                    $image->model_type = $nick->getMorphClass();
                    $image->model_id = $nick->id;
                    $image->unsetRelation('model');
                    if ($path !== $image->getPathRelativeToRoot()) {
                        throw new \RuntimeException('Incompatible media path generator.');
                    }
                }
                if ($files->isNotEmpty()) {
                    $updated = $publication->media()->whereIn('id', $files->pluck('media_id'))
                        ->where('collection_name', 'images')->update(['model_type' => $nick->getMorphClass(), 'model_id' => $nick->id]);
                    if ($updated !== $files->count()) {
                        throw new \RuntimeException('Prepared media ownership changed.');
                    }
                }
                $publication->update(['status' => 'completed', 'nick_id' => $nick->id, 'payload' => [], 'active_key' => null, 'error' => null]);
            });
        } catch (Throwable $e) {
            // A post-commit cache failure must not turn a published nick into a failed draft.
            NickPublication::whereKey($id)->where('status', 'pending')->update([
                'status' => 'failed', 'error' => $e instanceof ValidationException
                    ? Str::limit(collect($e->errors())->flatten()->first(), 490)
                    : ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException ? $e->getMessage() : 'Chưa thể mở bán nick. Hãy thử lại hoặc liên hệ quản trị viên.'),
            ]);
            Log::warning('Nick publication finalization failed', ['publication_id' => $id, 'exception' => $e::class]);
        }
    }

    public function retry(NickPublication $publication): void
    {
        DB::transaction(function () use ($publication) {
            $publication = NickPublication::whereKey($publication->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($publication->status, ['failed', 'pending'], true), 409, 'Bản đăng đã hoàn tất hoặc đã hủy.');
            $publication->files()->where('status', 'failed')->update(['status' => 'pending', 'attempts' => 0,
                'queued_at' => null, 'available_at' => null, 'error' => null]);
            $publication->update(['status' => 'pending', 'error' => null]);
        });
        $this->dispatchPending($publication->id);
        $this->finalize($publication->id);
    }

    public function cancel(NickPublication $publication): void
    {
        DB::transaction(function () use ($publication) {
            $publication = NickPublication::whereKey($publication->id)->lockForUpdate()->firstOrFail();
            abort_if($publication->status === 'completed', 409, 'Nick đã mở bán. Hãy quản lý tại danh sách nick.');
            $publication->update(['status' => 'cancelled', 'active_key' => null, 'payload' => [], 'error' => null]);
            $publication->files()->update(['claim_token' => null, 'processing_at' => null]);
        });
        $this->cleanPublication($publication->fresh());
    }

    public function recover(): void
    {
        // Jobs killed by host/worker timeout are recovered from SQL, even if Redis lost them.
        foreach (NickMediaFile::where('status', 'processing')->where('processing_at', '<', now()->subMinutes(3))->limit(200)->get() as $file) {
            NickMediaFile::whereKey($file->id)->where('claim_token', $file->claim_token)->update([
                'status' => $file->attempts >= 3 ? 'failed' : 'pending', 'claim_token' => null,
                'processing_at' => null, 'queued_at' => null, 'available_at' => null,
                'error' => 'Lượt xử lý bị gián đoạn hoặc quá thời gian.',
            ]);
        }
        foreach (NickPublication::where('status', 'pending')
            ->whereDoesntHave('files', fn ($q) => $q->whereIn('status', ['pending', 'processing']))
            ->orderBy('updated_at')->limit(200)->pluck('id') as $id) {
            $this->finalize($id);
        }
        $this->dispatchPending();
        foreach (NickMediaFile::whereNull('publication_id')->where('created_at', '<=', now()->subDay())->limit(200)->get() as $file) {
            DB::transaction(function () use ($file) {
                $file = NickMediaFile::whereKey($file->id)->lockForUpdate()->first();
                if (! $file || $file->publication_id) {
                    return;
                }
                if ($file->path) {
                    Storage::disk('nick-staging')->delete($file->path);
                }
                $file->delete();
            });
        }
        foreach (NickPublication::whereIn('status', ['completed', 'cancelled'])
            ->whereHas('files', fn ($q) => $q->whereNotNull('path')->orWhereNotNull('source_url'))->limit(100)->get() as $publication) {
            $this->cleanPublication($publication);
        }
    }

    private function cleanPublication(NickPublication $publication): void
    {
        $files = $publication->files()->where(fn ($q) => $q->whereNotNull('path')->orWhereNotNull('source_url'))
            ->get(['id', 'path']);
        foreach ($files as $file) {
            if ($file->path) {
                Storage::disk('nick-staging')->delete($file->path);
            }
        }
        if ($files->isNotEmpty()) {
            NickMediaFile::whereIn('id', $files->modelKeys())->update(['path' => null, 'source_url' => null]);
        }
        // Only draft media: completed images now belong to Nick and are preserved.
        if ($publication->status === 'cancelled') {
            $publication->clearMediaCollection('images');
        }
    }
}
