<?php

declare(strict_types=1);

use FriendsOfREDAXO\YFormEncryption\FieldMapper;
use FriendsOfREDAXO\YFormEncryption\KeyManager;

/**
 * YForm Encryption - Info-Seite.
 *
 * @var rex_addon $this
 * @psalm-scope-this rex_addon
 */

$addon = rex_addon::get('yform_encryption');
$keyManager = KeyManager::getInstance();
$mapper = FieldMapper::getInstance();

// ---- Funktionsweise ----
$infoContent = '';
$infoContent .= '<h4>' . $addon->i18n('yform_encryption_info_how_title') . '</h4>';
$infoContent .= '<p>' . $addon->i18n('yform_encryption_info_how_desc') . '</p>';

$infoContent .= '<h4>' . $addon->i18n('yform_encryption_info_key_title') . '</h4>';
$infoContent .= '<p>' . $addon->i18n('yform_encryption_info_key_desc') . '</p>';

$infoContent .= '<ol>';
$infoContent .= '<li><strong>' . $addon->i18n('yform_encryption_source_env') . '</strong>';
$infoContent .= '<br>' . $addon->i18n('yform_encryption_info_env_desc') . '</li>';
$infoContent .= '<li><strong>' . $addon->i18n('yform_encryption_source_file') . '</strong>';
$infoContent .= '<br>' . $addon->i18n('yform_encryption_info_file_desc') . '</li>';
$infoContent .= '<li><strong>' . $addon->i18n('yform_encryption_source_data') . '</strong>';
$infoContent .= '<br>' . $addon->i18n('yform_encryption_info_data_desc') . '</li>';
$infoContent .= '</ol>';

$infoContent .= '<h4>' . $addon->i18n('yform_encryption_info_usage_title') . '</h4>';
$infoContent .= '<pre><code>';
$infoContent .= rex_escape('// Daten entschlüsseln (z.B. für PDF-Export)
use FriendsOfREDAXO\YFormEncryption\EncryptionService;

$encryption = EncryptionService::getInstance();

// Einzelnen Wert entschlüsseln
$klartext = $encryption->decrypt($verschluesselt);

// Sicher entschlüsseln (gibt Original bei Fehler zurück)
$klartext = $encryption->decryptSafe($wert);

// Prüfen ob ein Wert verschlüsselt ist
if ($encryption->isEncrypted($wert)) {
    // ...
}

// Mehrere Felder auf einmal entschlüsseln
$data = $encryption->decryptFields($row, [\'name\', \'email\', \'phone\']);');
$infoContent .= '</code></pre>';

$infoContent .= '<h4>' . $addon->i18n('yform_encryption_info_plesk_title') . '</h4>';
$infoContent .= '<p>' . $addon->i18n('yform_encryption_info_plesk_desc') . '</p>';
$infoContent .= '<pre><code>';
$infoContent .= rex_escape('# Plesk → Domains → PHP-Einstellungen → Zusätzliche Direktiven:
# (oder via .htaccess)
SetEnv YFORM_ENCRYPTION_KEY "' . ($keyManager->hasKey() ? '***gesetzt***' : '<Ihr-Base64-Schlüssel>') . '"

# Oder in der php.ini:
# auto_prepend_file kann auch einen Schlüssel setzen');
$infoContent .= '</code></pre>';

$infoContent .= '<h4>' . $addon->i18n('yform_encryption_info_docker_title') . '</h4>';
$infoContent .= '<pre><code>';
$infoContent .= rex_escape('# docker-compose.yml
services:
  web:
    environment:
      - YFORM_ENCRYPTION_KEY=Ihr-Base64-Schlüssel

# Oder .env Datei
YFORM_ENCRYPTION_KEY=Ihr-Base64-Schlüssel');
$infoContent .= '</code></pre>';

$infoContent .= '<h4>' . $addon->i18n('yform_encryption_info_warning_title') . '</h4>';
$infoContent .= '<div class="alert alert-danger">';
$infoContent .= '<i class="rex-icon fa-exclamation-triangle"></i> ';
$infoContent .= $addon->i18n('yform_encryption_info_warning_desc');
$infoContent .= '</div>';

$fragment = new rex_fragment();
$fragment->setVar('title', $addon->i18n('yform_encryption_info'), false);
$fragment->setVar('body', $infoContent, false);
echo $fragment->parse('core/page/section.php');

// ---- Aktuelles Mapping ----
$mappings = $mapper->getAllMappings();

if ($mappings !== []) {
    $mappingContent = '<table class="table table-striped">';
    $mappingContent .= '<thead><tr>';
    $mappingContent .= '<th>' . $addon->i18n('yform_encryption_table') . '</th>';
    $mappingContent .= '<th>' . $addon->i18n('yform_encryption_fields') . '</th>';
    $mappingContent .= '</tr></thead>';
    $mappingContent .= '<tbody>';

    foreach ($mappings as $tableName => $fields) {
        $mappingContent .= '<tr>';
        $mappingContent .= '<td><code>' . rex_escape($tableName) . '</code></td>';
        $mappingContent .= '<td>';
        foreach ($fields as $field) {
            $mappingContent .= '<span class="label label-success"><i class="rex-icon fa-lock"></i> '
                . rex_escape($field) . '</span> ';
        }
        $mappingContent .= '</td>';
        $mappingContent .= '</tr>';
    }

    $mappingContent .= '</tbody></table>';

    $fragment = new rex_fragment();
    $fragment->setVar('title', $addon->i18n('yform_encryption_current_mapping'), false);
    $fragment->setVar('body', $mappingContent, false);
    echo $fragment->parse('core/page/section.php');
}
