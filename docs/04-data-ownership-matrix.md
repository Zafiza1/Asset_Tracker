# 04 — Matriks Kepemilikan Data Tenant

Legenda penegakan: **G** = global scope aplikasi (`BelongsToOrganization`, fail-closed) · **C** = composite FK `(organization_id, id)` · **R** = PostgreSQL RLS · **P** = policy/permission · **I** = immutable/append-only (trigger + grant).

| Tabel | Pemilik | Kunci kepemilikan | Penulis yang sah | Pembaca | Penegakan |
|---|---|---|---|---|---|
| organizations | Platform | — | Platform Admin (`platform.organization.manage`); Org Admin hanya profil (`organization.manage`) | Anggota org (miliknya), Platform | P |
| organization_settings | Tenant | organization_id (PK) | Org Admin (`settings.manage`) | Anggota org | G R P |
| users (tenant) | Tenant | organization_id (immutable) | Org Admin (`user.*`); user sendiri untuk profil/password | Org (`user.view`), user sendiri | G C P |
| users (platform) | Platform | — (organization_id NULL) | Platform Admin | Platform | P |
| user_roles, user_data_scopes | Tenant | organization_id | Org Admin (`role.manage`, `user.update`) | Org (`user.view`) | G C R P |
| roles, role_permissions | Tenant | organization_id | Org Admin (`role.manage`) | Org (`role.view`) | G C R P |
| permissions | Platform | — | Seeder/migrasi saja | Semua user login | I |
| platform_roles, platform_user_roles | Platform | — | Platform Admin | Platform | P |
| branches, departments, locations | Tenant | organization_id | `master.tenant.*` | Org (`master.tenant.view`) | G C R P |
| generic_asset_categories, generic_asset_definitions, generic_asset_definition_versions, generic_attribute_definitions, units, location_types, generic_conditions, generic_transaction_types | Platform | — | Platform (`master.generic.manage`) | Semua tenant (`master.generic.view`) | P I (versi released) |
| tenant_asset_categories, asset_conditions | Tenant | organization_id | `master.tenant.*` | Org | G C R P |
| tenant_asset_definitions, tenant_asset_definition_versions, tenant_attribute_definitions, tenant_attribute_overrides | Tenant | organization_id | `master.tenant.*` | Org | G C R P I (versi released) |
| tenant_transaction_types | Tenant | organization_id | `settings.manage` | Org | G C R P |
| asset_containers, asset_container_versions | Tenant | organization_id | Sistem (registrasi, migrasi) — tidak ada endpoint tulis langsung | `asset.view` + scope | G C R I (versions) |
| assets | Tenant | organization_id | Field terkontrol: posting transaksi; field deskriptif: `asset.update`; arsip: `asset.archive/restore` | `asset.view` + scope | G C R P |
| asset_attribute_values | Tenant (via asset) | organization_id + asset_id | Posting / `asset.update` | `asset.view` + scope | G C R |
| asset_assignments, asset_location_histories, asset_status_histories | Tenant | organization_id | Sistem (posting) — append-only | `asset.view` + scope | G C R I |
| asset_documents | Tenant | organization_id | `document.manage` + scope aset | `document.view` + scope aset | G C R P |
| number_sequences | Tenant | organization_id | Sistem | — | G R |
| asset_transactions, asset_transaction_items | Tenant | organization_id | Pemohon (`transaction.create/submit`), sistem (posting) | `transaction.view` + scope (aset dalam scope atau pemohon sendiri) | G C R P I (posted) |
| workflow_definitions, workflow_versions, workflow_steps | Tenant | organization_id | `workflow.manage` | `workflow.view` | G C R P I (published) |
| workflow_instances, transaction_approvals | Tenant | organization_id | Sistem; keputusan oleh approver sah | `transaction.view` | G C R I |
| idempotency_keys | Tenant | organization_id + user_id | Sistem | Sistem | G |
| export_jobs | Tenant | organization_id + requested_by | `report.export` | Pembuat job | G C R P |
| asset_definition_migrations | Tenant | organization_id | `master.tenant.migrate` | `master.tenant.view` | G C R |
| audit_logs | Tenant (NULL = platform) | organization_id | **Hanya AuditLogger** (INSERT) | `audit.view` (tenant); `platform.audit.view` (platform) | G R I |
| support_access_sessions | Platform | organization_id (target) | Platform (`platform.support.access`) | Platform; tenant melihat jejaknya di audit | P I |

## Aturan lintas tabel
1. Setiap FK dari tabel tenant ke tabel tenant lain adalah **composite FK** dengan `organization_id` → DB menolak relasi lintas tenant (diuji: *Cross-tenant foreign key ditolak*).
2. FK dari tabel tenant ke tabel platform (mis. `generic_definition_version_id`) adalah FK biasa; platform data dapat dibaca semua tenant tetapi tidak dapat ditulis tenant.
3. Pencarian, filter, export, laporan, lampiran, dan operasi bulk memakai query builder yang sama (global scope + data scope); tidak ada raw query tanpa `organization_id`.
4. Job queue menyimpan `organization_id` + `user_id` di payload; worker memvalidasi ulang status user, organisasi & permission sebelum eksekusi dan menyetel konteks RLS.
5. Kunci cache diprefiks `org:{id}:`.
