# Changelog

## 1.3.0 - 2026-10-04

### Fixed

- **Abgeschnittene Chiffrate verhindert:** Beim Erweitern einer Spalte auf `TEXT` wird jetzt auch der `db_type` in der YForm-Felddefinition auf `text` gesetzt. Bisher hat YForm bei Tabellen mit „Schema überschreiben“ (Standard) die Spalte beim nächsten Neuaufbau – etwa beim Speichern eines Felds im Tablemanager – wieder auf den kurzen Typ verkleinert (z. B. `varchar(34)` bei `fields_iban`). Die verschlüsselten Werte wurden dabei abgeschnitten und waren nicht mehr zu entschlüsseln.
- Das Update korrigiert bestehende Zuordnungen automatisch; die Feldzuordnung warnt zusätzlich, wenn ein verschlüsseltes Feld noch mit kurzem `db_type` hinterlegt ist.
- **Listenansicht:** Verschlüsselte Spalten behalten das Format ihres Feldtyps (z. B. Zusammenfassung einer Tabelle, formatierte IBAN) und zeigen es mit dem entschlüsselten Wert – statt rohen Text oder JSON.

### Added

- Weitere verschlüsselbare Feldtypen des AddOns [Fields](https://github.com/FriendsOfREDAXO/fields): `fields_table`, `fields_contacts`, `fields_social_web`, `fields_faq`, `fields_opening_hours` (JSON).
- Extension Point `YFORM_ENCRYPTION_FIELD_TYPES`: eigene Feldtypen als verschlüsselbar anmelden.
- `FieldMapper::getEncryptableTypes()`, `ColumnMigrator::persistDbTypes()`.

## 1.2.1 - 2026-07-29

Vendor: phpoffice/phpspreadsheet (3.10.5 => 3.10.7)


## 1.2.0 - 2026-05-03

### Added

- **Export-Spalten-Konfiguration**: Im Mapping-Tab kann je Tabelle festgelegt werden, welche Spalten im CSV- und Excel-Export enthalten sein sollen. Nicht ausgewählte Spalten werden ausgeblendet. Ohne Konfiguration werden wie bisher alle Spalten exportiert.

## 1.1.0 - 2026-05-03

### Added

- Unterstützung für eingebettete YForm-Managerseiten: Die Lock/Unlock-UI und der SessionGuard-Status werden nun auch auf Addon-Seiten mit gueltigem `table_name` aktiviert (nicht mehr nur auf `page=yform/manager/data*`).

### Fixed

- Bearbeiten verschluesselter Felder im Backend: Bei Submit-Requests werden gepostete Werte nicht mehr durch entschluesselte Altwerte aus `objparams[data]` ueberschrieben.
- Verbesserte Kompatibilitaet mit eingebetteten YForm-Edit/Speicher-Workflows.

## 1.0.0 - 2025-01-01

### Added

- Initiales Release.