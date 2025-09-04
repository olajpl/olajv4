<?php
// engine/Settings/EnumHelper.php — Olaj.pl V4 (pomocnik dla enum_values)
declare(strict_types=1);

namespace Engine\Settings;

use PDO;

final class EnumHelper
{
    public function __construct(private PDO $pdo) {}

    /**
     * Zwraca wszystkie wartości enum dla podanego set_key (np. 'owner_setting_type')
     * @return array<int, array{value_key: string, label: string, description: string|null}>
     */
    public function listBySet(string $setKey): array
    {
        $stmt = $this->pdo->prepare("SELECT value_key, label, description FROM enum_values WHERE set_key = ? ORDER BY sort_order ASC, value_key ASC");
        $stmt->execute([$setKey]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Zwraca label dla danej wartości w zbiorze enum
     */
    public function getLabel(string $setKey, string $valueKey): ?string
    {
        $stmt = $this->pdo->prepare("SELECT label FROM enum_values WHERE set_key = ? AND value_key = ?");
        $stmt->execute([$setKey, $valueKey]);
        return $stmt->fetchColumn() ?: null;
    }

    /**
     * Sprawdza, czy podana wartość należy do zbioru enum
     */
    public function isValid(string $setKey, string $valueKey): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM enum_values WHERE set_key = ? AND value_key = ? LIMIT 1");
        $stmt->execute([$setKey, $valueKey]);
        return (bool)$stmt->fetchColumn();
    }
}
