<?php

declare(strict_types=1);

use FriendsOfREDAXO\YFormEncryption\SessionGuard;

/**
 * API-Endpoint für Re-Authentifizierung und Session-Management.
 *
 * Aktionen:
 * - unlock: Authentifiziert den User und schaltet Entschlüsselung frei
 * - lock: Sperrt die Entschlüsselung manuell
 * - status: Gibt den aktuellen Status zurück
 */
class rex_api_yform_encryption_auth extends rex_api_function
{
    protected $published = false;

    public function execute(): rex_api_result
    {
        rex_response::cleanOutputBuffers();

        $user = rex::getUser();
        if (null === $user) {
            rex_response::setStatus(rex_response::HTTP_FORBIDDEN);
            rex_response::sendJson(['error' => 'Nicht angemeldet']);
            exit;
        }

        $action = rex_request('enc_action', 'string', '');
        $guard = SessionGuard::getInstance();

        switch ($action) {
            case 'unlock':
                $this->handleUnlock($guard);
                break;

            case 'lock':
                $this->handleLock($guard);
                break;

            case 'status':
                $this->sendStatus($guard);
                break;

            default:
                rex_response::setStatus(rex_response::HTTP_BAD_REQUEST);
                rex_response::sendJson(['error' => 'Ungültige Aktion']);
                exit;
        }

        exit;
    }

    /**
     * Re-Authentifizierung und Freischaltung.
     */
    private function handleUnlock(SessionGuard $guard): void
    {
        $login = rex_post('enc_login', 'string', '');
        $password = rex_post('enc_password', 'string', '');

        if ($login === '' || $password === '') {
            rex_response::sendJson([
                'success' => false,
                'error' => rex_i18n::msg('yform_encryption_auth_fields_required'),
            ]);
            return;
        }

        if ($guard->authenticate($login, $password)) {
            rex_response::sendJson([
                'success' => true,
                'remaining' => $guard->getRemainingTime(),
                'timeout' => $guard->getTimeout(),
                'message' => rex_i18n::msg('yform_encryption_auth_success'),
            ]);
        } else {
            rex_response::sendJson([
                'success' => false,
                'error' => rex_i18n::msg('yform_encryption_auth_failed'),
            ]);
        }
    }

    /**
     * Manuelle Sperrung.
     */
    private function handleLock(SessionGuard $guard): void
    {
        $guard->lock();
        rex_response::sendJson([
            'success' => true,
            'message' => rex_i18n::msg('yform_encryption_locked'),
        ]);
    }

    /**
     * Status-Abfrage.
     */
    private function sendStatus(SessionGuard $guard): void
    {
        rex_response::sendJson([
            'unlocked' => $guard->isUnlocked(),
            'remaining' => $guard->getRemainingTime(),
            'timeout' => $guard->getTimeout(),
        ]);
    }
}
