#!/bin/bash
# quarantine_notify.sh 
# Infomail an Mailkonten, wenn sich Mails in Quarantäne befinden.

QUARANTINE_DIR="/dein/quarantäne/verzeichnis"
MAIL_FROM="john@doe.com" # Keine Umlaute
DISPLAY_NAME="Quarantäne System"
MAIL_SUBJECT="Sie haben Mails in Quarantäne"
QUARANTINE_URL="https://deine.quarantaene-url.com"
LOGFILE="/var/log/quarantine_notify.log"

log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $*" | tee -a "$LOGFILE"
}

log "=== QUARANTÄNE-NOTIFY ==="

[[ ! -d "$QUARANTINE_DIR" ]] && { log "FEHLER: $QUARANTINE_DIR existiert nicht!"; exit 1; }

declare -A counts
shopt -s nullglob

for file in "$QUARANTINE_DIR"/QUARANTINE_*; do
    [[ -f "$file" ]] || continue
    log "Prüfe Datei: $file"

    recipient=$(sed -n '
        /^From [^ ]\+/ { next }
        /^$/ { h; b }
        H
        ${
            x
            /To:/I!b
            s/.*To:[[:space:]]*\(.*\)/\1/I
            s/.*<\([^@]\+\)[^>]*>.*/\1/
            s/.*\([^[:space:]<@]\+@[[:alnum:].-]\+\.[[:alpha:]]\{2,\}\).*/\1/
            p
            q
        }
    ' "$file")

    [[ -z "$recipient" ]] && recipient=$(grep -i "^To:" "$file" | head -1 | grep -oE '[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}' | head -1)

    [[ -z "$recipient" ]] && log "  → KEIN To: → überspringen" && continue

    log "  → To:-Empfänger: $recipient"
    ((counts["$recipient"]++))
done

[[ ${#counts[@]} -eq 0 ]] && log "Keine Mails mit To: → Ende." && exit 0

log "ZUSAMMENFASSUNG:"
for r in "${!counts[@]}"; do log "   $r → ${counts[$r]} Mail(s)"; done

for recipient in "${!counts[@]}"; do
    count=${counts[$recipient]}

    {
        echo "From: =?UTF-8?Q?Quarant=C3=A4ne_System?= <$MAIL_FROM>"
        echo "To: $recipient"
        echo "Subject: =?UTF-8?Q?Quarant=C3=A4ne=3A_Sie_haben_Mails_in_Quarant=C3=A4ne?="
        echo "Content-Type: text/plain; charset=UTF-8"
        echo "Date: $(date -R)"
        echo
        echo "Sie haben $count Mail(s) in Quarantäne."
        echo
        echo "Prüfen Sie die Mails auf $QUARANTINE_URL"
        echo
        echo "Diese Nachricht wurde automatisch generiert – bitte nicht antworten."
        echo "Quarantäne-System $(hostname)"
        echo "--"
        echo "IP: $(hostname -I | awk '{print $1}')"
    } | /usr/sbin/sendmail -t -i -f "$MAIL_FROM"

    if [ $? -eq 0 ]; then
        log "ZUGESTELLT → $recipient ($count Mails)"
    else
        log "FEHLER → $recipient (sollte aber jetzt nicht mehr passieren)"
    fi
done

log "=== FERTIG – Alle Mails mit quarantaene@... zugestellt – KEIN BOUNCE! ==="
