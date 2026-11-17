<?php

namespace Tests\Feature\Phase6;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CommunicationWorkflow;
use Tests\Support\PdfFactory;
use Tests\Support\RequestWorkflow;
use Tests\Support\SigningWorkflow;
use Tests\TestCase;

/** Phase 6 §22–§23: administrator notifications (creation, content, API, authorization). */
class NotificationTest extends TestCase
{
    use CommunicationWorkflow, RefreshDatabase, RequestWorkflow, SigningWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Mail::fake();
    }

    private function api(User $user, string $method, string $uri)
    {
        $this->app['auth']->forgetGuards(); // the guard caches the user between requests of one test

        return $this->actingAsAdmin($user)->json($method, $uri);
    }

    #[Test]
    public function client_actions_notify_the_administrators_with_safe_content(): void
    {
        $admin = $this->admin();
        [$request, $token] = $this->requestWithLink();
        $this->asGuest()->publicSubmit($token, PdfFactory::upload('agreement.pdf', ['Agreement']))->assertCreated();

        $list = $this->api($admin, 'GET', '/api/notifications')->assertOk()
            ->assertJsonPath('meta.unread_count', 1)
            ->assertJsonPath('data.0.kind', 'document_submitted')
            ->assertJsonPath('data.0.title', 'Client document received — Review required')
            ->assertJsonPath('data.0.href', "/matters/{$request->matter_id}/requests/{$request->id}")
            ->assertJsonPath('data.0.matter.id', $request->matter_id)
            ->assertJsonPath('data.0.read_at', null);
        $this->assertStringNotContainsString($token, $list->getContent());
        $this->assertStringNotContainsString('client-upload#', DB::table('notifications')->value('data'));

        // Client signature → notification; an administrator's own signature → none.
        [$signing, $signToken] = $this->clientSigning();
        $this->viewAndStart($signToken);
        $this->publicSigning('POST', '/complete', $signToken, $this->signaturePayload())->assertCreated();
        $this->api($admin, 'GET', '/api/notifications?unread=1')->assertOk()
            ->assertJsonPath('data.0.kind', 'document_signed')
            ->assertJsonPath('data.0.href', "/matters/{$signing->matter_id}/signatures/{$signing->id}");
        $matter = $this->signingMatter('Admin Signed Ltd');
        $document = $this->uploadDocument($matter, null, 'Admin doc');
        $before = DB::table('notifications')->count();
        $this->actingAsAdmin($this->signingAdmin())->postJson("/api/matters/{$matter->id}/documents/{$document->id}/sign",
            [...collect($this->signaturePayload())->except('confirmed_name')->all(), 'document_version_id' => $document->current_version_id])->assertCreated();
        $this->assertSame($before, DB::table('notifications')->count());

        // A rolled-back event leaves no notification: an upload refused for an archived matter notifies nobody.
        [$other, $otherToken] = $this->requestWithLink();
        DB::table('matters')->where('id', $other->matter_id)->update(['status' => 'archived', 'archived_at' => now(), 'archived_from_status' => 'open']);
        $this->asGuest()->publicSubmit($otherToken, PdfFactory::upload('late.pdf', ['Late']))->assertStatus(410);
        $this->assertSame($before, DB::table('notifications')->count());
    }

    #[Test]
    public function read_state_is_per_administrator_persistent_and_private(): void
    {
        $alice = $this->admin();
        $bob = User::factory()->create(['name' => 'Bob Admin']);
        [, $token] = $this->requestWithLink();
        $this->asGuest()->publicSubmit($token, PdfFactory::upload('a.pdf', ['A']))->assertCreated();
        [, $token2] = $this->requestWithLink();
        $this->asGuest()->publicSubmit($token2, PdfFactory::upload('b.pdf', ['B']))->assertCreated();

        $this->api($alice, 'GET', '/api/notifications/unread-count')->assertOk()->assertJsonPath('data.unread_count', 2);
        $this->api($bob, 'GET', '/api/notifications/unread-count')->assertOk()->assertJsonPath('data.unread_count', 2);
        $aliceFirst = $this->api($alice, 'GET', '/api/notifications')->json('data.0.id');
        $bobFirst = $this->api($bob, 'GET', '/api/notifications')->json('data.0.id');
        $this->assertNotSame($aliceFirst, $bobFirst, 'separate records per administrator');

        // Bob cannot read or mark Alice's notification.
        $this->api($bob, 'POST', "/api/notifications/{$aliceFirst}/read")->assertNotFound();
        $this->api($alice, 'POST', "/api/notifications/{$aliceFirst}/read")->assertOk()
            ->assertJsonPath('meta.unread_count', 1)->assertJsonPath('data.id', $aliceFirst);
        $this->assertNotNull($this->api($alice, 'GET', '/api/notifications')->json('data.0.read_at'), 'persisted');
        $this->api($bob, 'GET', '/api/notifications/unread-count')->assertJsonPath('data.unread_count', 2);

        $this->api($alice, 'POST', '/api/notifications/read-all')->assertOk()->assertJsonPath('data.marked', 1);
        $this->api($alice, 'GET', '/api/notifications?unread=1')->assertOk()->assertJsonCount(0, 'data');
        $this->api($bob, 'GET', '/api/notifications/unread-count')->assertJsonPath('data.unread_count', 2);
        $this->api($alice, 'POST', '/api/notifications/'.Str::uuid().'/read')->assertNotFound();
        $this->api($alice, 'POST', '/api/notifications/not-a-uuid/read')->assertNotFound();

        // Guests and client link holders have no access.
        $this->asGuest()->getJson('/api/notifications')->assertUnauthorized();
        $this->asGuest()->getJson('/api/notifications', ['X-Upload-Token' => $token, 'X-Signing-Token' => $token])->assertUnauthorized();
        $this->asGuest()->postJson('/api/notifications/read-all')->assertUnauthorized();
    }

    #[Test]
    public function a_crafted_notification_link_never_leaves_the_workspace(): void
    {
        $admin = $this->admin();
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(), 'type' => 'email_failed', 'notifiable_type' => 'user', 'notifiable_id' => $admin->id,
            'data' => json_encode(['kind' => 'email_failed', 'title' => 'x', 'body' => 'y', 'href' => '//evil.example/phish']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->api($admin, 'GET', '/api/notifications')->assertJsonPath('data.0.href', '/dashboard');
    }
}
