<?php

declare(strict_types=1);

namespace FriendsOfREDAXO\YFormEncryption;

use rex;
use rex_extension;
use rex_extension_point;
use rex_sql;

/**
 * Registriert und verarbeitet alle YForm Extension Points
 * für die automatische Ver- und Entschlüsselung.
 */
class EventHandler
{
    /**
     * Registriert alle Extension Points.
     */
    public static function register(): void
    {
        // Nach dem Speichern: Felder in der DB verschlüsseln
        rex_extension::register('YFORM_SAVED', [self::class, 'onDataSaved'], rex_extension::LATE);

        if (rex::isBackend() && rex::getUser()) {
            // Vor dem Edit-Formular: Daten entschlüsseln für Anzeige
            rex_extension::register('YFORM_DATA_UPDATE', [self::class, 'onDataUpdate']);

            // Listen-Anzeige: Spaltenformatierung zum Entschlüsseln
            rex_extension::register('YFORM_DATA_LIST', [self::class, 'onDataList']);

            // Export-Buttons in der YForm-Listen-Toolbar
            rex_extension::register('YFORM_DATA_LIST_LINKS', [self::class, 'onDataListLinks']);
        }
    }

    /**
     * EP: YFORM_SAVED
     * Wird nach jedem Speichern (Frontend + Backend) ausgelöst.
     * Verschlüsselt die konfigurierten Felder direkt in der Datenbank.
     */
    public static function onDataSaved(rex_extension_point $ep): void
    {
        $tableName = (string) $ep->getParam('table');
        $dataId = $ep->getParam('id');

        if ($dataId === null || $dataId === '' || $dataId === 0) {
            return;
        }

        $mapper = FieldMapper::getInstance();
        $fields = $mapper->getEncryptedFields($tableName);

        if ($fields === []) {
            return;
        }

        try {
            $encryption = EncryptionService::getInstance();

            // Aktuelle Daten laden
            $sql = rex_sql::factory();
            $sql->setQuery(
                'SELECT * FROM `' . $tableName . '` WHERE id = :id LIMIT 1',
                ['id' => (int) $dataId],
            );

            if ($sql->getRows() === 0) {
                return;
            }

            $updates = [];
            foreach ($fields as $field) {
                $value = $sql->getValue($field);
                if ($value === null || !is_string($value) || $value === '') {
                    continue;
                }

                // Bereits verschlüsselt? Nicht erneut verschlüsseln
                if ($encryption->isEncrypted($value)) {
                    continue;
                }

                $updates[$field] = $encryption->encrypt($value);
            }

            if ($updates === []) {
                return;
            }

            // Verschlüsselte Werte in DB schreiben
            $updateSql = rex_sql::factory();
            $updateSql->setTable($tableName);
            $updateSql->setWhere('id = :id', ['id' => (int) $dataId]);

            foreach ($updates as $field => $encryptedValue) {
                $updateSql->setValue($field, $encryptedValue);
            }

            $updateSql->update();

            // Logging
            self::log($tableName, (int) $dataId, implode(', ', array_keys($updates)), 'encrypt');
        } catch (\Exception $e) {
            self::log($tableName, (int) $dataId, '', 'encrypt_error', $e->getMessage());
        }
    }

    /**
     * EP: YFORM_DATA_UPDATE
     * Wird vor dem Anzeigen des Edit-Formulars ausgelöst.
     * Entschlüsselt die Daten und setzt sie ins YForm-Data-Array,
     * damit sie in executeFields() Schritt 3 die verschlüsselten SQL-Werte überschreiben.
     *
     * @return \rex_yform
     */
    public static function onDataUpdate(rex_extension_point $ep)
    {
        /** @var \rex_yform $yform */
        $yform = $ep->getSubject();

        /** @var \rex_yform_manager_table $table */
        $table = $ep->getParam('table');

        /** @var \rex_yform_manager_dataset $dataset */
        $dataset = $ep->getParam('data');

        $tableName = $table->getTableName();
        $mapper = FieldMapper::getInstance();
        $fields = $mapper->getEncryptedFields($tableName);

        if ($fields === []) {
            return $yform;
        }

        try {
            $encryption = EncryptionService::getInstance();

            // Bestehende data-Werte auslesen (falls schon gesetzt)
            $data = $yform->getObjectparams('data');
            if (!is_array($data)) {
                $data = [];
            }

            foreach ($fields as $field) {
                if (!$dataset->hasValue($field)) {
                    continue;
                }

                $value = $dataset->getValue($field);
                if (!is_string($value) || $value === '') {
                    continue;
                }

                if ($encryption->isEncrypted($value)) {
                    $decrypted = $encryption->decrypt($value);
                    // Dataset aktualisieren
                    $dataset->setValue($field, $decrypted);
                    // YForm data-Array setzen – wird in executeFields() Schritt 3
                    // die verschlüsselten SQL-Werte überschreiben
                    $data[$field] = $decrypted;
                }
            }

            $yform->setObjectparams('data', $data);
        } catch (\Exception $e) {
            // Fehler ignorieren, verschlüsselter Wert wird angezeigt
        }

        return $yform;
    }

    /**
     * EP: YFORM_DATA_LIST
     * Wird beim Rendern der Datensatz-Liste ausgelöst.
     * Setzt Custom-Formatter für verschlüsselte Spalten.
     *
     * @return \rex_list
     */
    public static function onDataList(rex_extension_point $ep)
    {
        /** @var \rex_list $list */
        $list = $ep->getSubject();

        /** @var \rex_yform_manager_table $table */
        $table = $ep->getParam('table');

        $tableName = $table->getTableName();
        $mapper = FieldMapper::getInstance();
        $fields = $mapper->getEncryptedFields($tableName);

        if ($fields === []) {
            return $list;
        }

        foreach ($fields as $field) {
            if (in_array($field, $list->getColumnNames(), true)) {
                $list->setColumnFormat($field, 'custom', static function (array $params) {
                    $value = $params['value'] ?? '';
                    if (!is_string($value) || $value === '') {
                        return $value;
                    }

                    try {
                        $encryption = EncryptionService::getInstance();
                        if ($encryption->isEncrypted($value)) {
                            $decrypted = $encryption->decryptSafe($value);
                            return '<i class="rex-icon fa-lock" title="Verschlüsselt gespeichert"></i> '
                                . rex_escape($decrypted);
                        }
                    } catch (\Exception $e) {
                        return '<i class="rex-icon fa-exclamation-triangle text-danger"></i> '
                            . rex_escape($value);
                    }

                    return rex_escape($value);
                });
            }
        }

        return $list;
    }

    /**
     * EP: YFORM_DATA_LIST_LINKS
     * Fügt CSV- und Excel-Export-Buttons in die YForm-Listen-Toolbar ein.
     * Buttons erscheinen nur wenn die Tabelle verschlüsselte Felder hat.
     *
     * @return mixed
     */
    public static function onDataListLinks(rex_extension_point $ep)
    {
        $linkSets = $ep->getSubject();

        /** @var \rex_yform_manager_table $table */
        $table = $ep->getParam('table');
        $tableName = $table->getTableName();

        $mapper = FieldMapper::getInstance();
        if (!$mapper->hasEncryptedFields($tableName)) {
            return $linkSets;
        }

        $hasXlsx = class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet');

        // CSV-Button (immer verfügbar)
        $csvParams = http_build_query([
            'rex-api-call' => 'yform_encryption_export',
            'table' => $tableName,
            'format' => 'csv',
        ]);
        $csvItem = [];
        $csvItem['label'] = '<i class="bi bi-filetype-csv" aria-hidden="true"></i> '
            . \rex_i18n::msg('yform_encryption_export_csv');
        $csvItem['url'] = 'index.php?' . $csvParams;
        $csvItem['attributes']['class'][] = 'btn-success';
        $csvItem['attributes']['title'] = \rex_i18n::msg('yform_encryption_export_csv_title');
        $linkSets['table_links'][] = $csvItem;

        // Excel-Button (nur wenn PhpSpreadsheet verfügbar)
        if ($hasXlsx) {
            $xlsxParams = http_build_query([
                'rex-api-call' => 'yform_encryption_export',
                'table' => $tableName,
                'format' => 'xlsx',
            ]);
            $xlsxItem = [];
            $xlsxItem['label'] = '<i class="bi bi-file-earmark-excel-fill" aria-hidden="true"></i> '
                . \rex_i18n::msg('yform_encryption_export_xlsx');
            $xlsxItem['url'] = 'index.php?' . $xlsxParams;
            $xlsxItem['attributes']['class'][] = 'btn-success';
            $xlsxItem['attributes']['title'] = \rex_i18n::msg('yform_encryption_export_xlsx_title');
            $linkSets['table_links'][] = $xlsxItem;
        }

        $ep->setSubject($linkSets);

        return $linkSets;
    }

    /**
     * Schreibt einen Eintrag ins Verschlüsselungs-Log.
     */
    private static function log(
        string $tableName,
        int $datasetId,
        string $fieldName,
        string $action,
        string $message = '',
    ): void {
        $addon = \rex_addon::get('yform_encryption');

        if (!$addon->getConfig('logging_enabled', true)) {
            return;
        }

        try {
            $sql = rex_sql::factory();
            $sql->setTable(rex::getTable('yform_encryption_log'));
            $sql->setValue('table_name', $tableName);
            $sql->setValue('dataset_id', $datasetId);
            $sql->setValue('field_name', $fieldName);
            $sql->setValue('action', $action);
            $sql->setValue('status', str_contains($action, 'error') ? 'error' : 'success');
            $sql->setValue('message', $message);
            $sql->addGlobalCreateFields();
            $sql->addGlobalUpdateFields();
            $sql->insert();
        } catch (\Exception $e) {
            // Log-Fehler nicht eskalieren
        }
    }
}
