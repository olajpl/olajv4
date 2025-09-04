<?php
// engine/Enum/EnumValidator.php

declare(strict_types=1);

namespace Engine\Enum;

use InvalidArgumentException;

final class EnumValidator
{
    /**
     * Sprawdza, czy podana wartość istnieje w tablicy ALL danej klasy ENUM.
     * Rzuca wyjątek, jeśli niepoprawna.
     *
     * @param class-string $enumClass
     * @param string $value
     */
    public static function assert(string $enumClass, string $value): void
    {
        if (!\in_array($value, $enumClass::ALL, true)) {
            throw new InvalidArgumentException("Invalid value '$value' for enum $enumClass");
        }
    }

    /**
     * Sprawdza, czy wartość jest dopuszczalna (zamiast rzucać wyjątek).
     *
     * @param class-string $enumClass
     * @param string $value
     * @return bool
     */
    public static function isValid(string $enumClass, string $value): bool
    {
        return \in_array($value, $enumClass::ALL, true);
    }
}
