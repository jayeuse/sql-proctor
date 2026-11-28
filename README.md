# SQL-Proctor

A web-based SQL practical examination system with separate Next.js and Laravel applications, PostgreSQL 17, MariaDB 11, and documentation. Phase 1 ([SPEC-0004](docs/specs/SPEC-0004-auth-rbac-and-audit-logging.md)) delivers administrator bootstrap, Sanctum staff authentication, RBAC, classroom codes, Kahoot-style student entry, and append-only audit logging; exam authoring and SQL execution follow in Phases 2 and 3.

## Stack

| Layer | Technology |
|---|---|
| Frontend | Next.js 16.4.6, React 19.3.0, Tailwind CSS ^4.3.5, JavaScript, App Router, Axios, Monaco Editor |
| Backend | Laravel 13.43.0, PHP 8.5.12, Laravel Sanctum 4.5.0 |
| Application database | PostgreSQL 17.12 |
| Student query execution | MariaDB 11.8.9 |
| Infrastructure | Docker 29.8.0, Docker Compose 5.5.1 |
| Tests | PHPUnit 12.5.44 (backend; `^12.5.12` constraint; Pest 4 compatible, not installed). Vitest ^5.1.2, React Testing Library ^16.3.3, and Playwright ^1.65.1 (frontend unit and E2E). |
| CI | GitHub Actions ([.github/workflows/ci.yml](.github/workflows/ci.yml)) running Pint, PHPUnit, Vitest, ESLint, Next build, and Playwright Chromium |

## Agent engineering

The project uses a discovery-first agent engineering standard. The root [AGENTS.md](AGENTS.md) is always loaded and holds the project rules and the skill-loading map. The detailed skills live in `.agents/skills/`; `skills-lock.json` pins the 12 skills installed from external sources. Four project-specific skills cover negative-first testing, SQL execution isolation, integration testing, and frontend testing.

The standard requires Specification-Driven Development, Test-Driven Development, negative-first test design, security review, code review, and evidence-based completion. The inventory, sources, rejected candidates, version compatibility, and maintenance commands are in [docs/agent-engineering.md](docs/agent-engineering.md).

```sh
npx skills list --json     # show the installed project skills
npx skills update          # update external skills; review the diff first
```

Tailwind CSS v4, Vitest, React Testing Library, and Playwright are installed in `frontend/sql-proctor-ui/`. Pest 4 stays uninstalled.

## Directory structure

```text
sql-proctor/
├── .github/workflows/ci.yml      Backend, frontend, and Playwright E2E CI
├── backend/
│   └── sql-proctor-api/          Laravel API
├── frontend/
│   └── sql-proctor-ui/           Next.js application
├── infra/
│   ├── compose.yaml              PostgreSQL and MariaDB
│   ├── .env.example              Placeholders for local values
│   ├── .env                      Local values (gitignored)
│   └── README.md                 Infrastructure commands
├── docs/
│   ├── architecture.md
│   ├── database-design.md
│   ├── api-specification.md
│   ├── development-roadmap.md
│   ├── agent-engineering.md
│   ├── plans/                    Implementation plans and test matrices
│   ├── specs/                    Specifications (SPEC-0001 ...)
│   ├── templates/                Specification, plan, test, TDD, review, DoD, styling
│   └── adr/                      Architecture decisions (ADR-0001 ...)
├── .agents/skills/               Installed and custom agent skills
├── skills-lock.json              Pinned external skill sources
├── .agent-runtime/               Local run records (gitignored)
├── .gitignore
└── README.md
```

`infra/` holds infrastructure only. Application code never goes there.

## Ports

| Service | Address |
|---|---|
| Next.js | `http://localhost:3000` |
| Laravel | `http://localhost:8000` |
| PostgreSQL | `127.0.0.1:5432` |
| MariaDB | `127.0.0.1:3306` |

## First-time setup

The toolchain and both applications are set up on this machine. MariaDB is configured but not yet verified: XAMPP MySQL holds port 3306. See [Known constraints](#known-constraints). These steps reproduce the environment on another machine.

### 1. Databases

```sh
cd infra
cp .env.example .env
# Replace every placeholder in .env.
chmod 600 .env
docker compose up -d
docker compose ps
```

Both services must report `healthy`. See [infra/README.md](infra/README.md) for the full command table and the port 3306 conflict.

### 2. Backend

```sh
cd backend/sql-proctor-api
PATH="/opt/homebrew/bin:$PATH" composer install
cp .env.example .env
php artisan key:generate
# Set DB_PASSWORD to the POSTGRES_PASSWORD value in infra/.env.
php artisan migrate
php artisan app:bootstrap-admin
```

### 3. Frontend

```sh
cd frontend/sql-proctor-ui
npm install
cp .env.local.example .env.local
```

## Run the applications

Use three terminals.

```sh
# 1. Databases
cd infra && docker compose up -d

# 2. API
cd backend/sql-proctor-api
PATH="/opt/homebrew/bin:$PATH" php artisan serve

# 3. UI
cd frontend/sql-proctor-ui
npm run dev
```

Open `http://localhost:3000`. The API health check is `http://localhost:8000/up`.

## Verify

```sh
# Tools
PATH="/opt/homebrew/bin:$PATH" php -v
PATH="/opt/homebrew/bin:$PATH" composer --version
node -v
npm -v
docker --version
docker compose version

# Databases
cd infra && docker compose ps
docker compose exec -T postgres sh -c 'pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB"'

# API
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8000/up          # 200
curl -s -o /dev/null -w '%{http_code}\n' -H 'Accept: application/json' \
  http://127.0.0.1:8000/api/user                                           # 401

# UI
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:3000/            # 200

# Backend tests and style
cd backend/sql-proctor-api
PATH="/opt/homebrew/bin:$PATH" ./vendor/bin/pint --test
PATH="/opt/homebrew/bin:$PATH" php artisan test

# Frontend checks and E2E
cd frontend/sql-proctor-ui
npm test
npm run lint
npm run build
npm run test:e2e
```

## Stop

```sh
cd infra && docker compose down        # keeps the data volumes
```

Caution: `docker compose down -v` deletes both database volumes and their data.

## Known constraints

1. When `/Applications/XAMPP/xamppfiles/bin` precedes Homebrew in `PATH` (for example in non-login agent shells), `php` resolves to XAMPP PHP 8.2.4. Prefix Laravel and Composer commands with `PATH="/opt/homebrew/bin:$PATH"`. Do not edit the XAMPP installation.
2. XAMPP MySQL listens on port 3306. Stop it (`sudo /Applications/XAMPP/xamppfiles/xampp stopmysql`) or set a different `MARIADB_PORT` before starting MariaDB.
3. `.env` files are local secrets. Never commit, print, or copy their values into a record.
4. Laravel 13 and Next.js 16 generate their own `AGENTS.md` files inside each application. They are framework bootstrap notes. This root `AGENTS.md` governs.
5. The Laravel skeleton asks agents to install Laravel Boost. It is not installed and is not part of the approved setup.

## Documentation

- [AGENTS.md](AGENTS.md)
- [docs/architecture.md](docs/architecture.md)
- [docs/database-design.md](docs/database-design.md)
- [docs/api-specification.md](docs/api-specification.md)
- [docs/development-roadmap.md](docs/development-roadmap.md)
- [docs/agent-engineering.md](docs/agent-engineering.md)
- [docs/specs/README.md](docs/specs/README.md)
- [docs/templates/specification.md](docs/templates/specification.md)
- [docs/adr/README.md](docs/adr/README.md)
- [infra/README.md](infra/README.md)
