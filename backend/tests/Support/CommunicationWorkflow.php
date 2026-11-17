<?php

namespace Tests\Support;

use App\Mail\ClientActionMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Testing\Fakes\MailFake;
use Tests\TestCase;

/**
 * Phase 6 helpers. Most tests capture messages with Mail::fake() (the content, recipient and the action URL
 * of each message are asserted); transport failures use a REAL SMTP connection to a closed local port; the
 * SMTP integration tests deliver to the isolated E2E Mailpit (never a real mailbox, never the dev inbox).
 *
 * @mixin TestCase
 */
trait CommunicationWorkflow
{
    /** @return list<ClientActionMail> */
    protected function sentMails(): array
    {
        return Mail::sent(ClientActionMail::class)->all();
    }

    protected function lastMail(): ClientActionMail
    {
        $mails = $this->sentMails();
        $this->assertNotEmpty($mails, 'an e-mail was sent');

        return end($mails);
    }

    /** The token inside an e-mail's action URL (fragment) — kept in memory only, never printed. */
    protected function tokenFromMail(ClientActionMail $mail, string $page = 'client-upload'): string
    {
        $url = (string) $mail->mailContent->actionUrl;
        $this->assertMatchesRegularExpression('#/'.$page.'\#[A-Za-z0-9_-]{43}$#', $url, 'the token travels in the URL fragment');

        return substr($url, strrpos($url, '#') + 1);
    }

    /** A real SMTP transport pointed at a closed local port: a genuine "connection refused". */
    protected function useUnreachableSmtp(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1, // nothing listens here
            'mail.mailers.smtp.timeout' => 3,
            'mail.mailers.smtp.scheme' => null,
            'mail.mailers.smtp.username' => null,
            'mail.mailers.smtp.password' => null,
        ]);
        $this->useRealMailer();
    }

    /** The isolated E2E Mailpit (docker compose service mailpit-e2e). */
    protected function useE2eMailpit(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => (int) (getenv('LEXORA_E2E_MAIL_SMTP_PORT') ?: 1027),
            'mail.mailers.smtp.timeout' => 5,
            'mail.mailers.smtp.scheme' => null,
            'mail.mailers.smtp.username' => null,
            'mail.mailers.smtp.password' => null,
        ]);
        $this->useRealMailer();
    }

    /** Undo Mail::fake(): the facade goes back to the real mail manager with the current configuration. */
    protected function useRealMailer(): void
    {
        $root = Mail::getFacadeRoot();
        // Mail::fake() also rebinds 'mail.manager' to the fake; the real manager is the fake's $manager.
        $real = $root instanceof MailFake ? $root->manager : $root;
        Mail::swap($real);
        $real->forgetMailers();
    }

    protected function e2eMailpitApi(): string
    {
        return 'http://127.0.0.1:'.(getenv('LEXORA_E2E_MAIL_UI_PORT') ?: 8027).'/api/v1';
    }
}
