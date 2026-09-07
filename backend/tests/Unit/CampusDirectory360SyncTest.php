<?php

namespace Tests\Unit;

use App\Services\CampusDirectory360Sync;
use PHPUnit\Framework\TestCase;

class CampusDirectory360SyncTest extends TestCase
{
    public function test_absolute_tour_url_preserves_query_and_fragment(): void
    {
        $sync = new CampusDirectory360Sync;
        $relative = '/vista_export/Atelier/index.htm?media-name=GW_INT_53#media-name=GW_INT_53';
        $base = 'https://360.arucad.edu.tr';

        $this->assertSame(
            'https://360.arucad.edu.tr/vista_export/Atelier/index.htm?media-name=GW_INT_53#media-name=GW_INT_53',
            $sync->absoluteTourUrl($relative, $base),
        );
    }

    public function test_absolute_tour_url_keeps_absolute_url_with_fragment(): void
    {
        $sync = new CampusDirectory360Sync;
        $absolute = 'https://360.arucad.edu.tr/vista_export/Main/index.htm?media-name=RODIN#media-name=RODIN';

        $this->assertSame(
            $absolute,
            $sync->absoluteTourUrl($absolute, 'https://360.arucad.edu.tr'),
        );
    }

    public function test_absolute_tour_url_rejects_blank_and_non_http(): void
    {
        $sync = new CampusDirectory360Sync;

        $this->assertNull($sync->absoluteTourUrl('', 'https://360.arucad.edu.tr'));
        $this->assertNull($sync->absoluteTourUrl('ftp://example.com/tour', 'https://360.arucad.edu.tr'));
        $this->assertNull($sync->absoluteTourUrl(null, 'https://360.arucad.edu.tr'));
    }
}
