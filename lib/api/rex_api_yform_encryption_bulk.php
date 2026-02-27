<?php

declare(strict_types=1);

use FriendsOfREDAXO\YFormEncryption\EncryptionService;
use FriendsOfREDAXO\YFormEncryption\FieldMapper;

/**
 * API-Endpoint für Bulk-Operationen auf verschlüsselte Felder.
 *
 * Aktionen:
 * - encrypt_existing: Verschlüsselt alle bestehenden Klartext-Daten
 * - decrypt_all: Entschlüsselt alle Daten (z.B. vor Addon-Deinstallation)
 * - status: Gibt den Verschlüsselungsstatus zurück
 */
class rex_api_yform_encryption_bulk extends rex_api_function
{
    protected $published = false;

    public function execute(): rex_api_result
    {
        rex_response::cleanOutputBuffers();

        $user = rex::getUser();
        if (null === $user || !$user->isAdmin()) {
            rex_response::setStatus(rex_response::HTTP_FORBIDDEN);
            rex_response::sendJson(['error' => 'Keine Berechtigung']);
            exit;
        }

        $action = rex_request('bulk_action', 'string', '');
        $tableName = rex_request('table', 'string', '');

        switch ($action) {
            case 'encrypt_existing':
                $this->encryptExisting($tableName);
                break;

            case 'decrypt_all':
                $this->decryptAll($tableName);
                break;

            case 'status':
                $this->getStatus($tableName);
                break;

            default:
                rex_response::setStatus(rex_response::HTTP_BAD_REQUEST);
                rex_response::sendJson(['error' => 'Ungültige Aktion: ' . $action]);
                exit;
        }

        exit;
    }

    /**
     * Verschlüsselt alle bestehenden Klartext-Datensätze.
     */
    private function encryptExisting(string $tableName): void
    {
        if ($tableName === '') {
            rex_response::setStatus(rex_response::HTTP_BAD_REQUEST);
            rex_response::sendJson(['error' => 'Tabellenname erforderlich']);
            exit;
        }

        $mapper = FieldMapper::getInstance();
        $fields = $mapper->getEncryptedFields($tableName);

        if ($fields === []) {
            rex_response::sendJson([
                'success' => true,
                'encrypted' => 0,
                'message' => 'Keine Felder zum Verschlüsseln konfiguriert',
            ]);
            exit;
        }

        $encryption = EncryptionService::getInstance();

        $sql = rex_sql::factory();
        $sql->setQuery('SELECT id, ' . implode(', ', array_map(static fn (string $f) => '`' . $f . '`', $fields)) . ' FROM `' . $tableName . '`');

        $count = 0;
        for ($i = 0; $i < $sql->getRows(); ++$i) {
            $updates = [];
            $dataId = (int) $sql->getValue('id');

            foreach ($fields as $field) {
                $value = $sql->getValue($field);
                if (!is_string($value) || $value === '' || $encryption->isEncrypted($value)) {
                    continue;
                }

                $updates[$field] = $encryption->encrypt($value);
            }

            if ($updates !== []) {
                $updateSql = rex_sql::factory();
                $updateSql->setTable($tableName);
                $updateSql->setWhere('id = :id', ['id' => $dataId]);

                foreach ($updates as $field => $encryptedValue) {
                    $updateSql->setValue($field, $encryptedValue);
                }

                $updateSql->update();
                ++$count;
            }

            $sql->next();
        }

        rex_response::sendJson([
            'success' => true,
            'encrypted' => $count,
            'message' => $count . ' Datensätze verschlüsselt',
        ]);
    }

    /**
     * Entschlüsselt alle Datensätze einer Tabelle.
     */
    private function decryptAll(string $tableName): void
    {
        if ($tableName === '') {
            rex_response::setStatus(rex_response::HTTP_BAD_REQUEST);
            rex_response::sendJson(['error' => 'Tabellenname erforderlich']);
            exit;
        }

        $mapper = FieldMapper::getInstance();
        $fields = $mapper->getEncryptedFields($tableName);

        if ($fields === []) {
            rex_response::sendJson([
                'success' => true,
                'decrypted' => 0,
                'message' => 'Keine Felder zum Entschlüsseln konfiguriert',
            ]);
            exit;
        }

        $encryption = EncryptionService::getInstance();

        $sql = rex_sql::factory();
        $sql->setQuery('SELECT id, ' . implode(', ', array_map(static fn (string $f) => '`' . $f . '`', $fields)) . ' FROM `' . $tableName . '`');

        $count = 0;
        for ($i = 0; $i < $sql->getRows(); ++$i) {
            $updates = [];
            $dataId = (int) $sql->getValue('id');

            foreach ($fields as $field) {
                $value = $sql->getValue($field);
                if (!is_string($value) || $value === '' || !$encryption->isEncrypted($value)) {
                    continue;
                }

                $decrypted = $encryption->decrypt($value);
                $updates[$field] = $decrypted;
            }

            if ($updates !== []) {
                $updateSql = rex_sql::factory();
                $updateSql->setTable($tableName);
                $updateSql->setWhere('id = :id', ['id' => $dataId]);

                foreach ($updates as $field => $decryptedValue) {
                    $updateSql->setValue($field, $decryptedValue);
                }

                $updateSql->update();
                ++$count;
            }

            $sql->next();
        }

        rex_response::sendJson([
            'success' => true,
            'decrypted' => $count,
            'message' => $count . ' Datensätze entschlüsselt',
        ]);
    }

    /**
     * Gibt den Verschlüsselungsstatus einer Tabelle zurück.
     */
    private function getStatus(string $tableName): void
    {
        if ($tableName === '') {
            rex_response::setStatus(rex_response::HTTP_BAD_REQUEST);
            rex_response::sendJson(['error' => 'Tabellenname erforderlich']);
            exit;
        }

        $mapper = FieldMapper::getInstance();
        $fields = $mapper->getEncryptedFields($tableName);

        if ($fields === []) {
            rex_response::sendJson([
                'success' => true,
                'total' => 0,
                'encrypted' => 0,
                'unencrypted' => 0,
                'fields' => [],
            ]);
            exit;
        }

        $encryption = EncryptionService::getInstance();

        $sql = rex_sql::factory();
        $sql->setQuery('SELECT id, ' . implode(', ', array_map(static fn (string $f) => '`' . $f . '`', $fields)) . ' FROM `' . $tableName . '`');

        $total = $sql->getRows();
        $encrypted = 0;
        $unencrypted = 0;

        for ($i = 0; $i < $sql->getRows(); ++$i) {
            $rowHasEncrypted = false;
            $rowHasUnencrypted = false;

            foreach ($fields as $field) {
                $value = $sql->getValue($field);
                if (!is_string($value) || $value === '') {
                    continue;
                }

                if ($encryption->isEncrypted($value)) {
                    $rowHasEncrypted = true;
                } else {
                    $rowHasUnencrypted = true;
                }
            }

            if ($rowHasEncrypted) {
                ++$encrypted;
            }
            if ($rowHasUnencrypted) {
                ++$unencrypted;
            }

            $sql->next();
        }

        rex_response::sendJson([
            'success' => true,
            'total' => $total,
            'encrypted' => $encrypted,
            'unencrypted' => $unencrypted,
            'fields' => $fields,
        ]);
    }
}
