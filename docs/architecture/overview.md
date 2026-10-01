# Asset Tracker PaaS - Architecture Overview

## Vision

Asset Tracker PaaS is a Platform as a Service that enables organizations to create customized asset tracking systems. The platform provides core functionality while allowing customers to configure modules, integrations, and custom fields without modifying the source code.

## Core Principles

1. **Generic & Multi-Tenant**: Platform serves multiple organizations with complete data isolation
2. **Modular**: Business functionality is encapsulated in installable modules
3. **Integration-Ready**: Hardware and software integrations connect through standardized contracts
4. **API-First**: All functionality accessible via REST API
5. **Event-Driven**: Architecture supports asynchronous event processing
6. **Extensible**: Custom fields, modules, and integrations can be added without core changes
7. **Secure**: Tenant isolation, role-based permissions, and audit logging
8. **Maintainable**: Clear separation of concerns and modular architecture
9. **Scalable**: Designed to handle growth with containerized deployment
10. **Backward Compatible**: Versioned modules, templates, and APIs

## Architecture Diagram

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

## Hierarchy

```
Platform
│
└── Organization / Tenant
    │
    ├── Users
    ├── Roles
    ├── Permissions
    │
    └── Projects
        │
        ├── Template
        ├── Modules
        ├── Assets
        ├── Locations
        ├── Movements
        ├── Devices
        ├── Integrations
        ├── Custom Fields
        ├── Webhooks
        └── Settings
```

## Core Concepts

### Platform
The entire Asset Tracker PaaS system.

### Organization / Tenant
A company or customer using the platform. All data and configuration is isolated per organization.

### Project
A single asset tracking application created by an organization. One organization can have multiple projects (e.g., Vehicle Tracker, Warehouse Tracker).

### Template
A blueprint for creating projects with pre-configured modules and settings.

### Module
Business functionality that can be installed and enabled on a project (e.g., Asset, Location, Maintenance, Inspection).

### Integration
Connection to external hardware, software, or APIs (e.g., RFID, GPS, BLE, ERP).

### Asset
The object being tracked with two identities:
- **System ID**: Immutable, platform-generated (e.g., AST-01J8X9ABCD)
- **Serial Number**: Customer-defined, unique within project (e.g., TAB-C2H2-00001)

### Device
Hardware device that can be bound to assets (e.g., RFID tag, GPS tracker).

### Event
Standardized events representing state changes (e.g., asset.created, asset.location.updated).

## Technology Stack

### Backend
- **Framework**: Laravel 10 (PHP 8.2)
- **Database**: PostgreSQL 15
- **Cache/Queue**: Redis 7
- **Authentication**: Laravel Sanctum
- **Events**: Laravel Events + Queue

### Frontend
- **Framework**: React 18
- **Language**: TypeScript
- **Build Tool**: Vite
- **Styling**: Tailwind CSS
- **Routing**: React Router

### Infrastructure
- **Containerization**: Docker & Docker Compose
- **Web Server**: Nginx
- **Process Management**: Docker Compose

## Non-Negotiable Rules

1. **No Customer Hard-coding**: Never hard-code specific customer logic in the core platform
2. **Generic Core**: Core only understands universal concepts (Organization, Project, Asset, etc.)
3. **Integration Abstraction**: RFID, GPS, etc. are integrations, not core dependencies
4. **Extensible Integrations**: Platform must support adding new integrations without core changes
5. **Dual Asset Identity**: Maintain both system ID and customer serial number
6. **Device Binding**: Use explicit device binding, not direct device attachment
7. **Multiple Integrations**: One asset can have multiple integrations
8. **Event-Driven**: Use standardized event contracts
9. **Integration Contracts**: All integrations implement a common interface
10. **Module vs Integration**: Modules = business logic, Integrations = external technology

## Tenant Isolation

All data must be scoped by:
- `organization_id` for organization-level entities
- `project_id` for project-level entities

Queries must include tenant/project scoping. Never use `Model::find($id)` without authorization checks for tenant-sensitive endpoints.

## Security Layers

1. **Authentication**: Token-based via Laravel Sanctum
2. **Authorization**: Role-based permissions with policies
3. **Tenant Isolation**: Data scoped by organization/project
4. **Rate Limiting**: API rate limits
5. **Audit Logging**: Activity tracking
6. **Input Validation**: All requests validated
7. **API Security**: API keys for integrations
8. **Webhook Security**: Signature verification
9. **Secret Security**: Encrypted storage for sensitive data
10. **IDOR Protection**: Full authorization chain checks

## Module System

Modules follow this lifecycle:
```
AVAILABLE → INSTALLED → CONFIGURED → ENABLED → DISABLED → UNINSTALLED
```

Modules are versioned to allow projects to stay on older versions while newer versions are available.

## Integration Architecture

```
Hardware
    ↓
Gateway / Provider
    ↓
Integration Adapter
    ↓
Normalized Event
    ↓
Event Bus
    ↓
Asset Tracker Core
```

All integrations implement a standard contract with methods like:
- `connect()`
- `disconnect()`
- `configure()`
- `testConnection()`
- `receive()`
- `normalize()`
- `healthCheck()`

## Event Flow Example (GPS)

```
GPS Device
    ↓
GPS Integration
    ↓
Normalize GPS Data
    ↓
asset.location.updated event
    ↓
Event Listener
    ↓
Update Asset Location
    ↓
Store Movement
    ↓
Audit Log
    ↓
Webhook
```

## Customization Levels

1. **Level 1 - Core**: Foundation entities (Asset, Location, Movement, User, Permission, Project, Organization)
2. **Level 2 - Configurable**: Custom fields, custom status, custom forms, basic workflows, dashboard widgets
3. **Level 3 - Extensible**: Custom modules, custom APIs, custom integrations, custom events

## Database Design Principles

- Scalable and indexed
- Normalized for core entities
- Metadata-driven for custom fields
- Tenant and project aware
- Migration-safe

## API Design

- RESTful with versioning (`/api/v1`)
- Consistent response format
- Pagination, filtering, sorting, search
- Authentication and authorization on all endpoints
- Rate limiting

## Deployment Strategy

MVP uses Docker Compose with:
- Frontend (React)
- Backend (Laravel)
- Worker (Queue processing)
- Redis (Cache/Queue)
- PostgreSQL (Database)
- Nginx (Reverse proxy)

Future scaling may move to container orchestration or microservices when needed.
