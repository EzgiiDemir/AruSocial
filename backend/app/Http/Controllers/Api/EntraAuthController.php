<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EntraIdTokenVerifier;
use App\Services\EntraTokenException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EntraAuthController extends Controller
{
    use ApiResponds;

    public function config(EntraIdTokenVerifier $verifier): JsonResponse
    {
        $configured = $verifier->configured();

        return $this->ok([
            'configured' => $configured,
            'tenantId' => $configured ? $verifier->tenantId() : '',
            'clientId' => $configured ? $verifier->clientId() : '',
            'redirectUri' => $configured ? $verifier->redirectUri() : '',
        ]);
    }

    public function session(Request $request, EntraIdTokenVerifier $verifier): JsonResponse
    {
        if (! $verifier->configured()) {
            return $this->fail(
                501,
                'ENTRA_NOT_CONFIGURED',
                'Microsoft Entra is not configured on this server (ENTRA_TENANT_ID / ENTRA_CLIENT_ID).'
            );
        }

        $idToken = (string) $request->input('idToken', '');
        if ($idToken === '') {
            return $this->fail(400, 'VALIDATION', 'idToken is required.');
        }

        try {
            $claims = $verifier->verify($idToken);
        } catch (EntraTokenException $e) {
            return $this->fail(401, 'ENTRA_TOKEN_INVALID', $e->getMessage());
        }

        $user = User::where('email', $claims['email'])->first();
        if ($user === null) {
            $user = User::create([
                'name' => $claims['name'],
                'email' => $claims['email'],
                'password' => Str::password(32),
                'role' => 'student',
            ]);
        }

        if ($user->isBanned()) {
            return $this->fail(403, 'ACCOUNT_BANNED',
                'Bu hesap topluluk kurallarını ihlal nedeniyle askıya alındı.');
        }

        $token = $user->createToken('arucad-app')->plainTextToken;

        return $this->ok(['user' => $user->toApiArray(), 'token' => $token]);
    }
}
