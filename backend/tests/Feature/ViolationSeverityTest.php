<?php

namespace Tests\Feature;

use App\Services\Moderation\ViolationSeverity;
use App\Services\Moderation\Workflow\ReportReason;
use Tests\TestCase;

/**
 * The map that decides what a violation costs.
 *
 * It is read by every path that can charge an account, from three
 * different category vocabularies, so the thing worth pinning is that all
 * three are actually covered and that they agree with the bands a
 * moderator already works to.
 */
class ViolationSeverityTest extends TestCase
{
    public function test_the_offline_engine_codes_are_mapped(): void
    {
        $this->assertSame('critical', ViolationSeverity::for('THR'));
        $this->assertSame('severe', ViolationSeverity::for('HATE'));
        $this->assertSame('serious', ViolationSeverity::for('HAR'));
        $this->assertSame('minor', ViolationSeverity::for('SPAM'));
    }

    public function test_the_provider_codes_are_mapped(): void
    {
        $this->assertSame('critical', ViolationSeverity::for('sexual/minors'));
        $this->assertSame('critical', ViolationSeverity::for('harassment/threatening'));
        $this->assertSame('severe', ViolationSeverity::for('violence/graphic'));
        $this->assertSame('serious', ViolationSeverity::for('illicit'));
    }

    public function test_the_image_classifier_codes_are_mapped(): void
    {
        $this->assertSame('severe', ViolationSeverity::for('nsfw'));
        $this->assertSame('severe', ViolationSeverity::for('clip_nudity'));
        $this->assertSame('severe', ViolationSeverity::for('clip_gore'));
        $this->assertSame('minor', ViolationSeverity::for('clip_swimwear'));
    }

    public function test_matching_ignores_case(): void
    {
        $this->assertSame('critical', ViolationSeverity::for('thr'));
        $this->assertSame('severe', ViolationSeverity::for('NSFW'));
    }

    /**
     * A category nobody has mapped is still a refusal that happened.
     * Charging nothing for it would make the gap invisible.
     */
    public function test_an_unknown_category_is_charged_as_minor(): void
    {
        $this->assertSame('minor', ViolationSeverity::for('something_nobody_mapped'));
        $this->assertSame('minor', ViolationSeverity::for(''));
    }

    public function test_the_worst_category_in_a_set_decides(): void
    {
        $this->assertSame('critical', ViolationSeverity::highest(['SPAM', 'THR']));
        $this->assertSame('severe', ViolationSeverity::highest(['PROF', 'HATE', 'HAR']));
        $this->assertSame('minor', ViolationSeverity::highest(['PROF', 'SPAM']));
        $this->assertSame('minor', ViolationSeverity::highest([]));
    }

    public function test_the_label_written_to_the_violation_row_is_the_worst_one(): void
    {
        $this->assertSame('THR', ViolationSeverity::primaryCategory(['SPAM', 'THR', 'PROF']));
        $this->assertSame('policy', ViolationSeverity::primaryCategory([]));
    }

    /**
     * The point of having one map: an automatic decision and a moderator
     * confirming a report of the same behaviour must cost the same.
     */
    public function test_it_agrees_with_the_report_reasons_a_moderator_works_to(): void
    {
        $equivalents = [
            ReportReason::Threat->value => 'THR',
            ReportReason::Hate->value => 'HATE',
            ReportReason::SexualContent->value => 'SEX',
            ReportReason::PersonalInformation->value => 'PRIV',
            ReportReason::Harassment->value => 'HAR',
            ReportReason::Violence->value => 'VIO',
            ReportReason::Scam->value => 'SCAM',
            ReportReason::Drugs->value => 'DRUG',
            ReportReason::Impersonation->value => 'IMP',
            ReportReason::Spam->value => 'SPAM',
        ];

        foreach ($equivalents as $reasonValue => $classifierCode) {
            $reason = ReportReason::from($reasonValue);

            $this->assertSame(
                $reason->severityIfConfirmed(),
                ViolationSeverity::for($classifierCode),
                "{$reasonValue} and {$classifierCode} describe the same act and must cost the same.",
            );
        }
    }
}
