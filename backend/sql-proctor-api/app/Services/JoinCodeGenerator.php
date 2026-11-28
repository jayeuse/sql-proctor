<?php

namespace App\Services;

use App\Models\Classroom;
use RuntimeException;

/**
 * Generates unambiguous 8-character uppercase classroom join codes (excluding 0/O/1/I/L).
 */
class JoinCodeGenerator
{
    public const string ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public const int LENGTH = 8;

    public static function randomCode(int $length = self::LENGTH): string
    {
        $alphabetLength = strlen(self::ALPHABET);
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return $code;
    }

    public function generateUnique(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = self::randomCode();

            if (! Classroom::query()->where('join_code', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate a unique classroom join code.');
    }
}
