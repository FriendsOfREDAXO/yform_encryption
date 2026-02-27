<?php

declare(strict_types=1);

use FriendsOfREDAXO\YFormEncryption\KeyManager;

/**
 * YForm Encryption - Konfigurationsseite.
 *
 * @var rex_addon $this
 * @psalm-scope-this rex_addon
 */

$addon = rex_addon::get('yform_encryption');
$keyManager = KeyManager::getInstance();

$message = '';
$error = '';

// Formular verarbeiten
if (rex_post('btn_save', 'string', '') !== '') {
    $addon->setConfig('key_file_path', rex_post('key_file_path', 'string', ''));
    $addon->setConfig('logging_enabled', rex_post('logging_enabled', 'int', 1));
    $timeoutMinutes = max(1, rex_post('session_timeout', 'int', 30));
    $addon->setConfig('session_timeout', $timeoutMinutes * 60);
    $keyManager->resetCache();
    $message = $addon->i18n('yform_encryption_config_saved');
}

// Schlüssel generieren
if (rex_post('btn_generate_key', 'string', '') !== '') {
    $location = rex_post('key_location', 'string', 'file');

    try {
        $encodedKey = $keyManager->generateKey($location);
        $message = $addon->i18n('yform_encryption_key_generated');

        if ($location === 'file') {
            $message .= '<br>' . $addon->i18n('yform_encryption_key_saved_to', $keyManager->getKeyFilePath());
        } else {
            $message .= '<br>' . $addon->i18n('yform_encryption_key_saved_to', $keyManager->getDataDirKeyPath());
        }
    } catch (rex_exception $e) {
        $error = $e->getMessage();
    }
}

// Status ermitteln
$hasKey = $keyManager->hasKey();
$keySource = $keyManager->getKeySource();
$sodiumAvailable = extension_loaded('sodium');

// Ausgabe
if ($message !== '') {
    echo rex_view::success($message);
}
if ($error !== '') {
    echo rex_view::error($error);
}

// ---- Status-Info ----
$statusContent = '';

// Sodium-Status
$statusContent .= '<dl class="dl-horizontal">';
$statusContent .= '<dt>' . $addon->i18n('yform_encryption_sodium_status') . '</dt>';
$statusContent .= '<dd>' . ($sodiumAvailable
    ? '<span class="text-success"><i class="rex-icon fa-check"></i> ' . $addon->i18n('yform_encryption_available') . '</span>'
    : '<span class="text-danger"><i class="rex-icon fa-times"></i> ' . $addon->i18n('yform_encryption_not_available') . '</span>'
) . '</dd>';

// Schlüssel-Status
$statusContent .= '<dt>' . $addon->i18n('yform_encryption_key_status') . '</dt>';
$statusContent .= '<dd>';
if ($hasKey) {
    $sourceLabels = [
        'environment' => $addon->i18n('yform_encryption_source_env'),
        'file' => $addon->i18n('yform_encryption_source_file') . ': <code>' . rex_escape($keyManager->getKeyFilePath()) . '</code>',
        'data_dir' => $addon->i18n('yform_encryption_source_data') . ': <code>' . rex_escape($keyManager->getDataDirKeyPath()) . '</code>',
    ];
    $statusContent .= '<span class="text-success"><i class="rex-icon fa-check"></i> ' . $addon->i18n('yform_encryption_key_found') . '</span>';
    $statusContent .= '<br><small>' . $addon->i18n('yform_encryption_source') . ': ' . ($sourceLabels[$keySource] ?? $keySource) . '</small>';
} else {
    $statusContent .= '<span class="text-danger"><i class="rex-icon fa-times"></i> ' . $addon->i18n('yform_encryption_no_key') . '</span>';
}
$statusContent .= '</dd>';
$statusContent .= '</dl>';

$fragment = new rex_fragment();
$fragment->setVar('title', $addon->i18n('yform_encryption_status'), false);
$fragment->setVar('body', $statusContent, false);
echo $fragment->parse('core/page/section.php');

// ---- Konfigurationsformular ----
$formContent = '';
$formContent .= '<form action="' . rex_url::currentBackendPage() . '" method="post">';

// Pfad zur Schlüsseldatei
$formContent .= '<div class="form-group">';
$formContent .= '<label for="key_file_path">' . $addon->i18n('yform_encryption_key_file_path') . '</label>';
$formContent .= '<input class="form-control" type="text" id="key_file_path" name="key_file_path" '
    . 'value="' . rex_escape($addon->getConfig('key_file_path', '')) . '" '
    . 'placeholder="' . rex_escape($keyManager->getKeyFilePath()) . '">';
$formContent .= '<p class="help-block">' . $addon->i18n('yform_encryption_key_file_path_help') . '</p>';
$formContent .= '</div>';

// Logging
$loggingEnabled = (int) $addon->getConfig('logging_enabled', 1);
$formContent .= '<div class="form-group">';
$formContent .= '<label>';
$formContent .= '<input type="checkbox" name="logging_enabled" value="1"' . ($loggingEnabled === 1 ? ' checked' : '') . '> ';
$formContent .= $addon->i18n('yform_encryption_logging_enabled');
$formContent .= '</label>';
$formContent .= '<p class="help-block">' . $addon->i18n('yform_encryption_logging_help') . '</p>';
$formContent .= '</div>';

// Session Timeout
$currentTimeout = (int) $addon->getConfig('session_timeout', 1800);
$timeoutMinutes = intdiv($currentTimeout, 60);
$formContent .= '<div class="form-group">';
$formContent .= '<label for="session_timeout">' . $addon->i18n('yform_encryption_session_timeout') . '</label>';
$formContent .= '<div class="input-group" style="max-width:200px;">';
$formContent .= '<input class="form-control" type="number" id="session_timeout" name="session_timeout" '
    . 'value="' . $timeoutMinutes . '" min="1" max="1440">';
$formContent .= '<span class="input-group-addon">min</span>';
$formContent .= '</div>';
$formContent .= '<p class="help-block">' . $addon->i18n('yform_encryption_session_timeout_help') . '</p>';
$formContent .= '</div>';

$formContent .= '<button class="btn btn-save" type="submit" name="btn_save" value="1">'
    . $addon->i18n('yform_encryption_save') . '</button>';
$formContent .= '</form>';

$fragment = new rex_fragment();
$fragment->setVar('title', $addon->i18n('yform_encryption_config'), false);
$fragment->setVar('body', $formContent, false);
$fragment->setVar('class', 'edit', false);
echo $fragment->parse('core/page/section.php');

// ---- Schlüssel generieren ----
$keyContent = '';

if (!$hasKey) {
    $keyContent .= '<div class="alert alert-warning">';
    $keyContent .= '<i class="rex-icon fa-exclamation-triangle"></i> ';
    $keyContent .= $addon->i18n('yform_encryption_no_key_warning');
    $keyContent .= '</div>';
}

$keyContent .= '<form action="' . rex_url::currentBackendPage() . '" method="post">';
$keyContent .= '<div class="form-group">';
$keyContent .= '<label>' . $addon->i18n('yform_encryption_key_location') . '</label>';
$keyContent .= '<div class="radio"><label>';
$keyContent .= '<input type="radio" name="key_location" value="file" checked> ';
$keyContent .= $addon->i18n('yform_encryption_location_file');
$keyContent .= '<br><small class="text-muted">' . rex_escape($keyManager->getKeyFilePath()) . '</small>';
$keyContent .= '</label></div>';
$keyContent .= '<div class="radio"><label>';
$keyContent .= '<input type="radio" name="key_location" value="data_dir"> ';
$keyContent .= $addon->i18n('yform_encryption_location_data');
$keyContent .= '<br><small class="text-muted">' . rex_escape($keyManager->getDataDirKeyPath()) . '</small>';
$keyContent .= '</label></div>';
$keyContent .= '</div>';

$keyContent .= '<div class="alert alert-info">';
$keyContent .= '<i class="rex-icon fa-info-circle"></i> ';
$keyContent .= $addon->i18n('yform_encryption_env_hint');
$keyContent .= '<br><code>YFORM_ENCRYPTION_KEY=' . ($hasKey ? '***' : '&lt;base64-encoded-key&gt;') . '</code>';
$keyContent .= '</div>';

$keyContent .= '<button class="btn btn-primary" type="submit" name="btn_generate_key" value="1" '
    . 'onclick="return confirm(\'' . $addon->i18n('yform_encryption_generate_confirm') . '\')">';
$keyContent .= '<i class="rex-icon fa-key"></i> ' . $addon->i18n('yform_encryption_generate_key');
$keyContent .= '</button>';

if ($hasKey) {
    $keyContent .= ' <span class="text-warning"><i class="rex-icon fa-exclamation-triangle"></i> '
        . $addon->i18n('yform_encryption_key_exists_warning') . '</span>';
}

$keyContent .= '</form>';

$fragment = new rex_fragment();
$fragment->setVar('title', $addon->i18n('yform_encryption_key_management'), false);
$fragment->setVar('body', $keyContent, false);
$fragment->setVar('class', 'edit', false);
echo $fragment->parse('core/page/section.php');
