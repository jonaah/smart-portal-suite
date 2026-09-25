#!/usr/bin/env bash
#
# Smart Portal Suite - Version Bump Helper
# Usage: ./scripts/bump-version.sh <new_version>
# Example: ./scripts/bump-version.sh 0.3.0

set -e

NEW_VERSION="$1"

if [ -z "$NEW_VERSION" ]; then
    echo "Fehler: Keine Versionsnummer angegeben."
    echo "Verwendung: $0 <neue_version> (z. B. 0.3.0)"
    exit 1
fi

# Validiere SemVer Format
if ! [[ "$NEW_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[a-zA-Z0-9\.]+)?$ ]]; then
    echo "Fehler: '$NEW_VERSION' entspricht nicht dem Semantic Versioning Format (z. B. 0.3.0 oder 1.0.0-rc1)."
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
PLUGIN_FILE="$ROOT_DIR/smart-portal-suite.php"

echo "Aktualisiere Version auf: $NEW_VERSION in $PLUGIN_FILE..."

# Update Plugin Header Version
sed -i '' -E "s/(\* *Version: *)([0-9\.]+)/\1$NEW_VERSION/" "$PLUGIN_FILE"

# Update SPS_VERSION PHP constant
sed -i '' -E "s/(define\( *'SPS_VERSION', *')[0-9\.]+(' *\);)/\1$NEW_VERSION\2/" "$PLUGIN_FILE"

echo "✓ smart-portal-suite.php erfolgreich aktualisiert."

# Überprüfe Konsistenz
python3 -c "
import re, sys
with open('$PLUGIN_FILE', 'r') as f:
    c = f.read()
hv = re.search(r'\*\s*Version:\s*([0-9\.]+)', c).group(1)
cv = re.search(r'define\(\s*[\'\"]SPS_VERSION[\'\"],\s*[\'\"]([0-9\.]+)[\'\"]\s*\);', c).group(1)
if hv != '$NEW_VERSION' or cv != '$NEW_VERSION':
    print(f'Fehler beim Abgleich: Header={hv}, Constant={cv}, Target=$NEW_VERSION', file=sys.stderr)
    sys.exit(1)
print(f'✓ Versions-Konsistenz erfolgreich validiert: {hv}')
"

echo ""
echo "Nächste Schritte:"
echo "1. Dokumentiere die Änderungen in CHANGELOG.md unter '## [$NEW_VERSION] - $(date +%Y-%m-%d)'"
echo "2. Überprüfe die README.md auf Aktualität"
echo "3. Erstelle einen Commit: git commit -am \"chore: bump version to v$NEW_VERSION\""
echo "4. Erstelle einen Git-Tag (bei Release): git tag v$NEW_VERSION"
