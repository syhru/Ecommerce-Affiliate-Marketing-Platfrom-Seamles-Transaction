<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class MidtransService
{
    protected string $serverKey;
    protected bool   $isProduction;
    protected string $snapBaseUrl;

    public function __construct()
    {
        $this->serverKey    = config('services.midtrans.server_key', '');
        $this->isProduction = (bool) config('services.midtrans.is_production', false);
        $this->snapBaseUrl  = $this->isProduction
            ? 'https://app.midtrans.com/snap/v1'
            : 'https://app.sandbox.midtrans.com/snap/v1';
    }


    public function isValidSignature(array $payload): bool
    {
        $orderId     = $payload['order_id'] ?? '';
        $statusCode  = $payload['status_code'] ?? '';
        $grossAmount = $payload['gross_amount'] ?? '';
        $signature   = $payload['signature_key'] ?? '';

        $expected = hash('sha512', $orderId . $statusCode . $grossAmount . $this->serverKey);

        return hash_equals($expected, $signature);
    }

    public function getTransactionStatus(string $orderId): ?array
    {
        $baseUrl = $this->isProduction
            ? 'https://api.midtrans.com/v2'
            : 'https://api.sandbox.midtrans.com/v2';

        try {
            $response = Http::withBasicAuth($this->serverKey, '')
                ->get("{$baseUrl}/{$orderId}/status");

            if ($response->successful()) {
                return $response->json();
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('MidtransService::getTransactionStatus error', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * @param  array  $params  
     * @return string  
     * @throws \RuntimeException
     */
    public function createSnapToken(array $params): string
    {
        $response = Http::withBasicAuth($this->serverKey, '')
            ->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json'])
            ->post("{$this->snapBaseUrl}/transactions", $params);

        if ($response->failed()) {
            throw new \RuntimeException('Midtrans Snap error: ' . $response->body());
        }

        $data = $response->json();

        if (empty($data['redirect_url'])) {
            throw new \RuntimeException('Midtrans returned no redirect_url: ' . json_encode($data));
        }

        return $data['redirect_url'];
    }
}
