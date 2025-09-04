<?php

declare(strict_types=1);

namespace Engine\Utils;

final class Validator
{
    public static function str(?string $v, int $max = 255): ?string
    {
        if ($v === null) return null;
        $v = trim($v);
        return mb_substr($v, 0, $max);
    }
}
