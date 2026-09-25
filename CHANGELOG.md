# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.0.0/) und dieses Projekt folgt [Semantic Versioning](https://semver.org/lang/de/).

---

## [Unreleased]

---

## [0.2.1] - 2026-09-25
### Hinzugefügt
- **CI/CD Quality Gate (`.github/workflows/ci.yml`):**
  - PHP-Syntax-Matrix (PHP 7.4–8.3), JSON-Schema-Validierung für `config/forms/*.json`, JavaScript-Syntax-Check und Versionskonsistenz-Prüfung.
- **Automatisches Release-Packaging (`.github/workflows/release.yml`):**
  - Automatisches Erstellen von installationsfertigen `smart-portal-suite.zip` Archiven und Veröffentlichung als GitHub Release.
- **In-Dashboard Auto-Update Engine (PUC v5):**
  - Integration von YahnisElsts `plugin-update-checker` v5.7 (`includes/vendor/plugin-update-checker/`).
  - Ermöglicht 1-Klick-Updates im WordPress-Admin direkt aus GitHub Releases.
- **Entwickler-Tools & GitHub Templates:**
  - Versions-Bumping-Skript `scripts/bump-version.sh`.
  - Pull-Request-Template mit Pflicht-Checkliste (`.github/pull_request_template.md`).
  - Issue-Templates für Bug Reports und Feature Requests (`.github/ISSUE_TEMPLATE/`).

### Behoben
- Versions-Inkonsistenz in `smart-portal-suite.php` behoben (`SPS_VERSION` auf `0.2.0` und anschließend auf `0.2.1` synchronisiert).

---

## [0.2.0] - 2026-09-24
### Hinzugefügt
- **Nextcloud User & Social Login Sync (`modules/auth/`):**
  - Vollständige Synchronisation von WordPress-Benutzern mit Nextcloud via OCS API.
  - Dedizierte Admin-Verwaltungsseite für Account-Synchronisation (`class-sps-account-sync-page.php`).
  - Shortcodes für Auth-Buttons, Magic Links und geschützte Formulare (`class-sps-auth-shortcodes.php`).
- **Formular-Manager & Import (`includes/class-sps-form-manager.php`):**
  - Modal-Dialog zum Importieren neuer Formulare und Löschfunktion für Formulare.
  - Dynamischer Styling-Editor mit Farbauswahl und Live-Vorschau.
- **Dateiuploads:** Unterstützung für HEIC-Bilddateien mit Client-seitiger Erkennung.
- **Berechnungen & Assets:** Formellogik (`calculations.js`), Einheiten-Slider und SVG-Sprites für `gebaeude-check` und `projekte-mit-mir`.
- **Datenschutz:** Konfigurierbare Datenschutzerklärung-URLs direkt in der Formular-Engine.

### Dokumentation
- 17 modulare technische Dokumentationskapitel im Verzeichnis `docs/`.
- JSDoc-Kommentare für `form-engine.js`.

---

## [0.1.0] - 2026-09-24
### Hinzugefügt
- **Basis-Architektur (4-Schichten-Modell):**
  - Deklarative JSON-Formular-Engine (`config/forms/`).
  - Shortcode-Renderer `[sps_form id="..."]` mit Instanz-Isolation.
  - Nextcloud Forms API v3 Integration (`modules/nextcloud/class-sps-nc-forms.php`).
  - WebDAV-Fallback zur Speicherung von Leads bei API-Störungen (`modules/nextcloud/class-sps-nc-webdav.php`).
- **Admin & Diagnose:**
  - Einstellungsseite mit Credential-Verschlüsselung (`class-sps-settings.php`).
  - Integriertes Diagnose-Tool mit Ring-Buffer-Logging und Verbindungstests (`class-sps-diagnostics.php`).
- **Frontend-Assets:**
  - Responsive CSS mit CSS-Custom-Properties (`--sps-*`).
  - Adressvervollständigung via OpenStreetMap / Nominatim (`osm-autocomplete.js`).
  - SVG-Master-Sprite (`portal-icons.svg`).
