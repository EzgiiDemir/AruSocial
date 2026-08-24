<?php

namespace App\Services;

// Used when FIREBASE_* is unset: inbox rows still persist, nothing is
// POSTed to Google. Tests bind FakeFcmClient instead of this.
class NullFcmClient implements FcmClient
{
    public function send(string $deviceToken, string $title, string $body, array $data): FcmSendResult
    {
        return FcmSendResult::ok();
    }
}
