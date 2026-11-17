<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;

class AngelOneClient
{
    private const RATE_LIMIT_CACHE_KEY = 'angelone.rate-limit-until';

    public function isConfigured(): bool
    {
        foreach ([
            'api_key',
            'client_code',
            'pin',
            'totp_secret',
            'client_local_ip',
            'client_public_ip',
            'mac_address',
        ] as $key) {
            if (blank(config("angelone.{$key}"))) {
                return false;
            }
        }

        return true;
    }

    public function isRateLimited(): bool
    {
        return Cache::has(self::RATE_LIMIT_CACHE_KEY);
    }

    public function rateLimitRetryAt(): ?string
    {
        return Cache::get(self::RATE_LIMIT_CACHE_KEY);
    }

    /**
     * @return array{jwt_token: string, feed_token: string}
     */
    public function websocketCredentials(): array
    {
        $tokens = $this->sessionTokens();

        if (blank($tokens['feedToken'] ?? null)) {
            throw new RuntimeException('Angel One login did not return a feed token for WebSocket access.');
        }

        return [
            'jwt_token' => $tokens['jwtToken'],
            'feed_token' => $tokens['feedToken'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function instruments(): array
    {
        return Cache::remember('angelone.instrument-master', now()->addHours(12), function (): array {
            $path = tempnam(storage_path('app'), 'angelone-instruments-');

            if ($path === false) {
                throw new RuntimeException('Unable to create a temporary instrument-master file.');
            }

            try {
                $response = Http::withOptions(['sink' => $path])
                    ->acceptJson()
                    ->connectTimeout(15)
                    ->timeout(120)
                    ->get(config('angelone.instrument_master_url'));
                $response->throw();

                $instruments = $this->niftyInstrumentsFromFile($path);

                if ($instruments === []) {
                    throw new RuntimeException('Angel One instrument master contained no NIFTY futures or options.');
                }

                return $instruments;
            } finally {
                if (is_file($path) && ! unlink($path)) {
                    throw new RuntimeException('Unable to remove the temporary instrument-master file.');
                }
            }
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function niftyInstrumentsFromFile(string $path): array
    {
        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Unable to read the downloaded Angel One instrument master.');
        }

        $instruments = [];
        $insideObject = false;
        $insideString = false;
        $escaped = false;
        $depth = 0;
        $object = '';

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 65536);

                if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                    throw new RuntimeException('Unable to read the downloaded Angel One instrument master.');
                }

                $chunkLength = strlen($chunk);

                for ($index = 0; $index < $chunkLength; $index++) {
                    $character = $chunk[$index];

                    if (! $insideObject) {
                        if ($character === '{') {
                            $insideObject = true;
                            $depth = 1;
                            $object = '{';
                        }

                        continue;
                    }

                    $object .= $character;

                    if ($insideString) {
                        if ($escaped) {
                            $escaped = false;
                        } elseif ($character === '\\') {
                            $escaped = true;
                        } elseif ($character === '"') {
                            $insideString = false;
                        }

                        continue;
                    }

                    if ($character === '"') {
                        $insideString = true;

                        continue;
                    }

                    if ($character === '{') {
                        $depth++;
                    } elseif ($character === '}') {
                        $depth--;

                        if ($depth === 0) {
                            try {
                                $instrument = json_decode($object, true, flags: JSON_THROW_ON_ERROR);
                            } catch (JsonException $exception) {
                                throw new RuntimeException('Angel One returned an invalid instrument-master entry.', previous: $exception);
                            }

                            if (($instrument['name'] ?? null) === 'NIFTY'
                                && ($instrument['exch_seg'] ?? null) === 'NFO'
                                && in_array($instrument['instrumenttype'] ?? null, ['FUTIDX', 'OPTIDX'], true)) {
                                $instruments[] = $instrument;
                            }

                            $object = '';
                            $insideObject = false;
                        }
                    }
                }
            }
        } finally {
            fclose($stream);
        }

        if ($insideObject) {
            throw new RuntimeException('Angel One returned a truncated instrument master.');
        }

        return $instruments;
    }

    /**
     * @return list<array<int, string|int|float|null>>
     */
    public function candles(string $token, string $from, string $to): array
    {
        $data = $this->authenticatedPost('/rest/secure/angelbroking/historical/v1/getCandleData', [
            'exchange' => 'NFO',
            'symboltoken' => $token,
            'interval' => 'FIVE_MINUTE',
            'fromdate' => $from,
            'todate' => $to,
        ]);

        if (! isset($data['data']) || ! is_array($data['data'])) {
            throw new RuntimeException('Angel One did not return candle data.');
        }

        return $data['data'];
    }

    public function ltp(string $exchange, string $symbol, string $token): float
    {
        $data = $this->authenticatedPost('/rest/secure/angelbroking/order/v1/getLtpData', [
            'exchange' => $exchange,
            'tradingsymbol' => $symbol,
            'symboltoken' => $token,
        ]);

        if (! isset($data['data']['ltp']) || ! is_numeric($data['data']['ltp'])) {
            throw new RuntimeException("Angel One did not return an LTP for {$symbol}.");
        }

        return (float) $data['data']['ltp'];
    }

    /**
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    private function authenticatedPost(string $path, array $payload): array
    {
        if (str_contains($path, '/historical/') && $this->isRateLimited()) {
            throw new RuntimeException('Angel One rate limit cooldown is active until '.$this->rateLimitRetryAt().'.');
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $response = Http::withHeaders($this->headers($this->jwtToken()))
                ->acceptJson()
                ->timeout(15)
                ->post(config('angelone.base_url').$path, $payload);

            $body = $response->json();

            $tokenExpired = $response->status() === 401
                || (is_array($body) && in_array($body['errorcode'] ?? null, ['AG8001', 'AG8002'], true))
                || (is_array($body) && ($body['error_type'] ?? null) === 'TokenException');

            if ($attempt === 0 && $tokenExpired) {
                Cache::forget($this->sessionCacheKey());

                continue;
            }

            $errorMessage = is_array($body)
                ? (string) ($body['message'] ?? '')
                : $response->body();

            if ($response->status() === 403 && str_contains(strtolower($errorMessage), 'access rate')) {
                $retryAt = now()->addMinutes((int) config('angelone.rate_limit_cooldown_minutes'));
                Cache::put(self::RATE_LIMIT_CACHE_KEY, $retryAt->toIso8601String(), $retryAt);

                throw new RuntimeException('Angel One rate limit reached. Scanning paused until '.$retryAt->toIso8601String().'.');
            }

            $response->throw();

            if (! is_array($body)) {
                throw new RuntimeException('Angel One returned an invalid API response.');
            }

            if (($body['status'] ?? false) === true && is_array($body['data'] ?? null)) {
                return $body;
            }

            throw new RuntimeException($body['message'] ?? 'Angel One rejected the market-data request.');
        }

        throw new RuntimeException('Angel One authentication failed after retrying.');
    }

    private function jwtToken(): string
    {
        return $this->sessionTokens()['jwtToken'];
    }

    /**
     * @return array{jwtToken: string, feedToken: string|null}
     */
    private function sessionTokens(): array
    {
        $encryptedTokens = Cache::remember($this->sessionCacheKey(), now()->addHours(12), function (): string {
            if (! $this->isConfigured()) {
                throw new RuntimeException('Complete the Angel One settings in the server .env file first.');
            }

            $response = Http::withHeaders($this->headers())
                ->acceptJson()
                ->timeout(15)
                ->post(config('angelone.base_url').'/rest/auth/angelbroking/user/v1/loginByPassword', [
                    'clientcode' => config('angelone.client_code'),
                    'password' => config('angelone.pin'),
                    'totp' => $this->totpCode((string) config('angelone.totp_secret')),
                ]);
            $response->throw();
            $body = $response->json();

            if (! is_array($body) || ($body['status'] ?? false) !== true || blank($body['data']['jwtToken'] ?? null)) {
                throw new RuntimeException(is_array($body) ? ($body['message'] ?? 'Angel One login failed.') : 'Angel One returned an invalid login response.');
            }

            return Crypt::encryptString(json_encode([
                'jwtToken' => $body['data']['jwtToken'],
                'feedToken' => $body['data']['feedToken'] ?? null,
            ], JSON_THROW_ON_ERROR));
        });

        $tokens = json_decode(Crypt::decryptString($encryptedTokens), true);

        if (! is_array($tokens) || blank($tokens['jwtToken'] ?? null)) {
            throw new RuntimeException('Angel One session credentials are invalid.');
        }

        return [
            'jwtToken' => $tokens['jwtToken'],
            'feedToken' => is_string($tokens['feedToken'] ?? null) ? $tokens['feedToken'] : null,
        ];
    }

    private function sessionCacheKey(): string
    {
        return 'angelone.session-token-v2.'.hash('sha256', config('angelone.client_code').'|'.config('angelone.api_key'));
    }

    /**
     * @return array<string, string>
     */
    private function headers(?string $jwt = null): array
    {
        $headers = [
            'X-PrivateKey' => (string) config('angelone.api_key'),
            'X-UserType' => 'USER',
            'X-SourceID' => 'WEB',
            'X-ClientLocalIP' => (string) config('angelone.client_local_ip'),
            'X-ClientPublicIP' => (string) config('angelone.client_public_ip'),
            'X-MACAddress' => (string) config('angelone.mac_address'),
        ];

        if ($jwt !== null) {
            $headers['Authorization'] = 'Bearer '.$jwt;
        }

        return $headers;
    }

    private function totpCode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper(rtrim($secret, '='));
        $bits = '';

        foreach (str_split($secret) as $character) {
            $value = strpos($alphabet, $character);

            if ($value === false) {
                throw new RuntimeException('The Angel One TOTP secret is not valid Base32.');
            }

            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $key = '';

        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $key .= chr(bindec($byte));
            }
        }

        if ($key === '') {
            throw new RuntimeException('The Angel One TOTP secret is empty or invalid.');
        }

        $counter = (int) floor(time() / 30);
        $hash = hash_hmac('sha1', pack('N2', 0, $counter), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $number = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($number % 1_000_000), 6, '0', STR_PAD_LEFT);
    }
}
