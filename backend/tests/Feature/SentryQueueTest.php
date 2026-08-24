<?php

namespace Tests\Feature;

use App\Jobs\DeliverFcmNotification;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\Concerns\FakesSentry;
use Tests\TestCase;

// P3-6 §11: queue job exceptions reach Sentry without changing the job's
// own failure handling (it still swallows, still clears its dedupe key,
// still doesn't rethrow — unaffected by whether Sentry is configured).
class SentryQueueTest extends TestCase
{
    use FakesSentry;

    public function test_fcm_job_failure_is_reported_without_changing_swallow_behavior(): void
    {
        $transport = $this->fakeSentry();

        $push = Mockery::mock(PushNotificationService::class);
        $push->shouldReceive('sendToUser')->once()->andThrow(new \RuntimeException('fcm boom'));

        $job = new DeliverFcmNotification(1, 'Title', 'Body', [], 'dedupe-key-sentry-test');

        // Same dedupe key handling as PushNotificationService::sendToUser
        // wires up: Cache::add must succeed first for handle() to attempt
        // delivery at all.
        $job->handle($push);

        $this->assertCount(1, $transport->events);
        $this->assertSame('fcm boom', $transport->events[0]->getExceptions()[0]->getValue());

        // The job's own failure handling (log + dedupe cache clear, no
        // rethrow) is completely unchanged — Sentry is purely additive.
        $this->assertFalse(Cache::has('fcm.sent.dedupe-key-sentry-test'));
    }

    public function test_fcm_job_success_reports_nothing_to_sentry(): void
    {
        $transport = $this->fakeSentry();

        $push = Mockery::mock(PushNotificationService::class);
        $push->shouldReceive('sendToUser')->once();

        $job = new DeliverFcmNotification(2, 'Title', 'Body', [], 'dedupe-key-sentry-success');
        $job->handle($push);

        $this->assertCount(0, $transport->events);
        $this->assertTrue(Cache::has('fcm.sent.dedupe-key-sentry-success'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
