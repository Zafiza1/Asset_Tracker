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
- Laravel 11 (PHP 8.2+)
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

2. Copy environment file:
```bash
cp .env.example .env
```

3. Start services:
```bash
docker-compose up -d
```

4. Install backend dependencies:
```bash
docker-compose exec backend composer install
docker-compose exec backend php artisan key:generate
docker-compose exec backend php artisan migrate
```

5. Install frontend dependencies:
```bash
docker-compose exec frontend npm install
```

6. Access the application:
- Frontend: http://localhost
- API: http://localhost/api/v1
- Health check: http://localhost/health

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
- **Phase 16**: Module Builder (Future)

## Documentation

- [API v1 reference](docs/api/api-v1.md)
- [Architecture overview](docs/architecture/overview.md)
- [Tenancy rules](docs/architecture/tenancy.md)
- [Integration architecture](docs/architecture/integrations.md)
- [Module architecture](docs/architecture/modules.md)
- [Implementation documentation and validation record](docs/phase14-documentation.md)

## Security

- All data is scoped by organization and project
- Role-based access control (RBAC)
- API token authentication via Laravel Sanctum
- Input validation on all endpoints
- Rate limiting on API endpoints
- Audit logging for sensitive operations
- Tenant isolation enforced at all layers

## License

MIT

## Support

For support and documentation, see the `docs/` directory.
