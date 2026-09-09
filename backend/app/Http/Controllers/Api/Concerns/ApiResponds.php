<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Http\Requests\PaginatedListRequest;
use App\Models\User;
use App\Services\Moderation\ModerationNotice;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

// Wraps every response in the {data, meta, error} envelope from
// docs/API_CONTRACT.md — the same contract the Node prototype backend used,
// so RestCampusRepository's parsing code needed zero changes to point here.
trait ApiResponds
{
    protected function ok($data, int $status = 200, ?array $pagination = null): JsonResponse
    {
        $meta = ['request_id' => $this->requestId()];
        if ($pagination !== null) {
            $meta['pagination'] = $pagination;
        }

        // A publishable-but-notable moderation result rides along in meta.
        //
        // "warned", "review" and "support" all let the content through, so
        // the controller returns a plain success and the client had no way
        // to know anything happened. That silently discarded the self-harm
        // support message — the one outcome where telling the person
        // matters most — and left "sent for review" invisible too.
        //
        // Attached here rather than at each call site so no endpoint can
        // forget: every controller already answers through ok().
        $notice = ModerationNotice::take();
        if ($notice !== null) {
            $meta['moderation'] = $notice;
        }

        return response()->json([
            'data' => $data,
            'meta' => $meta,
            'error' => null,
        ], $status);
    }

    /**
     * Length-aware page: filter/sort already applied on $query.
     * Query params `page` (default 1) and `perPage` (default 20, max 50).
     *
     * @param  callable(mixed): mixed  $map
     */
    protected function okPage($query, PaginatedListRequest $request, callable $map): JsonResponse
    {
        $page = max(1, (int) $request->input('page', 1));
        $perPage = (int) $request->input('perPage', 20);
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return $this->ok(
            $paginator->getCollection()->map($map)->values(),
            200,
            [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $details  Optional business fields (e.g. distanceMeters)
     */
    protected function fail(int $status, string $code, string $message, array $details = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return response()->json([
            'data' => null,
            'meta' => ['request_id' => $this->requestId()],
            'error' => $error,
        ], $status);
    }

    protected function newId(string $prefix): string
    {
        return $prefix.'-'.Str::uuid();
    }

    // One request_id per HTTP request rather than per response call, stored
    // on the request itself so AttachSentryContext's `request_id` Sentry tag
    // (set earlier in the middleware stack) always matches the id a client
    // sees in `meta.request_id` for the same request (P3-6 §9).
    protected function requestId(): string
    {
        $request = request();
        $existing = $request?->attributes->get('_request_id');
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $generated = 'req-'.Str::uuid();
        $request?->attributes->set('_request_id', $generated);

        return $generated;
    }

    // The real user this request authenticated as, resolved from its
    // Sanctum bearer token. Every route that reaches this is behind
    // `auth:sanctum`, so a missing user means the middleware was bypassed
    // rather than an anonymous-but-allowed request — reported as the same
    // 401 envelope instead of failing later on a null.
    protected function currentUser(): User
    {
        $user = request()->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
