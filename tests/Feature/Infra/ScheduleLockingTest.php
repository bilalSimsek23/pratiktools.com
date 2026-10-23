<?php

namespace Tests\Feature\Infra;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

final class ScheduleLockingTest extends TestCase
{
    /**
     * @return list<Event>
     */
    private function events(bool $syncEnabled): array
    {
        config(['commerce.schedule.enabled' => $syncEnabled, 'consent.schedule.enabled' => $syncEnabled]);
        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);
        Facade::clearResolvedInstance(Schedule::class);

        require base_path('routes/console.php');

        return $schedule->events();
    }

    public function test_every_scheduled_task_runs_on_one_server_without_overlapping(): void
    {
        $events = $this->events(true);
        $names = array_map(fn ($e) => $e->description ?: $e->command, $events);

        foreach (['platform-heartbeat', 'tenant-integration-health', 'commerce-sync-orders', 'commerce-reconciliation', 'consent-sync-iys_changes'] as $expected) {
            $this->assertContains($expected, $names);
        }
        $this->assertNotEmpty(array_filter($names, fn ($n) => str_contains((string) $n, 'horizon:snapshot')));

        foreach ($events as $event) {
            $label = $event->description ?: $event->command;
            $this->assertTrue($event->onOneServer, "{$label} must run on one server");
            $this->assertTrue($event->withoutOverlapping, "{$label} must not overlap");
        }
    }

    public function test_sync_dispatch_is_not_scheduled_unless_enabled(): void
    {
        $names = array_map(fn ($e) => $e->description, $this->events(false));

        $this->assertEmpty(array_filter($names, fn ($n) => str_starts_with((string) $n, 'commerce-') || str_starts_with((string) $n, 'consent-')));
        $this->assertContains('platform-heartbeat', $names);
    }
}
