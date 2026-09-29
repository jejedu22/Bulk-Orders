# Bulk-Orders

Application Symfony 4.4 de gestion de commandes groupées.

## Déploiement Docker (derrière Traefik)

L'application est prévue pour tourner derrière le reverse proxy
[traefik](https://github.com/jejedu22/traefik), qui fournit le réseau Docker
externe `proxy`, la terminaison TLS (Let's Encrypt) et le middleware `default@file`.

Prérequis : Traefik démarré (`docker compose up -d` dans son dépôt) et le DNS
du domaine de l'application pointant vers le serveur.

```bash
cp .env.docker.example .env.docker   # puis renseigner les valeurs
docker compose --env-file .env.docker up -d --build
```

> Les variables de Compose sont dans `.env.docker` : le fichier `.env` est celui
> de Symfony, il ne doit pas contenir de secrets.

Services :

- `app` : PHP 7.4 + Apache, exposé uniquement via Traefik (`Host(APP_HOST)`, HTTPS).
- `db` : MySQL 8.4, sur un réseau interne non exposé.

Volumes : `db` (données MySQL), `uploads` (logo téléversé), `sessions`.

### Base de données

Au démarrage (`RUN_MIGRATIONS=1`, par défaut) :

- base vide : le schéma est créé depuis les entités et toutes les migrations
  sont marquées comme exécutées (l'historique ne rejoue pas depuis zéro) ;
- base existante : `doctrine:migrations:migrate`.

Reprise d'une base existante :

```bash
docker compose --env-file .env.docker up -d db
docker compose --env-file .env.docker exec -T db \
    sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < dump.sql
docker compose --env-file .env.docker up -d
```

Sauvegarde :

```bash
docker compose --env-file .env.docker exec -T db \
    sh -c 'mysqldump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' > dump.sql
```

Le logo existant se copie dans le volume avec
`docker compose --env-file .env.docker cp logo.png app:/var/www/html/public/uploads/logo/`.

### Commandes utiles

```bash
docker compose --env-file .env.docker logs -f app
docker compose --env-file .env.docker exec -u www-data app php bin/console <commande>
```
