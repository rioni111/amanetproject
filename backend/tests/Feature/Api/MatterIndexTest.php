<?php

namespace Tests\Feature\Api;

use App\Enums\MatterStatus;
use App\Enums\Priority;
use App\Models\Client;
use App\Models\Matter;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MatterIndexTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_a_paginated_envelope(): void
    {
        Matter::factory()->count(18)->create();

        $this->actingAsAdmin()->getJson('/api/matters?per_page=5&page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta', ['current_page' => 2, 'last_page' => 4, 'per_page' => 5, 'total' => 18, 'from' => 6, 'to' => 10])
            ->assertJsonStructure([
                'data' => [['id', 'matter_number', 'title', 'status', 'priority', 'due_at', 'is_overdue', 'client' => ['id', 'name', 'client_type'], 'open_tasks_count']],
                'links' => ['first', 'last', 'prev', 'next'],
            ]);
    }

    #[Test]
    public function it_filters_by_status_priority_and_active_scope(): void
    {
        Matter::factory()->create(['status' => MatterStatus::Open, 'priority' => Priority::Urgent]);
        Matter::factory()->create(['status' => MatterStatus::OnHold, 'priority' => Priority::Low]);
        Matter::factory()->status(MatterStatus::Closed)->create(['priority' => Priority::Urgent]);

        $admin = $this->actingAsAdmin();
        $admin->getJson('/api/matters?status=on_hold')->assertJsonPath('meta.total', 1);
        $admin->getJson('/api/matters?status=active')->assertJsonPath('meta.total', 2);
        $admin->getJson('/api/matters?priority=urgent')->assertJsonPath('meta.total', 2);
        $admin->getJson('/api/matters?status=active&priority=urgent')->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function search_matches_title_number_and_client_name_case_insensitively(): void
    {
        $client = Client::factory()->create(['name' => 'Quillon Maritime']);
        Matter::factory()->for($client)->create(['title' => 'Charter party dispute', 'matter_number' => 'LEX-1']);
        Matter::factory()->create(['title' => 'Lease renewal', 'matter_number' => 'LEX-2']);

        $admin = $this->actingAsAdmin();
        $admin->getJson('/api/matters?search=quillon')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.matter_number', 'LEX-1');
        $admin->getJson('/api/matters?search=LEASE')->assertJsonPath('data.0.matter_number', 'LEX-2');
        $admin->getJson('/api/matters?search=lex-2')->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function like_wildcards_in_search_are_treated_literally(): void
    {
        Matter::factory()->count(3)->create();

        $this->actingAsAdmin()->getJson('/api/matters?search=%25')->assertJsonPath('meta.total', 0);
    }

    #[Test]
    public function it_sorts_by_deadline_with_undated_matters_last(): void
    {
        Matter::factory()->create(['matter_number' => 'NONE', 'due_at' => null]);
        Matter::factory()->create(['matter_number' => 'LATER', 'due_at' => now()->addDays(20)]);
        Matter::factory()->create(['matter_number' => 'SOON', 'due_at' => now()->addDays(2)]);

        $this->actingAsAdmin()->getJson('/api/matters?sort=due_at')
            ->assertJsonPath('data.*.matter_number', ['SOON', 'LATER', 'NONE']);
    }

    #[Test]
    public function it_counts_only_open_tasks(): void
    {
        $matter = Matter::factory()->create();
        Task::factory()->for($matter)->count(2)->create();
        Task::factory()->for($matter)->completed()->create();

        $this->actingAsAdmin()->getJson('/api/matters')->assertJsonPath('data.0.open_tasks_count', 2);
    }

    #[Test]
    public function invalid_query_parameters_return_422_with_field_errors(): void
    {
        $this->actingAsAdmin()->getJson('/api/matters?status=deleted&sort=password&per_page=1000')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status', 'sort', 'per_page']);
    }
}
