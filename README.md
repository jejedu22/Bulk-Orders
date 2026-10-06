# Bulk-Orders

Application Symfony 6.4 de gestion de commandes groupées.

## Tests

Tests PHPUnit dans `tests/` : tests fonctionnels des parcours (connexion,
inscription, prise et annulation de commande, suivi, livraison, export,
administration, mot de passe oublié) et tests unitaires des services.
Chaque test part d'une base SQLite vide (`var/test.db`, schéma généré depuis
les entités) : aucun serveur MySQL n'est nécessaire.

Avec PHP 8.3 (ou plus récent) et les extensions `intl` et `pdo_sqlite` :

```bash
composer install
php vendor/bin/phpunit
```

Sans PHP 8.3 en local, via Docker (image avec `intl` et `pdo_sqlite`) :

```bash
docker run --rm -v "$PWD":/app -w /app chialab/php:8.3 php vendor/bin/phpunit
```

Les dépréciations Symfony sont listées en fin d'exécution sans faire échouer
les tests (`SYMFONY_DEPRECATIONS_HELPER` dans `phpunit.xml.dist`).

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

- `app` : PHP 8.3 + Apache, exposé uniquement via Traefik (`Host(APP_HOST)`, HTTPS).
- `db` : MySQL 8.4, sur un réseau interne non exposé.

Volumes : `db` (données MySQL), `uploads` (logo téléversé), `sessions`.

### Base de données

Au démarrage (`RUN_MIGRATIONS=1`, par défaut) :

- base vide : le schéma est créé depuis les entités et toutes les migrations
  sont marquées comme exécutées (l'historique ne rejoue pas depuis zéro) ;
- base existante : `doctrine:migrations:migrate`. Une table `migration_versions`
  au format de doctrine/migrations 2.x (versions `20201024132528`…) est
  convertie automatiquement au format 3.x (`DoctrineMigrations\Version…`),
  sans rejouer les migrations déjà passées.

Sauvegarde :

```bash
docker compose --env-file .env.docker exec -T db \
    sh -c 'mysqldump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' > dump.sql
```

Sauvegarde quotidienne (crontab) :

```bash
0 3 * * * cd /chemin/Bulk-Orders && docker compose --env-file .env.docker exec -T db sh -c 'mysqldump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' | gzip > /backups/bulkorders-$(date +\%F).sql.gz
```

### Commandes utiles

```bash
docker compose --env-file .env.docker logs -f app
docker compose --env-file .env.docker exec -u www-data app php bin/console <commande>
```

### E-mails

Les e-mails (commande enregistrée, paiement reçu, mot de passe oublié) partent
via `MAILER_DSN` dans `.env.docker`. Après modification, recréer le conteneur :
`docker compose --env-file .env.docker up -d`.

Tester l'envoi (affiche l'erreur exacte du serveur SMTP en cas d'échec) :

```bash
docker compose --env-file .env.docker exec -u www-data app php bin/console app:mail:test vous@example.org
```

Exemples de `MAILER_DSN` :

- Gmail : `gmail+smtp://adresse%40gmail.com:MOTDEPASSEAPPLICATION@default`
  — un **mot de passe d'application** est obligatoire (compte Google →
  Sécurité → Validation en deux étapes → Mots de passe des applications),
  à saisir **sans les espaces** ;
- Infomaniak : `smtp://adresse%40domaine.fr:MOTDEPASSE@mail.infomaniak.com:587`
  — identifiant = adresse complète de la boîte ; avec la double
  authentification, utiliser un mot de passe d'application. L'expéditeur
  doit être cette boîte : renseigner `MAILER_FROM` avec la même adresse ;
- autre fournisseur : `smtp://utilisateur:motdepasse@smtp.example.org:587`.

Les caractères spéciaux de l'identifiant et du mot de passe doivent être
encodés (`@` → `%40`, `:` → `%3A`, `/` → `%2F`, `#` → `%23`, `%` → `%25`).

Si le fournisseur refuse un expéditeur différent du compte, renseigner
`MAILER_FROM` avec l'adresse du compte : l'e-mail de contact de la
configuration devient alors l'adresse de réponse.

Un échec d'envoi n'interrompt pas la commande : l'utilisateur est averti et
l'erreur apparaît dans `docker compose logs app`.

## Migration depuis l'ancienne installation (Apache + MySQL 5.7)

Deux règles :

- **importer le dump avant le premier démarrage de `app`**, sinon un schéma
  vide est créé ;
- Traefik prend les ports 80/443 : si l'ancien Apache est sur le même
  serveur, il faut l'arrêter au moment de la bascule.

### 1. Préparation (sans coupure)

```bash
# Traefik
git clone https://github.com/jejedu22/traefik.git && cd traefik
cp .env.example .env        # ACME_EMAIL, TRAEFIK_DASHBOARD_HOST, TRAEFIK_DASHBOARD_AUTH
# ne pas le démarrer tant que l'ancien Apache occupe 80/443

# Application
cd .. && git clone https://github.com/jejedu22/Bulk-Orders.git && cd Bulk-Orders
cp .env.docker.example .env.docker
```

Renseigner `.env.docker` :

- `APP_HOST` : le domaine actuel, tel que tapé dans le navigateur (avec `www.` le cas échéant) ;
- `APP_SECRET` : `openssl rand -hex 32` ;
- `MYSQL_*` : nouveaux mots de passe ;
- `MAILER_DSN` : repris de l'ancienne config (`.env.local` ou variables Apache),
  par ex. `gmail+smtp://USER:MOT_DE_PASSE_APPLICATION@default`.

Construire l'image et démarrer **uniquement** la base :

```bash
docker compose --env-file .env.docker build
docker compose --env-file .env.docker up -d db
```

### 2. Répétition à blanc (recommandé)

Faire les étapes 3.b et 3.c avec un dump pris à chaud, pour valider l'import
dans MySQL 8.4 et l'état des migrations, puis repartir de zéro :

```bash
docker compose --env-file .env.docker down -v
docker compose --env-file .env.docker up -d db
```

### 3. Bascule (quelques minutes de coupure)

**a. Figer l'ancienne application** (page de maintenance ou arrêt du vhost)
pour qu'aucune commande n'arrive pendant l'export.

**b. Exporter la base et le logo** depuis l'ancienne installation :

```bash
mysqldump -u <user> -p --single-transaction --no-tablespaces \
    --default-character-set=utf8mb4 <base> > dump.sql
tar czf logo.tgz -C /chemin/ancienne/app/public/uploads logo
```

**c. Importer dans le nouveau MySQL** :

```bash
docker compose --env-file .env.docker exec -T db \
    sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < dump.sql
```

Contrôler les migrations (table `migration_versions`, dernière version
attendue : `20210218223845`) :

```bash
docker compose --env-file .env.docker run --rm --no-deps -e RUN_MIGRATIONS=0 app \
    sh -c 'php bin/console doctrine:migrations:sync-metadata-storage -n && php bin/console doctrine:migrations:status'
```

(`sync-metadata-storage` convertit la table au format de doctrine/migrations 3,
comme le fait le démarrage de l'application.)

- `New Migrations: 0` : rien à faire ;
- sinon elles seront appliquées au démarrage. Vérifier qu'elles correspondent
  à des changements réellement absents de la base ; si le schéma est déjà à
  jour, les marquer comme exécutées :
  `php bin/console doctrine:migrations:version --add --all -n`
  (même commande `run --rm --no-deps -e RUN_MIGRATIONS=0 app` que ci-dessus).

**d. Arrêter l'ancien Apache** (s'il est sur le même serveur) **et démarrer
Traefik** :

```bash
sudo systemctl stop apache2 && sudo systemctl disable apache2
cd ../traefik && docker compose up -d
```

**e. DNS** : si le serveur change, faire pointer le domaine vers la nouvelle
IP (baisser le TTL la veille). Le certificat Let's Encrypt n'est délivré
qu'une fois le DNS en place.

**f. Démarrer l'application et restaurer le logo** :

```bash
cd ../Bulk-Orders
docker compose --env-file .env.docker up -d
tar xzf logo.tgz
docker compose --env-file .env.docker cp logo/. app:/var/www/html/public/uploads/logo/
docker compose --env-file .env.docker exec app chown -R www-data:www-data public/uploads
```

### 4. Vérifications

```bash
docker compose --env-file .env.docker logs app | tail -30   # « Already at the latest version »
docker logs traefik 2>&1 | grep -i acme                     # certificat obtenu
```

Dans le navigateur : HTTPS valide, connexion avec un compte existant,
commandes / produits / jours de distribution présents, logo affiché, exports,
envoi d'un mail (réinitialisation de mot de passe ou contact).
Les utilisateurs devront se reconnecter (sessions non migrées).

### 5. Retour arrière

Tant que l'ancienne base n'a pas été modifiée :

```bash
cd traefik && docker compose down
sudo systemctl enable --now apache2
```

(et remettre l'ancien DNS s'il a changé). Conserver l'ancienne installation
et `dump.sql` quelques semaines.
