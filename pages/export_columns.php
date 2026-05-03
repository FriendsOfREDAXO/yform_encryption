<?php

declare(strict_types=1);

use FriendsOfREDAXO\YFormEncryption\FieldMapper;

/**
 * YForm Encryption - Export-Spalten-Konfiguration.
 *
 * Legt je Tabelle fest, welche Spalten in CSV- und Excel-Exporten erscheinen.
 *
 * @var rex_addon $this
 * @psalm-scope-this rex_addon
 */

$addon = rex_addon::get('yform_encryption');
$mapper = FieldMapper::getInstance();

// POST verarbeiten
if (rex_post('btn_save_export_columns', 'string', '') !== '') {
    $exportColumnsConfig = rex_post('export_columns', 'array', []);
    $sanitized = [];
    foreach ($exportColumnsConfig as $tbl => $cols) {
        if (is_string($tbl) && is_array($cols)) {
            $sanitized[$tbl] = array_values(array_filter(array_map('strval', $cols)));
        }
    }
    $addon->setConfig('export_columns', json_encode($sanitized));
    echo rex_view::success($addon->i18n('yform_encryption_export_columns_saved'));
}

$exportColumnsConfig = json_decode((string) $addon->getConfig('export_columns', '{}'), true);
if (!is_array($exportColumnsConfig)) {
    $exportColumnsConfig = [];
}

$tablesWithMapping = $mapper->getAllMappings();

if ($tablesWithMapping === []) {
    echo rex_view::info($addon->i18n('yform_encryption_no_tables'));
    return;
}

$formContent = '<p class="help-block">' . $addon->i18n('yform_encryption_export_columns_help') . '</p>';
$formContent .= '<form action="' . rex_url::currentBackendPage() . '" method="post">';
$formContent .= '<input type="hidden" name="btn_save_export_columns" value="1">';

foreach (array_keys($tablesWithMapping) as $tblName) {
    // DB-Spalten ermitteln
    $colsSql = rex_sql::factory();
    try {
        $colsSql->setQuery('SHOW COLUMNS FROM `' . $tblName . '`');
    } catch (\rex_sql_exception $e) {
        continue;
    }

    $dbColumns = [];
    for ($ci = 0; $ci < $colsSql->getRows(); ++$ci) {
        $dbColumns[] = (string) $colsSql->getValue('Field');
        $colsSql->next();
    }

    if ($dbColumns === []) {
        continue;
    }

    // YForm-Labels und Tabellenname
    try {
        $yformTbl = rex_yform_manager_table::get($tblName);
    } catch (\Exception $e) {
        $yformTbl = null;
    }

    $colLabels = [];
    foreach ($dbColumns as $colName) {
        $vf = ($yformTbl !== null) ? $yformTbl->getValueField($colName) : null;
        $colLabels[$colName] = $vf ? $vf->getLabel() : $colName;
    }

    try {
        $tblLabel = ($yformTbl !== null) ? rex_i18n::translate($yformTbl->getName()) : $tblName;
    } catch (\Exception $e) {
        $tblLabel = $tblName;
    }

    $selectedCols = $exportColumnsConfig[$tblName] ?? [];
    $allSelected = ($selectedCols === []);
    $encFields = $mapper->getEncryptedFields($tblName);
    $uid = preg_replace('/[^a-z0-9]/', '_', $tblName);

    $formContent .= '<div class="yform-encryption-table-group" style="margin-bottom:24px">';
    $formContent .= '<h4><i class="rex-icon fa-table"></i> ' . rex_escape($tblLabel);
    $formContent .= ' <small class="text-muted">' . rex_escape($tblName) . '</small>';
    $formContent .= ' &nbsp;<a href="#" class="btn btn-xs btn-default yform-enc-col-all" data-target="' . $uid . '">'
        . $addon->i18n('yform_encryption_export_select_all') . '</a>'
        . ' <a href="#" class="btn btn-xs btn-default yform-enc-col-none" data-target="' . $uid . '">'
        . $addon->i18n('yform_encryption_export_select_none') . '</a>';
    $formContent .= '</h4>';

    $formContent .= '<div class="row" id="yform-enc-cols-' . $uid . '">';
    foreach ($dbColumns as $colName) {
        $isChecked = $allSelected || in_array($colName, $selectedCols, true);
        $isEncCol = in_array($colName, $encFields, true);

        $formContent .= '<div class="col-sm-3 col-md-2" style="margin-bottom:4px">';
        $formContent .= '<label style="font-weight:normal;margin:0">';
        $formContent .= '<input type="checkbox" name="export_columns[' . rex_escape($tblName) . '][]" '
            . 'value="' . rex_escape($colName) . '"'
            . ($isChecked ? ' checked' : '') . '> ';
        $formContent .= '<code style="font-size:11px">' . rex_escape($colName) . '</code>';
        if ($colLabels[$colName] !== $colName) {
            $formContent .= '<br><small class="text-muted" style="padding-left:18px">'
                . rex_escape($colLabels[$colName]) . '</small>';
        }
        if ($isEncCol) {
            $formContent .= ' <i class="rex-icon fa-lock text-success" title="verschlüsselt"></i>';
        }
        $formContent .= '</label>';
        $formContent .= '</div>';
    }
    $formContent .= '</div>';
    $formContent .= '</div>';
}

$formContent .= '<div class="rex-page-section-footer">';
$formContent .= '<button class="btn btn-save" type="submit" name="btn_save_export_columns" value="1">'
    . '<i class="rex-icon fa-save"></i> ' . $addon->i18n('yform_encryption_export_save_cols')
    . '</button>';
$formContent .= '</div>';
$formContent .= '</form>';

$fragment = new rex_fragment();
$fragment->setVar('title', $addon->i18n('yform_encryption_export_columns_title'), false);
$fragment->setVar('body', $formContent, false);
$fragment->setVar('class', 'edit', false);
echo $fragment->parse('core/page/section.php');
