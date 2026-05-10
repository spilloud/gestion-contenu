# Connexion n8n -> API Contenu (lecture seule)

## Configuration dans l’outil (recommandé)

En tant qu’**administrateur**, ouvrir dans le navigateur :

`https://contenu.osmose-marketing.ch/admin/integration-api`

(Ancienne URL conservée : `/admin/integration-luc`.)

Vous pouvez y **générer un token**, définir les **IP autorisées** (VPS n8n) et copier l’URL exacte pour n8n.

Les valeurs enregistrées là ont la **priorité** sur le fichier `.env` du serveur (`AI_API_TOKEN`, `AI_API_ALLOWED_IPS`).

### Dépannage : **403 Forbidden** alors que le token est bon

Ce n’est **pas** Laravel : l’app est **Symfony**. Un **401** = token absent ou invalide. Un **403** sur cet endpoint = le token est accepté mais l’**IP du client** n’est pas dans la liste **IP autorisées**.

- Si vous avez renseigné des IP (page Intégration API ou `AI_API_ALLOWED_IPS`), il faut y inclure **l’IPv4 et l’IPv6** du serveur qui appelle (ex. Lucy sur n8n peut sortir en **IPv6**).
- Exemple IPv6 à ajouter si c’est l’IP vue côté serveur : `2001:1600:13:101::19e9` (à vérifier avec votre hébergeur ; plusieurs IP possibles).
- Laisser le champ **IP** vide = aucune restriction par IP (seul le token protège).

Après déploiement récent, la comparaison **normalise IPv4/IPv6** et prend en compte la chaîne proxy (`X-Forwarded-For`) lorsque `TRUSTED_PROXIES` est configuré.

Le frontal **nginx** du projet applique aussi **`real_ip`** : sinon PHP ne voyait souvent que l’IP **Docker interne** (172.x), pas l’IP publique de Lucy — la whitelist ne pouvait jamais matcher. Après mise à jour nginx : `docker compose restart contenu_nginx` (ou équivalent).

### Débogage rapide (403 avec bon token)

Ce n’est **pas Laravel** : pas de `php artisan`. Sur le serveur :

```bash
docker exec contenu_php php bin/console cache:clear --env=prod --no-interaction
docker exec contenu_php php bin/console cache:warmup --env=prod --no-interaction
```

Pour voir **quelles IPs Symfony reçoit** dans la réponse JSON du 403 (temporaire), dans `.env` ou `docker-compose` du service `php` :

```env
AI_API_DEBUG_IP=1
```

Puis redémarrer le conteneur `contenu_php`. La réponse 403 contiendra `reason` et `debug.seenClientIps` / `debug.allowedList`. **Remettre à 0 ensuite.**

Les logs Symfony (`var/log/prod.log` ou équivalent) enregistrent aussi une ligne **warning** avec les IP vues à chaque 403 IP.

---

## Endpoints

### KPI (léger)

- URL: `https://contenu.osmose-marketing.ch/api/ai/dashboard-kpi`
- Methode: `GET`
- Authentification:
  - `Authorization: Bearer <AI_API_TOKEN>`
  - ou `X-API-Key: <AI_API_TOKEN>`

### Export métier complet (Lucy)

- URL: `https://contenu.osmose-marketing.ch/api/ai/full-export`
- Methode: `GET`
- Authentification: identique (`Bearer` ou `X-API-Key`), même whitelist IP que les autres routes `/api/ai`.

Réponse JSON en trois blocs :

- `reference` : formats, statuts, community managers, utilisateurs (sans secrets), clients (y compris archivés), pages client (infos importantes, idées, todos).
- `contents` : posts planifiés avec détail vidéo (URLs, fichiers, miniatures, légende, sous-titres), liaisons Asana (`taskGid`, `subtitlesTaskGid`), commentaires avec auteur et dates.

**Exclus volontairement (sécurité / secrets)** : hash de mot de passe, jetons de réinitialisation, et toute config d’API interne — comme pour le reste de l’API Lucy.

**Pagination** (liste `contents` uniquement, pour limiter la taille des réponses) :

- `page` (défaut `1`)
- `per_page` entre **50** et **500** (défaut **200**)
- `include_contents` : `1` / `true` pour charger les posts (défaut), `0` / `false` pour ne renvoyer que `reference` + `meta.contentsTotal` (utile pour rafraîchir catalogues sans tout recharger).

`meta.contentsTotal` indique le nombre total de posts ; enchaîner les pages jusqu’à `meta.contentsTotalPages` pour tout récupérer dans n8n.

**Charge serveur** : un export complet paginé peut être lourd ; prévoir des appels espacés ou `include_contents=0` quand seuls les référentiels changent.

## Variables d'environnement a definir cote serveur

- `AI_API_TOKEN` (obligatoire): token secret long et unique
- `AI_API_ALLOWED_IPS` (optionnel): liste d'IPs autorisees separees par virgules

Exemple:

```bash
AI_API_TOKEN="METTRE_UN_TOKEN_LONG_RANDOM"
AI_API_ALLOWED_IPS="203.0.113.10"
```

## Exemple de credential n8n (HTTP Request)

- Authentication: `Header Auth` (ou `Bearer Auth`)
- Header name: `Authorization`
- Header value: `Bearer METTRE_UN_TOKEN_LONG_RANDOM`

## Exemple de test curl

```bash
curl -H "Authorization: Bearer METTRE_UN_TOKEN_LONG_RANDOM" \
  https://contenu.osmose-marketing.ch/api/ai/dashboard-kpi
```

## Structure JSON retournee

- `meta.generatedAt`, `meta.monthStart`, `meta.monthEnd`
- `kpi.postsThisMonth`
- `kpi.publishedThisMonth`
- `kpi.completionRate`
- `kpi.overdue`
- `kpi.upcoming7Days`
- `breakdowns.byStatus[]`
- `breakdowns.topClients[]`
- `nextPosts[]`
