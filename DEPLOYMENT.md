# Déploiement - Système de gestion des contenus

## Prérequis

- Docker et Docker Compose
- Accès SSH au serveur 83.228.217.159
- DNS : `contenu.osmose-marketing.ch` → 83.228.217.159

## Ordre de déploiement

1. **Créer le réseau partagé** (une seule fois) :
   ```bash
   docker network create osmose_shared
   ```

2. **Démarrer GPEC** (formation) en premier pour exposer les ports 80/443 :
   ```bash
   cd /chemin/vers/gpec
   docker compose up -d
   ```

3. **Obtenir le certificat SSL** pour contenu.osmose-marketing.ch :
   ```bash
   # Sur le serveur, avec certbot (à adapter selon votre setup)
   certbot certonly --webroot -w /var/www/certbot -d contenu.osmose-marketing.ch
   # Ou si certbot utilise le challenge sur le port 80 déjà utilisé par nginx :
   # Arrêter temporairement nginx, lancer certbot standalone, puis redémarrer
   ```

4. **Démarrer Contenu** :
   ```bash
   cd /chemin/vers/contenu
   export CONTENU_DB_PASSWORD="votre_mot_de_passe_fort"
   docker compose up -d --build
   ```

5. **Installer les dépendances PHP** (dans le conteneur) :
   ```bash
   docker exec -it contenu_php composer install --no-dev --optimize-autoloader
   ```

6. **Lancer les migrations** :
   ```bash
   docker exec -it contenu_php php bin/console doctrine:migrations:migrate --no-interaction
   ```

7. **Créer un utilisateur admin** :
   ```bash
   docker exec -it contenu_php php bin/console app:create-admin email@example.com VotreMotDePasse "Nom Admin"
   ```

## Variables d'environnement

Créer un fichier `.env` à la racine du projet contenu ou définir :
- `CONTENU_DB_PASSWORD` : mot de passe PostgreSQL pour la base contenu

## Connexion SSH (agents / développeurs)

- Modèle : [`deploy/ssh-config.example`](deploy/ssh-config.example)
- Guide : [`docs/CONNEXION-SERVEUR.md`](docs/CONNEXION-SERVEUR.md)

## Mise à jour en production (git déjà configuré sur le serveur)

Sur le serveur (utilisateur typique : `debian`, dépôt cloné sous le chemin avec espace ci-dessous) :

```bash
cd '/home/debian/Systeme de formation/contenu'
git pull origin main
docker exec contenu_php php bin/console cache:clear --env=prod --no-interaction
```

En local, si `~/.ssh/config` définit un hôte (ex. `Contenu-Osmose` → `83.228.217.159`, user `debian`), tu peux enchaîner :  
`ssh Contenu-Osmose 'cd '\''/home/debian/Systeme de formation/contenu'\'' && git pull origin main && docker exec contenu_php php bin/console cache:clear --env=prod --no-interaction'`

Exemple de bloc à ajouter dans `~/.ssh/config` (adapter la ligne `IdentityFile` selon ta clé) :
```sshconfig
Host Contenu-Osmose
  HostName 83.228.217.159
  User debian
  IdentityFile ~/.ssh/<ta_cle>
```

## Vérification

- https://contenu.osmose-marketing.ch → doit afficher la page de connexion
- https://formation.osmose-marketing.ch → doit continuer à fonctionner normalement

## En cas de problème SSL

Si le certificat pour contenu.osmose-marketing.ch n'existe pas encore, le nginx GPEC ne démarrera pas (fichier cert manquant). Options :

1. **Obtenir le certificat avant de monter la config** :
   ```bash
   certbot certonly --webroot -w /chemin/vers/gpec/public -d contenu.osmose-marketing.ch
   ```
   Puis redémarrer le nginx GPEC.

2. **Désactiver temporairement** : dans `gpec/docker-compose.yml`, commenter la ligne qui monte `contenu-ssl.conf`. Le système formation continuera de fonctionner, mais contenu ne sera pas accessible en HTTPS jusqu'à configuration du certificat.

3. **Certificat existant (formation)** : si vous avez déjà un certificat pour formation.osmose-marketing.ch, vous pouvez étendre avec :
   ```bash
   certbot certonly --expand -d formation.osmose-marketing.ch -d contenu.osmose-marketing.ch
   ```
