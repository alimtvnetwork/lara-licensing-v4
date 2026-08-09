<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\AuthException;


use App\Exceptions\LaraException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Plan 09 login modernization. Stateless HMAC-signed math CAPTCHA.
 *
 * Wire format for ChallengeId:
 *   base64url(json({A, B, Op, Exp})) . '.' . base64url(hmac_sha256(payload, APP_KEY))
 *
 * Op is one of Add|Sub|Mul, values bounded to keep the human task trivial
 * (both operands in 1..9). Exp is a unix timestamp; verify() rejects
 * expired challenges and forged signatures with LoginCaptchaInvalid.
 */
final class LoginCaptcha
{
    private const OPERATIONS = ['Add', 'Sub', 'Mul'];
    private const MIN_OPERAND = 1;
    private const MAX_OPERAND = 9;

    public static function issue(): array
    {
        $ttl = (int) Config::get('lara.login_captcha.challenge_ttl_seconds', 300);
        $a = random_int(self::MIN_OPERAND, self::MAX_OPERAND);
        $b = random_int(self::MIN_OPERAND, self::MAX_OPERAND);
        $op = self::OPERATIONS[random_int(0, count(self::OPERATIONS) - 1)];
        if ($op === 'Sub' && $b > $a) {
            [$a, $b] = [$b, $a];
        }
        $exp = time() + max(30, $ttl);
        $payload = ['A' => $a, 'B' => $b, 'Op' => $op, 'Exp' => $exp];
        $challengeId = self::sign($payload);

        return [
            'ChallengeId' => $challengeId,
            'Question' => self::questionFor($a, $b, $op),
            'ExpiresAt' => gmdate('c', $exp),
        ];
    }

    public static function verify(string $challengeId, string $answer): void
    {
        $parts = explode('.', $challengeId, 2);
        if (count($parts) !== 2) {
            throw self::reject('Malformed');
        }
        [$body, $sig] = $parts;
        $decoded = self::base64UrlDecode($body);
        $expected = self::base64UrlEncode(hash_hmac('sha256', $body, self::key(), true));
        if (hash_equals($expected, $sig) === false) {
            throw self::reject('SignatureMismatch');
        }
        $payload = json_decode($decoded, true);
        if (! is_array($payload) || ! isset($payload['A'], $payload['B'], $payload['Op'], $payload['Exp'])) {
            throw self::reject('InvalidPayload');
        }
        if ((int) $payload['Exp'] < time()) {
            throw self::reject('Expired');
        }
        $expectedAnswer = self::computeAnswer((int) $payload['A'], (int) $payload['B'], (string) $payload['Op']);
        if (trim($answer) !== (string) $expectedAnswer) {
            throw self::reject('WrongAnswer');
        }
    }

    private static function computeAnswer(int $a, int $b, string $op): int
    {
        return match ($op) {
            'Add' => $a + $b,
            'Sub' => $a - $b,
            'Mul' => $a * $b,
            default => throw new RuntimeException('UnknownOp'),
        };
    }

    private static function questionFor(int $a, int $b, string $op): string
    {
        $symbol = match ($op) { 'Add' => '+', 'Sub' => '-', 'Mul' => '×', default => '?' };

        return sprintf('%d %s %d', $a, $symbol, $b);
    }

    private static function sign(array $payload): string
    {
        $body = self::base64UrlEncode((string) json_encode($payload, JSON_THROW_ON_ERROR));
        $sig = self::base64UrlEncode(hash_hmac('sha256', $body, self::key(), true));

        return $body . '.' . $sig;
    }

    private static function reject(string $reason): LaraException
    {
        Log::warning('auth.login.captcha_rejected', ['Reason' => $reason]);

        return AuthException::custom('LoginCaptchaInvalid', 'Captcha check failed. Please try a new challenge.', []);
    }

    private static function key(): string
    {
        $key = (string) Config::get('app.key', '');
        if ($key === '') {
            throw new RuntimeException('APP_KEY is not configured.');
        }

        return $key;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $pad = strlen($data) % 4;
        $padded = $pad === 0 ? $data : $data . str_repeat('=', 4 - $pad);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
