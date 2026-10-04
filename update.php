<?php

/**
 * Update auf 1.3.0: db_type verschlüsselter Felder in der YForm-Felddefinition auf TEXT setzen.
 *
 * Tabellen mit „Schema überschreiben“ (YForm-Standard) haben eine auf TEXT erweiterte Spalte beim nächsten
 * Neuaufbau wieder auf den kurzen Typ (z. B. varchar(34) bei fields_iban) verkleinert – verschlüsselte Werte
 * wurden dabei abgeschnitten. Bewusst reines SQL, damit das Update ohne Klassen-Autoload läuft.
 */
$map = rex::getTable('yform_encryption_map');
$fieldTable = rex::getTable('yform_field');
$sql = rex_sql::factory();
try {
    $rows = $sql->getArray('SELECT table_name, field_name FROM ' . $map . ' WHERE status = 1');
} catch (rex_sql_exception $e) {
    $rows = [];
}
foreach ($rows as $row) {
    $field = $sql->getArray('SELECT db_type FROM ' . $fieldTable . ' WHERE table_name = ? AND name = ? AND type_id = "value"', [$row['table_name'], $row['field_name']])[0] ?? null;
    if ($field && preg_match('/^varchar\((\d+)\)/i', (string) $field['db_type'], $m) && (int) $m[1] < 500) {
        $sql->setQuery('UPDATE ' . $fieldTable . ' SET db_type = "text" WHERE table_name = ? AND name = ? AND type_id = "value"', [$row['table_name'], $row['field_name']]);
    }
}
