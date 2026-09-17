# Baseline: Smart Portal Suite (WordPress-Nextcloud-Plugin)

**Status:** Entwurf zur Freigabe · **Version:** 0.1 · **Stand:** 17.09.2026

Dieses Dokument ist die verbindliche Grundlage für die Entwicklung von Version 1.0. Es
löst frühere Widersprüche zwischen Architektur-Idee und Umsetzungsschritten auf und
legt fest, was gebaut wird, was zurückgestellt wird und wie vorgegangen wird. Änderungen
an dieser Baseline sollten bewusst und dokumentiert erfolgen (z. B. per Commit auf diese
Datei), nicht stillschweigend im Code.

---

## 1. Ausgangslage & Ziel

Aktuell bestehen mehrere Formulare (Gebäude-Check, Projektanfrage) als isolierte
Snippets (HTML/CSS/JS/PHP gemischt, u. a. über WPCode Lite), mit fest im Code
verdrahteten Nextcloud-Zugangsdaten und Tracking-IDs. Ziel ist ein eigenständiges,
wiederverwendbares WordPress-Plugin, das:

- sich per Konfiguration (kein Code-Editing) auf neuen Kundenservern einrichten lässt,
- neue Formulare als Daten (JSON) statt als neuen Code ermöglicht,
- Formulardaten zuverlässig an Nextcloud überträgt (mit Fallback bei Ausfällen),
- sauber, testbar und versioniert entwickelt wird (Git-Workflow, siehe Abschnitt 7).

**Namensraum-Entscheidung (verbindlich für den gesamten Code):**

| Element | Wert |
|---|---|
| Plugin-Name (Arbeitstitel) | Smart Portal Suite |
| Slug / Ordnername | `smart-portal-suite` |
| Code-Präfix (PHP-Klassen, Funktionen, Hooks, Options, CSS-Variablen, JS-Namespace) | `sps` / `SPS_` |
| Shortcode | `[sps_form id="..."]` |
| Text-Domain | `smart-portal-suite` |

> Alter Präfix `eh_` (Effizientes Heim) wird **nicht** weiterverwendet, auch nicht in
> migriertem Code aus den alten Snippets. Der Plugin-Name kann sich noch ändern (siehe
> Abschnitt 9), der technische Präfix `sps` bleibt davon unabhängig bestehen, damit
> nichts doppelt umbenannt werden muss.

---

## 2. Scope v1.0 (MVP)

### 2.1 Im Scope

1. **Zentrale Einstellungsseite** (WP-Admin)
   - Nextcloud-URL, Service-Account-Name, App-Passwort
   - Basisordner für WebDAV-Ablage
   - Admin-Benachrichtigungsadresse(n) für Fallback-Warnungen
   - Primär-/Akzentfarbe fürs Formular-Theming
   - Link zur Datenschutzerklärung (für Consent-Feld, siehe 2.3)
   - Zugangsdaten verschlüsselt gespeichert (siehe Abschnitt 5)

2. **Nextcloud-Bridge (Service-Layer)**
   - `SPS_NC_Client`: HTTP/OCS-Requests, Timeout, Retry, Fehlerlogging
   - `SPS_NC_Forms`: Forms-API-v3-Anbindung, Feld-Mapping, Submission
   - `SPS_NC_WebDAV`: Ordner-Erstellung (MKCOL), Datei-Uploads, Fallback-Ablage

3. **Generische Formular-Engine** (nicht formularspezifischer Code!)
   - Formulare werden als JSON-Schema definiert (siehe Abschnitt 6)
   - Ein universeller JS-Renderer baut Schritte, Slider, Kachelauswahl,
     Abhängigkeiten (z. B. "kein Keller → Keller-Schritte ausblenden") und
     Nominatim-Adresssuche dynamisch aus dem Schema auf
   - SVG-Icons zentral als Sprite ausgelagert, nicht inline im Formular
   - Ein einziger Shortcode für beliebig viele Formulare: `[sps_form id="..."]`

4. **Absende-Flow**
   - Eingeloggte Nutzer: direkter Submit an Nextcloud Forms API
   - Ausgeloggte Nutzer: siehe offene Entscheidung in Abschnitt 9 (Magic-Link vs.
     vereinfachter Flow)

5. **Diagnose-Tool** (nur für Admins) zur schnellen Fehleranalyse der
   Nextcloud-Verbindung auf Kundenservern

### 2.2 Explizit zurückgestellt (nicht v1.0)

- Bookly-Terminübergabe / Parameter-Bridge
- Eigenes Tracking-Management (Google Ads/Meta/GTM) — läuft vorerst über
  bestehende Tools wie Google Site Kit oder GTM, nicht über das Plugin
- Visueller Drag-and-Drop-Formular-Builder im Admin
- OAuth-SSO zwischen WordPress und Nextcloud

### 2.3 Ergänzung gegenüber früheren Entwürfen: Consent-Feld

Da personenbezogene Daten (Name, E-Mail, Telefon, Adresse) verarbeitet werden, gehört
ein Consent-Feldtyp von Anfang an ins JSON-Schema (nicht nachträglich einbauen), das auf
die in den Einstellungen hinterlegte Datenschutzerklärung verlinkt und ohne Zustimmung
kein Absenden erlaubt.

---

## 3. Architektur: 4-Schichten-Modell

```
Konfiguration (Admin, wp_options)
        │
        ▼
Formular-Schema (JSON, deklarativ)
        │
        ▼
Backend-Logik (PHP-Service-Klassen: Nextcloud-Bridge, AJAX-Handler)
        │
        ▼
Frontend-Renderer (JS-Engine liest Schema, baut UI)
```

Jede Schicht ist unabhängig austauschbar: ein neues Formular braucht nur eine neue
JSON-Datei, ein neuer Kunde braucht nur neue Einstellungswerte — in beiden Fällen ohne
PHP/JS-Änderung.

---

## 4. Ordnerstruktur (verbindlich)

```
smart-portal-suite/
├── smart-portal-suite.php        # Bootstrap, Plugin-Header, require_once-Loader
├── config/
│   └── forms/                    # Formular-Schemata als JSON
│       ├── gebaeude-check.json
│       └── projekte-mit-mir.json
├── includes/
│   ├── class-sps-settings.php    # Einstellungsseite, Verschlüsselung
│   ├── class-sps-ajax-handler.php# Endpunkt admin-ajax.php?action=sps_submit_form
│   ├── class-sps-form-renderer.php # Shortcode, lädt JSON + Template
│   └── class-sps-diagnostics.php # Admin-only Diagnose-Werkzeug
├── modules/
│   └── nextcloud/
│       ├── class-sps-nc-client.php
│       ├── class-sps-nc-forms.php
│       └── class-sps-nc-webdav.php
├── assets/
│   ├── css/
│   │   └── portal-base.css       # ausschließlich --sps-* Variablen
│   ├── js/
│   │   ├── form-engine.js        # generischer Renderer (liest JSON)
│   │   ├── osm-autocomplete.js
│   │   └── calculations.js
│   └── icons/
│       └── portal-icons.svg      # zentrales SVG-Sprite
├── templates/
│   └── form-container.php        # ein generisches Template für alle Formulare
├── tests/
│   └── serve-test.js             # NUR Layout/UI-Mock, siehe Abschnitt 8
├── .editorconfig
├── .gitignore
├── README.md
└── smart-portal-suite.php
```

**Kein Composer/PSR-4 in v1.0.** Klassen werden verwendet, aber einfach per
`require_once` im Bootstrap geladen. Composer wird erst eingeführt, wenn eine externe
Library (z. B. Plugin Update Checker) es wirklich erfordert — siehe Abschnitt 9.

---

## 5. Sicherheits-Anforderungen (verbindlicher Teil des AJAX-Handlers, nicht optional)

Diese Punkte müssen **von Anfang an** im ersten AJAX-Handler stecken, nicht
nachgerüstet werden:

- [ ] **CSRF-Schutz:** Jede Submission prüft einen WordPress-Nonce
      (`check_ajax_referer()`), bevor irgendetwas verarbeitet wird.
- [ ] **Input-Sanitization:** Jedes eingehende Feld wird typgerecht bereinigt
      (`sanitize_text_field`, `sanitize_email`, `absint` etc.), bevor es an Nextcloud
      geht oder gespeichert wird.
- [ ] **Output-Escaping:** Alles, was im Admin (z. B. Diagnose-Tool) ausgegeben wird,
      wird escaped (`esc_html`, `esc_attr`, `esc_url`).
- [ ] **Capability-Check:** Diagnose-Tool und Einstellungsseite sind ausschließlich für
      `current_user_can('manage_options')` erreichbar.
- [ ] **Credentials-Verschlüsselung:** `sodium_crypto_secretbox()` (PHP-Core seit 7.2,
      kein externes Paket nötig) statt `openssl_encrypt()` mit unklarem IV-Handling.
- [ ] **Spam-/Bot-Schutz (Minimalversion):** Honeypot-Feld plus einfache
      Zeitprüfung ("Formular wurde schneller als 2 Sekunden abgesendet → verwerfen").
- [ ] **Kein GitHub-Token im ausgelieferten Plugin-Code**, falls das Repo privat ist
      (siehe Abschnitt 9 zum Update-Checker).

---

## 6. Formular-Schema-Standard

Jedes Formular ist eine JSON-Datei unter `config/forms/`. Minimalstruktur:

```json
{
  "form_id": "gebaeude_check",
  "form_type": "Gebaeudedaten",
  "title": "Gebäude-Check",
  "steps": [
    {
      "id": "typ",
      "type": "radio",
      "dataType": "string",
      "label": "Um was für einen Gebäudetyp handelt es sich?",
      "choices": [
        { "text": "Freistehendes Haus", "icon": "house-single" },
        { "text": "Reihenmittelhaus", "icon": "house-row-middle" }
      ]
    },
    {
      "id": "energieverbrauch",
      "type": "slider",
      "dataType": "int",
      "min": 1000,
      "max": 10000,
      "step": 250,
      "suffix": " kWh"
    },
    {
      "id": "consent",
      "type": "consent",
      "dataType": "bool",
      "required": true,
      "label": "Ich habe die Datenschutzerklärung gelesen und stimme zu."
    }
  ]
}
```

Unterstützte Feldtypen in v1.0: `radio`, `slider`, `text`, `email`, `tel`, `select`,
`consent`. Weitere Typen werden erst ergänzt, wenn ein konkretes Formular sie braucht.

---

## 7. Datenhaltung & Fallback-Strategie

- **Primärweg:** Direkte Submission an die Nextcloud Forms API v3.
- **Fallback bei Fehler** (z. B. HTTP 403, Netzwerkfehler): Ablage per WebDAV.
  - **Wichtig:** Jede Submission wird als **eigene Datei** abgelegt
    (`lead_{datum}_{uhrzeit}_{zufallsstring}.json`), **nicht** an eine gemeinsame
    CSV-Datei angehängt. WebDAV kann nicht atomar anhängen — bei gleichzeitigen
    Submissions würde ein Append-Ansatz Leads stillschweigend überschreiben.
- Bei Fallback-Nutzung erhält die hinterlegte Admin-Adresse eine Benachrichtigung.

---

## 8. Lokale Entwicklungsumgebung & Testing

1. **Umgebung:** [Local](https://localwp.com/) (Windows/macOS), keine Docker-Kenntnisse
   nötig. Repository wird direkt im Plugin-Ordner der Local-Site initialisiert
   (`app/public/wp-content/plugins/smart-portal-suite`) — kein Symlink nötig, das
   funktioniert plattformunabhängig identisch.
2. **Debug-Modus** in `wp-config.php`:
   ```php
   define( 'WP_DEBUG', true );
   define( 'WP_DEBUG_LOG', true );
   define( 'WP_DEBUG_DISPLAY', false );
   ```
3. **Drei Testebenen, mit klar unterschiedlichem Zweck:**
   - `tests/serve-test.js` (Node-Mock-Server): **nur** für Layout, Slider-Verhalten,
     Schrittwechsel — kennt keine WP-Nonces, keine echte `admin-ajax.php`. Ein
     erfolgreicher Test hier bedeutet **nicht**, dass es in WordPress funktioniert.
   - Browser-DevTools (Netzwerk-Tab, Filter Fetch/XHR): für echte AJAX-Aufrufe gegen
     `admin-ajax.php?action=sps_submit_form`, Payload und Response direkt einsehbar.
   - `wp-content/debug.log`: für PHP-seitige Fehler (z. B. fehlgeschlagene
     cURL-Aufrufe an Nextcloud).
4. **Für Nextcloud-Integrationstests wird eine echte (Test-)Nextcloud-Instanz
   benötigt** — siehe offene Punkte in Abschnitt 9.

---

## 9. Offene Entscheidungen / benötigte Informationen

Diese Punkte sind mit sinnvollen Annahmen in dieses Dokument eingeflossen, sollten aber
vor bzw. während der Entwicklung bestätigt oder korrigiert werden:

1. **Test-Nextcloud-Instanz:** Gibt es eine Staging-/Test-Nextcloud (mit Forms API v3),
   gegen die lokal entwickelt werden kann? Ohne sie lässt sich Abschnitt 2 Punkt 2 nicht
   end-to-end testen, nur mocken.
2. **Ausgeloggter Absende-Flow:** Soll der volle Magic-Link-Flow (mit Transient-Zwischenspeicherung
   und Abhängigkeit von Magic Login Pro) wirklich Teil von v1.0 sein, oder reicht für den
   Start ein einfacherer Flow (Absenden funktioniert immer, Bestätigung per E-Mail
   läuft daneben)? Das reduziert Komplexität und externe Abhängigkeiten für die erste
   Version spürbar.
3. **GitHub-Repository:** Privat oder öffentlich? Falls privat: Wie wird der
   Zugriffstoken für den Plugin Update Checker gehandhabt, ohne dass er im an Kunden
   ausgelieferten Plugin-Code landet?
4. **Mindestanforderungen Kundenserver:** Welche PHP- und WordPress-Mindestversionen
   sollen unterstützt werden (relevant für z. B. `sodium_*`-Funktionen, die PHP ≥ 7.2
   voraussetzen)?
5. **Plugin-/Firmenname final:** "Smart Portal Suite" ist ein Arbeitstitel. Eine spätere
   Umbenennung betrifft nur Anzeige-Strings, nicht den Code-Präfix `sps`.

---

## 10. Git-Workflow

### 10.1 Branching

- `main`: ausschließlich produktionsreifer Code, jeder Merge bekommt einen Tag
  (`v1.0.0`, `v1.0.1`, …)
- `develop`: aktiver Integrations-Branch
- `feature/*`: ein Branch pro Modul (z. B. `feature/nc-forms-api`,
  `feature/form-json-engine`), PR nach `develop`

### 10.2 `.gitignore` (Kern-Einträge)

```
.DS_Store
Thumbs.db
.idea/
.vscode/
node_modules/
vendor/
dist/
build/
*.zip
*.log
debug.log
```

### 10.3 Release-Prozess

1. Tag pushen (`git tag v1.0.0 && git push origin --tags`)
2. GitHub Action baut automatisch:
   - PHP-Syntax-Check / Linting
   - Entfernt `tests/`, `.github/`, Dev-Dateien
   - Schnürt `smart-portal-suite.zip` und hängt es ans Release an
3. Updates beim Kunden laufen über den Plugin Update Checker (siehe offener Punkt 9.3)

---

## 11. Vorgehen: Reihenfolge der Umsetzung

Bewusst in dieser Reihenfolge, damit spätere Schritte nicht auf unfertigen früheren
Entscheidungen aufbauen:

1. **Grundgerüst:** Git-Repo, `.gitignore`, Plugin-Bootstrap mit Header, Präfix `sps`
   überall konsequent angewendet.
2. **JSON-Schema final spezifizieren** (Abschnitt 6) inkl. Consent-Feld — bevor
   irgendein Formular gebaut wird.
3. **Sicherheits-Grundgerüst des AJAX-Handlers** (Abschnitt 5) — Nonce, Sanitization,
   Capability-Checks, Honeypot. Erst danach eigentliche Formularlogik.
4. **Settings-Seite** für Nextcloud-Zugangsdaten (verschlüsselt).
5. **Nextcloud-Bridge:** `SPS_NC_Client` → `SPS_NC_Forms` → `SPS_NC_WebDAV`
   (Ein-Datei-pro-Lead-Prinzip, Abschnitt 7).
6. **Generischer Formular-Renderer:** JS-Engine, die das JSON-Schema liest und die
   UI dynamisch aufbaut — **nicht** ein statisches Template pro Formular.
7. **Diagnose-Tool** zur Fehleranalyse der Nextcloud-Verbindung.
8. **Erstes reales Formular** (Gebäude-Check) als JSON-Schema definieren und
   End-to-End testen (Browser-DevTools + echte Test-Nextcloud, siehe 9.1).
9. **Zweites Formular** (Projektanfrage) als Bestätigung, dass die Engine wirklich
   generisch funktioniert, bevor v1.0 als abgeschlossen gilt.

## 12. Definition of Done für v1.0

- Beide Formulare (Gebäude-Check, Projektanfrage) laufen ausschließlich über die
  generische JSON-Engine, kein formularspezifischer PHP/JS-Code.
- Alle Punkte aus der Sicherheits-Checkliste (Abschnitt 5) sind umgesetzt.
- Fallback-Mechanismus (WebDAV, ein File pro Lead) ist getestet, inkl. simuliertem
  Nextcloud-Ausfall.
- Ein neuer Kunde lässt sich ausschließlich über die Einstellungsseite konfigurieren,
  ohne eine Zeile Code zu ändern.
- Diagnose-Tool zeigt Verbindungsstatus korrekt an und ist gegen unbefugten Zugriff
  geschützt.