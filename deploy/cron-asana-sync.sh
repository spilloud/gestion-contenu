#!/bin/bash
# Sync Asana ↔ Lucy toutes les 15 minutes (contenu.osmose-marketing.ch)
LOG="/home/debian/contenu-asana-sync.log"
docker exec contenu_php php bin/console app:asana:sync-linked-tasks --env=prod --no-interaction >> "$LOG" 2>&1
