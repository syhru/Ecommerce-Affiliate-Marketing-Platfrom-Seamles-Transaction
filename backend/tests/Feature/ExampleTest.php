<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_redirects_to_configured_frontend(): void
    {
        $previousEnv = $_ENV['FRONTEND_URL'] ?? null;
        $previousServer = $_SERVER['FRONTEND_URL'] ?? null;
        $_ENV['FRONTEND_URL'] = $_SERVER['FRONTEND_URL'] = 'https://frontend.example.test';

        try {
            $this->get('/')
                ->assertStatus(302)
                ->assertRedirect('https://frontend.example.test');
        } finally {
            if ($previousEnv === null) {
                unset($_ENV['FRONTEND_URL']);
            } else {
                $_ENV['FRONTEND_URL'] = $previousEnv;
            }
            if ($previousServer === null) {
                unset($_SERVER['FRONTEND_URL']);
            } else {
                $_SERVER['FRONTEND_URL'] = $previousServer;
            }
        }
    }
}
