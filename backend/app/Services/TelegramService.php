<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    protected string $botToken;
    protected string $apiUrl;

    public function __construct()
    {
        $this->botToken = config('services.telegram.bot_token', '');
        $this->apiUrl   = config('services.telegram.api_url', 'https://api.telegram.org');
    }

    /**
     * @param  string  $chatId  
     * @param  string  $message  
     * @return bool
     */
    public function sendMessage(string $chatId, string $message): bool
    {
        if (empty($this->botToken)) {
            Log::warning('TelegramService: TELEGRAM_BOT_TOKEN is not configured');
            return false;
        }

        try {
            $response = Http::timeout(10)->post(
                "{$this->apiUrl}/bot{$this->botToken}/sendMessage",
                [
                    'chat_id'    => $chatId,
                    'text'       => $message,
                    'parse_mode' => 'Markdown',
                ]
            );

            if ($response->successful() && ($response->json('ok') === true)) {
                return true;
            }

            Log::warning('TelegramService: API error', [
                'chat_id'  => $chatId,
                'status'   => $response->status(),
                'response' => $response->json(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('TelegramService: Exception', [
                'chat_id' => $chatId,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }
}
