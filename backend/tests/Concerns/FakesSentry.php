<?php

namespace Tests\Concerns;

use App\Support\SentryScrubber;
use Sentry\ClientBuilder;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Tests\Fakes\FakeSentryTransport;

// Binds a real Sentry client (so our before_send scrubbing runs exactly as
// in production) with a fake DSN and an in-memory transport, so no test
// ever makes a real network call to Sentry (P3-6 §21).
trait FakesSentry
{
    protected function fakeSentry(): FakeSentryTransport
    {
        $transport = new FakeSentryTransport;

        $client = ClientBuilder::create([
            'dsn' => 'http://public@localhost/1',
            'transport' => $transport,
            'send_default_pii' => false,
            'before_send' => [SentryScrubber::class, 'scrubEvent'],
        ])->getClient();

        SentrySdk::setCurrentHub(new Hub($client));

        return $transport;
    }
}
