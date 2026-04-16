# Connexion n8n -> API Contenu (lecture seule)

## Endpoint

- URL: `https://contenu.osmose-marketing.ch/api/ai/dashboard-kpi`
- Methode: `GET`
- Authentification:
  - `Authorization: Bearer <AI_API_TOKEN>`
  - ou `X-API-Key: <AI_API_TOKEN>`

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
