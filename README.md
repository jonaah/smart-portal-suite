# Smart Portal Suite (WordPress-Nextcloud-Plugin)

Eigenständiges, wiederverwendbares WordPress-Plugin für dynamische Multi-Step-Formulare (Gebäude-Check, Projektanfragen) mit direkter Nextcloud-Anbindung (Forms API v3 & WebDAV-Fallback).

---

## 1. Architektur (4-Schichten-Modell)

```
Konfiguration (WP-Admin, wp_options)
        │
        ▼
Formular-Schema (JSON, deklarativ unter config/forms/)
        │
        ▼
Backend-Logik (PHP-Service-Klassen: Nextcloud-Bridge, AJAX-Handler)
        │
        ▼
Frontend-Renderer (JS-Engine liest Schema, baut UI)
```

Jede Schicht ist unabhängig austauschbar: ein neues Formular braucht nur eine neue JSON-Datei, ein neuer Kunde braucht nur neue Einstellungswerte — in beiden Fällen ohne PHP/JS-Änderung.

---

## 2. Namensraum-Konventionen

- **Plugin-Name:** Smart Portal Suite
- **Slug / Ordner:** `smart-portal-suite`
- **Code-Präfix:** `sps` / `SPS_` (PHP-Klassen, Funktionen, Hooks, CSS-Variablen, JS-Namespace)
- **Shortcode:** `[sps_form id="..."]`
- **Text-Domain:** `smart-portal-suite`

---

## 3. Ordnerstruktur

```
smart-portal-suite/
├── .github/                        # CI/CD Workflows, PR- & Issue-Templates
│   ├── workflows/
│   │   ├── ci.yml                  # PR Quality Gate (PHP 7.4-8.3 Matrix, JSON-Validator)
│   │   └── release.yml             # Automatisches Packaging & GitHub Release
│   ├── ISSUE_TEMPLATE/             # Standardisierte Bug- & Feature-Templates
│   └── pull_request_template.md    # PR-Checkliste inkl. README-Pflicht
├── smart-portal-suite.php          # Bootstrap, Plugin-Header, require_once-Loader
├── scripts/                        # Automatisierungs-Skripte
│   └── bump-version.sh             # SemVer Version-Bumping-Tool
├── docs/                           # Vollständige modulare technische Dokumentation (lokal)
├── config/
│   └── forms/                      # Formular-Schemata als JSON
│       ├── gebaeude-check.json
│       └── projekte-mit-mir.json
├── includes/
│   ├── class-sps-settings.php      # Einstellungsseite, Verschlüsselung, DB-Credentials
│   ├── class-sps-ajax-handler.php  # AJAX-Endpunkt sps_submit_form, FormData & Files
│   ├── class-sps-form-renderer.php # Shortcode [sps_form id="..."], Asset- & Sprite-Lader
│   ├── class-sps-form-manager.php  # Admin-Menü "Formulare", Styling-Editor & Presets
│   ├── class-sps-diagnostics.php   # Admin-only Diagnose-Werkzeug
│   ├── class-sps-account-sync-page.php # Admin-Dashboard: Account-Sync & Status
│   └── vendor/
│       └── plugin-update-checker/  # In-Dashboard 1-Klick Auto-Update Engine (PUC v5)
├── modules/
│   ├── nextcloud/
│   │   ├── class-sps-nc-client.php # HTTP/OCS-Requests, OCS User API, Timeout, Logging
│   │   ├── class-sps-nc-forms.php  # Nextcloud Forms API v3 Anbindung
│   │   └── class-sps-nc-webdav.php # WebDAV Fallback-Ablage (ein File pro Lead)
│   └── auth/
│       ├── class-sps-nc-user-sync.php    # Nextcloud User & Social Login Sync Engine
│       └── class-sps-auth-shortcodes.php # Shortcodes: auth_buttons, login, register
├── assets/
│   ├── css/
│   │   ├── portal-base.css         # CSS-Styling mit --sps-* Variablen
│   │   ├── admin.css               # Admin-Styling für Einstellungen & Diagnose
│   │   ├── admin-forms.css         # Styling-Editor & Formular-Übersichtstabelle
│   │   ├── admin-sync.css          # Account-Sync Dashboard Styling
│   │   └── auth-forms.css          # Header Auth Buttons & Magic Link Forms CSS
│   ├── js/
│   │   ├── form-engine.js          # Universeller Formular-Renderer (instanzbasiert)
│   │   ├── osm-autocomplete.js    # Adressvervollständigung via Nominatim
│   │   ├── calculations.js        # Formelberechnungen & Einheiten-Formatierung
│   │   ├── admin.js               # Diagnostics Verbindungstest, Log-Steuerung
│   │   ├── admin-forms.js         # Styling-Editor, wpColorPicker, Live-Preview
│   │   ├── admin-sync.js          # Account-Sync AJAX, Batch-Sync & DB-Test
│   │   └── auth-forms.js          # Magic Login Loading-State & Error Observer
│   └── icons/
│       ├── portal-icons.svg        # Zentrales SVG-Master-Sprite
│       ├── gebaeude-check.svg      # Formspezifisches SVG-Sprite (Gebäude-Check)
│       └── projekte-mit-mir.svg    # Formspezifisches SVG-Sprite (Projekte mit mir)
├── templates/
│   ├── form-container.php          # Generisches Formular-Template mit isolierter Instanz
│   └── admin/
│       └── preview-mock.php        # HTML-Mock für Live-Vorschau im Admin
├── tests/
│   └── serve-test.js               # Node-Mock-Server für UI/Layout-Tests
├── CHANGELOG.md                    # Lückenlose Versionshistorie nach Keep a Changelog
├── .editorconfig
├── .gitignore
├── README.md                       # Projektdokumentation & Entwickler-Handbuch
└── Baseline-smart-portal-suite.md
```

---

## 4. Nutzung via Shortcode

Einbinden eines Formulars via Shortcode auf beliebigen Seiten oder Beiträgen:
```text
[sps_form id="gebaeude_check"]
[sps_form id="projekte_mit_mir"]
```
*Hinweis: Bindestrich (`gebaeude-check`) und Unterstrich (`gebaeude_check`) werden automatisch erkannt.*

---

## 5. Wie die Formulare funktionieren (Entwickler-Handbuch)

Die Formulare sind **100 % deklarativ**. Um ein neues Formular zu erstellen, muss **kein PHP- oder JavaScript-Code** geschrieben werden. Alles wird über eine JSON-Datei in `config/forms/` gesteuert.

### 5.1 Aufbau des JSON-Formular-Schemas

Jedes Formular besitzt eine JSON-Datei (z. B. `config/forms/mein-formular.json`):

```json
{
  "form_id": "mein_formular",
  "form_type": "Kundenanfrage",
  "title": "Kundenanfrage 2026",
  "nc_form_title": "Kundenanfragen",
  "sprite": "assets/icons/portal-icons.svg",
  "steps": [
    {
      "id": "gebaeudetyp",
      "type": "radio",
      "dataType": "string",
      "icon": "icon-house-single",
      "label": "Um welchen Gebäudetyp handelt es sich?",
      "desc": "Bitte wählen Sie die zutreffende Gebäudeart aus.",
      "reason": "Beeinflusst das Verhältnis von Hüllfläche zu Volumen.",
      "required": true,
      "choices": [
        { "text": "Freistehendes Haus", "icon": "icon-house-single", "value": "freistehend" },
        { "text": "Reihenhaus", "icon": "icon-house-row-middle", "value": "reihenhaus" }
      ]
    }
  ]
}
```

#### Wichtigste Schema-Attribute:
- `form_id`: Eindeutiger technischer Bezeichner des Formulars (z. B. `gebaeude_check`).
- `form_type`: Typ-Bezeichnung für Nextcloud Forms API und WebDAV-Ordner.
- `title`: Überschrift für Barrierefreiheit und Titelanzeige.
- `nc_form_title` *(optional)*: Exakter Titel des Ziellisten-Formulars in Nextcloud Forms.
- `requires_login` *(optional, bool)*: Sperrt das Formular für Gäste (`true`). Nicht angemeldeten Nutzern wird ein stilvolles Authentifizierungs-Gate mit Login- und Registrierungs-Buttons sowie automatischem Return-Redirect angezeigt.
- `styles` *(optional, object)*: Direkte Definition von CSS-Variablen im Schema (z. B. `{ "--sps-accent": "#39baff" }`).
- `sprite` *(optional)*: Pfad zu einer individuellen SVG-Sprite-Datei. Fehlt dieses Attribut, prüft die Engine automatisch, ob eine Datei `assets/icons/{form_id}.svg` existiert (z. B. `gebaeude-check.svg`), bevor das Master-Sprite (`portal-icons.svg`) genutzt wird.
- `steps`: Array der einzelnen Formularschritte.

---

### 5.2 Unterstützte Feldtypen (`type`)

| Typ | Beschreibung | Besondere Optionen |
|---|---|---|
| `radio` | Kachelauswahl (Single Choice) | `choices`: Array (`text`, `value`, `icon`, `subtitle`), `layout: "list"` (vertikale Stapelung) |
| `slider` | Interaktiver Schieberegler mit Live-Werteanzeige | `min`, `max`, `step`, `value`, `suffix`, `dynamicConfig` |
| `text` | Einzeiliges Textfeld | `placeholder`, `maxLength`, `showCounter`, `required` |
| `email` | E-Mail-Feld mit Format-Validierung | `placeholder`, `required` |
| `tel` | Telefonnummer-Eingabe | `placeholder`, `required` |
| `textarea` | Mehrzeiliges Textfeld | `placeholder`, `maxLength`, `rows` |
| `date` | Datumsauswahl (HTML5 Date-Picker) | `required` |
| `group` | Gruppierte Felder in Spalten/Zeilen | `fields`: Array aus Feldern oder `{ "type": "row", "fields": [...] }` |
| `address-full` | Vollständiges Adressfeld mit OpenStreetMap/Nominatim Autocomplete | Automatische Aufteilung in Straße, Hausnr., PLZ, Ort |
| `checkbox-multi` | Mehrfachauswahl-Kacheln (Array als Antwort) | `choices`: Array (`id`, `text`, `subtitle`, `icon`), `layout: "list"` |
| `upload` | Datei-Upload mit Drag & Drop (Binärübertragung) | `accept` (unterstützt PDF, JPG, PNG, WEBP, HEIC), State-Handling via `FormData` |
| `summary` | Übersicht aller bisher gegebenen Antworten | Rendert automatisch alle sichtbaren Antworten vor dem Absenden |
| `consent` | DSGVO-Zustimmungs-Checkbox | `required: true`, enthält Link zur Datenschutzerklärung |

---

### 5.3 Die Logik zwischen den Fragen

Formulare sind nicht starr, sondern reagieren dynamisch auf Benutzereingaben:

#### A. Bedingte Sichtbarkeit (`showIf`)
Schritte können abhängig von vorherigen Antworten ein- oder ausgeblendet werden. Ist eine Bedingung nicht erfüllt, wird der Schritt bei der Navigation automatisch übersprungen:

```json
{
  "id": "keller_sanierung_jahr",
  "type": "slider",
  "label": "In welchem Jahr wurde die Kellerdecke zuletzt saniert?",
  "showIf": {
    "field": "keller_sanierung",
    "op": "eq",
    "value": "Ja, es gab eine Sanierung"
  },
  "min": 1960,
  "max": 2026,
  "step": 1,
  "value": 2000
}
```

**Verfügbare Vergleichs-Operatoren (`op`):**
- `eq`: Gleich (`fieldValue == value`)
- `neq`: Ungleich (`fieldValue != value`)
- `gt`: Größer als (`fieldValue > value`)
- `gte`: Größer oder gleich (`fieldValue >= value`)
- `lt`: Kleiner als (`fieldValue < value`)
- `lte`: Kleiner oder gleich (`fieldValue <= value`)
- `in`: Enthalten (prüft ob `value` in einem Array oder String vorhanden ist, z. B. bei `checkbox-multi`)

#### B. Dynamische Schieberegler-Konfiguration (`dynamicConfig`)
Erlaubt es, Minimal-/Maximalwerte, Schritte oder Suffixe eines Sliders anhand einer vorherigen Auswahl dynamisch umzuschalten:

```json
{
  "id": "energieverbrauch",
  "type": "slider",
  "label": "Jährlicher Verbrauch",
  "dynamicConfig": {
    "dependsOn": "energietraeger",
    "configs": {
      "Erdgas": { "min": 5000, "max": 50000, "step": 1000, "suffix": " kWh", "value": 20000 },
      "Heizöl": { "min": 500, "max": 5000, "step": 100, "suffix": " Liter", "value": 2500 }
    }
  }
}
```

---

### 5.4 SVG-Sprite-Anbindung (`#icon-Dateiname`)

Um Ladezeiten zu minimieren und Darstellungsfehler bei externen SVGs (CORS, Safari-Caching) zu verhindern, arbeitet das Plugin mit **zentralen SVG-Sprites**:

1. **Namenskonvention:**
   Im SVG-Sprite wird jedes Symbol nach dem Schema `id="icon-[dateiname]"` definiert.
2. **Referenzierung im JSON-Schema:**
   In den Formular-Schritten oder -Auswahlen kann das Icon flexibel angegeben werden:
   ```json
   "icon": "icon-house-single"
   ```
   *(Der Resolver akzeptiert sowohl `"icon-house-single"`, `"#icon-house-single"` als auch `"house-single"`).*
3. **Mögliche Einsatzorte:**
   - **In Auswahlen (`choices`):** Icon erscheint groß zentriert auf der Auswahlkarte.
   - **Im Schritt-Header:** Wird `"icon": "..."` auf Schritt-Ebene definiert, erscheint das Icon neben oder über der Frage.
   - **In Datei-Upload & Statusmeldungen:** Icons für Dateiablage, Checkmarks etc.
4. **Aufbau einer Sprite-Datei (`assets/icons/portal-icons.svg`):**
   ```xml
   <svg xmlns="http://www.w3.org/2000/svg" style="display: none;" aria-hidden="true">
     <defs>
       <symbol id="icon-mein-symbol" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
         <path d="..."/>
       </symbol>
     </defs>
   </svg>
   ```
   *Tipp: Immer `viewBox="0 0 24 24"` und `stroke="currentColor"` / `fill="none"` verwenden, damit die Farben automatisch über die CSS-Variablen (`--sps-accent`, `--sps-card-bg`) gesteuert werden.*

---

### 5.5 Frontend-Engine & Übertragungslogik

- **Instanzbasierte Architektur (`SPS.FormInstance`):**
  Jedes Formular im DOM ist ein eigenes Objekt mit isoliertem Status. Werden mehrere Shortcodes auf einer Seite eingebunden, stören sich diese nicht gegenseitig.
- **Automatisches Lifecycle-Mounting:**
  Beim Laden des Dokuments sucht `SPS.initAll()` nach allen `.sps-form-container:not(.sps-mounted)` und mountet sie sofort.
- **Universeller Datei- & Datentransfer (`FormData`):**
  Beim Absenden werden alle Antworten und binären Dateien (`$_FILES`) über ein `FormData`-Objekt per POST an `admin-ajax.php?action=sps_submit_form` gesendet.
- **Sicherheit & Anti-Bot:**
  - **CSRF-Schutz:** Jede Übertragung validiert ein WordPress-Nonce.
  - **Honeypot (`sps_hp`):** Unsichtbares Feld, das von Bots ausgefüllt wird und zum sofortigen Abbruch führt.
  - **Mindestdauer:** Absendungen unter 1,5 Sekunden werden automatisch verworfen.
  - **Sanitization:** Alle übermittelten Werte werden im Backend rekursiv typgerecht bereinigt (`sanitize_text_field`, `sanitize_email`, etc.).
- **Nextcloud & WebDAV-Fallback:**
  Kann die Nextcloud Forms API v3 nicht erreicht werden (z. B. Netzwerkfehler, Berechtigungsproblem), wird die Submission automatisch als JSON-Datei über WebDAV im konfigurierten Ordner gespeichert (`Ein-Datei-pro-Lead-Prinzip`).

---

## 6. Formular-Verwaltung & Styling-Customizer (WP-Admin)

Im WordPress-Backend steht unter **Smart Portal > Formulare** ein visueller Formular-Manager zur Verfügung:

1. **Formular-Übersicht:**
   - Liste aller im System registrierten Formulare mit Titel, Shortcode, Schritt-Anzahl und Styling-Status (`Standard` vs. `Individuell`).
   - Klickbarer Shortcode mit automatischer Zwischenablage-Kopierfunktion.
2. **Individueller Styling-Editor:**
   - Jedes Formular kann individuell farblich und formell an das Corporate Design angepasst werden.
   - **Farbwähler (`wp-color-picker`):** Hintergrund (`--sps-bg-main`), Karten (`--sps-card-bg`), Primärer Akzent / Buttons (`--sps-accent`), Sekundärer Akzent (`--sps-accent-green`), Textfarben.
   - **Radien-Schieberegler:** Eckenrundungen für Karten (`--sps-radius-card`) und Buttons (`--sps-radius-btn`).
   - **Schnell-Themes (Presets):** Ein-Klick-Anwendung von Design-Vorlagen (*Effizientes Heim Standard*, *Clean Modern Ocean Blue*, *Minimalist Light*) sowie Zurücksetzen auf Werkseinstellungen.
   - **Live-Vorschau:** Echtzeit-Vorschau des Formulars während der Anpassung im Admin-Panel.
   - Speicherung erfolgt updatesicher in `wp_options` unter `sps_form_theme_{form_id}` und wird per Inline-CSS-Filter (`sps_form_container_styles`) ins Frontend injiziert.

---

## 7. Neues Formular erstellen & Bereitstellung

Formulare können flexibel auf zwei Wegen bereitgestellt werden:

### Weg A: Als Schema-Datei im Codebase (Entwickler)
1. **JSON-Schema anlegen:**
   Erstelle eine neue Datei `config/forms/meine-anfrage.json` mit den gewünschten Schritten und Feldtypen.
2. **Optionales SVG-Sprite:**
   Falls spezifische Icons benötigt werden, lege `assets/icons/meine-anfrage.svg` an.

### Weg B: Formular-Import & Export im WordPress-Admin (Neu)
Über die Schaltfläche **Formular importieren** unter `Smart Portal > Formulare` können Formulare direkt im Backend importiert werden:

1. **ZIP-Paket-Import (`.zip`):**
   - Enthält die Schema-Datei `{form_id}.json` und das zugehörige SVG-Icon-Sprite `{form_id}.svg`.
   - Das Archiv wird automatisch entpackt, das SVG wird von potenziell gefährlichen Skripten bereinigt (SVG-Sanitization) und beide Dateien werden im Uploads-Ordner gespeichert.
2. **JSON-Datei-Upload (`.json`):**
   - Direkter Upload einzelner Schema-Dateien (für Formulare, die das globale Master-Sprite nutzen).
3. **JSON-Code Direkteingabe (Paste):**
   - Reinkopieren des JSON-Strings in das Eingabefeld.
4. **Aus Nextcloud importieren (Scaffolder):**
   - Ruft per Knopfdruck alle Formulare aus der verbundenen Nextcloud Forms API ab.
   - Generiert automatisch ein fertiges SPS-Formular-Gerüst inklusive Fragemapping, Summary- und Consent-Schritt.
5. **Roundtrip-Export (Download):**
   - Jedes Formular kann über die Tabelle als `.zip` (falls formspezifisches SVG-Sprite vorhanden) oder als `.json` exportiert werden.
6. **Updatesichere Speicherung:**
   - Importierte Formulare und Sprites liegen in `wp-content/uploads/smart-portal-suite/forms/` bzw. `icons/`.
   - Sie sind damit **vollständig updatesicher** und werden bei Aktualisierungen des Plugins nicht überschrieben oder gelöscht.
   - Importierte Formulare können im Admin auch wieder gelöscht werden (System-Formulare bleiben geschützt).

### Formular einbinden:
Shortcode auf einer beliebigen WordPress-Seite einfügen:
```text
[sps_form id="meine-anfrage"]
```
Das Formular ist sofort einsatzbereit, styling-kompatibel, responsiv und an Nextcloud angebunden.

---

## 8. Authentifizierung & Magic Link Shortcodes

Die Smart Portal Suite bietet standardisierte Shortcodes für Benutzerauthentifizierung mit **Magic Login Pro**:

### 8.1 Header Auth Buttons: `[sps_auth_buttons]`
Bindet dynamische Authentifizierungs-Buttons für den Header ein (SVG-Icons):
- **Eingeloggt:** Zeigt einen Logout-Button mit Sicherheitsabfrage (`wp_logout_url`).
- **Ausgeloggt:** Zeigt Buttons für Login und Registrierung.
- **Redirect-Erhalt:** Speichert beim Klick die aktuelle Seiten-URL in einem Cookie (`sps_redirect`), sodass der Benutzer nach der Anmeldung automatisch zurückkehrt.

**Attribute:**
```text
[sps_auth_buttons login_url="/login/" register_url="/registrierung/"]
```
*(Rückwärtskompatibler Alias: `[auth_buttons]`)*

### 8.2 Magic Link Login: `[sps_login]`
Rendert ein responsives Anmeldeformular im Corporate Design der Suite als Wrapper um Magic Login Pro:
- Formularrahmen mit konfigurierbarem Titel und Infotext.
- E-Mail-Eingabefeld mit Fokus-Effekt.
- Registrierungs-Footer mit Direktlink zur Registrierungsseite.

**Attribute:**
```text
[sps_login redirect_to="/" title="Login" register_url="/registrierung/"]
```
*(Rückwärtskompatibler Alias: `[energie_login]`)*

### 8.3 Magic Link Registrierung: `[sps_register]`
Rendert das Registrierungsformular im zweispaltigen Raster:
- Vorname & Nachname nebeneinander, E-Mail-Feld in voller Breite.
- Integrierte AGB-/Datenschutz-Checkbox mit dynamischer Verlinkung auf die in den Einstellungen hinterlegte Datenschutzerklärung.
- Double-Submit-Schutz (Button wird während der Übertragung gesperrt: "Wird registriert...").
- Automatischer Reset bei Validierungsfehlern (MutationObserver).
- Löst nach erfolgreicher Registrierung den DOM-Event `sps_registration_success` aus (ideal für Google Tag Manager oder Analytics).
- Footer mit Direktlink zur Login-Seite.

**Attribute:**
```text
[sps_register button_text="Registrieren" login_url="/login/" privacy_url="/datenschutzerklaerung"]
```
*(Rückwärtskompatibler Alias: `[energie_registrierung]`)*

---

## 9. Nextcloud Account & Social-Login Synchronisation

Die Suite synchronisiert WordPress-Benutzer nahtlos mit Nextcloud (`SPS_NC_User_Sync`):

1. **Automatische Account-Erstellung (`user_register`):**
   - Sobald sich ein Benutzer in WordPress registriert, wird im Hintergrund via Nextcloud OCS Provisioning API ein entsprechendes Nextcloud-Konto angelegt.
2. **Standard-Gruppenzuweisung:**
   - Neu angelegte Accounts werden automatisch den konfigurierten Gruppen zugewiesen (Standard: `Hauseigner`).
3. **Passwort-Management:**
   - Ein sicheres 24-stelliges Passwort wird generiert, per OCS-API gesetzt und in `wp_usermeta` mit **Sodium** (`sodium_crypto_secretbox`) verschlüsselt gespeichert (`nextcloud_username`, `nextcloud_password_enc`).
4. **Social-Login Verknüpfung (Nextcloud DB):**
   - Verknüpft das Nextcloud-Konto mit dem WordPress OAuth Server (**WP OAuth Server - CE**).
   - Schreibt über eine sichere PDO-Verbindung den Identifikator `identifier = 'wordpress-' . $user_id` in die Nextcloud-Tabelle `oc_sociallogin_connect`.
   - Ermöglicht dem Benutzer, sich in Nextcloud direkt über den Button **"Über Gebäude Portal anmelden"** einzuloggen.
5. **Asynchrone Non-Blocking-Architektur:**
   - Der Synchronisationsprozess läuft über den `shutdown`-Hook mit `fastcgi_finish_request()`. Dadurch spürt der Besucher im Browser keinerlei Verzögerung bei der Registrierung oder beim Login.
6. **Self-Healing bei Login (`wp_login`):**
   - Wenn sich ein bestehender WordPress-Benutzer anmeldet, dessen Nextcloud- oder Social-Login-Verknüpfung noch fehlt, wird der Sync automatisch im Hintergrund nachgeholt.

---

## 10. Account-Sync & Status Admin-Dashboard

Unter **Smart Portal > Account-Sync** steht ein Administrations-Panel bereit:

- **Schnittstellen-Statuskarten:**
  - Nextcloud OCS API (Erreichbarkeit & Service-Account Rechte).
  - Nextcloud Datenbank (PDO-Verbindungstest & Prüfung von `oc_sociallogin_connect`).
  - Magic Login Pro (Aktivierungsstatus).
  - WP OAuth Server (Aktivierungsstatus).
- **Echtzeit-Metriken:**
  - Gesamtzahl WordPress-Benutzer.
  - Mit Nextcloud synchronisierte Konten.
  - Erfolgreich Social-Login-verknüpfte Konten.
  - Ausstehende / nicht synchronisierte Benutzer.
- **Aktionen & Stapelverarbeitung:**
  - **"Alle ausstehenden Benutzer synchronisieren"**: Führt einen sequenziellen AJAX-Batch-Sync mit Live-Fortschrittsbalken und Fehlerzählung durch.
  - **"Nextcloud-DB testen"**: Prüft die MySQL-Verbindung zur Nextcloud-Datenbank in Echtzeit.
- **Benutzerverwaltungstabelle:**
  - Status-Badges pro Benutzer (*Angelegt*, *Verknüpft*, *Ausstehend*, *Fehler*).
  - Ein-Klick-Button **"Jetzt syncen"** / **"Erneut syncen"** pro Zeile (ohne Neuladen der Seite).
  - Filter nach Ausstehenden, Synchronisierten oder Fehlern sowie Suchfunktion.

---

## 11. Technische Dokumentation der Einzelfunktionen (`docs/`)

Jede Funktion, jedes Modul und jede Schicht des Plugins ist in einem eigenen technischen Referenzdokument im Ordner [`docs/`](docs/README.md) dokumentiert:

1. [01. Plugin Bootstrap & Core Lifecycle](docs/01-plugin-bootstrap-core.md) (`smart-portal-suite.php`)
2. [02. Zentrale Einstellungsseite & Credentials-Verschlüsselung](docs/02-einstellungen-credentials.md) (`SPS_Settings`)
3. [03. Formular-Renderer & Shortcode-Engine](docs/03-formular-renderer-shortcode.md) (`SPS_Form_Renderer`, `[sps_form]`)
4. [04. Frontend Form-Engine & State Management](docs/04-frontend-form-engine.md) (`form-engine.js`, `portal-base.css`)
5. [05. AJAX Form-Submission, Bot-Schutz & WebDAV-Fallback](docs/05-ajax-form-submission-fallback.md) (`SPS_Ajax_Handler`)
6. [06. Nextcloud OCS/REST Client Bridge](docs/06-nextcloud-client-bridge.md) (`SPS_NC_Client`)
7. [07. Nextcloud Forms API v3 Service](docs/07-nextcloud-forms-api.md) (`SPS_NC_Forms`)
8. [08. WebDAV Fallback Lead Storage](docs/08-nextcloud-webdav-fallback.md) (`SPS_NC_WebDAV`)
9. [09. Nextcloud User Provisioning & Social Login Sync](docs/09-nextcloud-user-social-login-sync.md) (`SPS_NC_User_Sync`)
10. [10. Account-Sync Admin Dashboard & Batch Worker](docs/10-account-sync-admin-dashboard.md) (`SPS_Account_Sync_Page`)
11. [11. Authentifizierungs-Shortcodes & Magic Login Pro](docs/11-auth-shortcodes-magic-login.md) (`SPS_Auth_Shortcodes`)
12. [12. Formular-Manager & Styling Customizer](docs/12-formular-manager-styling-editor.md) (`SPS_Form_Manager`)
13. [13. Formular Import/Export & Nextcloud Scaffolder](docs/13-formular-import-export-scaffolder.md) (`SPS_Form_Manager`)
14. [14. OpenStreetMap Nominatim Adressvervollständigung](docs/14-osm-adressvervollstaendigung.md) (`osm-autocomplete.js`)
15. [15. Deklarative Berechnungen, Einheiten & dynamische Slider](docs/15-berechnungen-einheiten-slider.md) (`calculations.js`)
16. [16. Systemdiagnose & Debug Logging](docs/16-systemdiagnose-debug-logging.md) (`SPS_Diagnostics`)
17. [17. Potenzielle Erweiterungen & Sinnvolle Zukünftige Optionen](docs/17-potenzielle-erweiterungen-ideen.md) (Architektur-Roadmap)

---

## 12. Potenzielle Erweiterungen & Roadmap

Eine vollständige Analyse künftiger Erweiterungsmöglichkeiten findet sich in [docs/17-potenzielle-erweiterungen-ideen.md](docs/17-potenzielle-erweiterungen-ideen.md).
Ausgewählte Highlights für kommende Versionen:
- **v1.1:** Lokale Lead-Datenbank in WordPress mit CSV/Excel-Export, automatische Kunden-Bestätigungsmails (HTML/PDF), ausgehende Webhooks (Zapier/Make/n8n) und automatischer WebDAV-Retry-Cronjob.
- **v1.2:** Nextcloud Deck Integration (automatische Kanban-Karten für Handwerker & Energieberater), Bildvorschau & Kamera-Direktzugriff bei Datei-Uploads, digitales Unterschriftenfeld (Signature Canvas).
- **v2.0:** Eigenes Kundenportal via Shortcode `[sps_customer_portal]` mit Live-Projektstatus und visueller Drag-and-Drop Formular-Builder im WP-Admin.

---

## 13. Entwicklung, Git-Workflow & Versionsmanagement

Das Projekt folgt einem hochgradig standardisierten Git- und Release-Workflow nach Best Practices:

### 13.1 Branching-Modell
- **`main`**: Repräsentiert ausschließlich den produktionsfertigen, stabilen Stand (`vX.Y.Z`). Direkte Pushes sind über Branch Protection blockiert.
- **`Development`**: Zentraler Integrations-Branch für alle neuen Funktionen und Korrekturen.
- **Feature-/Fix-Branches**: Werden von `Development` abgezweigt (`feat/<name>`, `fix/<name>`, `chore/<name>`) und ausschließlich via Pull Request zurückgeführt.
- **Hotfixes**: Bei kritischen Produktionsfehlern wird von `main` ein `hotfix/<name>` abgezweigt und nach Prüfung sowohl in `main` als auch in `Development` gemergt.

### 13.2 Commit-Konvention (Conventional Commits)
Alle Commits folgen der Konvention `<typ>(<scope>): <nachricht>`:
- `feat:` Neues Feature (erhöht ggf. MINOR-Version)
- `fix:` Fehlerbehebung (erhöht ggf. PATCH-Version)
- `docs:` Dokumentationsanpassungen
- `refactor:` Code-Optimierungen ohne Verhaltensänderung
- `style:` Styling- und Formatierungsänderungen
- `chore:` Tooling, CI/CD, Wartungsarbeiten

### 13.3 Semantische Versionierung & Bumping
Das Plugin nutzt Semantic Versioning (`MAJOR.MINOR.PATCH`). Zur fehlerfreien Aktualisierung steht ein Skript bereit:
```bash
./scripts/bump-version.sh 0.3.0
```
Das Skript synchronisiert den Plugin-Header `Version:` und die PHP-Konstante `SPS_VERSION` in `smart-portal-suite.php` atomar und validiert die Konsistenz.

### 13.4 CI/CD Automatisierung (GitHub Actions)
1. **CI Quality Gate (`.github/workflows/ci.yml`)**:
   - Läuft bei jedem Push und PR auf `main` und `Development`.
   - **PHP Syntax Matrix**: Prüft alle PHP-Dateien parallel gegen PHP 7.4, 8.0, 8.1, 8.2 und 8.3.
   - **JSON-Validierung**: Verifiziert alle Formular-Schemata unter `config/forms/*.json`.
   - **JS-Syntax**: Syntaxprüfung aller Frontend-Skripte.
   - **Versionskonsistenz**: Prüft, ob Plugin-Header und `SPS_VERSION` übereinstimmen.
2. **Release Automation (`.github/workflows/release.yml`)**:
   - Wird bei Push eines Tags (`v*.*.*`) oder manuell via `workflow_dispatch` getriggert.
   - Erstellt ein sauberes Produktions-Archiv `smart-portal-suite.zip` (ohne `.git`, `.github`, `docs/`, `tests/` etc.).
   - Erstellt das offizielle GitHub Release mit automatisch generierten Release Notes und Download-Asset.

### 13.5 In-Dashboard 1-Klick Auto-Updates (Plugin Update Checker)
Das Plugin integriert die schlanke Library `plugin-update-checker` (PUC v5):
- WordPress-Installationen prüfen automatisch die GitHub Releases API von `jonaah/smart-portal-suite`.
- Sobald ein neues Release publiziert wird, meldet WordPress im Dashboard: *„Neue Version verfügbar. Jetzt aktualisieren.“*
- Die Aktualisierung erfolgt mit 1 Klick vollautomatisch aus dem Release-Asset `smart-portal-suite.zip`.
- Über den Filter `sps_github_updater_token` kann bei Bedarf ein Personal Access Token für private Repositories hinterlegt werden.

### 13.6 Richtlinie für Änderungen
> **Wichtig:** Gemäß der Repository-Richtlinie muss bei jeder funktionalen oder konfigurativen Änderung die `README.md` und `CHANGELOG.md` aktualisiert werden. Das Pull-Request-Template (`.github/pull_request_template.md`) erzwingt diese Prüfung als Pflichtkriterium.



