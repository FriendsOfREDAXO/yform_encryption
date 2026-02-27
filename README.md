# YForm Encryption

**REDAXO-Addon zur transparenten Feldverschlüsselung in YForm-Tabellen.**

Verschlüsselt sensible YForm-Felder serverseitig mit libsodium (XSalsa20-Poly1305). Die Daten werden verschlüsselt in der Datenbank gespeichert und im Backend automatisch entschlüsselt angezeigt. Der Schlüssel liegt außerhalb des Webroots.

## Features

- 🔐 **Transparente Verschlüsselung** – YForm-Felder werden beim Speichern automatisch ver- und beim Anzeigen entschlüsselt, ohne Änderungen am Formular
- 🗝️ **Schlüssel außerhalb des Webroots** – als Datei oder Umgebungsvariable (`YFORM_ENCRYPTION_KEY`), kompatibel mit Plesk, Docker, Apache, Nginx
- 📋 **Feldzuordnung per Backend** – pro Tabelle einzelne Felder zur Verschlüsselung auswählen, keine Codeänderungen nötig
- 🔄 **Bulk-Migration** – bestehende Datensätze nachträglich ver- oder entschlüsseln, gesichert durch SessionGuard (Re-Authentifizierung)
- 📊 **Integrierter CSV- und Excel-Export** – entschlüsselte Daten exportieren, nutzbar für alle YForm-Tabellen (auch unverschlüsselte), inklusive Dokument-Metadaten (Autor, Zeitstempel, Domain)
- 🔎 **Info-Seite** – zeigt Schlüsselstatus, Quelle und verschlüsselte Felder je Tabelle
- 🛡️ **Nur für Admins** – alle Funktionen erfordern Admin-Rechte im REDAXO-Backend
- 🔗 **PHP-API** – `Helper`-Klasse für einfachen Zugriff aus Modulen und Templates

---

## Voraussetzungen

- REDAXO ≥ 5.18
- PHP ≥ 8.1 mit aktivierter `sodium`-Extension
- YForm ≥ 5.0
- **Inkompatibel mit dem Addon `yform_export`** (eigener Export ist integriert)

---

## Installation

1. Addon über den REDAXO-Installer installieren oder manuell in `redaxo/src/addons/yform_encryption/` ablegen.
2. Im REDAXO-Backend unter **YForm Encryption → Einstellungen** den Schlüsselpfad konfigurieren (Verzeichnis **außerhalb** des Webroots empfohlen).
3. Schlüssel generieren lassen oder vorhandenen Schlüssel hinterlegen.

---

## Konfiguration

| Einstellung | Beschreibung |
|---|---|
| Schlüsselpfad | Absoluter Pfad zur Schlüsseldatei, idealerweise außerhalb des Webroots |
| Schlüssel generieren | Neuen libsodium-Schlüssel erzeugen und speichern |

---

## Schlüssel als Umgebungsvariable (empfohlen)

Statt den Schlüssel in einer Datei zu speichern, kann er als Systemumgebungsvariable `YFORM_ENCRYPTION_KEY` hinterlegt werden. Das Addon prüft diese Variable **zuerst** – vor dem Dateipfad.

Der Wert muss der **Base64-kodierte** 32-Byte-Schlüssel sein (wird im Backend unter „Einstellungen → Schlüssel anzeigen" angezeigt).

### Plesk

1. Plesk → Domain → **Apache & nginx-Einstellungen**
2. Im Feld **„Zusätzliche Apache-Direktiven"** (HTTP oder HTTPS):
   ```apache
   SetEnv YFORM_ENCRYPTION_KEY "IhrBase64SchluesselHier=="
   ```
3. **Speichern** – Plesk schreibt die Direktive in die vHost-Konfiguration.

Alternativ über die Plesk-Erweiterung **„Node.js/PHP Environment Variables"** oder direkt in der `php.ini` der Domain:
```ini
; Plesk → PHP-Einstellungen → Zusätzliche Direktiven
env[YFORM_ENCRYPTION_KEY] = "IhrBase64SchluesselHier=="
```

---

### Docker / docker-compose

In `docker-compose.yml`:
```yaml
services:
  web:
    image: your-redaxo-image
    environment:
      YFORM_ENCRYPTION_KEY: "IhrBase64SchluesselHier=="
```

Oder mit einer `.env`-Datei (niemals ins Repository einchecken!):
```dotenv
# .env
YFORM_ENCRYPTION_KEY=IhrBase64SchluesselHier==
```
```yaml
# docker-compose.yml
services:
  web:
    env_file:
      - .env
```

Beim direkten `docker run`:
```bash
docker run -e YFORM_ENCRYPTION_KEY="IhrBase64SchluesselHier==" your-redaxo-image
```

---

### Apache (ohne Plesk)

In der **VirtualHost-Konfiguration** oder `.htaccess`:
```apache
# /etc/apache2/sites-available/ihre-domain.conf  ODER  .htaccess
SetEnv YFORM_ENCRYPTION_KEY "IhrBase64SchluesselHier=="
```

> `.htaccess` ist weniger sicher als die VirtualHost-Konfiguration, da sie im Webroot liegen kann. Den `.htaccess`-Eintrag unbedingt **außerhalb des öffentlich zugänglichen Verzeichnisses** setzen oder per `Deny from all` schützen.

---

### Nginx + PHP-FPM

Nginx selbst unterstützt `SetEnv` nicht – die Variable muss im **PHP-FPM-Pool** gesetzt werden:

```ini
# /etc/php/8.x/fpm/pool.d/ihre-domain.conf
env[YFORM_ENCRYPTION_KEY] = "IhrBase64SchluesselHier=="
```

Nach dem Bearbeiten PHP-FPM neu starten:
```bash
systemctl restart php8.x-fpm
```

---

### Linux-Server (systemd / global)

Für systemd-verwaltete PHP-FPM-Dienste in der Override-Konfiguration:
```bash
systemctl edit php8.x-fpm
```
```ini
[Service]
Environment="YFORM_ENCRYPTION_KEY=IhrBase64SchluesselHier=="
```

Global für alle Prozesse (weniger empfohlen):
```bash
# /etc/environment
YFORM_ENCRYPTION_KEY="IhrBase64SchluesselHier=="
```

---

### Sicherheitshinweis zu Umgebungsvariablen

- `.env`-Dateien **niemals** in Git einchecken – `.gitignore`-Eintrag prüfen.
- Berechtigungen von Konfigurationsdateien mit dem Schlüssel auf `640` oder `600` setzen.
- In Shared-Hosting-Umgebungen lieber die **Schlüsseldatei außerhalb des Webroots** verwenden, da Umgebungsvariablen u.U. durch `phpinfo()` sichtbar werden.

---

## Felder verschlüsseln

Unter **YForm Encryption → Feldzuordnung** können pro YForm-Tabelle einzelne Felder zur Verschlüsselung markiert werden.

Folgende YForm-Feldtypen können verschlüsselt werden:

| Feldtyp | Beschreibung | Typischer Anwendungsfall |
|---|---|---|
| `text` | Einzeiliges Textfeld | Name, Vorname, Ausweisnummer, Tokens |
| `textarea` | Mehrzeiliges Textfeld | Notizen, Anamnese, Freitexte mit sensiblem Inhalt |
| `email` | E-Mail-Adresse | Kontaktdaten mit erhöhtem Schutzbedarf |
| `phone` | Telefonnummer | Mobil-/Festnetznummern |
| `url` | URL-Feld | Interne Links, Token-URLs |
| `ip` | IP-Adresse | Logging, DSGVO-relevante Netzwerkdaten |
| `fields_iban` | IBAN (fields-Addon) | Bankverbindungen – **besonders schützenswert** |
| `fields_inline` | Inline-Gruppe (fields-Addon) | Kombinierte Felder z.B. Adresse + IBAN |

**Nicht verschlüsselbar** (und auch nicht sinnvoll):
- Auswahlfelder (`select`, `choice`, `checkbox`, `radio`) – zu wenige diskrete Werte, Verschlüsselung bringt keinen Sicherheitsgewinn
- Relationsfelder (`be_relation`, `relation`) – nur IDs, kein personenbezogener Inhalt
- Zahlenfelder (`integer`, `float`) – Datenbanktyp inkompatibel mit Ciphertext-String
- Datumsfelder (`date`, `datetime`) – oft für Sortierung/Filterung nötig, Verschlüsselung würde Abfragen komplett blockieren
- `be_media`, `upload` – Dateiname/Pfad, Dateiinhalt selbst liegt im Dateisystem

Nach dem Speichern der Feldzuordnung werden **neue Einträge** automatisch verschlüsselt gespeichert. Bestehende Daten können über die **Migration**-Funktion nachträglich verschlüsselt werden.

---

## Export (CSV / Excel)

Das Addon ersetzt `yform_export` und bringt einen eigenen, vollwertigen Exporter mit:

- In der YForm-Datenliste erscheinen **CSV**- und **Excel (XLSX)**-Buttons für jede Tabelle.
- Verschlüsselte Felder werden beim Export automatisch entschlüsselt.
- **Funktioniert auch für vollständig unverschlüsselte Tabellen** – der Exporter ist ein vollwertiger Ersatz für `yform_export` und kann für beliebige YForm-Tabellen genutzt werden.
- Die Excel-Datei enthält Dokument-Metadaten: Autor (eingeloggter REDAXO-User), Exportzeitpunkt, Sitetitel und Domain.
- CSV-Export mit UTF-8-BOM für korrekte Darstellung in Excel.
- Spaltenbezeichnungen aus den YForm-Feldlabels (nicht die Datenbankspaltenname).
- Erste Zeile in Excel fett formatiert, Spaltenbreite automatisch, erste Zeile eingefroren.

---

## ⚠️ Einschränkung: YForm-Suche

**Die integrierte YForm-Datenlisten-Suche kann verschlüsselte Felder nicht durchsuchen.**

Der Grund: Die Suche arbeitet mit SQL-`LIKE`-Abfragen direkt auf der Datenbank. Verschlüsselte Werte liegen als Ciphertext vor – ein Klartext-Suchbegriff kann dort keine Treffer finden.

**Betroffen sind alle als verschlüsselt markierten Felder.** Nicht-verschlüsselte Felder derselben Tabelle (z.B. `id`, Datumsfelder, Status-Felder) sind weiterhin normal durchsuchbar.

**Workaround**: Den **CSV- oder Excel-Export** nutzen und lokal in der Tabellenkalkulation suchen – die exportierten Daten sind vollständig entschlüsselt.

> **Empfehlung**: Nur wirklich sensible Felder verschlüsseln (IBAN, Ausweisdaten, Gesundheitsdaten, Tokens). Felder, nach denen häufig gesucht wird (z.B. Name, E-Mail), nur verschlüsseln wenn ein erhöhtes Breach-Risiko besteht – dann ist der Komfortverlust durch die eingeschränkte Suche bewusst in Kauf zu nehmen.

---

## Autorisierung für Bulk-Operationen (SessionGuard)

Massenoperationen wie das **nachträgliche Ver- oder Entschlüsseln bestehender Datensätze** (Migration) sind durch einen zusätzlichen Authentifizierungsschritt gesichert – den **SessionGuard**.

**Ablauf:**
1. Unter **YForm Encryption → Feldzuordnung** eine Bulk-Aktion auslösen (z.B. „Alle bestehenden Datensätze verschlüsseln").
2. Das System fordert eine **erneute Eingabe** von REDAXO-Benutzername und Passwort.
3. Nach erfolgreicher Eingabe ist die Session für **30 Minuten** freigeschaltet.
4. Weitere Bulk-Aktionen innerhalb dieses Zeitfensters erfordern keine erneute Eingabe.
5. Nach Ablauf des Timeouts (oder manueller Sperrung) muss erneut authentifiziert werden.

> Der Timeout ist unter **YForm Encryption → Einstellungen** konfigurierbar.

**Warum dieser zusätzliche Schutz?**  
Bulk-Operationen schreiben alle Datensätze einer Tabelle um. Ein versehentlicher Klick – oder ein kompromittiertes Admin-Konto das gerade in der Session läuft – könnte sonst alle verschlüsselten Daten im Klartext in die DB schreiben. Die erneute Passwortabfrage stellt sicher, dass die Aktion bewusst von einer autorisierten Person ausgelöst wird.

---

## Sicherheitshinweise

- Den Schlüssel **regelmäßig sichern** – ohne Schlüssel sind verschlüsselte Daten unwiederbringlich verloren.
- Den Schlüssel **niemals** im Webroot ablegen.
- Den Schlüssel **nicht** in der Versionsverwaltung tracken.
- Bei einem Server-Umzug den Schlüssel separat übertragen.

---

## API-Referenz

Alle Klassen liegen im Namespace `FriendsOfREDAXO\YFormEncryption\` und werden von REDAXO automatisch geladen.

---

### `Helper` – Einstiegspunkt für externe Nutzung

Die einfachste Klasse für den Zugriff aus Modulen, Addons oder Templates.

```php
use FriendsOfREDAXO\YFormEncryption\Helper;
```

| Methode | Rückgabe | Beschreibung |
|---|---|---|
| `Helper::getDecryptedRow(string $tableName, int $id)` | `?array` | Einzelnen Datensatz laden und alle verschlüsselten Felder entschlüsseln |
| `Helper::getDecryptedTable(string $tableName, string $where, string $orderBy, int $limit, int $offset)` | `array` | Mehrere Datensätze laden und entschlüsseln |
| `Helper::decryptValue(string $value)` | `string` | Einzelnen Wert entschlüsseln (gibt Klartext zurück, auch wenn nicht verschlüsselt) |
| `Helper::encryptValue(string $value)` | `string` | Einzelnen Wert verschlüsseln |
| `Helper::isEncrypted(string $value)` | `bool` | Prüfen ob ein Wert verschlüsselt ist |
| `Helper::getEncryptedFieldsForTable(string $tableName)` | `array` | Liste der verschlüsselten Feldnamen für eine Tabelle |

**Beispiel:**
```php
$row = Helper::getDecryptedRow('rex_my_table', 42);
echo $row['iban']; // entschlüsselt

$rows = Helper::getDecryptedTable('rex_my_table', 'status = 1', 'id DESC', 50);
```

---

### `EncryptionService` – Kern-Verschlüsselung

```php
use FriendsOfREDAXO\YFormEncryption\EncryptionService;
$enc = EncryptionService::getInstance();
```

| Methode | Rückgabe | Beschreibung |
|---|---|---|
| `getInstance()` | `self` | Singleton-Instanz |
| `encrypt(string $plaintext)` | `string` | Text verschlüsseln (mit Prefix) |
| `decrypt(string $encrypted)` | `string` | Text entschlüsseln |
| `decryptSafe(string $value)` | `string` | Entschlüsseln ohne Exception – gibt Originalwert bei Fehler zurück |
| `isEncrypted(string $value)` | `bool` | Präfix-Prüfung |
| `encryptFields(array $data, array $fields)` | `array` | Mehrere Felder eines Datensatzes verschlüsseln |
| `decryptFields(array $data, array $fields)` | `array` | Mehrere Felder eines Datensatzes entschlüsseln |
| `getPrefix()` | `string` | Verschlüsselungs-Präfix (statisch) |

---

### `FieldMapper` – Feldzuordnungen

```php
use FriendsOfREDAXO\YFormEncryption\FieldMapper;
$mapper = FieldMapper::getInstance();
```

| Methode | Rückgabe | Beschreibung |
|---|---|---|
| `getInstance()` | `self` | Singleton-Instanz |
| `getEncryptedFields(string $tableName)` | `array` | Alle verschlüsselten Feldnamen einer Tabelle |
| `hasEncryptedFields(string $tableName)` | `bool` | Hat die Tabelle verschlüsselte Felder? |
| `isFieldEncrypted(string $tableName, string $fieldName)` | `bool` | Ist ein bestimmtes Feld verschlüsselt? |
| `addField(string $tableName, string $fieldName)` | `void` | Feld zur Verschlüsselung hinzufügen |
| `removeField(string $tableName, string $fieldName)` | `void` | Feld aus der Verschlüsselung entfernen (Mapping löschen) |
| `getAllMappings()` | `array` | Alle Zuordnungen als `['table' => ['field1', 'field2']]` |
| `getAvailableTablesAndFields()` | `array` | Alle YForm-Tabellen mit verschlüsselbaren Feldern |

---

### `KeyManager` – Schlüsselverwaltung

```php
use FriendsOfREDAXO\YFormEncryption\KeyManager;
$km = KeyManager::getInstance();
```

| Methode | Rückgabe | Beschreibung |
|---|---|---|
| `getInstance()` | `self` | Singleton-Instanz |
| `hasKey()` | `bool` | Ist ein Schlüssel verfügbar? |
| `getKey()` | `string` | Rohen Schlüssel-Binärstring liefern |
| `getKeySource()` | `string` | Herkunft des Schlüssels: `environment`, `file`, `data_dir` |
| `getKeyFilePath()` | `string` | Pfad zur konfigurierten Schlüsseldatei |
| `generateKey(string $location)` | `string` | Neuen Schlüssel erzeugen (`'file'` oder `'data_dir'`) |

**Schlüsselpriorität**: `YFORM_ENCRYPTION_KEY` (Env) → konfigurierter Dateipfad → `data/`-Verzeichnis

---

### `SessionGuard` – Bulk-Autorisierung

```php
use FriendsOfREDAXO\YFormEncryption\SessionGuard;
$guard = SessionGuard::getInstance();
```

| Methode | Rückgabe | Beschreibung |
|---|---|---|
| `getInstance()` | `self` | Singleton-Instanz |
| `isUnlocked()` | `bool` | Ist die Session aktuell entsperrt? |
| `authenticate(string $login, string $password)` | `bool` | Authentifizieren und Session entsperren |
| `unlock()` | `void` | Session manuell entsperren (ohne Passwort) |
| `lock()` | `void` | Session sofort sperren |
| `getRemainingTime()` | `int` | Verbleibende Sekunden bis zum Timeout |
| `getTimeout()` | `int` | Konfigurierten Timeout in Sekunden liefern |

---

### `ColumnMigrator` – Spaltentyp-Migration

```php
use FriendsOfREDAXO\YFormEncryption\ColumnMigrator;
```

| Methode | Rückgabe | Beschreibung |
|---|---|---|
| `ColumnMigrator::checkColumns(string $tableName, array $fields)` | `array` | Prüfen welche Spalten auf `TEXT` erweitert werden müssen |
| `ColumnMigrator::migrateColumns(string $tableName, array $fields)` | `int` | Spalten auf `TEXT` migrieren, gibt Anzahl geänderter Spalten zurück |
| `ColumnMigrator::getWarnings()` | `array` | Warnungen der letzten Migration abrufen |

> Ciphertexte sind länger als Klartexte – `VARCHAR`-Spalten werden bei Bedarf automatisch auf `TEXT` erweitert.

---

## Lizenz

MIT – siehe [LICENSE.md](LICENSE.md)

**Autor**: [Thomas Skerbis](https://github.com/skerbis) / [FriendsOfREDAXO](https://github.com/FriendsOfREDAXO)  
**Support**: https://github.com/FriendsOfREDAXO/yform_encryption
