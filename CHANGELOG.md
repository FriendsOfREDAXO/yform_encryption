# Changelog

## 1.1.0 - 2026-05-03

### Added

- Unterstützung für eingebettete YForm-Managerseiten: Die Lock/Unlock-UI und der SessionGuard-Status werden nun auch auf Addon-Seiten mit gueltigem `table_name` aktiviert (nicht mehr nur auf `page=yform/manager/data*`).

### Fixed

- Bearbeiten verschluesselter Felder im Backend: Bei Submit-Requests werden gepostete Werte nicht mehr durch entschluesselte Altwerte aus `objparams[data]` ueberschrieben.
- Verbesserte Kompatibilitaet mit eingebetteten YForm-Edit/Speicher-Workflows.

## 1.0.0 - 2025-01-01

### Added

- Initiales Release.