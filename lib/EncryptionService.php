<?php

declare(strict_types=1);

namespace FriendsOfREDAXO\YFormEncryption;

/**
 * Verschlüsselungsservice für YForm-Daten.
 *
 * Verwendet libsodium (XSalsa20-Poly1305) für authentifizierte Verschlüsselung.
 * Verschlüsselte Werte werden als 'ENC:' + Base64(nonce + ciphertext) gespeichert.
 */
class EncryptionService
{
    private const PREFIX = 'ENC:';
    private static ?self $instance = null;
    private KeyManager $keyManager;

    private function __construct()
    {
        $this->keyManager = KeyManager::getInstance();
    }

    public static function getInstance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Verschlüsselt einen Klartext-Wert.
     *
     * @param string $plaintext Der zu verschlüsselnde Text
     * @return string Der verschlüsselte Wert im Format ENC:base64(nonce+ciphertext)
     * @throws \rex_exception bei Verschlüsselungsfehlern
     */
    public function encrypt(string $plaintext): string
    {
        // Leere Werte nicht verschlüsseln
        if ($plaintext === '') {
            return '';
        }

        // Bereits verschlüsselte Werte nicht erneut verschlüsseln
        if ($this->isEncrypted($plaintext)) {
            return $plaintext;
        }

        $key = $this->keyManager->getKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);

        // Nonce + Ciphertext zusammenführen und Base64-kodieren
        $encoded = base64_encode($nonce . $ciphertext);

        // Speicher bereinigen
        sodium_memzero($plaintext);

        return self::PREFIX . $encoded;
    }

    /**
     * Entschlüsselt einen verschlüsselten Wert.
     *
     * @param string $encrypted Der verschlüsselte Wert
     * @return string Der entschlüsselte Klartext
     * @throws \rex_exception bei Entschlüsselungsfehlern
     */
    public function decrypt(string $encrypted): string
    {
        // Leere Werte oder nicht-verschlüsselte Werte direkt zurückgeben
        if ($encrypted === '' || !$this->isEncrypted($encrypted)) {
            return $encrypted;
        }

        $key = $this->keyManager->getKey();

        // Prefix entfernen und Base64 dekodieren
        $decoded = base64_decode(substr($encrypted, strlen(self::PREFIX)), true);

        if ($decoded === false) {
            throw new \rex_exception('Ungültiges verschlüsseltes Format: Base64-Dekodierung fehlgeschlagen');
        }

        $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

        if (strlen($decoded) < $nonceLength) {
            throw new \rex_exception('Ungültiges verschlüsseltes Format: Daten zu kurz');
        }

        $nonce = substr($decoded, 0, $nonceLength);
        $ciphertext = substr($decoded, $nonceLength);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

        if ($plaintext === false) {
            throw new \rex_exception(
                'Entschlüsselung fehlgeschlagen. '
                . 'Möglicherweise wurde der Schlüssel geändert oder die Daten sind beschädigt.'
            );
        }

        return $plaintext;
    }

    /**
     * Prüft ob ein Wert verschlüsselt ist.
     */
    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /**
     * Gibt den Encryption-Prefix zurück.
     */
    public static function getPrefix(): string
    {
        return self::PREFIX;
    }

    /**
     * Entschlüsselt einen Wert sicher (gibt Original zurück bei Fehler).
     *
     * Ideal für die Anzeige, wo Fehler nicht das System stoppen sollen.
     *
     * @param string $value Der möglicherweise verschlüsselte Wert
     * @return string Der entschlüsselte oder originale Wert
     */
    public function decryptSafe(string $value): string
    {
        try {
            return $this->decrypt($value);
        } catch (\Exception $e) {
            return $value;
        }
    }

    /**
     * Verschlüsselt mehrere Felder eines Datensatzes.
     *
     * @param array<string, mixed> $data Die Datensatz-Daten
     * @param list<string> $fields Die zu verschlüsselnden Feldnamen
     * @return array<string, mixed> Die Daten mit verschlüsselten Feldern
     */
    public function encryptFields(array $data, array $fields): array
    {
        foreach ($fields as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && $data[$field] !== '') {
                $data[$field] = $this->encrypt($data[$field]);
            }
        }
        return $data;
    }

    /**
     * Entschlüsselt mehrere Felder eines Datensatzes.
     *
     * @param array<string, mixed> $data Die Datensatz-Daten
     * @param list<string> $fields Die zu entschlüsselnden Feldnamen
     * @return array<string, mixed> Die Daten mit entschlüsselten Feldern
     */
    public function decryptFields(array $data, array $fields): array
    {
        foreach ($fields as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && $data[$field] !== '') {
                $data[$field] = $this->decryptSafe($data[$field]);
            }
        }
        return $data;
    }

    /**
     * Cache zurücksetzen.
     */
    public function resetCache(): void
    {
        self::$instance = null;
    }
}
