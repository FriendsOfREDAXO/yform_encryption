<?php

declare(strict_types=1);

/**
 * YForm Encryption AddOn - Install.
 *
 * @var rex_addon $this
 * @psalm-scope-this rex_addon
 */

// Prüfe ob sodium Extension verfügbar ist
if (!extension_loaded('sodium')) {
    throw new rex_functional_exception('Die PHP-Erweiterung "sodium" ist nicht verfügbar. Diese wird für die Verschlüsselung benötigt.');
}

// Mapping-Tabelle erstellen
rex_sql_table::get(rex::getTable('yform_encryption_map'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('table_name', 'varchar(191)', false))
    ->ensureColumn(new rex_sql_column('field_name', 'varchar(191)', false))
    ->ensureColumn(new rex_sql_column('status', 'tinyint(1)', false, '1'))
    ->ensureGlobalColumns()
    ->ensureIndex(new rex_sql_index('unique_field', ['table_name', 'field_name'], rex_sql_index::UNIQUE))
    ->ensure();

// Log-Tabelle für Verschlüsselungs-Operationen
rex_sql_table::get(rex::getTable('yform_encryption_log'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('table_name', 'varchar(191)', false))
    ->ensureColumn(new rex_sql_column('dataset_id', 'int', false))
    ->ensureColumn(new rex_sql_column('field_name', 'varchar(191)', false))
    ->ensureColumn(new rex_sql_column('action', 'varchar(50)', false))
    ->ensureColumn(new rex_sql_column('status', 'varchar(50)', false, 'success'))
    ->ensureColumn(new rex_sql_column('message', 'text', true))
    ->ensureGlobalColumns()
    ->ensure();
