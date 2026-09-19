<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Server-side source of truth for courier/service shipping rates.
 *
 * WS-02 (PA-F-03): the backend owns the shipping cost. Clients may only pick a
 * courier/service identifier; the rate below is what gets charged. Any
 * client-supplied shipping_cost is ignored on order creation.
 *
 * The structure is intentionally a flat in-memory table so a future dynamic
 * shipping integration can replace `rates()` (e.g. with a cached courier API
 * response) without changing the calling code.
 */
class ShippingRateService
{
    /**
     * Fixed rates per courier/service identifier (amounts in IDR).
     *
     * Mirrors the frontend courier list (frontend/app/checkout/page.tsx).
     *
     * @return array<string, int>
     */
    public function rates(): array
    {
        return [
            'jne_reg' => 15000,
            'jne_yes' => 25000,
            'jnt_reg' => 13000,
            'sicepat' => 14000,
            'pos_biasa' => 10000,
        ];
    }

    /**
     * Is the courier/service identifier one the backend knows a rate for?
     */
    public function isValidCourier(string $courier): bool
    {
        return array_key_exists($courier, $this->rates());
    }

    /**
     * Shipping cost in IDR for the given courier/service identifier.
     *
     * @throws InvalidArgumentException When the courier/service is not supported.
     */
    public function cost(string $courier): int
    {
        $rates = $this->rates();

        if (! array_key_exists($courier, $rates)) {
            throw new InvalidArgumentException("Layanan pengiriman tidak tersedia: {$courier}");
        }

        return $rates[$courier];
    }
}
