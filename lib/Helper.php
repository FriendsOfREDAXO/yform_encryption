<?php

declare(strict_types=1);

namespace FriendsOfREDAXO\YFormEncryption;

use rex_sql;

/**
 * Convenience-Helfer für den Zugriff auf verschlüsselte YForm-Daten.
 *
 * Nutzung z.B. in Modulen, Templates oder für PDF-Export:
 *
 * ```php
 * use FriendsOfREDAXO\YFormEncryption\Helper;
 *
 * // Einzelnen Datensatz entschlüsselt laden
 * $data = Helper::getDecryptedRow('rex_my_table', 42);
 *
 * // Einzelnes Feld entschlüsseln
 * $email = Helper::decryptValue($encryptedEmail);
 *
 * // Alle Datensätze einer Tabelle entschlüsselt laden
 * $rows = Helper::getDecryptedTable('rex_my_table');
 * ```
 */
class Helper
{
    /**
     * Lädt einen Datensatz und entschlüsselt die konfigurierten Felder.
     *
     * @param string $tableName Tabellenname (z.B. 'rex_my_table')
     * @param int $id Datensatz-ID
     * @return array<string, mixed>|null Die entschlüsselten Daten oder null
     */
    public static function getDecryptedRow(string $tableName, int $id): ?array
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT * FROM `' . $tableName . '` WHERE id = :id LIMIT 1',
            ['id' => $id],
        );

        if ($sql->getRows() === 0) {
            return null;
        }

        $data = [];
        foreach ($sql->getFieldnames() as $field) {
            $data[$field] = $sql->getValue($field);
        }

        $mapper = FieldMapper::getInstance();
        $encryptedFields = $mapper->getEncryptedFields($tableName);

        if ($encryptedFields !== []) {
            $encryption = EncryptionService::getInstance();
            $data = $encryption->decryptFields($data, $encryptedFields);
        }

        return $data;
    }

    /**
     * Lädt alle Datensätze einer Tabelle und entschlüsselt die konfigurierten Felder.
     *
     * @param string $tableName Tabellenname
     * @param string $where Optionale WHERE-Klausel (ohne WHERE-Keyword)
     * @param array<string, mixed> $params Parameter für die WHERE-Klausel
     * @return list<array<string, mixed>> Die entschlüsselten Datensätze
     */
    public static function getDecryptedTable(
        string $tableName,
        string $where = '',
        array $params = [],
    ): array {
        $query = 'SELECT * FROM `' . $tableName . '`';
        if ($where !== '') {
            $query .= ' WHERE ' . $where;
        }

        $sql = rex_sql::factory();
        $rows = $sql->getArray($query, $params);

        $mapper = FieldMapper::getInstance();
        $encryptedFields = $mapper->getEncryptedFields($tableName);

        if ($encryptedFields === []) {
            return $rows;
        }

        $encryption = EncryptionService::getInstance();
        foreach ($rows as &$row) {
            $row = $encryption->decryptFields($row, $encryptedFields);
        }

        return $rows;
    }

    /**
     * Entschlüsselt einen einzelnen Wert.
     *
     * @param string $value Der möglicherweise verschlüsselte Wert
     * @return string Der entschlüsselte Wert
     */
    public static function decryptValue(string $value): string
    {
        return EncryptionService::getInstance()->decryptSafe($value);
    }

    /**
     * Verschlüsselt einen einzelnen Wert.
     *
     * @param string $value Der Klartext-Wert
     * @return string Der verschlüsselte Wert
     */
    public static function encryptValue(string $value): string
    {
        return EncryptionService::getInstance()->encrypt($value);
    }

    /**
     * Prüft ob ein Wert verschlüsselt ist.
     */
    public static function isEncrypted(string $value): bool
    {
        return EncryptionService::getInstance()->isEncrypted($value);
    }

    /**
     * Gibt alle verschlüsselten Felder für eine Tabelle zurück.
     *
     * @param string $tableName Tabellenname
     * @return list<string> Feldnamen
     */
    public static function getEncryptedFieldsForTable(string $tableName): array
    {
        return FieldMapper::getInstance()->getEncryptedFields($tableName);
    }
}
