#!/bin/bash
# Sync Asana ↔ Lucy toutes les 15 minutes (contenu.osmose-marketing.ch)
docker exec contenu_php php bin/console app:asana:sync-linked-tasks --env=prod --no-interaction >> /var/log/contenu-asana-sync.log 2>&1
