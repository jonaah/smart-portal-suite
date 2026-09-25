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
├── smart-portal-suite.php          # Bootstrap, Plugin-Header, require_once-Loader
├── config/
│   └── forms/                      # Formular-Schemata als JSON
│       ├── gebaeude-check.json
│       └── projekte-mit-mir.json
├── includes/
│   ├── class-sps-settings.php      # Einstellungsseite, Verschlüsselung
│   ├── class-sps-ajax-handler.php  # AJAX-Endpunkt sps_submit_form, FormData & Files
│   ├── class-sps-form-renderer.php # Shortcode [sps_form id="..."], Asset- & Sprite-Lader
│   ├── class-sps-form-manager.php  # Admin-Menü "Formulare", Styling-Editor & Presets
│   └── class-sps-diagnostics.php   # Admin-only Diagnose-Werkzeug
├── modules/
│   └── nextcloud/
│       ├── class-sps-nc-client.php # HTTP/OCS-Requests, Timeout, Logging
│       ├── class-sps-nc-forms.php  # Nextcloud Forms API v3 Anbindung
│       └── class-sps-nc-webdav.php # WebDAV Fallback-Ablage (ein File pro Lead)
├── assets/
│   ├── css/
│   │   ├── portal-base.css         # CSS-Styling mit --sps-* Variablen
│   │   ├── admin.css               # Admin-Styling für Einstellungen & Diagnose
│   │   └── admin-forms.css         # Styling-Editor & Formular-Übersichtstabelle
│   ├── js/
│   │   ├── form-engine.js          # Universeller Formular-Renderer (instanzbasiert)
│   │   ├── osm-autocomplete.js    # Adressvervollständigung via Nominatim
│   │   ├── calculations.js        # Formelberechnungen & Einheiten-Formatierung
│   │   ├── admin.js               # Diagnostics Verbindungstest, Log-Steuerung
│   │   └── admin-forms.js         # Styling-Editor, wpColorPicker, Live-Preview
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
├── .editorconfig
├── .gitignore
├── README.md
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
