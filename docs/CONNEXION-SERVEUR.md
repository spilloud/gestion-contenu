# Connexion SSH au serveur Contenu

Les identifiants SSH **ne se mettent pas dans le dépôt Git**. Ils se configurent sur la machine qui exécute les commandes (PC local, agent Cursor, etc.).

## Fichier à créer ou modifier

Chemin : `~/.ssh/config` (Windows : `C:\Users\<vous>\.ssh\config`)

Copier le modèle depuis [`deploy/ssh-config.example`](../deploy/ssh-config.example) et adapter `IdentityFile`.

## Déploiement rapide

```bash
ssh Contenu-Osmose "cd '/home/debian/Systeme de formation/contenu' && git pull origin main && docker exec contenu_php php bin/console cache:clear --env=prod --no-interaction"
```

Voir aussi [`DEPLOYMENT.md`](../DEPLOYMENT.md).
