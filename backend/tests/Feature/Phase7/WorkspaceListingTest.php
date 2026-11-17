<?php

namespace Tests\Feature\Phase7;

use App\Enums\MatterStatus;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Phase 7 issue P7-008: archived matters no longer crowd the default matter list and the dashboard. */
class WorkspaceListingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_current_filter_hides_archived_matters_while_every_other_view_still_reaches_them(): void
    {
        $admin = User::factory()->create();
        $open = Matter::factory()->create(['title' => 'Open lease review']);
        $closed = Matter::factory()->status(MatterStatus::Closed)->create(['title' => 'Closed estate']);
        $archived = Matter::factory()->status(MatterStatus::Archived)->create(['title' => 'Archived dispute']);
        $this->actingAsAdmin($admin);

        $current = $this->getJson('/api/matters?status=current')->assertOk();
        $this->assertEqualsCanonicalizing([$open->id, $closed->id], collect($current->json('data'))->pluck('id')->all());
        $this->getJson('/api/matters')->assertJsonPath('meta.total', 3); // the API's unfiltered default is unchanged
        $this->getJson('/api/matters?status=archived')->assertJsonPath('data.0.id', $archived->id);
        $this->getJson("/api/matters/{$archived->id}")->assertOk();
        $this->getJson('/api/matters?status=retired')->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $recent = collect($this->getJson('/api/dashboard/summary')->assertOk()->json('data.recent_matters'))->pluck('id');
        $this->assertTrue($recent->contains($open->id));
        $this->assertFalse($recent->contains($archived->id), 'the dashboard shows current work only');
        $this->getJson('/api/dashboard/summary')->assertJsonPath('data.stats.total_matters', 3); // counts stay complete
    }
}
