<?php

declare(strict_types=1);

/**
 * YForm Encryption AddOn - Uninstall.
 *
 * @var rex_addon $this
 * @psalm-scope-this rex_addon
 */

rex_sql_table::get(rex::getTable('yform_encryption_map'))->drop();
rex_sql_table::get(rex::getTable('yform_encryption_log'))->drop();
