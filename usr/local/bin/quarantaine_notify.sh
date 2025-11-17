#!/bin/bash
# quarantine_notify.sh 
# Infomail an Mailkonten, wenn sich Mails in Quarantäne befinden.

QUARANTINE_DIR="/opt/quarantine"
MAIL_FROM="quarantaene@john.doe"
QUARANTINE_URL="https://deine.quarantaene.url"
LOGFILE="/var/log/quarantine_notify.log"

# === DOMAINS AN DIE ZUGESTELLT WERDEN DARF ===
ALLOWED_DOMAINS=(
    "johndoe.com"
    # weitere hier hinzufügen
)

log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $*" | tee -a "$LOGFILE"
}

log "=== QUARANTÄNE-NOTIFY START (To-Header + Whitelist) ==="

[[ ! -d "$QUARANTINE_DIR" ]] && { log "FEHLER: Verzeichnis fehlt"; exit 1; }

declare -A counts
shopt -s nullglob

for file in "$QUARANTINE_DIR"/QUARANTINE_*; do
    [[ -f "$file" ]] || continue
    log "Prüfe: $(basename "$file")"

    # To:-Header extrahieren
    recipient=$(grep -i "^To:" "$file" | head -1 | \
        grep -oE '[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}' | head -1)

    # Falls nichts gefunden
    [[ -z "$recipient" ]] && { log "  → Keine E-Mail-Adresse im To:-Header → ignoriert"; continue; }

    # Domain prüfen
    domain="${recipient#*@}"
    domain="${domain,,}"

    if ! printf '%s\n' "${ALLOWED_DOMAINS[@]}" | grep -Fxq "$domain"; then
        log "  → Fremde Domain im To: $recipient → ignoriert (kein Backscatter!)"
        continue
    fi

    log "  → GÜLTIG: $recipient"
    ((counts["$recipient"]++))
done

# Keine gültigen Empfänger
(( ${#counts[@]} == 0 )) && {
    log "Keine Mails für diese Domains → nichts zu versenden"
    log "=== ENDE ==="
    exit 0
}

log "ZUSAMMENFASSUNG (nur deine Domains):"
for r in "${!counts[@]}"; do log "  $r → ${counts[$r]} Mail(s)"; done

# Benachrichtigungen versenden
for recipient in "${!counts[@]}"; do
    count=${counts[$recipient]}
    {
        echo "From: =?UTF-8?Q?Quarant=C3=A4ne_System?= <$MAIL_FROM>"
        echo "To: $recipient"
        echo "Subject: =?UTF-8?Q?Sie_haben_Mails_in_Quarant=C3=A4ne?="
        echo "Content-Type: text/plain; charset=UTF-8"
        echo "Date: $(date -R)"
        echo
        echo "Hallo,"
        echo
        echo "Sie haben aktuell $count Mail(s) in der Quarantäne die an sie adressiert sind."
        echo
        echo "Bitte prüfen unter: $QUARANTINE_URL"
        echo
        echo "Diese Nachricht wurde automatisch erstellt – bitte nicht antworten."
        echo
        echo "Quarantäne-System $(hostname)"
    } | /usr/sbin/sendmail -t -i -f "$MAIL_FROM"

    [ $? -eq 0 ] && log "ZUGESTELLT → $recipient ($count Mails)" || log "FEHLER → $recipient"
done

log "=== FERTIG – Nur deine User wurden benachrichtigt ==="
exit 0
