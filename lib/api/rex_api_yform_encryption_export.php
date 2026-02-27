<?php

declare(strict_types=1);

use FriendsOfREDAXO\YFormEncryption\EncryptionService;
use FriendsOfREDAXO\YFormEncryption\FieldMapper;

/**
 * API-Endpoint für den entschlüsselten Export (CSV + XLSX).
 *
 * Aufruf: index.php?rex-api-call=yform_encryption_export&table=rex_my_table&format=csv|xlsx
 */
class rex_api_yform_encryption_export extends rex_api_function
{
    /** @var bool Nur Backend */
    protected $published = false;

    public function execute(): never
    {
        rex_response::cleanOutputBuffers();

        // Nur eingeloggte Backend-User mit Export-Berechtigung
        $user = rex::getUser();
        if (!$user || (!$user->isAdmin() && !$user->hasPerm('yform_encryption[export]'))) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }

        $tableName = rex_request('table', 'string', '');
        $format = rex_request('format', 'string', 'csv');

        if ($tableName === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Missing table parameter']);
            exit;
        }

        $table = rex_yform_manager_table::get($tableName);
        if (null === $table) {
            http_response_code(404);
            echo json_encode(['error' => 'Table not found']);
            exit;
        }

        // Daten laden
        $sql = rex_sql::factory();
        $rows = $sql->getArray('SELECT * FROM `' . $tableName . '`');

        if ($rows === null) {
            $rows = [];
        }

        // Entschlüsseln
        $mapper = FieldMapper::getInstance();
        $encryptedFields = $mapper->getEncryptedFields($tableName);

        if ($encryptedFields !== [] && $rows !== []) {
            try {
                $encryption = EncryptionService::getInstance();
                foreach ($rows as &$row) {
                    foreach ($encryptedFields as $field) {
                        if (isset($row[$field]) && is_string($row[$field]) && $encryption->isEncrypted($row[$field])) {
                            $row[$field] = $encryption->decryptSafe($row[$field]);
                        }
                    }
                }
                unset($row);
            } catch (\Exception $e) {
                // Entschlüsselung fehlgeschlagen – Rohdaten ausgeben
            }
        }

        // Spalten-Labels aus YForm-Tabellendefinition
        $labels = [];
        if ($rows !== []) {
            foreach (array_keys($rows[0]) as $colName) {
                $valueField = $table->getValueField($colName);
                $labels[$colName] = $valueField ? $valueField->getLabel() : $colName;
            }
        }

        $filename = date('Y-m-d_His') . '_' . preg_replace('/[^a-z0-9_]/', '_', $tableName);

        if ($format === 'xlsx' && class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            self::sendXlsx($rows, $labels, $filename);
        } else {
            self::sendCsv($rows, $labels, $filename);
        }
    }

    /**
     * CSV-Export mit UTF-8-BOM.
     *
     * @param list<array<string,mixed>> $rows
     * @param array<string,string> $labels
     */
    private static function sendCsv(array $rows, array $labels, string $filename): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }

        // UTF-8 BOM für korrekte Excel-Darstellung
        fwrite($out, "\xEF\xBB\xBF");

        // Header-Zeile
        if ($labels !== []) {
            fputcsv($out, array_values($labels), ';');
        }

        foreach ($rows as $row) {
            fputcsv($out, array_values($row), ';');
        }

        fclose($out);
        exit;
    }

    /**
     * XLSX-Export via PhpSpreadsheet (aus yform_export).
     *
     * @param list<array<string,mixed>> $rows
     * @param array<string,string> $labels
     */
    private static function sendXlsx(array $rows, array $labels, string $filename): never
    {
        /** @var \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet */
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        // Dokument-Metadaten
        $user = rex::getUser();
        $authorName = $user ? trim($user->getName()) : 'REDAXO';
        $authorName = ($authorName !== '') ? $authorName : ($user ? $user->getLogin() : 'REDAXO');
        $exportedAt = date('Y-m-d H:i:s');
        $siteTitle = rex::getServerName();

        $domain = rtrim(rex::getServer(), '/');

        $spreadsheet->getProperties()
            ->setCreator($authorName)
            ->setLastModifiedBy($authorName)
            ->setTitle($filename)
            ->setSubject('Entschlüsselter Export: ' . $filename)
            ->setDescription('Exportiert von ' . $authorName . ' am ' . $exportedAt . ' via ' . $siteTitle . ' (' . $domain . ')')
            ->setCompany($siteTitle)
            ->setCreated(time())
            ->setModified(time());

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr(preg_replace('/[^a-zA-Z0-9]/', '', $filename) ?: 'Export', 0, 31));

        // Header-Zeile (Zeile 1) – fett
        $col = 1;
        foreach (array_values($labels) as $label) {
            $sheet->setCellValue([$col, 1], $label);
            $sheet->getStyle([$col, 1])->getFont()->setBold(true);
            $col++;
        }

        // Datenzeilen
        $row = 2;
        foreach ($rows as $dataRow) {
            $col = 1;
            foreach (array_values($dataRow) as $value) {
                $sheet->setCellValue([$col, $row], $value);
                $col++;
            }
            $row++;
        }

        // Spaltenbreite automatisch
        $colCount = count($labels);
        for ($i = 1; $i <= $colCount; $i++) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }

        // Erste Zeile einfrieren
        $sheet->freezePane([1, 2]);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}
