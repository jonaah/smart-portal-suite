# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.0.0/) und dieses Projekt folgt [Semantic Versioning](https://semver.org/lang/de/).

---

## [0.6.0] - 2026-10-29
### Hinzugefügt
- **Steuerung per MCP:** 15 WordPress-Abilities (ab WP 6.9) für Formulare, Design, Zugang, Nextcloud-Zuordnung, Übertragungstest, Diagnose, Fallback-Leads, Einstellungen (ohne Zugangsdaten) und Konten-Sync. Werden von Easy MCP AI bzw. dem MCP Adapter als Werkzeuge angeboten (Easy MCP AI: unter „Tools › Abilities“ einschalten). Nur für Administratoren, ändernde Aufrufe werden protokolliert. `wp sps doctor` zeigt den Status.

### Geändert
- „Zuordnung prüfen“ im Formular-Editor wertet fehlende Optionen nur bei Pflichtfragen als Fehler (wie `wp sps doctor`).

## [0.5.2] - 2026-10-29
### Behoben
- **v0.5.0 war unvollständig:** `includes/class-sps-upload-staging.php` fehlte im Release → Fatal Error (HTTP 500) auf allen Seiten. (Tag v0.5.1 wurde ohne Versionsanpassung erstellt, dafür gibt es kein Release.)

### Hinzugefügt
- **Formular nur für angemeldete Nutzer:** pro Formular einstellbar (Smart Portal → Formulare → Styling → „Zugang & Nextcloud“ bzw. `wp sps configure --form-access=gebaeude_check=login`). Vorrang vor `requires_login` im Schema. Gäste sehen einen Hinweis im Formular-Design mit Anmelde-/Registrierungs-Button. Nach der Anmeldung geht es zurück zum Formular (`redirect_to` + Cookie). Die Sperre gilt auch für Uploads und Absenden. Der Hinweistext ist anpassbar.
- **Nextcloud-Formular im Editor wählen:** Liste der NC-Formulare (eigene + geteilte), Warnung bei gleichnamigen Formularen und „Zuordnung prüfen“ (wie `wp sps mapping`) direkt im Admin. `wp sps doctor` warnt bei gleichnamigen NC-Formularen und meldet Pflicht-Auswahlfragen mit fehlenden Optionen als Fehler.
- **Alle Formularfarben einstellbar:** Grundfarben (u. a. Akzent-Verlauf, Schrift auf Buttons, Häkchen, Fehler), Feinabstimmung (Rahmen, Hover, Eingabefelder, Platzhalter, Fortschritt, Slider, Tooltip, Schatten, Overlay; leer = automatisch abgeleitet) und Farben der Gebäude-Illustrationen. Keine fest kodierten Farben mehr im Formular-CSS.
- **Echte Live-Vorschau** im Styling-Editor (das tatsächliche Formular; in der Vorschau wird nichts gesendet).

### Geändert
- **Feste, responsive Formularhöhe:** Fortschritt, Frage und Buttons stehen bei jedem Schritt an derselben Stelle. Längere Inhalte scrollen innerhalb des Rahmens, mit weichem Rand als Scroll-Hinweis. Höhe im Editor einstellbar (Standard 760 px, passt sich der Fensterhöhe an, „automatisch“ = altes Verhalten).
- Mobil: Auswahlkacheln zweispaltig und kompakter, Zurück/Weiter nebeneinander, Fragen-Schriftgröße ab 24 px.

## [0.5.0]
### Behoben
- **Absenden bei Hostern mit Multipart-Sperre (z. B. Manitu):** Das Formular sendet kein `multipart/form-data` mehr (Ergebnis dort: HTTP 403). Antworten gehen als JSON an `admin-ajax.php?action=sps_submit_form`, Dateien vorher einzeln als Rohdaten (`application/octet-stream`) an `sps_upload_file`. Die Nonce-Erneuerung läuft per GET.
- **Dateien an Nextcloud ohne Multipart:** Standard ist jetzt die Ablage per WebDAV-PUT in `SPS_Uploads/<formular>/<datum>_<email>_<id>/<feld>/`. Textfragen mit dem Namen des Upload-Felds erhalten Dateinamen, Beschreibung und Ablageort, eine Frage `dateiablage` den Ordner. Der Upload-Endpunkt von Forms (Multipart) ist optional (`nc_file_mode=forms`).

### Hinzugefügt
- **Upload-Staging** (`SPS_Upload_Staging`): Nonce-, Endungs-, Inhaltstyp- und Größenprüfung (max. 10 MB bzw. `post_max_size`), Bindung an Formular, Feld und Benutzer, Rate-Limit je IP, Ablage außerhalb des Webroots (PHP-Temp oder `SPS_STAGING_DIR`), stündliches Aufräumen nach 2 Stunden.
- Einstellung „Dateien an Nextcloud“ (`--file-mode`, `--upload-dir`) und Prüfungen in `wp sps doctor` (Staging, Größenlimit, Pflicht-Dateifragen).

## [0.4.0]
### Behoben
- **Nextcloud-Forms-Übertragung:** Antworten werden im Format der Forms-API v3 gesendet (`{"<questionId>": [werte]}`, Auswahl als Options-ID). Das bisherige Listenformat entsprach nicht der API; Übertragungen landeten dadurch im Fallback.
- **Feldzuordnung:** Namen werden unabhängig von Groß-/Kleinschreibung und `-`/`_` zugeordnet; zusammengesetzte Felder (`plz_ort`) nur noch, wenn kein gleichnamiges Feld existiert. Radio-Werte (z. B. Fläche 125) werden über den Auswahltext der passenden Option zugeordnet, `0` wird übertragen.
- **Gebäude-Check:** Ableitungen des bisherigen Formulars (Außenwand, Fenster, Baujahr-Detail, Sanierungsjahre, Wärmeverbrauch original/kWh) laufen wieder – serverseitig als Regeln im Schema (`submit.rules`).
- **Formular-ID:** feste Zuordnung je Formular in den Einstellungen; die Titelsuche nutzt jetzt `nc_form_title`, `form_type` und `title` und auch geteilte Formulare.
- **WebDAV-Fallback** prüft den HTTP-Status, legt fehlende Ordner an und speichert hochgeladene Dateien mit. Vorher meldete er auch bei Fehlern Erfolg.
- **Datei-Uploads** werden über den Upload-Endpunkt von Forms übertragen (Session-Cookies bleiben erhalten).
- **Kein Rückfallwert mehr auf `nc.effizientes-heim.de`**; ohne Konfiguration findet keine Übertragung statt.
- **Migrationsreste entfernt** (`nc_decrypt`, `sync_wp_user_to_nextcloud`): eingeloggte Kunden senden mit ihrem eigenen NC-Konto, bei 401/403/404 automatisch mit dem Service-Account.
- **Konten-Sync als Gruppenadmin:** Gruppen werden bei der Anlage mitgegeben; NC-Passwort wird nur noch gesetzt, wenn keines gespeichert ist.
- **Datenschutz-Link:** leer = WordPress-Datenschutzseite.
- **Abgelaufener Nonce** (gecachte Seiten): Formular holt einmal einen frischen Nonce und sendet erneut.
- `requires_login` wird auch beim Absenden geprüft.

### Hinzugefügt
- **WP-CLI:** `wp sps configure`, `wp sps doctor`, `wp sps mapping`, `wp sps test-submit`, `wp sps sync-user`, `wp sps retry-leads`.
- **Nachreichen:** Fallback-Leads werden stündlich (WP-Cron) erneut an Forms übertragen und danach nach `_uebertragen/` verschoben.
- **Einstellungen:** Nextcloud-Formular-IDs, „Übertragung als Kunde“.
- `uninstall.php` entfernt Einstellungen und gespeicherte Zugangsdaten.
- Offline-Tests `tests/php/test-mapping.php` (auch in der CI).


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
