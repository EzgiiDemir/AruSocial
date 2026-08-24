<?php

namespace App\Services;

interface FcmClient
{
    /**
     * Deliver one notification to a single device token.
     * Must not log the token. Temporary failures throw; permanent
     * token death is returned as invalidToken so the caller can drop it.
     */
    public function send(string $deviceToken, string $title, string $body, array $data): FcmSendResult;
}
