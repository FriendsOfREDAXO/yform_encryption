<?php

declare(strict_types=1);

use FriendsOfREDAXO\YFormEncryption\EventHandler;
use FriendsOfREDAXO\YFormEncryption\FieldMapper;
use FriendsOfREDAXO\YFormEncryption\SessionGuard;

// Lokalen PhpSpreadsheet-Vendor einbinden
$vendorAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}

/**
 * YForm Encryption AddOn - Boot.
 *
 * @var rex_addon $this
 * @psalm-scope-this rex_addon
 */

// EP-Handler nur registrieren wenn YForm verfügbar ist
if (rex_addon::get('yform')->isAvailable()) {
    EventHandler::register();
}

// Berechtigung registrieren – erscheint in Rollenverwaltung
if (rex::isBackend()) {
    rex_perm::register('yform_encryption[export]', rex_i18n::msg('perm_yform_encryption_export'));
}

if (rex::isBackend() && rex::getUser()) {
    rex_view::addCssFile($this->getAssetsUrl('css/yform-encryption.css'));
    rex_view::addJsFile($this->getAssetsUrl('js/yform-encryption.js'));

    // Auf YForm-Manager-Seiten: Lock/Unlock-Bar einblenden
    rex_extension::register('PAGE_CHECKED', function () {
        $page = rex_be_controller::getCurrentPage();

        // Nur auf yform/manager-Seiten aktiv
        if (!str_starts_with($page, 'yform/manager/data')) {
            return;
        }

        // Prüfen ob die aktuelle Tabelle verschlüsselte Felder hat
        $tableName = rex_request('table_name', 'string', '');
        if ($tableName === '') {
            return;
        }

        $mapper = FieldMapper::getInstance();
        if (!$mapper->hasEncryptedFields($tableName)) {
            return;
        }

        $guard = SessionGuard::getInstance();
        $isUnlocked = $guard->isUnlocked();
        $remaining = $guard->getRemainingTime();
        $timeout = $guard->getTimeout();

        // Daten als data-Attribute für JS bereitstellen
        rex_extension::register('OUTPUT_FILTER', static function (rex_extension_point $ep) use ($isUnlocked, $remaining, $timeout, $tableName) {
            $content = $ep->getSubject();

            $statusBar = '<div id="yform-enc-status-bar" '
                . 'data-unlocked="' . ($isUnlocked ? '1' : '0') . '" '
                . 'data-remaining="' . $remaining . '" '
                . 'data-timeout="' . $timeout . '" '
                . 'data-table="' . rex_escape($tableName) . '" '
                . 'class="yform-enc-bar' . ($isUnlocked ? ' yform-enc-bar-unlocked' : ' yform-enc-bar-locked') . '">'
                . '</div>';

            // Auth-Modal
            $modal = '<div id="yform-enc-auth-modal" class="yform-enc-modal" style="display:none">'
                . '<div class="yform-enc-modal-backdrop"></div>'
                . '<div class="yform-enc-modal-dialog">'
                . '<div class="yform-enc-modal-header">'
                . '<h4><i class="rex-icon fa-lock"></i> ' . rex_i18n::msg('yform_encryption_auth_title') . '</h4>'
                . '</div>'
                . '<div class="yform-enc-modal-body">'
                . '<p>' . rex_i18n::msg('yform_encryption_auth_desc') . '</p>'
                . '<div class="form-group">'
                . '<label for="yform-enc-login">' . rex_i18n::msg('yform_encryption_auth_login') . '</label>'
                . '<input type="text" class="form-control" id="yform-enc-login" autocomplete="username">'
                . '</div>'
                . '<div class="form-group">'
                . '<label for="yform-enc-password">' . rex_i18n::msg('yform_encryption_auth_password') . '</label>'
                . '<input type="password" class="form-control" id="yform-enc-password" autocomplete="current-password">'
                . '</div>'
                . '<div id="yform-enc-auth-error" class="alert alert-danger" style="display:none"></div>'
                . '</div>'
                . '<div class="yform-enc-modal-footer">'
                . '<button type="button" class="btn btn-default" id="yform-enc-cancel">'
                . rex_i18n::msg('yform_encryption_auth_cancel') . '</button> '
                . '<button type="button" class="btn btn-primary" id="yform-enc-submit">'
                . '<i class="rex-icon fa-unlock"></i> '
                . rex_i18n::msg('yform_encryption_auth_submit') . '</button>'
                . '</div>'
                . '</div>'
                . '</div>';

            // Vor </body> einfügen
            $content = str_replace('</body>', $statusBar . $modal . '</body>', $content);

            return $content;
        });
    });
}
