# WeCare — Guide de démo

SaaS de gestion pour services d'aide à domicile — PHP 8.4 · Symfony 8 · Doctrine · MariaDB 11.4 · Docker · Caddy.

## Architecture

```
Navigateur → Caddy (reverse proxy, HTTPS) → PHP-FPM (Symfony) → MariaDB
```

| Service    | Image                          | Rôle                                                    |
|------------|--------------------------------|---------------------------------------------------------|
| `php`      | `Dockerfile` (PHP 8.4 FPM)     | Application Symfony, migrations au démarrage            |
| `caddy`    | `caddy:2.10`                   | Serveur web, certificat Let's Encrypt automatique (ACME)|
| `database` | `mariadb:11.4`                 | Base de données (volume persistant `database_data`)     |
| `mailer`   | `axllent/mailpit` (dev seulement) | Capture des e-mails                                  |

Fichiers :
- `compose.yaml` — configuration de **production** (images construites, aucun montage du code)
- `compose.override.yaml` — surcharge **dev**, chargée automatiquement en local (code monté en volume, port BDD exposé)
- `Dockerfile` — cibles `app_dev`, `app_prod`, `caddy_prod`
- `docker/` — configuration PHP, Caddyfile, script de démarrage, init MariaDB

## Prérequis

- Docker Desktop (ou Docker Engine) avec Docker Compose v2
- Git

Aucune installation locale de PHP, Composer ou MariaDB n'est nécessaire.

## Installation (développement)

```bash
git clone https://github.com/Manon-pinto/WeCare-App.git
cd WeCare-App
cp .env.example .env          # puis générer un APP_SECRET
docker compose up -d --build
docker compose exec php bin/console doctrine:fixtures:load --no-interaction
```

Accès : http://localhost:8080 (port défini par `HTTP_PORT` dans `.env`).
MariaDB est accessible depuis l'hôte sur le port `3307` (utilisateur `wecare` / `DB_PASSWORD`).

Au démarrage, le conteneur `php` installe les dépendances si besoin, attend la base de données puis **applique les migrations** automatiquement.

Commandes utiles :

```bash
docker compose logs -f php                        # logs applicatifs
docker compose exec php bin/console <commande>    # console Symfony
docker compose down                               # arrêt (les données sont conservées)
docker compose down -v                            # arrêt + suppression de la base
```

> Sans Docker, `symfony serve --no-tls` reste possible avec un MariaDB/MySQL local (adapter `DATABASE_URL` dans `.env`).

### Variables d'environnement (`.env`)

| Variable           | Description                                          | Exemple                           |
|--------------------|------------------------------------------------------|-----------------------------------|
| `APP_SECRET`       | Secret Symfony (CSRF, sessions) — **unique par environnement** | `php -r "echo bin2hex(random_bytes(16));"` |
| `DB_PASSWORD`      | Mot de passe de l'utilisateur MariaDB `wecare`       | —                                 |
| `DB_ROOT_PASSWORD` | Mot de passe root MariaDB                            | —                                 |
| `HTTP_PORT` / `HTTPS_PORT` | Ports exposés par Caddy                      | `8080` / `8443` en dev, `80` / `443` en prod |
| `DB_PORT`          | Port MariaDB exposé sur l'hôte (dev)                 | `3307`                            |
| `SERVER_NAME`      | Domaine servi par Caddy (prod)                       | `vps114753.serveur-vps.net`       |
| `COMPOSE_FILE`     | `compose.yaml` en prod pour ignorer la surcharge dev | —                                 |

Le fichier `.env` n'est **jamais commité** (`.gitignore`) ; `.env.example` sert de modèle.

---

## Tests

Pyramide de tests : **55 tests unitaires** (`tests/Unit`) + **24 tests d'intégration** (`tests/Integration`).

| Suite | Type | Contenu |
|---|---|---|
| `unit` | `TestCase` | Enums, entités (Utilisateur, Intervenant, Bénéficiaire, Intervention) |
| `integration` | `KernelTestCase` | Repositories contre une vraie base MariaDB : `findDisponibles`, isolation **multi-tenant** (`findAllWithDetails` par administrateur), détection de **chevauchement** d'interventions |
| `integration` | `WebTestCase` | Requêtes HTTP simulées : pages publiques, redirection `/login` sans session, **403** pour un rôle non autorisé, API de connexion, liste patients filtrée par organisation |

```bash
# Tout : recrée la base wecare_test (migrations + fixtures) puis lance les tests
docker compose exec php composer test

# Ou séparément
docker compose exec php composer test:db
docker compose exec php bin/phpunit --testsuite unit
docker compose exec php bin/phpunit --testsuite integration

# Analyse statique (PHPStan niveau 5)
docker compose exec php vendor/bin/phpstan analyse
```

Les tests tournent dans le même conteneur que l'application : résultats identiques quelle que soit la machine.

---

## Intégration et déploiement continus (GitHub Actions)

`.github/workflows/ci.yml` s'exécute à chaque push :

```
push → test (unitaires + intégration, MariaDB) ┐
     → quality (PHPStan, lint container/Twig/YAML) ┴→ build (images Docker prod) → deploy (master)
```

Chaque étape ne démarre que si les précédentes réussissent. Le déploiement (SSH vers le VPS) est activé en configurant dans GitHub (*Settings → Secrets and variables → Actions*) :
- la variable `DEPLOY_ENABLED=true`
- les secrets `VPS_HOST`, `VPS_USER`, `VPS_SSH_KEY`

---

## Déploiement en production (VPS)

| # | Étape | Commandes |
|---|---|---|
| 1 | Connexion | `ssh debian@vps114753.serveur-vps.net` |
| 2 | Prérequis | `apt install docker.io docker-compose-plugin git` |
| 3 | Clone | `git clone https://github.com/Manon-pinto/WeCare-App.git /var/www/wecare && cd /var/www/wecare` |
| 4 | Configuration | `cp .env.example .env` puis : `APP_ENV=prod`, `COMPOSE_FILE=compose.yaml`, `APP_SECRET`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `SERVER_NAME`, `HTTP_PORT=80`, `HTTPS_PORT=443` |
| 5 | Build & démarrage | `IMAGE_TAG=$(git rev-parse --short HEAD) docker compose up -d --build` |
| 6 | Migrations | appliquées au démarrage du conteneur `php` (ou `docker compose exec php bin/console doctrine:migrations:migrate`) |
| 7 | HTTPS | Caddy obtient le certificat Let's Encrypt au premier démarrage (ports 80/443 ouverts, DNS pointant vers le VPS) |

Données de démonstration (**jamais sur une vraie production**) :

```bash
docker compose --profile demo run --rm fixtures
```

### Mise à jour

```bash
cd /var/www/wecare
git pull --ff-only origin master
IMAGE_TAG=$(git rev-parse --short HEAD) docker compose up -d --build
```

Chaque déploiement produit des images taguées avec le commit (`wecare-app:<sha>`), les précédentes restent disponibles (`docker images wecare-app`). L'historique est consigné dans `.deploy-history` par la CI.

### Rollback

```bash
docker images wecare-app                          # repérer le tag précédent
IMAGE_TAG=<sha_precedent> docker compose up -d --no-build
```

⚠️ Si la version déployée contenait une migration, la revenir d'abord :
`docker compose exec php bin/console doctrine:migrations:migrate prev`.

### Sauvegarde de la base

```bash
docker compose exec database mariadb-dump -uroot -p"$DB_ROOT_PASSWORD" wecare > backup_$(date +%F).sql
```

---

## Comptes de démonstration

### WeCare Bordeaux (admin principal)

#### Administrateur
| Email | Mot de passe | Rôle |
|---|---|---|
| admin@wecare.fr | admin123 | Administrateur |

#### Intervenants
| Nom | Email | Mot de passe | Spécialité | Statut |
|---|---|---|---|---|
| Léo Lambert | leo@wecare.fr | interv123 | Aide à domicile | actif |
| Marie Dumont | marie@wecare.fr | interv123 | Soins infirmiers | actif |
| Karim Benali | karim@wecare.fr | interv123 | Auxiliaire de vie | congé |
| Sophie Marchand | sophie@wecare.fr | interv123 | Infirmière | actif |

#### Bénéficiaires
| Nom | Email | Mot de passe | Risque | Pathologie |
|---|---|---|---|---|
| Simone Ruault | simone@mail.fr | patient123 | modéré | Alzheimer débutant |
| Denise Huard | denise@mail.fr | patient123 | élevé | Diabète type 2 |
| Fatima Musson | fatima@mail.fr | patient123 | faible | — |
| Émile Neveu | emile@mail.fr | patient123 | critique | Insuffisance cardiaque |
| Robert Tissier | robert@mail.fr | patient123 | modéré | BPCO |
| Yvonne Cazenave | yvonne@mail.fr | patient123 | faible | — |
| Jean-Pierre Allard | jp.allard@mail.fr | patient123 | critique | AVC séquellaire |
| Marguerite Dumas | marguerite@mail.fr | patient123 | élevé | Parkinson débutant |
| Henri Marchais | henri@mail.fr | patient123 | modéré | Diabète type 1 |
| Paulette Vidal | paulette@mail.fr | patient123 | faible | — |
| Georges Faure | georges@mail.fr | patient123 | élevé | Insuffisance rénale |
| Madeleine Blanc | madeleine@mail.fr | patient123 | modéré | — |

---

### WeCare Lyon (second admin)

#### Administrateur
| Email | Mot de passe | Rôle |
|---|---|---|
| admin2@wecare.fr | admin456 | Administrateur |

#### Intervenants
| Nom | Email | Mot de passe | Spécialité | Statut |
|---|---|---|---|---|
| Antoine Leroy | antoine@wecare.fr | interv123 | Infirmier(e) | actif |
| Julie Martin | julie@wecare.fr | interv123 | Aide-soignant(e) | actif |

#### Bénéficiaires
| Nom | Email | Mot de passe | Risque | Pathologie |
|---|---|---|---|---|
| Pierre Blanc | pierre@mail.fr | patient123 | modéré | Arthrose |
| Lucie Bernard | lucie2@mail.fr | patient123 | faible | — |
| Roger Petit | roger@mail.fr | patient123 | élevé | Diabète type 2 |
