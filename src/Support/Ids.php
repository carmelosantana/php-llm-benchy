<?php

declare(strict_types=1);

namespace CarmeloSantana\PHPLLMBenchy\Support;

final class Ids
{
    public static function session(): string
    {
        return 'sess_' . bin2hex(random_bytes(8));
    }

    public static function attempt(): string
    {
        return 'att_' . bin2hex(random_bytes(8));
    }
}