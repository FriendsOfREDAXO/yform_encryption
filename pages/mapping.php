<?php

declare(strict_types=1);

use FriendsOfREDAXO\YFormEncryption\ColumnMigrator;
use FriendsOfREDAXO\YFormEncryption\EventHandler;
use FriendsOfREDAXO\YFormEncryption\FieldMapper;
use FriendsOfREDAXO\YFormEncryption\KeyManager;
use FriendsOfREDAXO\YFormEncryption\SessionGuard;

/**
 * YForm Encryption - Feld-Mapping-Seite.
 *
 * Hier wird konfiguriert, welche YForm-Felder verschlüsselt werden sollen.
 *
 * @var rex_addon $this
 * @psalm-scope-this rex_addon
 */

$addon = rex_addon::get('yform_encryption');
$mapper = FieldMapper::getInstance();
$keyManager = KeyManager::getInstance();
$guard = SessionGuard::getInstance();

$message = '';
$error = '';

// Prüfen ob Schlüssel vorhanden
if (!$keyManager->hasKey()) {
    echo rex_view::error(
        $addon->i18n('yform_encryption_no_key')
        . ' <a href="' . rex_url::backendPage('yform_encryption/config') . '">'
        . $addon->i18n('yform_encryption_goto_config')
        . '</a>'
    );
    return;
}

// --- SessionGuard: Re-Authentifizierung verarbeiten ---

// Manuell sperren
if (rex_post('btn_lock', 'string', '') !== '') {
    $guard->lock();
    $message = $addon->i18n('yform_encryption_session_locked');
}

// Authentifizierung prüfen
if (rex_post('btn_authenticate', 'string', '') !== '') {
    $authLogin = rex_post('auth_login', 'string', '');
    $authPassword = rex_post('auth_password', 'string', '');

    if ($guard->authenticate($authLogin, $authPassword)) {
        $message = $addon->i18n('yform_encryption_auth_success');
    } else {
        $error = $addon->i18n('yform_encryption_auth_failed');
    }
}

$isUnlocked = $guard->isUnlocked();

// --- Aktionen verarbeiten ---

// Feld hinzufügen
if (rex_post('btn_add_field', 'string', '') !== '') {
    $tableName = rex_post('add_table', 'string', '');
    $fieldName = rex_post('add_field', 'string', '');

    if ($tableName !== '' && $fieldName !== '') {
        $mapper->addField($tableName, $fieldName);
        $message = $addon->i18n('yform_encryption_field_added', $fieldName, $tableName);
    } else {
        $error = $addon->i18n('yform_encryption_field_add_error');
    }
}

// Felder per Checkbox speichern
if (rex_post('btn_save_mapping', 'string', '') !== '') {
    $selectedFields = rex_post('encrypted_fields', 'array', []);
    $availableTablesAndFields = $mapper->getAvailableTablesAndFields();

    // Alle bestehenden Mappings laden
    $existingMappings = $mapper->getAllMappings();

    // Für jede Tabelle und jedes Feld prüfen
    foreach ($availableTablesAndFields as $tableName => $fields) {
        foreach ($fields as $fieldInfo) {
            $fieldName = $fieldInfo['name'];
            $isSelected = isset($selectedFields[$tableName]) && in_array($fieldName, $selectedFields[$tableName], true);
            $isCurrentlyEncrypted = $mapper->isFieldEncrypted($tableName, $fieldName);

            if ($isSelected && !$isCurrentlyEncrypted) {
                $mapper->addField($tableName, $fieldName);
            } elseif (!$isSelected && $isCurrentlyEncrypted) {
                $mapper->removeField($tableName, $fieldName);
            }
        }
    }

    $message = $addon->i18n('yform_encryption_mapping_saved');

    // Spaltentypen automatisch migrieren
    $totalMigrated = 0;
    foreach ($availableTablesAndFields as $tblName => $tblFields) {
        $encFields = $mapper->getEncryptedFields($tblName);
        if ($encFields !== []) {
            $totalMigrated += ColumnMigrator::migrateColumns($tblName, $encFields);
        }
    }
    if ($totalMigrated > 0) {
        $message .= '<br>' . $totalMigrated . ' Spalte(n) auf TEXT erweitert für verschlüsselte Daten.';
    }
}

// Bulk-Verschlüsselung bestehender Daten
if (rex_post('btn_encrypt_existing', 'string', '') !== '' && $isUnlocked) {
    $tableName = rex_post('encrypt_table', 'string', '');
    if ($tableName !== '') {
        try {
            $encryption = \FriendsOfREDAXO\YFormEncryption\EncryptionService::getInstance();
            $fields = $mapper->getEncryptedFields($tableName);

            if ($fields !== []) {
                $sql = rex_sql::factory();
                $sql->setQuery('SELECT id, ' . implode(', ', array_map(static fn (string $f) => '`' . $f . '`', $fields)) . ' FROM `' . $tableName . '`');

                $count = 0;
                for ($i = 0; $i < $sql->getRows(); ++$i) {
                    $updates = [];
                    $dataId = (int) $sql->getValue('id');

                    foreach ($fields as $field) {
                        $value = $sql->getValue($field);
                        if (!is_string($value) || $value === '' || $encryption->isEncrypted($value)) {
                            continue;
                        }
                        $updates[$field] = $encryption->encrypt($value);
                    }

                    if ($updates !== []) {
                        $updateSql = rex_sql::factory();
                        $updateSql->setTable($tableName);
                        $updateSql->setWhere('id = :id', ['id' => $dataId]);
                        foreach ($updates as $field => $encryptedValue) {
                            $updateSql->setValue($field, $encryptedValue);
                        }
                        $updateSql->update();
                        ++$count;
                    }

                    $sql->next();
                }

                $message = $addon->i18n('yform_encryption_bulk_encrypted', (string) $count);                EventHandler::log($tableName, 0, '*', 'bulk_encrypt', $count . ' Datensätze verschlüsselt');            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Bulk-Entschlüsselung
if (rex_post('btn_decrypt_all', 'string', '') !== '' && $isUnlocked) {
    $tableName = rex_post('decrypt_table', 'string', '');
    if ($tableName !== '') {
        try {
            $encryption = \FriendsOfREDAXO\YFormEncryption\EncryptionService::getInstance();
            $fields = $mapper->getEncryptedFields($tableName);

            if ($fields !== []) {
                $sql = rex_sql::factory();
                $sql->setQuery('SELECT id, ' . implode(', ', array_map(static fn (string $f) => '`' . $f . '`', $fields)) . ' FROM `' . $tableName . '`');

                $count = 0;
                for ($i = 0; $i < $sql->getRows(); ++$i) {
                    $updates = [];
                    $dataId = (int) $sql->getValue('id');

                    foreach ($fields as $field) {
                        $value = $sql->getValue($field);
                        if (!is_string($value) || $value === '' || !$encryption->isEncrypted($value)) {
                            continue;
                        }
                        $updates[$field] = $encryption->decrypt($value);
                    }

                    if ($updates !== []) {
                        $updateSql = rex_sql::factory();
                        $updateSql->setTable($tableName);
                        $updateSql->setWhere('id = :id', ['id' => $dataId]);
                        foreach ($updates as $field => $decryptedValue) {
                            $updateSql->setValue($field, $decryptedValue);
                        }
                        $updateSql->update();
                        ++$count;
                    }

                    $sql->next();
                }

                $message = $addon->i18n('yform_encryption_bulk_decrypted', (string) $count);                EventHandler::log($tableName, 0, '*', 'bulk_decrypt', $count . ' Datensätze entschlüsselt');            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// --- Ausgabe ---
if ($message !== '') {
    echo rex_view::success($message);
}
if ($error !== '') {
    echo rex_view::error($error);
}

// Verfügbare Tabellen und Felder laden
$availableTablesAndFields = $mapper->getAvailableTablesAndFields();

if ($availableTablesAndFields === []) {
    echo rex_view::warning($addon->i18n('yform_encryption_no_tables'));
    return;
}

// Spalten-Warnungen prüfen
$columnWarnings = ColumnMigrator::getWarnings();
if ($columnWarnings !== []) {
    echo rex_view::warning(
        '<strong>Spaltentyp-Warnungen:</strong><br>'
        . implode('<br>', array_map('rex_escape', $columnWarnings))
        . '<br><br>Die Spalten werden beim nächsten Speichern des Mappings automatisch migriert.'
    );
}

// ---- Feld-Mapping Formular ----
$formContent = '<form action="' . rex_url::currentBackendPage() . '" method="post">';
$formContent .= '<input type="hidden" name="btn_save_mapping" value="1">';

foreach ($availableTablesAndFields as $tableName => $fields) {
    try {
        $yformTable = rex_yform_manager_table::require($tableName);
        $tableLabel = rex_i18n::translate($yformTable->getName());
    } catch (\Exception $e) {
        $tableLabel = $tableName;
    }

    $encryptedFields = $mapper->getEncryptedFields($tableName);

    $formContent .= '<div class="yform-encryption-table-group">';
    $formContent .= '<h4><i class="rex-icon fa-database"></i> ' . rex_escape($tableLabel);
    $formContent .= ' <small class="text-muted">' . rex_escape($tableName) . '</small></h4>';

    $formContent .= '<table class="table table-hover">';
    $formContent .= '<thead><tr>';
    $formContent .= '<th style="width:50px">' . $addon->i18n('yform_encryption_active') . '</th>';
    $formContent .= '<th>' . $addon->i18n('yform_encryption_field') . '</th>';
    $formContent .= '<th>' . $addon->i18n('yform_encryption_label') . '</th>';
    $formContent .= '<th>' . $addon->i18n('yform_encryption_type') . '</th>';
    $formContent .= '<th>' . $addon->i18n('yform_encryption_status_col') . '</th>';
    $formContent .= '</tr></thead>';
    $formContent .= '<tbody>';

    foreach ($fields as $fieldInfo) {
        $isEncrypted = in_array($fieldInfo['name'], $encryptedFields, true);
        $checkboxName = 'encrypted_fields[' . rex_escape($tableName) . '][]';

        $formContent .= '<tr' . ($isEncrypted ? ' class="success"' : '') . '>';
        $formContent .= '<td><input type="checkbox" name="' . $checkboxName . '" '
            . 'value="' . rex_escape($fieldInfo['name']) . '"'
            . ($isEncrypted ? ' checked' : '') . '></td>';
        $formContent .= '<td><code>' . rex_escape($fieldInfo['name']) . '</code></td>';
        $formContent .= '<td>' . rex_escape($fieldInfo['label']) . '</td>';
        $formContent .= '<td><span class="label label-default">' . rex_escape($fieldInfo['type_name']) . '</span></td>';
        $formContent .= '<td>';
        if ($isEncrypted) {
            $formContent .= '<span class="text-success"><i class="rex-icon fa-lock"></i> '
                . $addon->i18n('yform_encryption_encrypted') . '</span>';
        } else {
            $formContent .= '<span class="text-muted"><i class="rex-icon fa-unlock"></i> '
                . $addon->i18n('yform_encryption_not_encrypted') . '</span>';
        }
        $formContent .= '</td>';
        $formContent .= '</tr>';
    }

    $formContent .= '</tbody></table>';

    // Bulk-Aktionen pro Tabelle – nur wenn Session freigeschaltet
    if ($encryptedFields !== [] && $isUnlocked) {
        $formContent .= '<div class="yform-encryption-bulk-actions">';

        $formContent .= '<button class="btn btn-xs btn-success btn-encrypt-existing" type="submit" '
            . 'name="btn_encrypt_existing" value="1" '
            . 'onclick="this.form.encrypt_table.value=\'' . rex_escape($tableName) . '\'; '
            . 'return confirm(\'' . $addon->i18n('yform_encryption_bulk_encrypt_confirm') . '\')">'
            . '<i class="rex-icon fa-lock"></i> '
            . $addon->i18n('yform_encryption_encrypt_existing')
            . '</button> ';

        $formContent .= '<button class="btn btn-xs btn-warning btn-decrypt-all" type="submit" '
            . 'name="btn_decrypt_all" value="1" '
            . 'onclick="this.form.decrypt_table.value=\'' . rex_escape($tableName) . '\'; '
            . 'return confirm(\'' . $addon->i18n('yform_encryption_bulk_decrypt_confirm') . '\')">'
            . '<i class="rex-icon fa-unlock"></i> '
            . $addon->i18n('yform_encryption_decrypt_existing')
            . '</button>';

        $formContent .= '</div>';
    } elseif ($encryptedFields !== [] && !$isUnlocked) {
        $formContent .= '<div class="yform-encryption-bulk-actions">';
        $formContent .= '<span class="text-muted"><i class="rex-icon fa-lock"></i> '
            . $addon->i18n('yform_encryption_bulk_locked')
            . '</span>';
        $formContent .= '</div>';
    }

    $formContent .= '</div>';
}

$formContent .= '<input type="hidden" name="encrypt_table" value="">';
$formContent .= '<input type="hidden" name="decrypt_table" value="">';

$formContent .= '<div class="rex-page-section-footer">';
$formContent .= '<button class="btn btn-save" type="submit" name="btn_save_mapping" value="1">'
    . '<i class="rex-icon fa-save"></i> ' . $addon->i18n('yform_encryption_save_mapping')
    . '</button>';
$formContent .= '</div>';
$formContent .= '</form>';

$fragment = new rex_fragment();
$fragment->setVar('title', $addon->i18n('yform_encryption_mapping'), false);
$fragment->setVar('body', $formContent, false);
$fragment->setVar('class', 'edit', false);
echo $fragment->parse('core/page/section.php');

// ---- Bulk-Operationen: Authentifizierung / Timer-Panel ----
$bulkContent = '';

if ($isUnlocked) {
    // Session ist aktiv – Timer + Sperren-Button anzeigen
    $remaining = $guard->getRemainingTime();
    $timeoutTotal = $guard->getTimeout();

    $bulkContent .= '<div class="yform-encryption-session-active" id="yform-enc-session-panel">';
    $bulkContent .= '<div class="alert alert-success">';
    $bulkContent .= '<i class="rex-icon fa-unlock"></i> ';
    $bulkContent .= $addon->i18n('yform_encryption_session_active');
    $bulkContent .= ' <strong id="yform-enc-timer">' . sprintf('%02d:%02d', intdiv($remaining, 60), $remaining % 60) . '</strong>';
    $bulkContent .= '</div>';

    // Manuell sperren
    $bulkContent .= '<form action="' . rex_url::currentBackendPage() . '" method="post" class="form-inline">';
    $bulkContent .= '<button class="btn btn-sm btn-danger" type="submit" name="btn_lock" value="1">';
    $bulkContent .= '<i class="rex-icon fa-lock"></i> ' . $addon->i18n('yform_encryption_lock_now');
    $bulkContent .= '</button>';
    $bulkContent .= '</form>';
    $bulkContent .= '</div>';

    // JS-Daten für Timer als data-Attribute ausgeben
    $bulkContent .= '<script data-yform-enc-remaining="' . $remaining . '" '
        . 'data-yform-enc-page="' . rex_escape(rex_url::currentBackendPage()) . '" '
        . 'id="yform-enc-timer-data" type="application/json"></script>';
} else {
    // Gesperrt – Login-Formular anzeigen
    $bulkContent .= '<div class="yform-encryption-auth-form">';
    $bulkContent .= '<p>' . $addon->i18n('yform_encryption_auth_required_desc') . '</p>';

    $bulkContent .= '<form action="' . rex_url::currentBackendPage() . '" method="post" class="form-horizontal">';

    $bulkContent .= '<div class="form-group">';
    $bulkContent .= '<label class="col-sm-3 control-label" for="auth_login">'
        . $addon->i18n('yform_encryption_username') . '</label>';
    $bulkContent .= '<div class="col-sm-6">';
    $bulkContent .= '<input class="form-control" type="text" id="auth_login" name="auth_login" '
        . 'autocomplete="username" required>';
    $bulkContent .= '</div></div>';

    $bulkContent .= '<div class="form-group">';
    $bulkContent .= '<label class="col-sm-3 control-label" for="auth_password">'
        . $addon->i18n('yform_encryption_password') . '</label>';
    $bulkContent .= '<div class="col-sm-6">';
    $bulkContent .= '<input class="form-control" type="password" id="auth_password" name="auth_password" '
        . 'autocomplete="current-password" required>';
    $bulkContent .= '</div></div>';

    $bulkContent .= '<div class="form-group">';
    $bulkContent .= '<div class="col-sm-offset-3 col-sm-6">';
    $bulkContent .= '<button class="btn btn-primary" type="submit" name="btn_authenticate" value="1">';
    $bulkContent .= '<i class="rex-icon fa-key"></i> ' . $addon->i18n('yform_encryption_authenticate');
    $bulkContent .= '</button>';
    $bulkContent .= '</div></div>';

    $bulkContent .= '</form>';

    $timeoutMinutes = intdiv($guard->getTimeout(), 60);
    $bulkContent .= '<p class="help-block">';
    $bulkContent .= '<i class="rex-icon fa-info-circle"></i> ';
    $bulkContent .= $addon->i18n('yform_encryption_auth_timeout_info', (string) $timeoutMinutes);
    $bulkContent .= '</p>';

    $bulkContent .= '</div>';
}

$fragment = new rex_fragment();
$fragment->setVar('title', '<i class="rex-icon fa-shield"></i> ' . $addon->i18n('yform_encryption_bulk_auth_title'), false);
$fragment->setVar('body', $bulkContent, false);
$fragment->setVar('class', 'edit', false);
echo $fragment->parse('core/page/section.php');
