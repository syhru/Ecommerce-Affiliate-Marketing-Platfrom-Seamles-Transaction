<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramNotification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramNotificationTest extends TestCase
{

    public function test_telegram_failure_triggers_retry(): void
    {
        Queue::fake();

        $notificationId = 42;
        $job = new SendTelegramNotification($notificationId);

        
        $this->assertEquals(3, $job->tries, 'Job must be configured for 3 retry attempts');

        dispatch($job);

        Queue::assertPushed(SendTelegramNotification::class, function ($queued) use ($notificationId): bool {
            return $queued->notificationId === $notificationId;
        });
    }
}
