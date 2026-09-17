# Smart Portal Suite (WordPress-Nextcloud-Plugin)

Eigenständiges, wiederverwendbares WordPress-Plugin für dynamische Multi-Step-Formulare (Gebäude-Check, Projektanfragen) mit direkter Nextcloud-Anbindung (Forms API v3 & WebDAV-Fallback).

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

## 2. Namensraum-Konventionen

- **Plugin-Name:** Smart Portal Suite
- **Slug / Ordner:** `smart-portal-suite`
- **Code-Präfix:** `sps` / `SPS_` (PHP-Klassen, Funktionen, Hooks, CSS-Variablen, JS-Namespace)
- **Shortcode:** `[sps_form id="..."]`
- **Text-Domain:** `smart-portal-suite`

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
│   ├── class-sps-ajax-handler.php  # AJAX-Endpunkt sps_submit_form
│   ├── class-sps-form-renderer.php # Shortcode [sps_form id="..."], Asset-Lader
│   └── class-sps-diagnostics.php   # Admin-only Diagnose-Werkzeug
├── modules/
│   └── nextcloud/
│       ├── class-sps-nc-client.php # HTTP/OCS-Requests, Timeout, Logging
│       ├── class-sps-nc-forms.php  # Nextcloud Forms API v3 Anbindung
│       └── class-sps-nc-webdav.php # WebDAV Fallback-Ablage (ein File pro Lead)
├── assets/
│   ├── css/
│   │   └── portal-base.css         # CSS-Styling mit --sps-* Variablen
│   ├── js/
│   │   ├── form-engine.js          # Universeller Formular-Renderer
│   │   ├── osm-autocomplete.js    # Adressvervollständigung via Nominatim
│   │   └── calculations.js        # Formelberechnungen
│   └── icons/
│       └── portal-icons.svg        # Zentrales SVG-Sprite
├── templates/
│   └── form-container.php          # Generisches Formular-Template
├── tests/
│   └── serve-test.js               # Node-Mock-Server für UI/Layout-Tests
├── .editorconfig
├── .gitignore
├── README.md
└── Baseline-smart-portal-suite.md
```

## 4. Nutzung

Einbinden eines Formulars via Shortcode:
```text
[sps_form id="gebaeude_check"]
```
