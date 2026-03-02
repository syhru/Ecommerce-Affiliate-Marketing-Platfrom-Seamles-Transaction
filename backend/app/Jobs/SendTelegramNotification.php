<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use App\Services\TelegramService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendTelegramNotification implements ShouldQueue
{
    use Queueable, InteractsWithQueue;

    public int $tries = 3;

    public function __construct(public int $notificationId) {}


    public function handle(TelegramService $telegram): void
    {
        $notification = NotificationLog::find($this->notificationId);

        if (! $notification) {
            Log::warning("SendTelegramNotification: Notification #{$this->notificationId} not found");
            return;
        }

        if ($notification->status === 'sent') {
            return;
        }

        $chatId = $notification->recipient;

        if (empty($chatId) && $notification->user_id) {
            $chatId = $notification->user?->telegram_chat_id;
        }

        if (empty($chatId)) {
            Log::warning("SendTelegramNotification: No telegram_chat_id for notification #{$this->notificationId}");
            $notification->update([
                'status'     => 'failed',
                'last_error' => 'No telegram_chat_id configured',
            ]);
            return;
        }

        $success = $telegram->sendMessage($chatId, $notification->message_content);

        if ($success) {
            $notification->update([
                'status'  => 'sent',
                'sent_at' => now(),
            ]);
            Log::info("SendTelegramNotification: Sent notification #{$this->notificationId} to {$chatId}");
        } else {
            if ($this->attempts() >= $this->tries) {
                $notification->update([
                    'status'        => 'failed',
                    'error_message' => 'Max retries exceeded',
                ]);
                Log::error("SendTelegramNotification: Permanently failed notification #{$this->notificationId}");
                $this->fail();
            } else {
                Log::warning("SendTelegramNotification: Retrying notification #{$this->notificationId} (attempt {$this->attempts()})");
                throw new \RuntimeException('Telegram sendMessage failed, will retry');
            }
        }
    }

    /**
     * Backoff delay in seconds: 30s → 60s → 120s.
     */
    public function backoff(): array
    {
        return [30, 60, 120];
    }
}
