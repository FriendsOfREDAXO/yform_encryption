<?php

declare(strict_types=1);

/**
 * YForm Encryption - Backend Hauptseite.
 *
 * @var rex_addon $this
 * @psalm-scope-this rex_addon
 */

$package = rex_addon::get('yform_encryption');
echo rex_view::title($package->i18n('yform_encryption_title'));
rex_be_controller::includeCurrentPageSubPath();
