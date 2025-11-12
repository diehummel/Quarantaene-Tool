#!/bin/bash
# quarantine_clean.sh
# Löscht alle Dateien in /opt/quarantine, die KEIN "To:" enthalten und alle älter 30 Tage
# Nutzung: sudo ./quarantine_clean.sh   (oder als root/crontab)

QUARANTINE_DIR="dein/quarantäne/verzeichnis"

find $QUARANTINE_DIR -mtime +30 -exec rm {} \;

if [[ ! -d "$QUARANTINE_DIR" ]]; then
    echo "Fehler: Verzeichnis $QUARANTINE_DIR existiert nicht!"
    exit 1
fi

deleted=0
kept=0

echo "Durchsuche $QUARANTINE_DIR nach Dateien ohne 'To:' Header..."

find "$QUARANTINE_DIR" -maxdepth 1 -type f -print0 | while IFS= read -r -d '' file; do
    # grep -q = quiet, -F = fixed string, -m1 = stop nach erstem Treffer
    if grep -q -F -m1 "To:" "$file" 2>/dev/null; then
        echo "Behalte: $file (enthält To:)"
        ((kept++))
    else
        echo "LÖSCHE: $file (kein To: gefunden)"
        rm -f "$file"
        ((deleted++))
    fi
done

echo "Fertig: $deleted Datei(en) gelöscht, $kept Datei(en) behalten."
