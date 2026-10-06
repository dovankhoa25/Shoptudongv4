<?php

namespace Tests\Feature;

use App\Enums\Permission as AppPermission;
use App\Helpers\AccountEncrypt;
use App\Jobs\ProcessNickMedia;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Category;
use App\Models\GameType;
use App\Models\Nick;
use App\Models\NickMediaFile;
use App\Models\NickPublication;
use App\Models\User;
use App\Services\NickPublicationService;
use App\Services\SafeImageDownloader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NickPublicationTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Category $category;

    private NickPublicationService $service;

    private string $base = '/admin/games/accounts';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('nick-staging');
        Storage::fake('public');
        config(['media-library.disk_name' => 'public']);
        Permission::findOrCreate(AppPermission::NicksCreate->value, 'web');
        Permission::findOrCreate(AppPermission::NicksManage->value, 'web');
        Role::findOrCreate('ctv', 'web');
        $this->seller = User::factory()->create();
        $this->seller->assignRole('ctv')->givePermissionTo(AppPermission::NicksCreate->value);
        $game = GameType::create(['name' => 'Nick media game']);
        $this->category = Category::create(['game_type_id' => $game->id, 'name' => 'Nick media category',
            'template' => 'default', 'is_public' => true, 'status' => 'active']);
        $this->seller->categories()->attach($this->category->id, ['can_post' => true]);
        $this->actingAs($this->seller);
        $this->service = app(NickPublicationService::class);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'request_id' => (string) Str::uuid(), 'category_id' => $this->category->id,
            'account_name' => 'media-account', 'account_password' => 'private-password', 'price' => 100000,
            'description' => 'Nick test', 'listing_type' => 'normal', 'attribute_cache_json' => [],
            'upload_ids' => [], 'urls' => [],
        ], $overrides);
    }

    private function upload(string $name = 'screen.png'): string
    {
        return $this->postJson($this->base.'/media-uploads', ['image' => UploadedFile::fake()->image($name, 20, 20)])
            ->assertCreated()->json('id');
    }

    public static function imageBatchSizes(): array
    {
        return ['10 images' => [10], '50 images' => [50]];
    }

    #[DataProvider('imageBatchSizes')]
    public function test_image_processing_query_budget(int $count): void
    {
        $image = UploadedFile::fake()->image('remote.png', 20, 20);
        $this->mock(SafeImageDownloader::class)->shouldReceive('getTempFile')->times($count)
            ->andReturnUsing(function () use ($image) {
                $temp = tempnam(sys_get_temp_dir(), 'nick-media-budget-');
                copy($image->getPathname(), $temp);

                return $temp;
            });
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $publication = $this->service->submit($this->seller, $this->payload([
                'urls' => array_fill(0, $count, 'https://example.com/image.png'),
            ]));
            $submitQueries = DB::getQueryLog();
            $ids = $publication->files()->pluck('id');
            DB::flushQueryLog();
            foreach ($ids as $id) {
                $this->service->processFile($id);
            }
            $processingQueries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame('completed', $publication->fresh()->status);
        $this->assertDatabaseCount('nicks', 1);
        $this->assertDatabaseCount('media', $count);
        // Protect against reintroducing per-image SQL in submission and finalization.
        $this->assertLessThanOrEqual(20, count($submitQueries));
        $this->assertLessThanOrEqual(10 * $count + 20, count($processingQueries));
        $sql = fn ($query) => str_replace(['"', '`'], '', strtolower($query['query']));
        $this->assertCount(1, array_filter($submitQueries, fn ($query) => str_starts_with($sql($query), 'insert into nick_media_files')));
        $this->assertCount(1, array_filter($processingQueries, fn ($query) => str_starts_with($sql($query), 'update media set') && str_contains($sql($query), 'model_type')));
        if (getenv('NICK_MEDIA_BENCHMARK')) {
            fwrite(STDERR, sprintf("\nNick media %d images: submit=%d SQL; process+publish=%d SQL\n", $count, count($submitQueries), count($processingQueries)));
        }
    }

    public function test_uploaded_files_publish_only_after_all_images_and_preserve_selected_order(): void
    {
        $first = $this->upload('cover.png');
        $second = $this->upload('detail.png');
        $payload = $this->payload(['upload_ids' => [$first, $second]]);
        $this->postJson($this->base.'/media-publications', $payload)->assertStatus(202)->assertJsonPath('publication.ready', 0);
        $this->assertDatabaseCount('nicks', 0);
        $this->getJson('/api/categories/'.$this->category->slug.'/nicks')->assertOk()->assertJsonCount(0, 'data');
        Queue::assertPushed(ProcessNickMedia::class, fn ($job) => $job->connection === 'nick-media' && $job->queue === 'nick-media');
        $publication = NickPublication::firstOrFail();
        $this->assertStringNotContainsString('private-password', DB::table('nick_publications')->value('payload'));

        $this->service->processFile($second);
        $this->assertDatabaseCount('nicks', 0);
        $this->service->processFile($first);
        $publication->refresh();
        $this->assertSame('completed', $publication->status);
        $nick = Nick::withoutUserOwnedScope()->findOrFail($publication->nick_id);
        $this->assertSame('private-password', AccountEncrypt::decrypt($nick->account_password));
        $this->assertSame('not_sold', $nick->status);
        $media = $nick->getMedia('images');
        $this->assertCount(2, $media);
        $this->assertStringStartsWith($first, $media[0]->file_name);
        $this->assertSame($media[0]->getUrl(), $nick->image);
        foreach ($media as $image) {
            Storage::disk('public')->assertExists($image->getPathRelativeToRoot());
        }
        $this->service->processFile($first);
        $this->service->processFile($second);
        $this->postJson($this->base.'/media-publications', $payload)->assertStatus(202)->assertJsonPath('publication.nick_id', $nick->id);
        $this->assertDatabaseCount('nicks', 1);
        $this->assertDatabaseCount('media', 2);
        $this->service->recover();
        $this->assertSame([], Storage::disk('nick-staging')->allFiles());
        $this->assertDatabaseCount('media', 2);
    }

    public function test_idempotency_rejects_changed_content_and_parallel_drafts_for_same_account(): void
    {
        $payload = $this->payload(['urls' => ['https://example.com/one.png']]);
        $this->postJson($this->base.'/media-publications', $payload)->assertStatus(202);
        $this->postJson($this->base.'/media-publications', $payload)->assertStatus(202);
        $this->postJson($this->base.'/media-publications', array_replace($payload, ['price' => 2]))->assertStatus(409);
        $this->postJson($this->base.'/media-publications', array_replace($payload, ['request_id' => (string) Str::uuid()]))
            ->assertUnprocessable()->assertJsonValidationErrors('account_name');
        $this->assertDatabaseCount('nick_publications', 1);
        $this->assertDatabaseCount('nick_media_files', 1);
    }

    public function test_url_failure_retries_only_unfinished_image_and_keeps_the_cover(): void
    {
        $first = $this->upload();
        $publication = $this->service->submit($this->seller, $this->payload(['upload_ids' => [$first]]));
        // Add a URL item directly to simulate a mixed batch; the public endpoint disallows mixed inputs.
        $urlFile = NickMediaFile::create(['id' => (string) Str::uuid(), 'user_id' => $this->seller->id,
            'publication_id' => $publication->id, 'position' => 1, 'source_url' => 'https://example.com/fail.png',
            'name' => 'URL', 'status' => 'pending', 'attempts' => 2]);
        $this->service->processFile($first);
        $mediaId = NickMediaFile::find($first)->media_id;
        $this->mock(SafeImageDownloader::class)->shouldReceive('getTempFile')->once()
            ->andThrow(ValidationException::withMessages(['image' => 'bad image']));
        $this->service->processFile($urlFile->id);
        $this->assertSame('failed', $publication->fresh()->status);
        $this->assertDatabaseCount('nicks', 0);
        $this->postJson($this->base.'/media-publications/'.$publication->uuid.'/retry')->assertOk();
        $this->assertSame('ready', NickMediaFile::find($first)->status);
        $image = UploadedFile::fake()->image('remote.png', 20, 20);
        $temp = tempnam(sys_get_temp_dir(), 'nick-media-test-');
        copy($image->getPathname(), $temp);
        $this->mock(SafeImageDownloader::class)->shouldReceive('getTempFile')->once()->andReturn($temp);
        $this->service->processFile($urlFile->id);
        $this->assertSame('completed', $publication->fresh()->status);
        $this->assertSame($mediaId, NickMediaFile::find($first)->media_id);
        $this->assertDatabaseCount('media', 2);
        $this->assertFileDoesNotExist($temp);
    }

    public function test_cancellation_during_url_download_prevents_publication_and_cleans_download(): void
    {
        $publication = $this->service->submit($this->seller, $this->payload(['urls' => ['https://example.com/one.png']]));
        $image = UploadedFile::fake()->image('remote.png', 20, 20);
        $temp = tempnam(sys_get_temp_dir(), 'nick-media-test-');
        copy($image->getPathname(), $temp);
        $this->mock(SafeImageDownloader::class)->shouldReceive('getTempFile')->once()->andReturnUsing(function () use ($publication, $temp) {
            $this->service->cancel($publication);

            return $temp;
        });
        $this->service->processFile($publication->files()->first()->id);
        $this->assertSame('cancelled', $publication->fresh()->status);
        $this->assertDatabaseCount('nicks', 0);
        $this->assertDatabaseCount('media', 0);
        $this->assertFileDoesNotExist($temp);
    }

    public function test_other_users_cannot_read_retry_cancel_or_claim_another_users_upload(): void
    {
        $upload = $this->upload();
        $publication = $this->service->submit($this->seller, $this->payload(['urls' => ['https://example.com/one.png']]));
        $other = User::factory()->create();
        $other->assignRole('ctv')->givePermissionTo(AppPermission::NicksCreate->value);
        $other->categories()->attach($this->category->id, ['can_post' => true]);
        $this->actingAs($other);
        $this->getJson($this->base.'/media-publications')->assertOk()->assertJsonCount(0, 'publications.data');
        $this->postJson($this->base.'/media-publications/'.$publication->uuid.'/retry')->assertNotFound();
        $this->deleteJson($this->base.'/media-publications/'.$publication->uuid)->assertNotFound();
        $this->deleteJson($this->base.'/media-uploads/'.$upload)->assertNotFound();
        $this->postJson($this->base.'/media-publications', $this->payload(['upload_ids' => [$upload]]))
            ->assertUnprocessable()->assertJsonValidationErrors('upload_ids');
    }

    public function test_permissions_and_attribute_option_ownership_are_checked_before_acceptance(): void
    {
        $this->seller->categories()->detach();
        $this->postJson($this->base.'/media-publications', $this->payload())->assertForbidden();
        $this->seller->categories()->attach($this->category->id, ['can_post' => true]);
        $attribute = Attribute::create(['name' => 'Server', 'status' => true]);
        $otherAttribute = Attribute::create(['name' => 'Other', 'status' => true]);
        $option = AttributeOption::create(['attribute_id' => $otherAttribute->id, 'option_value' => 'Wrong', 'status' => true]);
        $this->category->attributes()->attach($attribute);
        $this->postJson($this->base.'/media-publications', $this->payload(['attribute_cache_json' => [
            ['attribute_id' => $attribute->id, 'option_id' => $option->id],
        ]]))->assertUnprocessable()->assertJsonValidationErrors('attribute_cache_json');
        $this->assertDatabaseCount('nick_publications', 0);
    }

    public function test_revoked_permission_prevents_background_job_from_opening_sales(): void
    {
        $upload = $this->upload();
        $publication = $this->service->submit($this->seller, $this->payload(['upload_ids' => [$upload]]));
        $this->seller->categories()->detach();
        $this->service->processFile($upload);
        $this->assertSame('failed', $publication->fresh()->status);
        $this->assertDatabaseCount('nicks', 0);
    }

    public function test_abandoned_upload_cleanup_does_not_delete_files_belonging_to_active_drafts(): void
    {
        $abandoned = $this->upload('abandoned.png');
        $used = $this->upload('used.png');
        $this->service->submit($this->seller, $this->payload(['upload_ids' => [$used]]));
        $path = NickMediaFile::find($used)->path;
        NickMediaFile::query()->update(['created_at' => now()->subDays(2)]);
        $this->service->recover();
        $this->assertNull(NickMediaFile::find($abandoned));
        Storage::disk('nick-staging')->assertExists($path);
    }

    public function test_interrupted_worker_is_recovered_but_an_active_claim_is_not_replayed(): void
    {
        $upload = $this->upload();
        $this->service->submit($this->seller, $this->payload(['upload_ids' => [$upload]]));
        $file = NickMediaFile::find($upload);
        $file->update(['status' => 'processing', 'processing_at' => now(), 'claim_token' => (string) Str::uuid(), 'attempts' => 1]);
        $this->service->processFile($upload);
        $this->service->recover();
        $this->assertSame('processing', $file->fresh()->status);
        $file->update(['processing_at' => now()->subMinutes(4)]);
        $this->service->recover();
        $this->assertSame('pending', $file->fresh()->status);
        $this->service->processFile($upload);
        $this->assertDatabaseCount('nicks', 1);
    }

    public function test_redis_dispatch_failure_retains_an_accepted_recoverable_draft(): void
    {
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class,
            \Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class)->shouldReceive('dispatch')
                ->andThrow(new \RuntimeException('Redis unavailable'))->getMock());
        $this->postJson($this->base.'/media-publications', $this->payload(['urls' => ['https://example.com/a.png']]))
            ->assertStatus(202)->assertJsonPath('publication.status', 'pending');
        $this->assertDatabaseCount('nick_publications', 1);
        $this->assertNull(NickMediaFile::first()->queued_at);
        $this->assertDatabaseCount('nicks', 0);
    }

    public function test_status_responses_never_expose_credentials_or_private_sources(): void
    {
        $this->service->submit($this->seller, $this->payload(['urls' => ['https://example.com/private-source.png']]));
        $response = $this->getJson($this->base.'/media-publications')->assertOk();
        $this->assertStringNotContainsString('private-password', $response->getContent());
        $this->assertStringNotContainsString('private-source', $response->getContent());
        $this->assertStringNotContainsString('payload', $response->getContent());
    }

    public function test_legacy_create_and_store_still_work_without_background_jobs(): void
    {
        $this->get($this->base.'/create')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Nicks/Create')->missing('backgroundUpload'));
        $this->get($this->base.'/create-background')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Nicks/Create')->where('backgroundUpload', true));
        $payload = $this->payload();
        unset($payload['request_id'], $payload['urls'], $payload['upload_ids']);
        $payload['images'] = [UploadedFile::fake()->image('legacy.png', 20, 20)];
        $this->post($this->base, $payload)->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseCount('nicks', 1);
        $this->assertDatabaseCount('media', 1);
        $this->assertDatabaseCount('nick_publications', 0);
        Queue::assertNothingPushed();
    }

    public function test_new_flow_rejects_mixed_sources_excessive_images_and_non_image_uploads(): void
    {
        $this->postJson($this->base.'/media-uploads', ['image' => UploadedFile::fake()->create('script.html', 1, 'text/html')])
            ->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->postJson($this->base.'/media-publications', $this->payload([
            'upload_ids' => [(string) Str::uuid()], 'urls' => ['https://example.com/a.png'],
        ]))->assertUnprocessable();
        $this->postJson($this->base.'/media-publications', $this->payload([
            'urls' => array_fill(0, 21, 'https://example.com/a.png'),
        ]))->assertUnprocessable()->assertJsonValidationErrors('urls');
        $this->assertDatabaseCount('nick_publications', 0);
    }

    public function test_used_upload_cannot_be_deleted_and_cancel_releases_account_for_another_draft(): void
    {
        $upload = $this->upload();
        $publication = $this->service->submit($this->seller, $this->payload(['upload_ids' => [$upload]]));
        $path = NickMediaFile::find($upload)->path;
        $this->deleteJson($this->base.'/media-uploads/'.$upload)->assertStatus(409);
        $this->deleteJson($this->base.'/media-publications/'.$publication->uuid)->assertOk();
        Storage::disk('nick-staging')->assertMissing($path);
        $this->postJson($this->base.'/media-publications', $this->payload(['urls' => ['https://example.com/a.png']]))
            ->assertStatus(202);
        $this->assertDatabaseCount('nicks', 0);
    }

    public function test_existing_legacy_nick_blocks_publication_without_losing_prepared_images(): void
    {
        $upload = $this->upload();
        $publication = $this->service->submit($this->seller, $this->payload(['upload_ids' => [$upload]]));
        Nick::create(['user_id' => $this->seller->id, 'category_id' => $this->category->id,
            'account_name' => 'media-account', 'account_password' => AccountEncrypt::encrypt('old'),
            'price' => 100, 'status' => 'not_sold', 'listing_type' => 'normal']);
        $this->service->processFile($upload);
        $this->assertSame('failed', $publication->fresh()->status);
        $this->assertSame('ready', NickMediaFile::find($upload)->status);
        $this->assertDatabaseCount('nicks', 1);
    }

    public function test_missing_prepared_image_can_be_recreated_without_losing_other_images(): void
    {
        $first = $this->upload();
        $second = $this->upload();
        $publication = $this->service->submit($this->seller, $this->payload(['upload_ids' => [$first, $second]]));
        $this->service->processFile($first);
        $media = Media::findOrFail(NickMediaFile::find($first)->media_id);
        Storage::disk('public')->delete($media->getPathRelativeToRoot());
        $this->service->processFile($second);
        $this->assertSame('failed', $publication->fresh()->status);
        $this->assertSame('failed', NickMediaFile::find($first)->status);
        $this->service->retry($publication);
        $this->service->processFile($first);
        $this->assertSame('completed', $publication->fresh()->status);
        $this->assertDatabaseCount('media', 2);
    }

    public function test_upload_releases_its_transaction_before_disk_io_and_reserves_the_last_quota_slot(): void
    {
        $rows = [];
        for ($i = 0; $i < 199; $i++) {
            $rows[] = [
                'id' => (string) Str::uuid(), 'user_id' => $this->seller->id, 'name' => 'reserved', 'status' => 'uploading',
            ];
        }
        NickMediaFile::insert($rows);
        $outerLevel = DB::transactionLevel();
        $image = \Mockery::mock(UploadedFile::class);
        $image->shouldReceive('extension')->once()->andReturn('png');
        $image->shouldReceive('getClientOriginalName')->once()->andReturn('last.png');
        $image->shouldReceive('storeAs')->once()->andReturnUsing(function ($directory, $name, $disk) use ($outerLevel) {
            $this->assertSame($outerLevel, DB::transactionLevel(), 'Disk writes must run outside the upload transaction.');
            $this->assertDatabaseCount('nick_media_files', 200);
            try {
                $this->service->upload($this->seller, UploadedFile::fake()->image('overflow.png'));
                $this->fail('Concurrent upload exceeded the reserved quota.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('image', $exception->errors());
            }
            Storage::disk($disk)->put($directory.'/'.$name, 'test image bytes');

            return $directory.'/'.$name;
        });
        $file = $this->service->upload($this->seller, $image);
        $this->assertSame('uploaded', $file->fresh()->status);
        $this->assertDatabaseCount('nick_media_files', 200);
        Storage::disk('nick-staging')->assertExists($file->path);
    }

    public function test_partial_disk_write_failure_releases_quota_and_removes_the_partial_file(): void
    {
        $image = \Mockery::mock(UploadedFile::class);
        $image->shouldReceive('extension')->once()->andReturn('png');
        $image->shouldReceive('getClientOriginalName')->once()->andReturn('broken.png');
        $image->shouldReceive('storeAs')->once()->andReturnUsing(function ($directory, $name, $disk) {
            Storage::disk($disk)->put($directory.'/'.$name, 'partial bytes');
            throw new \RuntimeException('Disk write interrupted.');
        });
        try {
            $this->service->upload($this->seller, $image);
            $this->fail('Failed upload was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Disk write interrupted.', $exception->getMessage());
        }
        $this->assertDatabaseCount('nick_media_files', 0);
        $this->assertSame([], Storage::disk('nick-staging')->allFiles());
    }

    public function test_reserved_but_unfinished_upload_cannot_be_attached_to_a_publication(): void
    {
        $file = NickMediaFile::create(['id' => (string) Str::uuid(), 'user_id' => $this->seller->id,
            'name' => 'unfinished.png', 'status' => 'uploading']);
        $this->postJson($this->base.'/media-publications', $this->payload(['upload_ids' => [$file->id]]))
            ->assertUnprocessable()->assertJsonValidationErrors('upload_ids');
        $this->assertDatabaseCount('nick_publications', 0);
    }

    public function test_partial_redis_dispatch_failure_releases_only_the_undispatched_tail(): void
    {
        $dispatcher = app(\Illuminate\Contracts\Bus\Dispatcher::class);
        $attempted = [];
        $mock = \Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $mock->shouldReceive('dispatch')->twice()->andReturnUsing(function ($job) use (&$attempted) {
            $attempted[] = $job->fileId;
            if (count($attempted) === 2) {
                throw new \RuntimeException('Redis disconnected after first job.');
            }
        });
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $mock);
        $publication = $this->service->submit($this->seller, $this->payload(['urls' => array_fill(0, 3, 'https://example.com/a.png')]));
        $this->assertNotNull(NickMediaFile::find($attempted[0])->queued_at);
        $this->assertNull(NickMediaFile::find($attempted[1])->queued_at);
        $this->assertSame(2, $publication->files()->whereNull('queued_at')->count());

        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $dispatcher);
        $this->service->dispatchPending($publication->id);
        Queue::assertPushed(ProcessNickMedia::class, 2);
        $this->assertSame(3, $publication->files()->whereNotNull('queued_at')->count());
    }

    public function test_successful_image_does_not_rescan_or_dispatch_siblings_but_cron_recovers_them(): void
    {
        $first = $this->upload();
        $second = $this->upload();
        $publication = $this->service->submit($this->seller, $this->payload(['upload_ids' => [$first, $second]]));
        NickMediaFile::whereKey($second)->update(['queued_at' => null]);
        Queue::fake();
        $this->service->processFile($first);
        Queue::assertNothingPushed();
        $this->assertSame('pending', $publication->fresh()->status);
        $this->service->recover();
        Queue::assertPushed(ProcessNickMedia::class, 1);
        Queue::assertPushed(ProcessNickMedia::class, fn ($job) => $job->fileId === $second);
        $this->service->processFile($second);
        $this->service->finalize($publication->id);
        $this->service->finalize($publication->id);
        $this->assertDatabaseCount('nicks', 1);
    }

    public function test_automatic_retry_dispatches_only_the_failed_image_and_honors_backoff(): void
    {
        $publication = $this->service->submit($this->seller, $this->payload(['urls' => array_fill(0, 2, 'https://example.com/a.png')]));
        $first = $publication->files()->first();
        Queue::fake();
        $this->mock(SafeImageDownloader::class)->shouldReceive('getTempFile')->once()->andThrow(new \RuntimeException('Temporary network failure.'));
        $this->service->processFile($first->id);
        $this->assertSame('pending', $first->fresh()->status);
        Queue::assertPushed(ProcessNickMedia::class, 1);
        Queue::assertPushed(ProcessNickMedia::class, fn ($job) => $job->fileId === $first->id && $job->delay->isFuture());
        // An early duplicate delivery cannot consume another attempt or bypass retry delay.
        $this->service->processFile($first->id);
        $this->assertSame(1, (int) $first->fresh()->attempts);
    }

    public function test_progress_counts_all_states_but_loads_only_failed_file_details(): void
    {
        $publication = $this->service->submit($this->seller, $this->payload(['urls' => array_fill(0, 4, 'https://example.com/a.png')]));
        $files = $publication->files()->get();
        foreach (['ready', 'processing', 'failed', 'pending'] as $i => $status) {
            $files[$i]->update(['status' => $status, 'error' => $status === 'failed' ? 'Image failed' : null]);
        }
        $retrieved = [];
        NickMediaFile::retrieved(function ($file) use (&$retrieved) {
            $retrieved[] = $file->id;
        });
        $this->getJson($this->base.'/media-publications')->assertOk()
            ->assertJsonPath('publications.data.0.total', 4)
            ->assertJsonPath('publications.data.0.ready', 1)
            ->assertJsonPath('publications.data.0.processing', 1)
            ->assertJsonPath('publications.data.0.failed', 1)
            ->assertJsonPath('publications.data.0.errors.0.position', 3)
            ->assertJsonCount(1, 'publications.data.0.errors');
        $this->assertSame([$files[2]->id], $retrieved);
    }

    public function test_bulk_attribute_insert_preserves_options_cache_and_timestamps(): void
    {
        $selected = [];
        foreach (['Server' => 'One', 'Planet' => 'Earth'] as $name => $value) {
            $attribute = Attribute::create(['name' => $name, 'status' => true]);
            $option = AttributeOption::create(['attribute_id' => $attribute->id, 'option_value' => $value, 'status' => true]);
            $this->category->attributes()->attach($attribute->id);
            $selected[] = ['attribute_id' => $attribute->id, 'option_id' => $option->id];
        }
        $publication = $this->service->submit($this->seller, $this->payload(['attribute_cache_json' => $selected]));
        $this->assertSame('completed', $publication->status);
        $nick = Nick::withoutUserOwnedScope()->findOrFail($publication->nick_id);
        $this->assertSame(['Server' => 'One', 'Planet' => 'Earth'], json_decode($nick->attribute_cache_json, true));
        foreach ($selected as $item) {
            $this->assertDatabaseHas('nick_attributes', ['nick_id' => $nick->id,
                'attribute_id' => $item['attribute_id'], 'attribute_option_id' => $item['option_id']]);
        }
        $this->assertSame(2, DB::table('nick_attributes')->where('nick_id', $nick->id)->whereNotNull('created_at')->whereNotNull('updated_at')->count());
    }
}
