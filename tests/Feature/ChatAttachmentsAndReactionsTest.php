<?php

namespace Tests\Feature;

use App\Enums\Permission as AppPermission;
use App\Events\ChatMessageReactionUpdated;
use App\Events\ChatMessageSent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChatAttachmentsAndReactionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            AppPermission::ChatsView,
            AppPermission::ChatsReply,
            AppPermission::ChatsManage,
        ] as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        Role::findOrCreate('ctv', 'web');
        Storage::fake('chat');
    }

    public function test_customer_can_send_an_image_only_message_and_open_its_signed_url(): void
    {
        $customer = User::factory()->create();
        $conversation = $this->conversationFor($customer);
        Event::fake([ChatMessageSent::class]);

        $response = $this->actingAs($customer)->post(
            "/chat/conversations/{$conversation->id}/messages",
            [
                'client_message_id' => (string) Str::uuid(),
                'images' => [UploadedFile::fake()->image('bang-chung.jpg', 640, 480)->size(250)],
            ],
            ['Accept' => 'application/json'],
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.type', ChatMessage::TYPE_IMAGE)
            ->assertJsonPath('data.body', '')
            ->assertJsonPath('data.attachments.0.mime_type', 'image/jpeg')
            ->assertJsonPath('data.attachments.0.width', 640)
            ->assertJsonPath('data.attachments.0.height', 480)
            ->assertJsonPath('data.attachments_expired', false);

        $media = Media::query()->sole();
        $this->assertSame('chat', $media->disk);
        Storage::disk('chat')->assertExists($media->getPathRelativeToRoot());

        $this->get($response->json('data.attachments.0.url'))
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');

        Event::assertDispatched(ChatMessageSent::class, fn (ChatMessageSent $event): bool => count($event->message['attachments']) === 1
            && $event->message['type'] === ChatMessage::TYPE_IMAGE
            && ! array_key_exists('is_mine', $event->message));
    }

    public function test_image_upload_keeps_client_message_id_idempotent(): void
    {
        $customer = User::factory()->create();
        $conversation = $this->conversationFor($customer);
        $clientMessageId = (string) Str::uuid();

        $firstId = $this->actingAs($customer)->post(
            "/chat/conversations/{$conversation->id}/messages",
            [
                'client_message_id' => $clientMessageId,
                'images' => [UploadedFile::fake()->image('first.jpg')],
            ],
            ['Accept' => 'application/json'],
        )->assertCreated()->json('data.id');

        $second = $this->actingAs($customer)->post(
            "/chat/conversations/{$conversation->id}/messages",
            [
                'client_message_id' => $clientMessageId,
                'images' => [UploadedFile::fake()->image('retry.jpg')],
            ],
            ['Accept' => 'application/json'],
        )->assertOk();

        $this->assertSame($firstId, $second->json('data.id'));
        $this->assertDatabaseCount('chat_messages', 1);
        $this->assertDatabaseCount('media', 1);
    }

    public function test_chat_image_validation_rejects_too_many_files_and_svg(): void
    {
        $customer = User::factory()->create();
        $conversation = $this->conversationFor($customer);
        $images = collect(range(1, 5))
            ->map(fn (int $number) => UploadedFile::fake()->image("image-{$number}.jpg"))
            ->all();

        $this->actingAs($customer)->post(
            "/chat/conversations/{$conversation->id}/messages",
            ['images' => $images],
            ['Accept' => 'application/json'],
        )->assertUnprocessable()->assertJsonValidationErrors('images');

        $svg = UploadedFile::fake()->createWithContent(
            'unsafe.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $this->actingAs($customer)->post(
            "/chat/conversations/{$conversation->id}/messages",
            ['images' => [$svg]],
            ['Accept' => 'application/json'],
        )->assertUnprocessable()->assertJsonValidationErrors('images.0');

        $this->assertDatabaseCount('chat_messages', 0);
        $this->assertDatabaseCount('media', 0);
    }

    public function test_reaction_desired_state_is_idempotent_and_broadcasts_neutral_summary(): void
    {
        $customer = User::factory()->create();
        $conversation = $this->conversationFor($customer);
        $message = $this->messageFor($conversation, $customer);
        Event::fake([ChatMessageReactionUpdated::class]);

        $url = "/chat/conversations/{$conversation->id}/messages/{$message->id}/reactions";
        $this->actingAs($customer)
            ->postJson($url, ['emoji' => '👍', 'active' => true])
            ->assertOk()
            ->assertJsonPath('data.0.emoji', '👍')
            ->assertJsonPath('data.0.count', 1)
            ->assertJsonPath('data.0.user_ids.0', $customer->id)
            ->assertJsonPath('data.0.reacted_by_me', true);

        $this->assertDatabaseHas('chat_message_reactions', [
            'message_id' => $message->id,
            'user_id' => $customer->id,
            'emoji' => '👍',
        ]);
        Event::assertDispatched(
            ChatMessageReactionUpdated::class,
            fn (ChatMessageReactionUpdated $event): bool => $event->messageId === $message->id
                && $event->actorId === $customer->id
                && $event->active
                && ! array_key_exists('reacted_by_me', $event->reactions[0]),
        );

        Event::fake([ChatMessageReactionUpdated::class]);
        $this->actingAs($customer)
            ->postJson($url, ['emoji' => '👍', 'active' => true])
            ->assertOk()
            ->assertJsonPath('data.0.count', 1)
            ->assertJsonPath('data.0.reacted_by_me', true);
        $this->assertDatabaseCount('chat_message_reactions', 1);
        Event::assertNotDispatched(ChatMessageReactionUpdated::class);

        $this->actingAs($customer)
            ->postJson($url, ['emoji' => '👍', 'active' => false])
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('chat_message_reactions', 0);
        Event::assertDispatched(
            ChatMessageReactionUpdated::class,
            fn (ChatMessageReactionUpdated $event): bool => ! $event->active && $event->reactions === [],
        );

        Event::fake([ChatMessageReactionUpdated::class]);
        $this->actingAs($customer)
            ->postJson($url, ['emoji' => '👍', 'active' => false])
            ->assertOk()
            ->assertJsonCount(0, 'data');
        Event::assertNotDispatched(ChatMessageReactionUpdated::class);
    }

    public function test_reactions_enforce_message_conversation_and_internal_note_visibility(): void
    {
        $customer = User::factory()->create();
        $otherCustomer = User::factory()->create();
        $conversation = $this->conversationFor($customer);
        $otherConversation = $this->conversationFor($otherCustomer);
        $message = $this->messageFor($conversation, $customer);
        $internal = ChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => null,
            'sender_kind' => ChatMessage::SENDER_AGENT,
            'type' => ChatMessage::TYPE_INTERNAL_NOTE,
            'body' => 'Ghi chú riêng',
            'is_internal' => true,
        ]);

        $this->actingAs($customer)
            ->postJson(
                "/chat/conversations/{$otherConversation->id}/messages/{$message->id}/reactions",
                ['emoji' => '❤️'],
            )
            ->assertForbidden();

        $this->actingAs($customer)
            ->postJson(
                "/chat/conversations/{$conversation->id}/messages/{$internal->id}/reactions",
                ['emoji' => '❤️'],
            )
            ->assertNotFound();

        $this->actingAs($customer)
            ->postJson(
                "/chat/conversations/{$conversation->id}/messages/{$message->id}/reactions",
                ['emoji' => 'not-an-emoji'],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('emoji');
    }

    public function test_cleanup_purges_old_completed_attachments_and_orphaned_media_but_keeps_active_chat(): void
    {
        $customer = User::factory()->create();
        $closedConversation = $this->conversationFor($customer);
        $activeConversation = $this->conversationFor($customer);
        $legacyClosedConversation = $this->conversationFor($customer);
        $orphanConversation = $this->conversationFor($customer);
        $closedMessage = $this->messageFor($closedConversation, $customer);
        $activeMessage = $this->messageFor($activeConversation, $customer);
        $legacyClosedMessage = $this->messageFor($legacyClosedConversation, $customer);
        $orphanMessage = $this->messageFor($orphanConversation, $customer);

        $closedMedia = $closedMessage->addMedia(UploadedFile::fake()->image('closed.jpg'))
            ->toMediaCollection(ChatMessage::MEDIA_COLLECTION_IMAGES, 'chat');
        $activeMedia = $activeMessage->addMedia(UploadedFile::fake()->image('active.jpg'))
            ->toMediaCollection(ChatMessage::MEDIA_COLLECTION_IMAGES, 'chat');
        $legacyClosedMedia = $legacyClosedMessage->addMedia(UploadedFile::fake()->image('legacy.jpg'))
            ->toMediaCollection(ChatMessage::MEDIA_COLLECTION_IMAGES, 'chat');
        $orphanMedia = $orphanMessage->addMedia(UploadedFile::fake()->image('orphan.jpg'))
            ->toMediaCollection(ChatMessage::MEDIA_COLLECTION_IMAGES, 'chat');
        $closedPath = $closedMedia->getPathRelativeToRoot();
        $activePath = $activeMedia->getPathRelativeToRoot();
        $legacyClosedPath = $legacyClosedMedia->getPathRelativeToRoot();
        $orphanPath = $orphanMedia->getPathRelativeToRoot();

        $closedConversation->forceFill([
            'status' => ChatConversation::STATUS_RESOLVED,
            'resolved_at' => now()->subDays(91),
        ])->save();
        $activeConversation->forceFill([
            'status' => ChatConversation::STATUS_WAITING_AGENT,
            'resolved_at' => now()->subDays(365),
        ])->save();
        $legacyClosedConversation->timestamps = false;
        $legacyClosedConversation->forceFill([
            'status' => ChatConversation::STATUS_CLOSED,
            'resolved_at' => null,
            'updated_at' => now()->subDays(91),
        ])->save();
        DB::table('chat_messages')->where('id', $orphanMessage->id)->delete();

        $this->artisan('chat:purge-attachments', ['--days' => 90])
            ->expectsOutput('Purged 2 attachment(s) from 2 message(s).')
            ->expectsOutput('Purged 1 orphaned chat attachment(s).')
            ->assertSuccessful();

        Storage::disk('chat')->assertMissing($closedPath);
        Storage::disk('chat')->assertExists($activePath);
        Storage::disk('chat')->assertMissing($legacyClosedPath);
        Storage::disk('chat')->assertMissing($orphanPath);
        $this->assertDatabaseMissing('media', ['id' => $closedMedia->id]);
        $this->assertDatabaseHas('media', ['id' => $activeMedia->id]);
        $this->assertDatabaseMissing('media', ['id' => $legacyClosedMedia->id]);
        $this->assertDatabaseMissing('media', ['id' => $orphanMedia->id]);
        $this->assertNotNull($closedMessage->refresh()->metadata['attachments_purged_at'] ?? null);

        $this->actingAs($customer)
            ->getJson("/chat/conversations/{$closedConversation->id}")
            ->assertOk()
            ->assertJsonPath('messages.0.attachments', [])
            ->assertJsonPath('messages.0.attachments_expired', true);
    }

    private function conversationFor(User $customer): ChatConversation
    {
        return ChatConversation::query()->create([
            'customer_id' => $customer->id,
            'category' => ChatConversation::CATEGORY_GENERAL,
            'status' => ChatConversation::STATUS_WAITING_AGENT,
            'priority' => ChatConversation::PRIORITY_NORMAL,
        ]);
    }

    private function messageFor(ChatConversation $conversation, User $sender): ChatMessage
    {
        return ChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'sender_kind' => ChatMessage::SENDER_CUSTOMER,
            'type' => ChatMessage::TYPE_TEXT,
            'body' => 'Tin nhắn kiểm thử',
            'is_internal' => false,
        ]);
    }
}
