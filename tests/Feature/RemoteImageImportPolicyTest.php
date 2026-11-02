<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\GameType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RemoteImageImportPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('super-admin', 'web');
        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $this->actingAs($user);
        Storage::fake('public');
        config(['media-library.disk_name' => 'public']);
    }

    public function test_catalog_endpoints_reject_remote_images_before_writing_data(): void
    {
        foreach (['/admin/games/categories', '/admin/randombox', '/admin/spins'] as $url) {
            $this->postJson($url, ['image_url' => 'https://images.example.com/track-origin.png'])
                ->assertUnprocessable()->assertJsonValidationErrors('image_url');
        }
        $this->assertDatabaseCount('categories', 0);
        $this->assertDatabaseCount('random_boxes', 0);
        $this->assertDatabaseCount('spins', 0);
        $this->assertDatabaseCount('media', 0);
    }

    public function test_category_upload_and_replace_work_and_rejected_url_keeps_existing_image(): void
    {
        $game = GameType::create(['name' => 'Upload policy game']);
        $data = ['game_type_id' => $game->id, 'name' => 'Upload category',
            'template' => 'default', 'is_public' => true, 'status' => 'active', 'sort_order' => 0];
        $this->post('/admin/games/categories', [...$data, 'image' => UploadedFile::fake()->image('first.png')])
            ->assertRedirect()->assertSessionHasNoErrors();
        $category = Category::sole();
        $first = $category->getFirstMedia('image');
        $this->assertNotNull($first);

        $url = '/admin/games/categories/'.$category->id;
        $this->post($url, [...$data, '_method' => 'PUT', 'image' => UploadedFile::fake()->image('second.png')])
            ->assertRedirect()->assertSessionHasNoErrors();
        $second = $category->fresh()->getFirstMedia('image');
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('second.png', $second->file_name);

        $this->putJson($url, [...$data, 'image_url' => 'https://images.example.com/track-origin.png'])
            ->assertUnprocessable()->assertJsonValidationErrors('image_url');
        $this->assertSame($second->id, $category->fresh()->getFirstMedia('image')->id);
        $this->assertDatabaseCount('media', 1);
    }
}
