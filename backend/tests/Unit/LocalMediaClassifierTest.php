<?php

namespace Tests\Unit;

use App\Services\LocalMediaClassifier;
use Tests\TestCase;

class LocalMediaClassifierTest extends TestCase
{
    public function test_missing_local_model_never_marks_media_clean_or_public(): void
    {
        config(['services.local_moderation.binary' => 'C:/not-a-real-arucad-model']);

        $decision = LocalMediaClassifier::inspect(__FILE__, 'image/jpeg');

        $this->assertFalse(LocalMediaClassifier::isConfigured());
        $this->assertFalse($decision['available']);
        $this->assertFalse($decision['blocked']);
        $this->assertSame([], $decision['categories']);
    }
}
