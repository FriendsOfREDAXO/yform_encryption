<?php

declare(strict_types=1);

namespace FriendsOfREDAXO\YFormEncryption;

use rex;
use rex_addon;
use rex_file;
use rex_path;

/**
 * Verwaltet den Verschlüsselungsschlüssel.
 *
 * Unterstützt drei Quellen (in dieser Priorität):
 * 1. Umgebungsvariable (YFORM_ENCRYPTION_KEY) - ideal für Plesk/Docker
 * 2. Datei außerhalb des Webroot
 * 3. Datei im REDAXO data-Verzeichnis (Fallback, weniger sicher)
 */
class KeyManager
{
    private const ENV_KEY_NAME = 'YFORM_ENCRYPTION_KEY';
    private const KEY_FILE_NAME = '.yform_encryption.key';

    private static ?self $instance = null;
    private ?string $key = null;

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Gibt den Verschlüsselungsschlüssel zurück (binary, 32 Bytes).
     *
     * @throws \rex_exception wenn kein Schlüssel gefunden wird
     */
    public function getKey(): string
    {
        if (null !== $this->key) {
            return $this->key;
        }

        // 1. Umgebungsvariable prüfen
        $envKey = $this->getKeyFromEnvironment();
        if (null !== $envKey) {
            $this->key = $envKey;
            return $this->key;
        }

        // 2. Datei außerhalb Webroot prüfen
        $fileKey = $this->getKeyFromFile();
        if (null !== $fileKey) {
            $this->key = $fileKey;
            return $this->key;
        }

        // 3. Datei im data-Verzeichnis prüfen
        $dataKey = $this->getKeyFromDataDir();
        if (null !== $dataKey) {
            $this->key = $dataKey;
            return $this->key;
        }

        throw new \rex_exception(
            'Kein Verschlüsselungsschlüssel gefunden. '
            . 'Bitte konfigurieren Sie den Schlüssel unter YForm Encryption > Einstellungen.'
        );
    }

    /**
     * Prüft ob ein Schlüssel verfügbar ist.
     */
    public function hasKey(): bool
    {
        try {
            $this->getKey();
            return true;
        } catch (\rex_exception $e) {
            return false;
        }
    }

    /**
     * Gibt die aktuelle Schlüsselquelle zurück.
     */
    public function getKeySource(): string
    {
        if (null !== $this->getKeyFromEnvironment()) {
            return 'environment';
        }

        if (null !== $this->getKeyFromFile()) {
            return 'file';
        }

        if (null !== $this->getKeyFromDataDir()) {
            return 'data_dir';
        }

        return 'none';
    }

    /**
     * Gibt den konfigurierten Pfad zur Schlüsseldatei zurück.
     */
    public function getKeyFilePath(): string
    {
        $addon = rex_addon::get('yform_encryption');
        $configuredPath = $addon->getConfig('key_file_path', '');

        if ($configuredPath !== '') {
            return $configuredPath;
        }

        // Standard: Ein Verzeichnis über dem Webroot
        $webroot = rex_path::base();
        return dirname($webroot) . '/' . self::KEY_FILE_NAME;
    }

    /**
     * Gibt den Pfad zum Schlüssel im data-Verzeichnis zurück.
     */
    public function getDataDirKeyPath(): string
    {
        return rex_path::addonData('yform_encryption', self::KEY_FILE_NAME);
    }

    /**
     * Generiert einen neuen Schlüssel und speichert ihn.
     *
     * @param string $location 'file' oder 'data_dir'
     * @throws \rex_exception
     */
    public function generateKey(string $location = 'file'): string
    {
        $key = sodium_crypto_secretbox_keygen();
        $encodedKey = base64_encode($key);

        if ($location === 'file') {
            $path = $this->getKeyFilePath();
            $dir = dirname($path);

            if (!is_dir($dir)) {
                throw new \rex_exception(
                    'Verzeichnis existiert nicht: ' . $dir
                );
            }

            if (file_put_contents($path, $encodedKey) === false) {
                throw new \rex_exception(
                    'Schlüsseldatei konnte nicht geschrieben werden: ' . $path
                    . ' – Bitte Dateiberechtigungen prüfen.'
                );
            }
            chmod($path, 0600);
        } elseif ($location === 'data_dir') {
            $path = $this->getDataDirKeyPath();
            rex_file::put($path, $encodedKey);

            // .htaccess zum Schutz der Datei
            $htaccessPath = rex_path::addonData('yform_encryption', '.htaccess');
            rex_file::put($htaccessPath, "Order deny,allow\nDeny from all\n");
        } else {
            throw new \rex_exception('Ungültige Location: ' . $location);
        }

        // Cache zurücksetzen
        $this->key = null;

        return $encodedKey;
    }

    /**
     * Schlüssel aus Umgebungsvariable lesen.
     */
    private function getKeyFromEnvironment(): ?string
    {
        // getenv() als primäre Quelle (funktioniert überall)
        $envKey = getenv(self::ENV_KEY_NAME);

        // Apache SetEnv / Plesk: über rex_request::server()
        if ($envKey === false) {
            $envKey = \rex_request::server(self::ENV_KEY_NAME, 'string', '');
            if ($envKey === '') {
                return null;
            }
        }

        if ($envKey === false || $envKey === '') {
            return null;
        }

        $decoded = base64_decode($envKey, true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return null;
        }

        return $decoded;
    }

    /**
     * Schlüssel aus Datei außerhalb Webroot lesen.
     */
    private function getKeyFromFile(): ?string
    {
        $path = $this->getKeyFilePath();

        if (!file_exists($path) || !is_readable($path)) {
            return null;
        }

        $content = trim(file_get_contents($path));
        if ($content === '' || $content === false) {
            return null;
        }

        $decoded = base64_decode($content, true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return null;
        }

        return $decoded;
    }

    /**
     * Schlüssel aus REDAXO data-Verzeichnis lesen.
     */
    private function getKeyFromDataDir(): ?string
    {
        $path = $this->getDataDirKeyPath();
        $content = rex_file::get($path);

        if (null === $content) {
            return null;
        }

        $content = trim($content);
        if ($content === '') {
            return null;
        }

        $decoded = base64_decode($content, true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return null;
        }

        return $decoded;
    }

    /**
     * Cache zurücksetzen (z.B. nach Schlüsseländerung).
     */
    public function resetCache(): void
    {
        $this->key = null;
        self::$instance = null;
    }
}
