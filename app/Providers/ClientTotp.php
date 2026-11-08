<?php

namespace App\Providers;

use InvalidArgumentException;

final class ClientTotp
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        if ($bytes < 16 || $bytes > 64) {
            throw new InvalidArgumentException('TOTP secret size must be between 16 and 64 bytes.');
        }

        return self::base32Encode(random_bytes($bytes));
    }

    public static function currentCode(string $secret, ?int $timestamp = null): string
    {
        return self::codeAt($secret, intdiv($timestamp ?? time(), 30));
    }

    public static function verify(string $secret, string $code, ?int $timestamp = null, int $window = 1): bool
    {
        if (preg_match('/\A[0-9]{6}\z/', $code) !== 1 || $window < 0 || $window > 2) {
            return false;
        }

        $counter = intdiv($timestamp ?? time(), 30);

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals(self::codeAt($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public static function provisioningUri(string $issuer, string $account, string $secret): string
    {
        $label = rawurlencode($issuer.':'.$account);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return list<string> */
    public static function generateRecoveryCodes(int $count = 10): array
    {
        if ($count < 1 || $count > 20) {
            throw new InvalidArgumentException('Recovery code count must be between 1 and 20.');
        }

        return array_map(static function (): string {
            $code = strtoupper(bin2hex(random_bytes(6)));

            return substr($code, 0, 6).'-'.substr($code, 6, 6);
        }, range(1, $count));
    }

    private static function codeAt(string $secret, int $counter): string
    {
        if ($counter < 0) {
            return '000000';
        }

        $key = self::base32Decode($secret);
        $message = pack('N2', intdiv($counter, 0x100000000), $counter % 0x100000000);
        $hash = hash_hmac('sha1', $message, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    private static function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(rtrim($encoded, '='));
        if ($encoded === '' || preg_match('/\A[A-Z2-7]+\z/', $encoded) !== 1) {
            throw new InvalidArgumentException('Invalid Base32 TOTP secret.');
        }

        $bits = '';
        foreach (str_split($encoded) as $character) {
            $value = strpos(self::BASE32_ALPHABET, $character);
            if ($value === false) {
                throw new InvalidArgumentException('Invalid Base32 TOTP secret.');
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8) as $chunk) {
            $bytes .= chr(bindec($chunk));
        }

        if (strlen($bytes) < 16) {
            throw new InvalidArgumentException('TOTP secret must contain at least 128 bits.');
        }

        return $bytes;
    }
}
