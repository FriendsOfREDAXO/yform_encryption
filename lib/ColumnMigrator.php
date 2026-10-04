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

        // Auch in der YForm-Felddefinition festhalten: Tabellen mit „Schema überschreiben“ (Standard) würden
        // die Spalte sonst beim nächsten Neuaufbau (z. B. Feld speichern) wieder auf den kurzen Typ
        // verkleinern und die verschlüsselten Werte abschneiden.
        self::persistDbTypes($tableName, $fields);

        return $count;
    }

    /**
     * Setzt db_type der YForm-Felder auf text, wo eine kurze varchar-Spalte hinterlegt ist.
     *
     * @param list<string> $fields
     * @return int Anzahl angepasster Felddefinitionen
     */
    public static function persistDbTypes(string $tableName, array $fields): int
    {
        $count = 0;
        foreach (self::shortDbTypes($tableName, $fields) as $field => $dbType) {
            rex_sql::factory()->setQuery(
                'UPDATE ' . rex::getTable('yform_field') . ' SET db_type = "text" WHERE table_name = ? AND name = ? AND type_id = "value"',
                [$tableName, $field],
            );
            ++$count;
        }
        if ($count > 0 && class_exists(\rex_yform_manager_table::class)) {
            \rex_yform_manager_table::deleteCache();
        }
        return $count;
    }

    /**
     * YForm-Felder, deren hinterlegter db_type zu klein für Chiffrat ist (varchar unter 500 Zeichen).
     *
     * @param list<string> $fields
     * @return array<string, string> Feldname => db_type
     */
    public static function shortDbTypes(string $tableName, array $fields): array
    {
        if ($fields === []) {
            return [];
        }
        $rows = rex_sql::factory()->getArray(
            'SELECT name, db_type FROM ' . rex::getTable('yform_field') . ' WHERE table_name = ? AND type_id = "value" AND name IN (' . implode(',', array_fill(0, count($fields), '?')) . ')',
            array_merge([$tableName], array_values($fields)),
        );
        $short = [];
        foreach ($rows as $row) {
            if (preg_match('/^varchar\((\d+)\)/i', (string) $row['db_type'], $m) && (int) $m[1] < 500) {
                $short[(string) $row['name']] = (string) $row['db_type'];
            }
        }
        return $short;
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

            foreach (self::shortDbTypes($tableName, $fields) as $field => $dbType) {
                $warnings[] = sprintf(
                    'Feld "%s.%s" ist in YForm mit db_type "%s" hinterlegt. Beim nächsten Neuaufbau der Tabelle würde YForm die Spalte '
                    . 'verkleinern und verschlüsselte Werte abschneiden. Bitte die Feldzuordnung erneut speichern (setzt db_type auf TEXT).',
                    $tableName,
                    $field,
                    $dbType,
                );
            }

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
