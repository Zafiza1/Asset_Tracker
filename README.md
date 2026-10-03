# Asset Tracker PaaS

A multi-tenant, modular, and integration-ready Platform as a Service for asset tracking.

## Overview

Asset Tracker PaaS provides a core platform for organizations to create their own asset tracking systems. Each customer (organization) can create projects, select templates, enable modules, configure integrations, and customize fields without modifying the core platform.

## Architecture

```
                    ASSET TRACKER PaaS
                           │
              ┌────────────┴────────────┐
              │                         │
        CONTROL PLANE              RUNTIME PLANE
              │                         │
       Organizations                Assets
       Projects                    Locations
       Templates                   Movements
       Modules                     Events
       Users                       Integrations
       Permissions                 Custom Modules
       Runtime                     Workflows
       Integrations
```

## Key Features

- **Multi-Tenant**: Complete data and configuration isolation per organization
- **Multi-Project**: Organizations can create multiple independent projects
- **Modular**: Install, configure, and enable business modules as needed
- **Integration-Ready**: Connect RFID, GPS, BLE, and other hardware
- **API-First**: Full REST API for all platform functionality
- **Event-Driven**: Event-based architecture for extensibility
- **Customizable**: Custom fields, forms, and views without code changes
- **Secure**: Role-based permissions, audit logging, and tenant isolation

## Tech Stack

### Backend
- Laravel 12 (PHP 8.4+)
- PostgreSQL 15
- Redis 7
- Laravel Sanctum (Authentication)
- Laravel Queue (Background processing)

### Frontend
- React 18
- TypeScript
- Vite
- Tailwind CSS
- React Router

### Infrastructure
- Docker & Docker Compose
- Nginx (Reverse proxy)

## Project Structure

```
Asset_Tracker/
│
├── backend/              # Laravel backend API
│   ├── app/
│   │   ├── Core/         # Core domain logic
│   │   ├── Modules/      # Business modules
│   │   ├── Integrations/ # Integration adapters
│   │   ├── Events/       # Event definitions
│   │   ├── Jobs/         # Background jobs
│   │   └── Services/     # Business services
│   ├── database/         # Database migrations & seeders
│   ├── routes/           # API routes
│   └── tests/            # Tests
│
├── frontend/             # React frontend
│   ├── src/
│   │   ├── core/         # Core components (auth, api, layout)
│   │   ├── modules/      # Module-specific UI
│   │   ├── integrations/ # Integration UI
│   │   ├── builder/      # Builder tools (future)
│   │   ├── pages/        # Page components
│   │   └── components/   # Reusable components
│
├── infrastructure/       # Infrastructure config
│   ├── docker/           # Docker configurations
│   ├── nginx/            # Nginx configuration
│   └── scripts/          # Utility scripts
│
├── docs/                 # Documentation
│   ├── architecture/     # Architecture documentation
│   ├── api/              # API documentation
│   ├── modules/          # Module documentation
│   └── integrations/     # Integration documentation
│
├── docker-compose.yml    # Docker services
├── .env.example          # Environment variables template
└── README.md             # This file
```

## Getting Started

### Prerequisites

- Docker and Docker Compose
- Git

### Installation

1. Clone the repository:
```bash
git clone <repository-url>
cd Asset_Tracker
```

2. Run the setup script (copies `.env` files, builds and starts the stack,
   generates `APP_KEY`, migrates and seeds roles, the module catalog and demo
   tenants):
```bash
infrastructure/scripts/setup.sh            # or: setup.sh --no-seed (no demo tenants)
```

   Or step by step:
```bash
cp .env.example .env && cp backend/.env.example backend/.env
docker compose up -d --build
docker compose exec backend php artisan key:generate
docker compose exec backend php artisan migrate
docker compose exec backend php artisan db:seed      # roles, permissions, modules, demo tenants
```

   PHP dependencies live in the image (`vendor/` is not committed). After
   changing `composer.json`, rebuild with `docker compose up -d --build -V`.
   Run the test suites with `infrastructure/scripts/test.sh`.

`APP_KEY` also encrypts integration and webhook secrets at rest: keep it
stable, and list old keys in `APP_PREVIOUS_KEYS` when rotating.

3. Access the application:
- Frontend: http://localhost
- API: http://localhost/api/v1
- Health check: http://localhost/health (database, Redis, queue)

The stack runs nginx → php-fpm (`backend`), a queue `worker` (queues
`webhooks,default`) and a `scheduler` (webhook retries every 5 minutes).

### Demo data

`db:seed` creates three demo tenants with projects built from templates,
modules, RFID/GPS integrations, devices bound to assets, locations, movement
history and custom fields (`DemoDataSeeder`). All demo passwords are `password`:

| Account | Organization / project |
|---|---|
| `admin@platform.com` | Platform admin (all tenants) |
| `admin@suryaintigas.com` (also `manager@`, `operator@`) | PT Surya Inti Gas — Cylinder Asset Tracker |
| `admin@abclogistics.com` (also `manager@`) | PT ABC Logistics — Vehicle Tracker |
| `admin@xyzmanufacturing.com` | PT XYZ Manufacturing — Factory Equipment / Warehouse Tracker |

New users can sign up at `/register`: step 1 creates the account, step 2 the
first organization and a project from a template.

### Web console

| Area | Pages |
|---|---|
| Runtime | Dashboard, Assets (list/detail), Locations, Movements, Devices (bind/unbind), Integrations (connect/test/health) |
| Modules | Customers, Deliveries, Maintenance (each shown when its module is enabled for the project) |
| Project setup | Modules (install/enable/disable/configure/upgrade/uninstall), Builder (Field Builder; other builders planned), Webhooks (deliveries, test, secret), API keys, Members & roles, Audit log, Health |
| Platform | Organizations & projects (create from template), Templates (versions and modules) |

Navigation follows the user's permissions in the selected project; the API
enforces them regardless.

## Development Phases

This project follows a phased development approach:

- **Phase 1**: Foundation (Docker, Backend, Frontend, PostgreSQL, Redis)
- **Phase 2**: Multi-Tenancy (Organization, Project, Users)
- **Phase 3**: Authorization (Authentication, Roles, Permissions)
- **Phase 4**: Asset Core (Asset, Asset Type, Status, Metadata)
- **Phase 5**: Location (Location, Asset Location, Movement)
- **Phase 6**: Module Engine (Module Registry, Installation, Configuration)
- **Phase 7**: Template Engine (Templates, Template Modules)
- **Phase 8**: Integration Engine (Integration Registry, Device Binding)
- **Phase 9**: RFID Integration
- **Phase 10**: GPS Integration
- **Phase 11**: API Implementation
- **Phase 12**: Webhook System
- **Phase 13**: Audit Logging
- **Phase 14**: Custom Fields
- **Phase 15**: Dashboard
- **Phase 16**: Module Builder (Future; Field Builder available as a foundation)

Phases 1–15 are implemented and covered by the backend test suite
(`cd backend && vendor/bin/phpunit`). Business modules with features:
**Maintenance** ([docs](docs/modules/maintenance.md)), **Customer**
([docs](docs/modules/customer.md)) and **Delivery**
([docs](docs/modules/delivery.md)), under `backend/app/Modules`. The other
catalog modules (Inspection, Inventory, Rental) have lifecycle and
versioning but no business features yet.

## Documentation

- [API v1 reference](docs/api/api-v1.md)
- [Architecture overview](docs/architecture/overview.md)
- [Tenancy rules](docs/architecture/tenancy.md)
- [Integration architecture](docs/architecture/integrations.md)
- [Module architecture](docs/architecture/modules.md)
- [Module Builder foundation](docs/architecture/module-builder.md)
- [Writing modules](docs/modules/README.md)
- [RFID integration](docs/integrations/rfid.md) · [GPS integration](docs/integrations/gps.md)
- [Implementation documentation and validation record](docs/phase14-documentation.md)

## Security

- All data is scoped by organization and project
- Role-based access control (RBAC)
- API token authentication via Laravel Sanctum
- Project-scoped API keys for gateways and external systems (hashed, revocable, scoped)
- Integration and webhook secrets encrypted at rest and never returned after creation
- Webhooks signed with HMAC-SHA256 over the exact request body
- Input validation on all endpoints
- Rate limiting on API endpoints
- Audit logging for sensitive operations
- Tenant isolation enforced at all layers

## License

MIT

## Support

For support and documentation, see the `docs/` directory.
