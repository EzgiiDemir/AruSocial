<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiContractInventoryTest extends TestCase
{
    public function test_documented_inventory_matches_registered_api_routes(): void
    {
        $fromLaravel = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $fromLaravel[$method.' /'.$uri] = true;
            }
        }
        $fromLaravel = array_keys($fromLaravel);
        sort($fromLaravel);

        $markdown = file_get_contents(base_path('../docs/API_CONTRACT.md'));
        $this->assertNotFalse($markdown);
        $offset = strpos($markdown, '## Route inventory (canonical,');
        $this->assertNotFalse($offset);
        $this->assertSame(1, preg_match('/```\R(.*)\R```/s', substr($markdown, $offset), $match));
        // The contract is edited on both Windows and Unix.  Normalise the
        // fenced inventory before comparing it so CRLF does not become part
        // of every documented route name on Windows checkouts.
        $fromDoc = array_values(array_filter(
            preg_split('/\R/', trim($match[1])) ?: [],
            fn ($line) => $line !== '',
        ));
        sort($fromDoc);

        $this->assertSame(
            $fromLaravel,
            $fromDoc,
            'docs/API_CONTRACT.md inventory drifted from php artisan route:list --path=api',
        );
        // A deliberate second gate: the comparison above only proves the
        // doc and the router agree, so both could drift together if a
        // route were added and documented without anyone noticing. This
        // number has to be changed by hand, which is the point.
        //
        // +1: POST /me/applications/{id}/cancel — a student withdrawing
        //     their own in-flight application.
        // +7: the moderation case queue, appeals, and a student's own
        //     appeal endpoints (Phase 2).
        $this->assertCount(258, $fromLaravel);
    }
}
