<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Http;

/**
 * Verifies a Microsoft Entra (Azure AD) ID token (RS256) against the
 * tenant JWKS. No extra Composer package — openssl + the published keys.
 * Unconfigured tenant/client → callers must return 501, not skip verify.
 */
class EntraIdTokenVerifier
{
    public function configured(): bool
    {
        return $this->tenantId() !== '' && $this->clientId() !== '';
    }

    public function tenantId(): string
    {
        return $this->firstNonEmpty([
            (string) config('services.entra.tenant_id', ''),
            (string) (AppSetting::getValue('entra.tenantId') ?? ''),
        ]);
    }

    public function clientId(): string
    {
        return $this->firstNonEmpty([
            (string) config('services.entra.client_id', ''),
            (string) (AppSetting::getValue('entra.clientId') ?? ''),
        ]);
    }

    public function redirectUri(): string
    {
        return $this->firstNonEmpty([
            (string) config('services.entra.redirect_uri', ''),
            (string) (AppSetting::getValue('entra.redirectUri') ?? ''),
        ]);
    }

    /**
     * @return array{oid: string, email: string, name: string, tid: string, sub: string}
     */
    public function verify(string $idToken): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new EntraTokenException('Malformed ID token.');
        }

        $header = $this->decodeJson($parts[0]);
        $payload = $this->decodeJson($parts[1]);
        $signature = $this->b64urlDecode($parts[2]);

        $alg = (string) ($header['alg'] ?? '');
        if ($alg !== 'RS256') {
            throw new EntraTokenException('Unsupported token algorithm.');
        }

        $kid = (string) ($header['kid'] ?? '');
        $jwk = $this->jwkForKid($kid);
        $pem = $this->jwkToPem((string) $jwk['n'], (string) $jwk['e']);
        $ok = openssl_verify($parts[0].'.'.$parts[1], $signature, $pem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new EntraTokenException('Invalid token signature.');
        }

        $now = time();
        $exp = (int) ($payload['exp'] ?? 0);
        $nbf = (int) ($payload['nbf'] ?? 0);
        if ($exp > 0 && $now >= $exp) {
            throw new EntraTokenException('Token expired.');
        }
        if ($nbf > 0 && $now < $nbf) {
            throw new EntraTokenException('Token not yet valid.');
        }

        $aud = $payload['aud'] ?? '';
        $audience = is_array($aud) ? ($aud[0] ?? '') : (string) $aud;
        if ($audience !== $this->clientId()) {
            throw new EntraTokenException('Token audience mismatch.');
        }

        $tid = (string) ($payload['tid'] ?? '');
        $iss = (string) ($payload['iss'] ?? '');
        $tenant = $this->tenantId();
        $expectedIss = 'https://login.microsoftonline.com/'.$tenant.'/v2.0';
        if ($iss !== $expectedIss) {
            throw new EntraTokenException('Token issuer mismatch.');
        }
        if ($tid !== '' && strcasecmp($tid, $tenant) !== 0) {
            throw new EntraTokenException('Token tenant mismatch.');
        }

        $email = strtolower(trim((string) (
            $payload['email']
            ?? $payload['preferred_username']
            ?? $payload['upn']
            ?? ''
        )));
        if ($email === '' || ! str_contains($email, '@')) {
            throw new EntraTokenException('Token has no email claim.');
        }

        if (! $this->emailDomainAllowed($email)) {
            throw new EntraTokenException('Email domain is not allowed.');
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            $name = strstr($email, '@', true) ?: $email;
        }

        return [
            'oid' => (string) ($payload['oid'] ?? $payload['sub'] ?? $email),
            'sub' => (string) ($payload['sub'] ?? ''),
            'tid' => $tid !== '' ? $tid : $tenant,
            'email' => $email,
            'name' => $name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jwkForKid(string $kid): array
    {
        $tenant = $this->tenantId();
        $url = 'https://login.microsoftonline.com/'.$tenant.'/discovery/v2.0/keys';
        $response = Http::timeout(10)
            ->withOptions(['verify' => (bool) config('services.entra.verify_ssl', true)])
            ->get($url);
        if ($response->failed()) {
            throw new EntraTokenException('Could not fetch Entra signing keys.');
        }
        $keys = $response->json('keys') ?? [];
        if (! is_array($keys)) {
            throw new EntraTokenException('Entra JWKS was not a key list.');
        }
        foreach ($keys as $key) {
            if (! is_array($key)) {
                continue;
            }
            if ($kid !== '' && ($key['kid'] ?? '') !== $kid) {
                continue;
            }
            if (($key['kty'] ?? '') === 'RSA' && isset($key['n'], $key['e'])) {
                return $key;
            }
        }

        throw new EntraTokenException('No matching Entra signing key.');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $segment): array
    {
        $json = $this->b64urlDecode($segment);
        $data = json_decode($json, true);
        if (! is_array($data)) {
            throw new EntraTokenException('Malformed token payload.');
        }

        return $data;
    }

    private function b64urlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new EntraTokenException('Malformed token encoding.');
        }

        return $decoded;
    }

    public function jwkToPem(string $n, string $e): string
    {
        $modulus = $this->b64urlDecode($n);
        $exponent = $this->b64urlDecode($e);
        $rsaPublicKey = $this->derSequence($this->derInteger($modulus).$this->derInteger($exponent));
        $algId = hex2bin('300d06092a864886f70d0101010500');
        $bitString = "\x03".$this->derLength(strlen($rsaPublicKey) + 1)."\x00".$rsaPublicKey;
        $spki = $this->derSequence($algId.$bitString);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n").'-----END PUBLIC KEY-----';
    }

    private function derSequence(string $inner): string
    {
        return "\x30".$this->derLength(strlen($inner)).$inner;
    }

    private function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".$this->derLength(strlen($bytes)).$bytes;
    }

    private function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bin = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bin)).$bin;
    }

    /**
     * @param  list<string>  $values
     */
    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return '';
    }

    private function emailDomainAllowed(string $email): bool
    {
        $domains = config('auth.allowed_email_domains');
        if (! is_array($domains) || $domains === []) {
            $single = trim((string) config('auth.allowed_email_domain', '@arucad.edu.tr'));
            $domains = $single !== '' ? [$single] : [];
        }

        foreach ($domains as $domain) {
            $domain = trim((string) $domain);
            if ($domain !== '' && str_ends_with($email, strtolower($domain))) {
                return true;
            }
        }

        return false;
    }
}
