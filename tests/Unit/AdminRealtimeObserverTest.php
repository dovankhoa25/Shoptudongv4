<?php

namespace Tests\Unit;

use App\Events\AdminEvent;
use App\Models\GoldTransaction;
use App\Observers\AdminRealtimeObserver;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AdminRealtimeObserverTest extends TestCase
{
    public function test_it_broadcasts_a_minimal_admin_event_for_a_new_gold_order(): void
    {
        Event::fake([AdminEvent::class]);

        $order = new GoldTransaction([
            'type' => GoldTransaction::TYPE_ORDER,
            'status' => GoldTransaction::STATUS_PENDING,
        ]);
        $order->id = 123;
        $order->exists = true;

        app(AdminRealtimeObserver::class)->created($order);

        Event::assertDispatched(AdminEvent::class, fn (AdminEvent $event): bool => $event->resource === 'gold_order'
            && $event->resourceId === 123
            && $event->action === 'created'
            && $event->status === GoldTransaction::STATUS_PENDING
            && ! str_contains($event->message, 'password'));
    }

    public function test_admin_event_uses_the_private_admin_channel(): void
    {
        $event = new AdminEvent(
            resource: 'gem_order',
            resourceId: 456,
            action: 'status_updated',
            status: 'completed',
            message: 'Test event',
            occurredAt: now()->toIso8601String(),
        );

        $this->assertSame('private-Admin.realtime', (string) $event->broadcastOn());
        $this->assertSame('AdminEvent', $event->broadcastAs());
        $this->assertSame([
            'resource',
            'resource_id',
            'action',
            'status',
            'message',
            'occurred_at',
        ], array_keys($event->broadcastWith()));
    }
}
