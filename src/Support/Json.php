<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy\Support;

final class Json
{
    public static function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $json;
    }

    public static function decode(string $payload): array
    {
        if ($payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }
}