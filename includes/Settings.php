<?php

/**
 * Small user-editable settings stored in the settings table (edited from
 * agency_settings.php). Never use this for secrets — API keys belong in
 * config.local.php.
 */
class Settings {
    public static function get(PDO $pdo, string $name, string $default = ''): string {
        $stmt = $pdo->prepare('SELECT value FROM settings WHERE name = :name');
        $stmt->execute(['name' => $name]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? $default : (string) $value;
    }

    public static function set(PDO $pdo, string $name, string $value): void {
        $pdo->prepare('INSERT INTO settings (name, value) VALUES (:name, :value) ON DUPLICATE KEY UPDATE value = VALUES(value)')
            ->execute(['name' => $name, 'value' => $value]);
    }
}
