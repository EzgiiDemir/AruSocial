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
        $fromDoc = array_values(array_filter(explode("\n", $match[1]), fn ($line) => $line !== ''));
        sort($fromDoc);

        $this->assertSame(
            $fromLaravel,
            $fromDoc,
            'docs/API_CONTRACT.md inventory drifted from php artisan route:list --path=api',
        );
        $this->assertCount(108, $fromLaravel);
    }
}
