## Zusammenfassung der Änderungen
<!-- Bitte beschreibe kurz und präzise, was dieser PR ändert, hinzufügt oder behebt. -->

## Art der Änderung
<!-- Bitte die zutreffende Option markieren: [x] -->
- [ ] `feat:` Neues Feature (erhöht ggf. MINOR-Version)
- [ ] `fix:` Fehlerbehebung (erhöht ggf. PATCH-Version)
- [ ] `docs:` Reine Dokumentationsänderung
- [ ] `refactor:` Code-Bereinigung / Refactoring ohne Funktionsänderung
- [ ] `style:` CSS / Styling-Anpassung
- [ ] `chore:` Wartungsarbeit, CI/CD, Tooling

## Qualitäts-Checkliste vor dem Merge
<!-- Alle Punkte müssen vor dem Merge geprüft werden: -->
- [ ] **README aktualisiert:** Gemäß Repository-Regel wurde die `README.md` auf den neuesten Stand gebracht, sofern Funktionalität oder Konfiguration geändert wurden.
- [ ] **Dokumentation:** Bei architekturellen Anpassungen wurden die betroffenen Kapitel in `docs/` aktualisiert.
- [ ] **Changelog:** Eintrag im `CHANGELOG.md` unter `[Unreleased]` hinzugefügt.
- [ ] **Versions-Konsistenz:** Falls die Version erhöht wurde, stimmen Header `Version:` und `SPS_VERSION` in `smart-portal-suite.php` exakt überein.
- [ ] **Lokale Tests:** Formular-Rendering, AJAX-Übermittlung und Nextcloud-Bridge wurden lokal verifiziert.
- [ ] **JSON-Gültigkeit:** Geänderte Formular-Schemata unter `config/forms/*.json` wurden validiert.
- [ ] **Keine sensiblen Daten:** Keine Passwörter, API-Tokens oder lokale Pfade versehentlich committet.
