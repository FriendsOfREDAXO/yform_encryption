<?php

declare(strict_types=1);

use FriendsOfREDAXO\YFormEncryption\EncryptionService;
use FriendsOfREDAXO\YFormEncryption\FieldMapper;

/**
 * API-Endpoint zum Entschlüsseln von YForm-Datensätzen.
 *
 * Verwendung z.B. für PDF-Export oder Datenweiterleitung.
 * Nur für eingeloggte Backend-User mit Admin-Rechten zugänglich.
 */
class rex_api_yform_encryption_decrypt extends rex_api_function
{
    protected $published = false;

    public function execute(): rex_api_result
    {
        rex_response::cleanOutputBuffers();

        // Nur Admins dürfen entschlüsseln
        $user = rex::getUser();
        if (null === $user || !$user->isAdmin()) {
            rex_response::setStatus(rex_response::HTTP_FORBIDDEN);
            rex_response::sendJson(['error' => 'Keine Berechtigung']);
            exit;
        }

        $tableName = rex_request('table', 'string', '');
        $dataId = rex_request('data_id', 'int', 0);
        $fields = rex_request('fields', 'string', '');

        if ($tableName === '' || $dataId <= 0) {
            rex_response::setStatus(rex_response::HTTP_BAD_REQUEST);
            rex_response::sendJson(['error' => 'Tabelle und Datensatz-ID sind erforderlich']);
            exit;
        }

        try {
            $mapper = FieldMapper::getInstance();
            $encryption = EncryptionService::getInstance();

            // Wenn keine Felder angegeben, alle verschlüsselten Felder der Tabelle verwenden
            $fieldList = $fields !== ''
                ? array_map('trim', explode(',', $fields))
                : $mapper->getEncryptedFields($tableName);

            if ($fieldList === []) {
                rex_response::sendJson([
                    'success' => true,
                    'data' => [],
                    'message' => 'Keine verschlüsselten Felder konfiguriert',
                ]);
                exit;
            }

            // Daten laden
            $sql = rex_sql::factory();
            $sql->setQuery(
                'SELECT * FROM `' . $tableName . '` WHERE id = :id LIMIT 1',
                ['id' => $dataId],
            );

            if ($sql->getRows() === 0) {
                rex_response::setStatus(rex_response::HTTP_NOT_FOUND);
                rex_response::sendJson(['error' => 'Datensatz nicht gefunden']);
                exit;
            }

            $result = [];
            foreach ($fieldList as $field) {
                $value = $sql->getValue($field);
                if (is_string($value) && $encryption->isEncrypted($value)) {
                    $result[$field] = $encryption->decrypt($value);
                } else {
                    $result[$field] = $value;
                }
            }

            rex_response::sendJson([
                'success' => true,
                'data' => $result,
            ]);
        } catch (Exception $e) {
            rex_response::setStatus(rex_response::HTTP_INTERNAL_ERROR);
            rex_response::sendJson([
                'error' => 'Entschlüsselung fehlgeschlagen: ' . $e->getMessage(),
            ]);
        }

        exit;
    }
}
