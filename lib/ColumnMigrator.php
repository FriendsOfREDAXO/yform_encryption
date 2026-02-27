<?php

declare(strict_types=1);

namespace FriendsOfREDAXO\YFormEncryption;

use rex;
use rex_sql;

/**
 * Hilfsklasse für die Migration von Spaltentypen.
 *
 * Verschlüsselte Werte sind deutlich länger als Klartext.
 * Ein Text mit 100 Zeichen wird verschlüsselt ca. 200+ Zeichen lang.
 * Daher müssen varchar-Spalten ggf. zu TEXT erweitert werden.
 */
class ColumnMigrator
{
    /**
     * Prüft ob Spalten für die Verschlüsselung groß genug sind.
     *
     * @return list<array{table: string, field: string, current_type: string, needed: bool}>
     */
    public static function checkColumns(string $tableName, array $fields): array
    {
        $results = [];

        $sql = rex_sql::factory();
        $sql->setQuery('SHOW COLUMNS FROM `' . $tableName . '`');

        $columnTypes = [];
        for ($i = 0; $i < $sql->getRows(); ++$i) {
            $columnTypes[(string) $sql->getValue('Field')] = (string) $sql->getValue('Type');
            $sql->next();
        }

        foreach ($fields as $field) {
            if (!isset($columnTypes[$field])) {
                continue;
            }

            $type = strtolower($columnTypes[$field]);
            $needsMigration = false;

            // varchar mit weniger als 500 Zeichen ist zu klein
            if (preg_match('/^varchar\((\d+)\)/', $type, $matches)) {
                $length = (int) $matches[1];
                if ($length < 500) {
                    $needsMigration = true;
                }
            }

            $results[] = [
                'table' => $tableName,
                'field' => $field,
                'current_type' => $columnTypes[$field],
                'needed' => $needsMigration,
            ];
        }

        return $results;
    }

    /**
     * Migriert Spalten zu TEXT wenn nötig.
     *
     * @return int Anzahl der migrierten Spalten
     */
    public static function migrateColumns(string $tableName, array $fields): int
    {
        $checks = self::checkColumns($tableName, $fields);
        $count = 0;

        foreach ($checks as $check) {
            if (!$check['needed']) {
                continue;
            }

            $sql = rex_sql::factory();
            $sql->setQuery(
                'ALTER TABLE `' . $tableName . '` MODIFY COLUMN `' . $check['field'] . '` TEXT',
            );
            ++$count;
        }

        return $count;
    }

    /**
     * Prüft alle konfigurierten Felder und gibt Warnungen zurück.
     *
     * @return list<string> Liste von Warnmeldungen
     */
    public static function getWarnings(): array
    {
        $warnings = [];
        $mapper = FieldMapper::getInstance();
        $mappings = $mapper->getAllMappings();

        foreach ($mappings as $tableName => $fields) {
            $checks = self::checkColumns($tableName, $fields);

            foreach ($checks as $check) {
                if ($check['needed']) {
                    $warnings[] = sprintf(
                        'Spalte "%s.%s" hat den Typ "%s" – zu klein für verschlüsselte Daten. '
                        . 'Empfehlung: Auf TEXT ändern.',
                        $check['table'],
                        $check['field'],
                        $check['current_type'],
                    );
                }
            }
        }

        return $warnings;
    }
}
