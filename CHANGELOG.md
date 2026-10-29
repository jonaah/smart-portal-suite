# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.0.0/) und dieses Projekt folgt [Semantic Versioning](https://semver.org/lang/de/).

---

## [0.6.7] - 2026-10-29
### Geändert
- Gebäude-Check, Kellergeschosse: Es stehen immer mindestens 0, 1 und 2 zur Auswahl, auch wenn die vorherigen Geschossangaben rechnerisch keinen Keller zulassen. Nutzer können frühere Fragen anders verstanden haben. Neu im Schema: `"dynamicMax": { …, "atLeast": N }`.

### Behoben
- Vertauschte Bilder: Holzdach ↔ massives Dach sowie Holz- ↔ Massivdecke (Kellerdecke).

## [0.6.6] - 2026-10-29
### Behoben
- **Konten-Sync mit Nextcloud 33/34** („Password confirmation is required“, OCS 403): Nextcloud akzeptiert App-Passwörter für die Kontenverwaltung nur noch mit der Ausnahme `allowed_no_password_confirmation_ranges` (IP des WordPress-Servers) – und die greift nur bei Anfragen **ohne Sitzungs-Cookie**. Das Plugin schickt Cookies jetzt nur noch beim Forms-Datei-Upload mit, wo Nextcloud sie braucht.
- Verständliche Fehlermeldung mit Lösungsweg statt „Password confirmation is required“.
- `wp sps doctor` / Diagnose-MCP: Nextcloud-Version und Test „Konten anlegen“ (Probe mit ungültigen Daten, legt nichts an) inkl. fertigem occ-Befehl mit der vermuteten Server-IP.

## [0.6.5] - 2026-10-29
### Geändert
- **Heizenergieverbrauch in der Einheit der Rechnung:** Öl in Litern, Gas in m³ oder kWh (umschaltbar), Pellets und Biomasse in kg, Strom-Direktheizung und Fernwärme in kWh, Wärmepumpe als Wärmepumpen-Strom in kWh. Das Formular zeigt live die Umrechnung („2.500 Liter ≈ 24.500 kWh Wärme pro Jahr · 1 Liter ≈ 9,8 kWh“, bei der Wärmepumpe „kWh Strom × Jahresarbeitszahl“) und einen Hinweis, wo die Zahl auf der Rechnung steht. An Nextcloud geht wie bisher der kWh-Wert; „waermeverbrauch-original“ enthält die Eingabe mit Einheit. Der Server rechnet aus Eingabe und Einheit selbst nach (Schema-Option `"inputUnits"`, Regel `"input"`).
- Faktoren wie im bisherigen Gebäude-Check: Heizöl 9,8 kWh/l, Erdgas 10,1 kWh/m³, Pellets 4,8 kWh/kg, Biomasse 4,0 kWh/kg.

## [0.6.4] - 2026-10-29
### Behoben
- **Häuser ohne Keller:** Das NC-Formular verlangt die Kellerdecke als Pflichtangabe, das Formular fragt sie bei 0 Kellergeschossen aber nicht ab – solche Anfragen landeten im Fallback. Ohne Keller wird jetzt automatisch „Kellerdecke aus Stahlbeton, massiv“ übertragen (unterster Boden ist dann meist eine Betonbodenplatte).
- **Wärmeverbrauch je Energieträger:** Öl, Pellets, Biomasse und Wärmepumpe hatten eigene, viel zu kleine Bereiche (z. B. Öl max. 5.000), obwohl der Wert in kWh gespeichert wird – realistische Verbräuche ließen sich nicht eingeben. Wieder wie im bisherigen Gebäude-Check: 4.000–40.000 kWh für alle, mit Umrechnung in Liter/m³/kg bzw. Strom.
- Wärmekosten bis 40 Cent/kWh (Strom-Direktheizung lag vorher über dem Maximum).
- Prüfungen berücksichtigen feste Regelwerte zusätzlich zu den Auswahloptionen. Neuer Offline-Test: 4.224 Antwortwege (Keller, Außenwand, Fenster, Energieträger, Dach) – alle Pflichtfragen des NC-Formulars werden befüllt.

## [0.6.3] - 2026-10-29
### Behoben
- **Abhängigkeiten der Geschoss-Fragen wieder da** (gingen beim Umbau verloren): Vollgeschosse ≤ Geschosse, Kellergeschosse ≤ Geschosse − Dachgeschoss − 1, beheizte Geschosse ≤ Geschosse. Die Zahlen-Buttons zeigen nur noch mögliche Werte; wird eine frühere Antwort verkleinert, werden zu große Folgeantworten verworfen. Schema-Option `"dynamicMax"`.
- Reihenfolge: Dachgeschoss und Kellergeschosse kommen vor „beheizte Geschosse“.

### Geändert
- **Erklärungen bei allen Fragen**: kurze Info, was gemeint ist und wofür es gebraucht wird; längere Hilfen aufklappbar („Weitere Informationen“). Grundlage sind die Hilfetexte des Nextcloud-Formulars.
- „Anzahl der Geschosse“ zählt alle Ebenen inkl. Keller und ausgebautem Dachgeschoss (wie im NC-Formular bei den Vollgeschossen beschrieben); neue Auswahl „5+“.
- Klare Trennung Strom/Wärme: „Jährlicher Stromverbrauch (Haushaltsstrom)“, „Was zahlen Sie für eine Kilowattstunde Strom?“, „Jährlicher Heizenergieverbrauch“, „Was kostet Sie eine Kilowattstunde Wärme?“.

## [0.6.2] - 2026-10-29
### Geändert
- **„Ein Bildschirm“ als Standard:** Das Formular füllt genau die Fensterhöhe (abzüglich Admin-Leiste/Sticky-Header) und wird beim Öffnen der Seite in den Blick gescrollt. Header und Footer bleiben erreichbar: Das Mausrad über dem Formular scrollt am Ende des Inhalts die Seite weiter (vorher nur über die Scrollleiste). Im Styling-Editor wählbar: „Ein Bildschirm“, „Feste Höhe“, „Automatisch“. Formulare mit bereits gespeicherter fester Höhe behalten diese.
- **Keine Vorauswahl bei Anzahl-Fragen:** Vollgeschosse, beheizte Geschosse und Kellergeschosse zeigen Zahlen-Buttons ohne Vorauswahl („Bitte wählen“); „Weiter“ verlangt eine Auswahl. Schema-Optionen `"preselect": false` und `"buttons": true`. Regler für kWh, Cent und Jahre behalten ihren Startwert.
- **Jahreszahlen ohne Tausenderpunkt:** Sanierungsjahre zeigen „1990“ statt „1.990“ (`"format": "year"`, sonst automatisch bei Bereichen 1800–2100).
- **Adressschritt:** Vorname, Nachname und E-Mail entfallen (Kunden sind angemeldet). PLZ/Ort und Straße/Nr. je in einer Zeile, einheitliche Breite.
- Zahlen-Buttons brechen um statt aus der Karte zu laufen; mobil kleinere Illustration bei Regler-Fragen.

## [0.6.1] - 2026-10-29
### Behoben
- **Gebäude-Check → Nextcloud-Formular:** Energieträger „Gas“, Dachart „Holzdach“/„Massives Dach“ und Kellerdecke „Holz“/„Massiv“/„Kappendecke“ werden beim Absenden auf die festen Optionen des NC-Formulars übersetzt („Erdgas“, „Dach in Holzkonstruktion“, „Dach in massiver Konstruktion“, „Kellerdecke als Holzbalkendecke“, „Kellerdecke aus Stahlbeton, massiv“, „Ziegel-/Hohlsteindecke“). Vorher scheiterte jede Übertragung mit HTTP 400 an diesen Pflichtfragen. Die Auswahl im Formular und das NC-Formular bleiben unverändert.
- Bereits gespeicherte Fallback-Leads werden beim Nachreichen ebenfalls übersetzt (nur die wiederholbaren Übersetzungsregeln, Ableitungen laufen nicht doppelt).
- Prüfungen (Doctor, „Zuordnung prüfen“, MCP) berücksichtigen solche Übersetzungsregeln. Offline-Test gegen `forms_nc/Gebaeude-Check.json`: alle Pflichtfragen passen.

### Geändert
- **Gebäude-Check standardmäßig nur für angemeldete Nutzer** (`requires_login` im Schema). Zum Testen weiter umschaltbar (Formular-Editor bzw. `wp sps configure --form-access=gebaeude_check=public`).
- **Schnell-Themes ohne Branding:** drei neue Farbschemata „Klar (Hell, Petrol)“, „Anthrazit (Dunkel, Bernstein)“, „Salbei (Hell, Grün)“ inkl. Illustrationsfarben; die bisherigen Vorlagen heißen jetzt „Standard (Violett)“, „Ozean (Dunkelblau)“, „Minimal (Hell, Blau)“.

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
