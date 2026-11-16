<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Headers a browser on the Next.js origin sends, which makes Sanctum treat the request as stateful. */
    protected function frontendHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Origin' => 'http://localhost:3000',
            'Referer' => 'http://localhost:3000/',
        ];
    }

    protected function fromFrontend(): static
    {
        return $this->withHeaders($this->frontendHeaders());
    }

    protected function actingAsAdmin(?User $user = null): static
    {
        return $this->actingAs($user ?? User::factory()->create(), 'web')->fromFrontend();
    }
}
