#!/usr/bin/env python3
"""
Nextcloud Forms: Export/Import/Prüfung einzelner Formulare über die OCS-API (v3).

Umgebungsvariablen:
  SRC_URL, SRC_USER, SRC_PASS   Quell-Instanz
  DST_URL, DST_USER, DST_PASS   Ziel-Instanz

Aufrufe:
  python3 form_transfer.py list   SRC
  python3 form_transfer.py export SRC <form_id> forms/<name>.json
  python3 form_transfer.py import DST forms/<name>.json [--dry-run]
  python3 form_transfer.py verify DST <neue_form_id> forms/<name>.json
  python3 form_transfer.py delete DST <form_id>
"""
import json
import os
import subprocess
import sys

try:
    import requests
except ImportError:
    subprocess.check_call([sys.executable, "-m", "pip", "install", "--quiet",
                           "--break-system-packages", "requests"])
    import requests

API = "/ocs/v2.php/apps/forms/api/v3"
MAPPING_FILE = "forms/id_mapping.json"   # alt -> neu, für nc-lead-sync

# Formular-Einstellungen, die exportiert, importiert und verglichen werden
FORM_KEYS = [
    "title", "description", "allowComments", "isAnonymous", "submitMultiple",
    "allowEditSubmissions", "showExpiration", "expires", "maxSubmissions",
    "submissionMessage", "confirmationEmailEnabled",
    "confirmationEmailSubject", "confirmationEmailBody",
]
# Fragen-Felder, die nur per PATCH gesetzt werden können
Q_PATCH_KEYS = ["name", "description", "isRequired", "accept", "extraSettings"]
# Alle Fragen-Felder im Export
Q_EXPORT_KEYS = ["type", "text", "order"] + Q_PATCH_KEYS


class ApiError(Exception):
    pass


class NC:
    def __init__(self, prefix):
        self.base = os.environ[f"{prefix}_URL"].rstrip("/") + API
        self.s = requests.Session()
        self.s.auth = (os.environ[f"{prefix}_USER"], os.environ[f"{prefix}_PASS"])
        self.s.headers.update({"OCS-APIRequest": "true", "Accept": "application/json"})

    def _result(self, r, method, path):
        if not r.ok:
            raise ApiError(f"{method} {path} -> {r.status_code}\n{r.text[:800]}")
        return r.json()["ocs"]["data"]

    def get(self, path):
        r = self.s.get(self.base + path, params={"format": "json"}, timeout=30)
        return self._result(r, "GET", path)

    def post(self, path, payload):
        r = self.s.post(self.base + path, params={"format": "json"},
                        json=payload, timeout=30)
        return self._result(r, "POST", path)

    def patch(self, path, payload):
        r = self.s.patch(self.base + path, params={"format": "json"},
                         json=payload, timeout=30)
        return self._result(r, "PATCH", path)

    def delete(self, path):
        r = self.s.delete(self.base + path, params={"format": "json"}, timeout=30)
        return self._result(r, "DELETE", path)


# --------------------------------------------------------------------------
# Export
# --------------------------------------------------------------------------
def export_form(nc, form_id):
    form = nc.get(f"/forms/{form_id}")
    src_questions = form.get("questions", [])
    questions = []
    for q in src_questions:
        entry = {k: q.get(k) for k in Q_EXPORT_KEYS}
        # Option-IDs und questionId sind instanzspezifisch -> weglassen
        entry["options"] = [
            {k: v for k, v in o.items() if k not in ("id", "questionId")}
            for o in (q.get("options") or [])
        ]
        entry["_source_question_id"] = q.get("id")   # nur für das ID-Mapping
        questions.append(entry)
    return {
        "source_id": form["id"],
        "settings": {k: form.get(k) for k in FORM_KEYS},
        "confirmation_question_index": next(
            (i for i, q in enumerate(src_questions)
             if q.get("id") == form.get("confirmationEmailQuestionId")), None),
        "questions": questions,
    }


# --------------------------------------------------------------------------
# Import
# --------------------------------------------------------------------------
def patch_props(nc, path, props, label, problems):
    """Alle Felder zusammen patchen; bei Fehler Feld für Feld, damit klar wird,
    welches Feld der Server ablehnt."""
    if not props:
        return
    try:
        nc.patch(path, {"keyValuePairs": props})
        return
    except ApiError:
        pass
    for key, value in props.items():
        try:
            nc.patch(path, {"keyValuePairs": {key: value}})
        except ApiError as e:
            shown = json.dumps(value, ensure_ascii=False)[:80]
            problems.append(f"{label}: Feld '{key}' = {shown} -> "
                            f"{str(e).splitlines()[0]}")


def save_mapping(source_id, new_id, src_qids, new_qids):
    mapping = {}
    if os.path.exists(MAPPING_FILE):
        with open(MAPPING_FILE, encoding="utf-8") as f:
            mapping = json.load(f)
    mapping[str(source_id)] = {
        "form": new_id,
        "questions": {str(a): b for a, b in zip(src_qids, new_qids)},
    }
    os.makedirs(os.path.dirname(MAPPING_FILE) or ".", exist_ok=True)
    with open(MAPPING_FILE, "w", encoding="utf-8") as f:
        json.dump(mapping, f, indent=2, ensure_ascii=False)


def import_form(nc, data, dry_run=False):
    s = data["settings"]
    form_props = {k: s[k] for k in FORM_KEYS if s.get(k) is not None}

    if dry_run:
        print("POST  /forms")
        print("PATCH /forms/<neu>", form_props)
        for i, q in enumerate(data["questions"]):
            props = {k: q[k] for k in Q_PATCH_KEYS if q.get(k) is not None}
            print(f"POST  questions [{i}]", {"type": q["type"], "text": q["text"]})
            print("PATCH question   ", props)
            if q.get("options"):
                print("POST  options    ", [o["text"] for o in q["options"]])
        print("\nDry-Run: nichts wurde gesendet.")
        return None, []

    problems = []
    new_id = nc.post("/forms", {})["id"]
    print(f"Formular angelegt: ID {new_id}")
    patch_props(nc, f"/forms/{new_id}", form_props, "Formular", problems)

    new_qids = []
    for i, q in enumerate(data["questions"]):
        label = f"Frage {i} ({q.get('text', '')[:40]})"
        nq = nc.post(f"/forms/{new_id}/questions",
                     {"type": q["type"], "text": q["text"]})
        qid = nq["id"]
        new_qids.append(qid)
        props = {k: q[k] for k in Q_PATCH_KEYS if q.get(k) is not None}
        patch_props(nc, f"/forms/{new_id}/questions/{qid}", props, label, problems)
        if q.get("options"):
            try:
                nc.post(f"/forms/{new_id}/questions/{qid}/options",
                        {"optionTexts": [o["text"] for o in q["options"]]})
            except ApiError as e:
                problems.append(f"{label}: Optionen -> {str(e).splitlines()[0]}")

    idx = data.get("confirmation_question_index")
    if idx is not None and s.get("confirmationEmailEnabled"):
        patch_props(nc, f"/forms/{new_id}",
                    {"confirmationEmailQuestionId": new_qids[idx]},
                    "Formular", problems)

    save_mapping(data.get("source_id"), new_id,
                 [q.get("_source_question_id") for q in data["questions"]],
                 new_qids)
    return new_id, problems


# --------------------------------------------------------------------------
# Prüfung
# --------------------------------------------------------------------------
def _norm(v):
    # PHP liefert leere Objekte teils als [], None und "" gelten als gleich
    if v in ({}, [], None, ""):
        return ""
    return v


def verify(nc, form_id, path):
    with open(path, encoding="utf-8") as f:
        expected = json.load(f)
    actual = export_form(nc, form_id)
    diffs = []

    for k in FORM_KEYS:
        a, b = expected["settings"].get(k), actual["settings"].get(k)
        if _norm(a) != _norm(b):
            diffs.append(f"Formular.{k}: erwartet {a!r}, gefunden {b!r}")

    eq, aq = expected["questions"], actual["questions"]
    if len(eq) != len(aq):
        diffs.append(f"Anzahl Fragen: erwartet {len(eq)}, gefunden {len(aq)}")
    for i, (e, a) in enumerate(zip(eq, aq)):
        for k in Q_EXPORT_KEYS:
            if _norm(e.get(k)) != _norm(a.get(k)):
                diffs.append(f"Frage {i} ({e.get('text', '')[:30]}).{k}: "
                             f"erwartet {e.get(k)!r}, gefunden {a.get(k)!r}")
        eo = [o.get("text") for o in e.get("options", [])]
        ao = [o.get("text") for o in a.get("options", [])]
        if eo != ao:
            diffs.append(f"Frage {i} ({e.get('text', '')[:30]}).options: "
                         f"erwartet {eo}, gefunden {ao}")
    if e_idx := expected.get("confirmation_question_index") != \
            actual.get("confirmation_question_index"):
        diffs.append("Bestätigungsmail-Frage weicht ab")

    if diffs:
        print(f"{len(diffs)} Abweichung(en):")
        for d in diffs:
            print(" -", d)
    else:
        print("Identisch: Einstellungen, alle Fragen, Optionen und technischen Namen.")
    return not diffs


# --------------------------------------------------------------------------
# main
# --------------------------------------------------------------------------
def main():
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    if len(args) < 2:
        sys.exit(__doc__)
    cmd, prefix = args[0], args[1]
    nc = NC(prefix)

    try:
        if cmd == "list":
            for f in nc.get("/forms"):
                print(f.get("id"), "|", f.get("title"))

        elif cmd == "export":
            data = export_form(nc, int(args[2]))
            os.makedirs(os.path.dirname(args[3]) or ".", exist_ok=True)
            with open(args[3], "w", encoding="utf-8") as f:
                json.dump(data, f, ensure_ascii=False, indent=2)
            print("gespeichert:", args[3])

        elif cmd == "import":
            with open(args[2], encoding="utf-8") as f:
                data = json.load(f)
            new_id, problems = import_form(nc, data, dry_run="--dry-run" in sys.argv)
            if new_id is not None:
                print("neue Form-ID:", new_id)
                if problems:
                    print(f"\n{len(problems)} Problem(e), Server hat abgelehnt:")
                    for p in problems:
                        print(" -", p)
                    sys.exit(1)
                print(f"Prüfen mit: python3 form_transfer.py verify {prefix} "
                      f"{new_id} {args[2]}")

        elif cmd == "verify":
            sys.exit(0 if verify(nc, int(args[2]), args[3]) else 1)

        elif cmd == "delete":
            form_id = int(args[2])
            title = nc.get(f"/forms/{form_id}").get("title")
            if input(f"Formular {form_id} '{title}' endgültig löschen? (ja/nein) ") == "ja":
                nc.delete(f"/forms/{form_id}")
                print("gelöscht")

        else:
            sys.exit(__doc__)
    except ApiError as e:
        sys.exit(str(e))


if __name__ == "__main__":
    main()