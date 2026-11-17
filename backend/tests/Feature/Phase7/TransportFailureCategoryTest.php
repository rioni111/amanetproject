<?php

namespace Tests\Feature\Phase7;

use App\Enums\EmailFailureCategory;
use App\Services\Communications\EmailDeliveryService;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Tests\TestCase;

/**
 * Phase 7 issue P7-014 (found by the final verification run): a server that accepts the TCP connection and closes
 * it before greeting produced either a TransportException ("closed unexpectedly") or an UnexpectedResponseException
 * with no reply code ("… but got empty code"), depending on timing — and the second one was reported as "unknown".
 * Both now mean the same thing to the administrator: the mail server could not be reached.
 */
class TransportFailureCategoryTest extends TestCase
{
    #[Test]
    public function a_dropped_connection_is_a_connection_failure_whichever_exception_symfony_throws(): void
    {
        $closed = new TransportException('Connection to "127.0.0.1:1028" has been closed unexpectedly.');
        $empty = new UnexpectedResponseException('Expected response code "220" but got empty code.', 0);

        $this->assertSame(EmailFailureCategory::Connection, EmailDeliveryService::categorize($closed));
        $this->assertSame(EmailFailureCategory::Connection, EmailDeliveryService::categorize($empty));
        $this->assertFalse(EmailFailureCategory::Connection->definitelyNotDelivered(), 'a dropped connection keeps the link reservation');
    }

    #[Test]
    public function real_smtp_replies_keep_their_categories(): void
    {
        $this->assertSame(EmailFailureCategory::RecipientRejected, EmailDeliveryService::categorize(new UnexpectedResponseException('550 5.1.1 User unknown', 550)));
        $this->assertSame(EmailFailureCategory::Authentication, EmailDeliveryService::categorize(new UnexpectedResponseException('535 Authentication failed', 535)));
        $this->assertSame(EmailFailureCategory::Connection, EmailDeliveryService::categorize(new UnexpectedResponseException('421 Service not available', 421)));
        $this->assertSame(EmailFailureCategory::Unknown, EmailDeliveryService::categorize(new UnexpectedResponseException('Expected response code "250" but got code "600".', 600)));
    }
}
