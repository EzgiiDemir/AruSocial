<?php

namespace Tests\Fakes;

use App\Services\FcmClient;
use App\Services\FcmSendResult;

class FakeFcmClient implements FcmClient
{
    /** @var list<array{token: string, title: string, body: string, data: array}> */
    public array $sent = [];

    /** @var array<string, true> */
    public array $invalidTokens = [];

    public bool $throw = false;

    public function send(string $deviceToken, string $title, string $body, array $data): FcmSendResult
    {
        $this->sent[] = [
            'token' => $deviceToken,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ];
        if ($this->throw) {
            throw new \RuntimeException('FCM unavailable');
        }
        if (isset($this->invalidTokens[$deviceToken])) {
            return FcmSendResult::invalidToken();
        }

        return FcmSendResult::ok();
    }
}
