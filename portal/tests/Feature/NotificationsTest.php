<?php

namespace Tests\Feature;

use App\Livewire\Bell;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The notification bell: creation + dedupe via Notifier, the Livewire
 * component's unread badge and mark-read actions, and the deep-link
 * navigation. Producers (ticket store / transfers / drainer / status
 * changes) are covered by their own suites; these tests pin the bell.
 */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifier_creates_and_dedupes_unread_duplicates(): void
    {
        $user = User::factory()->create();

        $notifier = app(\App\Services\Notifier::class);

        $first = $notifier->send(
            userId: $user->id,
            type: Notification::TYPE_TSR_ERROR,
            title: 'TSR sync failed for TICKET-00083',
            body: 'Monday API down',
            url: '/tsp/service-reports/1',
            ticketId: '2831388203',
        );
        $this->assertNotNull($first);

        // Same notification while unread → deduped, no new row.
        $second = $notifier->send(
            userId: $user->id,
            type: Notification::TYPE_TSR_ERROR,
            title: 'TSR sync failed for TICKET-00083',
            body: 'Monday API down',
            url: '/tsp/service-reports/1',
            ticketId: '2831388203',
        );
        $this->assertNull($second);
        $this->assertSame(1, Notification::count());

        // A DIFFERENT ticket still gets its own row.
        $other = $notifier->send(
            userId: $user->id,
            type: Notification::TYPE_TSR_ERROR,
            title: 'TSR sync failed for TICKET-00083',
            body: 'Monday API down',
            url: '/tsp/service-reports/1',
            ticketId: '9999999999',
        );
        $this->assertNotNull($other);

        // Once read, the same notification can fire again.
        Notification::query()->update(['read_at' => now()]);
        $third = $notifier->send(
            userId: $user->id,
            type: Notification::TYPE_TSR_ERROR,
            title: 'TSR sync failed for TICKET-00083',
            body: 'Still failing',
            url: '/tsp/service-reports/1',
            ticketId: '2831388203',
        );
        $this->assertNotNull($third);
        $this->assertSame(3, Notification::query()->where('user_id', $user->id)->count());
    }

    public function test_bell_shows_unread_count_and_marks_all_read(): void
    {
        $user = User::factory()->create(['role' => 'fse']);
        $this->actingAs($user);

        Notification::create([
            'user_id' => $user->id,
            'type'    => Notification::TYPE_CLAIMABLE,
            'title'   => 'New claimable ticket in NCR',
            'url'     => '/tsp/tickets/1',
            'ticket_id' => '1',
        ]);
        Notification::create([
            'user_id' => $user->id,
            'type'    => Notification::TYPE_TRANSFER,
            'title'   => 'Transfer requested',
        ]);

        $component = Livewire::test(Bell::class);

        $this->assertSame(2, $component->unreadCount);
        $this->assertCount(2, $component->items);

        $component->call('markAllRead');

        $component = Livewire::test(Bell::class);
        $this->assertSame(0, $component->unreadCount);
    }

    public function test_open_notification_marks_read_and_redirects_to_deep_link(): void
    {
        $user = User::factory()->create(['role' => 'fse']);
        $this->actingAs($user);

        $n = Notification::create([
            'user_id' => $user->id,
            'type'    => Notification::TYPE_CLAIMABLE,
            'title'   => 'New claimable ticket in NCR',
            'url'     => '/tsp/tickets/2831388203',
            'ticket_id' => '2831388203',
        ]);

        Livewire::test(Bell::class)
            ->call('openNotification', $n->id)
            ->assertRedirect('/tsp/tickets/2831388203');

        $this->assertNotNull($n->refresh()->read_at);
    }

    public function test_cannot_open_another_users_notification(): void
    {
        $user = User::factory()->create(['role' => 'fse']);
        $other = User::factory()->create(['role' => 'fse']);
        $this->actingAs($user);

        $n = Notification::create([
            'user_id' => $other->id,
            'type'    => Notification::TYPE_CLAIMABLE,
            'title'   => 'Not yours',
            'url'     => '/tsp/dashboard',
        ]);

        Livewire::test(Bell::class)
            ->call('openNotification', $n->id)
            ->assertNoRedirect();

        $this->assertNull($n->refresh()->read_at);
    }
}