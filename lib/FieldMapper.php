<?php

declare(strict_types=1);

namespace FriendsOfREDAXO\YFormEncryption;

use rex;
use rex_sql;

/**
 * Verwaltet das Mapping zwischen YForm-Tabellen/Feldern und Verschlüsselung.
 *
 * Cached die Mappings im Speicher für schnellen Zugriff.
 */
class FieldMapper
{
    private static ?self $instance = null;

    /** @var array<string, list<string>>|null */
    private ?array $mappings = null;

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Gibt die verschlüsselten Felder für eine Tabelle zurück.
     *
     * @param string $tableName Der Tabellenname (z.B. 'rex_my_table')
     * @return list<string> Liste der zu verschlüsselnden Feldnamen
     */
    public function getEncryptedFields(string $tableName): array
    {
        $mappings = $this->loadMappings();
        return $mappings[$tableName] ?? [];
    }

    /**
     * Prüft ob eine Tabelle verschlüsselte Felder hat.
     */
    public function hasEncryptedFields(string $tableName): bool
    {
        $fields = $this->getEncryptedFields($tableName);
        return $fields !== [];
    }

    /**
     * Prüft ob ein bestimmtes Feld verschlüsselt werden soll.
     */
    public function isFieldEncrypted(string $tableName, string $fieldName): bool
    {
        $fields = $this->getEncryptedFields($tableName);
        return in_array($fieldName, $fields, true);
    }

    /**
     * Fügt ein Feld zum Verschlüsselungs-Mapping hinzu.
     */
    public function addField(string $tableName, string $fieldName): void
    {
        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('yform_encryption_map'));
        $sql->setValue('table_name', $tableName);
        $sql->setValue('field_name', $fieldName);
        $sql->setValue('status', 1);
        $sql->addGlobalCreateFields();
        $sql->addGlobalUpdateFields();

        try {
            $sql->insert();
        } catch (\rex_sql_exception $e) {
            // Duplikat ignorieren, Status aktualisieren
            $sql2 = rex_sql::factory();
            $sql2->setQuery(
                'UPDATE ' . rex::getTable('yform_encryption_map')
                . ' SET status = 1, updatedate = NOW()'
                . ' WHERE table_name = :table AND field_name = :field',
                ['table' => $tableName, 'field' => $fieldName],
            );
        }

        $this->resetCache();
    }

    /**
     * Entfernt ein Feld aus dem Verschlüsselungs-Mapping.
     */
    public function removeField(string $tableName, string $fieldName): void
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'UPDATE ' . rex::getTable('yform_encryption_map')
            . ' SET status = 0, updatedate = NOW()'
            . ' WHERE table_name = :table AND field_name = :field',
            ['table' => $tableName, 'field' => $fieldName],
        );

        $this->resetCache();
    }

    /**
     * Löscht ein Feld komplett aus dem Mapping.
     */
    public function deleteField(string $tableName, string $fieldName): void
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'DELETE FROM ' . rex::getTable('yform_encryption_map')
            . ' WHERE table_name = :table AND field_name = :field',
            ['table' => $tableName, 'field' => $fieldName],
        );

        $this->resetCache();
    }

    /**
     * Gibt alle Mappings gruppiert nach Tabelle zurück.
     *
     * @return array<string, list<string>>
     */
    public function getAllMappings(): array
    {
        return $this->loadMappings();
    }

    /**
     * Gibt alle Mappings inkl. inaktiver zurück (für die Config-Seite).
     *
     * @return list<array{id: int, table_name: string, field_name: string, status: int}>
     */
    public function getAllMappingsRaw(): array
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT id, table_name, field_name, status FROM '
            . rex::getTable('yform_encryption_map')
            . ' ORDER BY table_name, field_name',
        );

        $result = [];
        for ($i = 0; $i < $sql->getRows(); ++$i) {
            $result[] = [
                'id' => (int) $sql->getValue('id'),
                'table_name' => (string) $sql->getValue('table_name'),
                'field_name' => (string) $sql->getValue('field_name'),
                'status' => (int) $sql->getValue('status'),
            ];
            $sql->next();
        }

        return $result;
    }

    /**
     * Gibt alle YForm-Tabellen zurück, die text-basierte Felder haben.
     *
     * @return array<string, list<array{name: string, label: string, type_name: string}>>
     */
    public function getAvailableTablesAndFields(): array
    {
        $result = [];

        try {
            $tables = \rex_yform_manager_table::getAll();
        } catch (\Exception $e) {
            return $result;
        }

        $encryptableTypes = [
            'text',
            'textarea',
            'email',
            'phone',
            'url',
            'ip',
            'fields_iban',
            'fields_inline',
        ];

        foreach ($tables as $table) {
            $fields = $table->getFields(['type_id' => 'value']);
            $tableFields = [];

            foreach ($fields as $field) {
                if (in_array($field->getTypeName(), $encryptableTypes, true)) {
                    $tableFields[] = [
                        'name' => $field->getName(),
                        'label' => \rex_i18n::translate($field->getLabel()),
                        'type_name' => $field->getTypeName(),
                    ];
                }
            }

            if ($tableFields !== []) {
                $result[$table->getTableName()] = $tableFields;
            }
        }

        return $result;
    }

    /**
     * Lädt Mappings aus der Datenbank.
     *
     * @return array<string, list<string>>
     */
    private function loadMappings(): array
    {
        if (null !== $this->mappings) {
            return $this->mappings;
        }

        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT table_name, field_name FROM '
            . rex::getTable('yform_encryption_map')
            . ' WHERE status = 1 ORDER BY table_name, field_name',
        );

        $this->mappings = [];
        for ($i = 0; $i < $sql->getRows(); ++$i) {
            $table = (string) $sql->getValue('table_name');
            $field = (string) $sql->getValue('field_name');

            if (!isset($this->mappings[$table])) {
                $this->mappings[$table] = [];
            }
            $this->mappings[$table][] = $field;
            $sql->next();
        }

        return $this->mappings;
    }

    /**
     * Cache zurücksetzen.
     */
    public function resetCache(): void
    {
        $this->mappings = null;
    }

    /**
     * Singleton zurücksetzen (z.B. nach Konfigurationsänderung).
     */
    public static function reset(): void
    {
        if (null !== self::$instance) {
            self::$instance->resetCache();
        }
        self::$instance = null;
    }
}
