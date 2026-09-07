<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaginatedListRequest;
use App\Models\AppSetting;
use App\Models\WordpressFormVersion;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WordpressVersionController extends Controller
{
    use ApiResponds;

    public function index(PaginatedListRequest $request): JsonResponse
    {
        $query = WordpressFormVersion::orderByDesc('version')->orderByDesc('id');

        return $this->okPage($query, $request, fn (WordpressFormVersion $v) => [
            'id' => $v->id,
            'sourceUrl' => $v->source_url,
            'contentHash' => $v->content_hash,
            'version' => $v->version,
            'savedBy' => $v->saved_by,
            'createdAt' => $v->created_at?->toIso8601String(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $siteUrl = rtrim((string) (AppSetting::getValue('wordpress.siteUrl') ?? ''), '/');
        $token = (string) (AppSetting::getValue('wordpress.apiToken') ?? '');
        $payload = $request->input('payload');

        if (! is_array($payload)) {
            if ($siteUrl === '' || $token === '') {
                return $this->fail(
                    501,
                    'WORDPRESS_NOT_CONFIGURED',
                    'WordPress site URL and API token are not set — cannot snapshot forms.'
                );
            }
            try {
                $response = Http::withToken($token)
                    ->timeout(20)
                    ->acceptJson()
                    ->get($siteUrl.'/wp-json/wpforms/v1/forms');
            } catch (\Throwable $e) {
                return $this->fail(502, 'WORDPRESS_UPSTREAM_ERROR', $e->getMessage());
            }
            if ($response->failed()) {
                return $this->fail(502, 'WORDPRESS_UPSTREAM_ERROR', 'WordPress forms request failed.');
            }
            $decoded = $response->json();
            $payload = is_array($decoded) ? $decoded : [];
        }

        $source = $siteUrl !== '' ? $siteUrl : 'manual';
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $last = WordpressFormVersion::where('source_url', $source)->orderByDesc('version')->first();
        if ($last && $last->content_hash === $hash) {
            return $this->ok([
                'unchanged' => true,
                'version' => $last->version,
                'contentHash' => $last->content_hash,
            ]);
        }

        $row = WordpressFormVersion::create([
            'id' => 'wpv-'.Str::uuid(),
            'source_url' => $source,
            'content_hash' => $hash,
            'version' => ($last?->version ?? 0) + 1,
            'payload' => $payload,
            'saved_by' => $this->currentUser()->id,
        ]);
        AuditLogger::logAsCurrentUser('update', 'wordpress', 'form snapshot v'.$row->version);

        return $this->ok([
            'unchanged' => false,
            'id' => $row->id,
            'version' => $row->version,
            'contentHash' => $row->content_hash,
        ], 201);
    }
}
