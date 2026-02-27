<?php

declare(strict_types=1);

namespace FriendsOfREDAXO\YFormEncryption;

use rex;
use rex_login;
use rex_sql;

/**
 * Verwaltet die Session-basierte Entschlüsselungs-Berechtigung.
 *
 * Verschlüsselte Daten werden im Backend nur entschlüsselt angezeigt,
 * wenn der User sich erneut authentifiziert hat (Re-Authentication).
 * Die Berechtigung läuft nach einer konfigurierbaren Zeit ab (Standard: 30 Minuten).
 */
class SessionGuard
{
    private const SESSION_KEY = 'yform_encryption_unlocked';
    private const SESSION_TIMESTAMP = 'yform_encryption_unlock_time';
    private const DEFAULT_TIMEOUT = 1800; // 30 Minuten in Sekunden

    private static ?self $instance = null;

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
     * Prüft ob die Entschlüsselung aktuell freigeschaltet ist.
     */
    public function isUnlocked(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $unlocked = $_SESSION[self::SESSION_KEY] ?? false;
        if ($unlocked !== true) {
            return false;
        }

        $unlockTime = $_SESSION[self::SESSION_TIMESTAMP] ?? 0;
        $timeout = $this->getTimeout();

        // Timeout prüfen
        if ((time() - $unlockTime) > $timeout) {
            $this->lock();
            return false;
        }

        return true;
    }

    /**
     * Authentifiziert den User und schaltet die Entschlüsselung frei.
     *
     * @param string $login Backend-Benutzername
     * @param string $password Backend-Passwort (Klartext)
     * @return bool true wenn Authentifizierung erfolgreich
     */
    public function authenticate(string $login, string $password): bool
    {
        $currentUser = rex::getUser();
        if (null === $currentUser) {
            return false;
        }

        // Login muss mit aktuellem User übereinstimmen
        if ($currentUser->getLogin() !== $login) {
            return false;
        }

        // Passwort aus DB laden und prüfen
        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT password FROM ' . rex::getTable('user') . ' WHERE id = :id AND status = 1 LIMIT 1',
            ['id' => $currentUser->getId()],
        );

        if ($sql->getRows() === 0) {
            return false;
        }

        $storedHash = (string) $sql->getValue('password');

        // REDAXO-Konvention: Passwort wird mit sha1 vorgehasht
        if (!rex_login::passwordVerify($password, $storedHash)) {
            return false;
        }

        // Freischalten
        $this->unlock();
        return true;
    }

    /**
     * Schaltet die Entschlüsselung frei.
     */
    public function unlock(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION[self::SESSION_KEY] = true;
        $_SESSION[self::SESSION_TIMESTAMP] = time();
    }

    /**
     * Sperrt die Entschlüsselung.
     */
    public function lock(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        unset($_SESSION[self::SESSION_KEY], $_SESSION[self::SESSION_TIMESTAMP]);
    }

    /**
     * Gibt die verbleibende Zeit in Sekunden zurück.
     *
     * @return int Verbleibende Sekunden, 0 wenn gesperrt
     */
    public function getRemainingTime(): int
    {
        if (!$this->isUnlocked()) {
            return 0;
        }

        $unlockTime = $_SESSION[self::SESSION_TIMESTAMP] ?? 0;
        $timeout = $this->getTimeout();
        $remaining = $timeout - (time() - $unlockTime);

        return max(0, $remaining);
    }

    /**
     * Gibt den konfigurierten Timeout in Sekunden zurück.
     */
    public function getTimeout(): int
    {
        $addon = \rex_addon::get('yform_encryption');
        return (int) $addon->getProperty('session_timeout', self::DEFAULT_TIMEOUT);
    }

    /**
     * Gibt den Zeitpunkt der Freischaltung zurück.
     */
    public function getUnlockTime(): int
    {
        return (int) ($_SESSION[self::SESSION_TIMESTAMP] ?? 0);
    }

    /**
     * Cache zurücksetzen.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
