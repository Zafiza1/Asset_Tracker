# 03 — Desain Database & ERD

Konvensi umum:
- PK `id uuid` (UUIDv7, dibangkitkan aplikasi). Waktu `timestamptz`. Uang `numeric(18,2)` + `currency char(3)`.
- Setiap tabel tenant memiliki `organization_id uuid NOT NULL` dan `UNIQUE (organization_id, id)` agar tabel lain dapat membuat **composite foreign key** `(organization_id, x_id) → parent(organization_id, id)`. Dengan begitu **relasi lintas tenant tidak mungkin terbentuk** walau aplikasi keliru.
- Tabel platform (Generic Master) tidak punya `organization_id` dan hanya dapat ditulis lewat endpoint platform.
- Status entitas master: `active | inactive | archived` (tidak ada hard delete untuk data yang direferensikan).
- Kode unik per lingkup: `UNIQUE (organization_id, code)` untuk tenant, `UNIQUE (code)` untuk platform.

## 1. Platform, identitas, tenancy

```mermaid
erDiagram
  organizations ||--o{ users : "user tenant (tepat 1 org)"
  organizations ||--|| organization_settings : ""
  organizations ||--o{ branches : ""
  organizations ||--o{ departments : ""
  organizations ||--o{ locations : ""
  branches ||--o{ departments : "opsional"
  branches ||--o{ locations : ""
  locations ||--o{ locations : "parent"
  location_types ||--o{ locations : "generic"
  users ||--o{ platform_user_roles : "hanya user platform"
  platform_roles ||--o{ platform_user_roles : ""
  platform_roles ||--o{ platform_role_permissions : ""
  permissions ||--o{ platform_role_permissions : ""
  organizations ||--o{ roles : ""
  roles ||--o{ role_permissions : ""
  permissions ||--o{ role_permissions : ""
  users ||--o{ user_roles : ""
  roles ||--o{ user_roles : ""
  users ||--o{ user_data_scopes : ""
  users ||--o{ support_access_sessions : "platform admin"
  organizations ||--o{ support_access_sessions : ""

  users { uuid id PK; string user_type; uuid organization_id FK; citext email UK; string name; string password; string status; uuid home_branch_id; uuid home_department_id; string employee_number; string job_title; timestamptz last_login_at }
  organizations { uuid id PK; string code UK; string name; string status; string timezone; char currency }
  organization_settings { uuid organization_id PK; string asset_number_format; string transaction_number_format; bool allow_self_approval_default; int session_timeout_minutes }
  user_data_scopes { uuid id PK; uuid organization_id; uuid user_id FK; string scope_type; uuid scope_ref_id }
  roles { uuid id PK; uuid organization_id FK; string code; string name; string template_code; bool is_locked }
  permissions { uuid id PK; string code UK; string scope }
  branches { uuid id PK; uuid organization_id; string code; string name; string status }
  departments { uuid id PK; uuid organization_id; uuid branch_id; string code; string name; string status }
  locations { uuid id PK; uuid organization_id; uuid branch_id; uuid parent_id; uuid location_type_id; string code; string name; string status }
```

Catatan relasi penting:
- **Satu user = satu perusahaan.** Tidak ada tabel membership; kepemilikan organisasi melekat langsung pada `users.organization_id`.
- `users.user_type ∈ {tenant, platform}` dengan CHECK: `tenant` ⇒ `organization_id NOT NULL`; `platform` ⇒ `organization_id IS NULL`. User platform tidak dapat menerima role tenant; user tenant tidak dapat menerima role platform (trigger + validasi service).
- `users.organization_id` **tidak dapat diubah** setelah dibuat (trigger). Karyawan yang pindah perusahaan = akun lama dinonaktifkan, akun baru dibuat di perusahaan baru (histori aset/transaksi tetap menunjuk akun lama).
- `email` unik global (login cukup email + password, organisasi ditentukan dari akun). Konsekuensi: satu email tidak bisa dipakai di dua perusahaan; email akun yang dinonaktifkan dapat dilepas oleh Platform Admin (diaudit) bila diperlukan.
- `UNIQUE (organization_id, id)` pada `users` agar `user_roles`, `user_data_scopes`, custodian, pemohon, dan approver memakai composite FK `(organization_id, user_id) → users(organization_id, id)`.
- Status user `invited | active | suspended | deactivated`.
- `user_data_scopes.scope_type ∈ {organization, branch, department, location}`; `scope_ref_id` NULL hanya bila `organization`. Integritas `scope_ref_id` divalidasi oleh 3 composite FK parsial (kolom `branch_id`/`department_id`/`location_id` terpisah + CHECK tepat satu terisi) — bukan polymorphic bebas.
- `roles` hanya tenant. Template role (Organization Administrator, Asset Manager, dst.) dikopi saat organisasi dibuat dengan `template_code` sebagai identitas sumber. Role `ORG_ADMIN` `is_locked` (permission inti tidak dapat dicabut) dan sistem menolak operasi yang membuat organisasi tanpa admin aktif.
- `permissions.scope ∈ {platform, tenant}`; trigger/validasi menolak permission platform pada `role_permissions`.

## 2. Generic Master & Tenant Custom Master

```mermaid
erDiagram
  generic_asset_categories ||--o{ generic_asset_categories : parent
  generic_asset_categories ||--o{ generic_asset_definitions : ""
  generic_asset_definitions ||--o{ generic_asset_definition_versions : "versi"
  generic_asset_definition_versions ||--o{ generic_attribute_definitions : "atribut"
  units ||--o{ generic_attribute_definitions : ""
  generic_asset_categories ||--o{ tenant_asset_categories : "induk generik"
  tenant_asset_categories ||--o{ tenant_asset_categories : parent
  generic_asset_definitions ||--o{ tenant_asset_definitions : "diadopsi"
  tenant_asset_categories ||--o{ tenant_asset_definitions : "opsional"
  tenant_asset_definitions ||--o{ tenant_asset_definition_versions : "versi"
  generic_asset_definition_versions ||--o{ tenant_asset_definition_versions : "berbasis"
  tenant_asset_definition_versions ||--o{ tenant_attribute_definitions : "atribut tambahan"
  tenant_asset_definition_versions ||--o{ tenant_attribute_overrides : "override"
  generic_conditions ||--o{ asset_conditions : "sumber"
  generic_transaction_types ||--o{ tenant_transaction_types : ""

  generic_asset_definition_versions { uuid id PK; uuid definition_id FK; int version_number; string status; text changelog; text migration_notes; timestamptz released_at; uuid released_by }
  generic_attribute_definitions { uuid id PK; uuid definition_version_id FK; string key; string label; string data_type; bool is_required; jsonb validation; jsonb options; uuid unit_id; jsonb default_value; bool is_searchable; bool is_controlled; jsonb overridable; int sort_order }
  tenant_asset_definitions { uuid id PK; uuid organization_id; uuid generic_definition_id FK; uuid tenant_category_id; string code; string name; string status }
  tenant_asset_definition_versions { uuid id PK; uuid organization_id; uuid tenant_definition_id; uuid generic_definition_version_id FK; int version_number; string status; jsonb effective_schema; string schema_hash; timestamptz released_at }
  tenant_attribute_definitions { uuid id PK; uuid organization_id; uuid tenant_definition_version_id; string key; string data_type; bool is_required; jsonb validation; jsonb options }
  tenant_attribute_overrides { uuid id PK; uuid organization_id; uuid tenant_definition_version_id; string generic_attribute_key; jsonb overrides }
  asset_conditions { uuid id PK; uuid organization_id; uuid generic_condition_id; string code; string name; int severity; string status }
  tenant_transaction_types { uuid id PK; uuid organization_id; string type_code FK; bool enabled; string number_prefix; bool reason_required; bool attachment_required; bool auto_post_on_approval }
```

Aturan:
- **Versi generik**: `draft → released → deprecated`. Setelah `released`, trigger DB menolak UPDATE kolom skema dan INSERT/UPDATE/DELETE pada `generic_attribute_definitions` milik versi tersebut. Satu-satunya perubahan yang diizinkan: `status → deprecated`.
- **Tenant tidak pernah menulis ke tabel `generic_*`** — ditegakkan oleh routing (hanya endpoint platform), policy, dan grant DB (role aplikasi tenant… lihat §5).
- **Override** hanya untuk properti yang tercantum di `generic_attribute_definitions.overridable`, dengan allowlist sistem: `label`, `help_text`, `default_value`, `required` (hanya **lebih ketat**: false→true), `options` (hanya **subset**), `validation` (hanya **mempersempit** rentang). Tipe data dan key tidak pernah bisa di-override.
- `tenant_attribute_definitions.key` tidak boleh bentrok dengan key generik pada versi dasar (divalidasi saat release).
- **Release versi tenant** menghitung `effective_schema` (generik + override + atribut tenant) beserta `schema_hash` dan membekukannya. Versi tenant yang sudah dirilis immutable (trigger sama).
- Tenant mengadopsi definisi generik → baris `tenant_asset_definitions` + versi 1 yang di-*pin* ke versi generik tertentu. Rilis generik baru **tidak** mengubah apa pun pada tenant; upgrade harus eksplisit (versi tenant baru berbasis versi generik baru) diikuti migrasi aset eksplisit.
- Kondisi: platform menyediakan `generic_conditions` (Baik, Rusak Ringan, Rusak Berat, Hilang); saat organisasi dibuat dikopi ke `asset_conditions` dengan `generic_condition_id` sebagai sumber. Tenant boleh menambah kondisi sendiri.

## 3. Asset Container & inventory

```mermaid
erDiagram
  asset_containers ||--|| assets : "1:1"
  asset_containers ||--o{ asset_container_versions : "riwayat binding definisi"
  tenant_asset_definition_versions ||--o{ asset_container_versions : ""
  generic_asset_definition_versions ||--o{ asset_container_versions : ""
  assets ||--o{ asset_attribute_values : ""
  assets ||--o{ asset_assignments : ""
  assets ||--o{ asset_location_histories : ""
  assets ||--o{ asset_status_histories : ""
  assets ||--o{ asset_documents : ""
  locations ||--o{ assets : ""
  users ||--o{ assets : "custodian"
  asset_conditions ||--o{ assets : ""
  asset_definition_migrations ||--o{ asset_container_versions : ""

  asset_containers { uuid id PK; uuid organization_id; string container_key UK; string lifecycle_state; uuid current_version_id; timestamptz created_at }
  asset_container_versions { uuid id PK; uuid organization_id; uuid container_id; int version_number; uuid generic_definition_version_id; uuid tenant_definition_version_id; jsonb schema_snapshot; string schema_hash; string reason; uuid migration_id; uuid created_by }
  assets { uuid id PK; uuid organization_id; uuid container_id UK; string asset_number; string serial_number; string name; text description; uuid generic_category_id; uuid tenant_category_id; string status; uuid condition_id; uuid branch_id; uuid department_id; uuid location_id; uuid custodian_user_id; date acquisition_date; numeric acquisition_cost; char currency; int lock_version; timestamptz archived_at; uuid archived_by }
  asset_attribute_values { uuid id PK; uuid organization_id; uuid asset_id; string attribute_key; string data_type; string value_string; numeric value_number; date value_date; bool value_boolean; jsonb value_json }
  asset_assignments { uuid id PK; uuid organization_id; uuid asset_id; uuid custodian_user_id; uuid department_id; timestamptz started_at; timestamptz ended_at; uuid transaction_id }
  asset_location_histories { uuid id PK; uuid organization_id; uuid asset_id; uuid from_location_id; uuid to_location_id; timestamptz moved_at; uuid transaction_id }
  asset_status_histories { uuid id PK; uuid organization_id; uuid asset_id; string from_status; string to_status; uuid from_condition_id; uuid to_condition_id; timestamptz changed_at; uuid transaction_id }
  asset_documents { uuid id PK; uuid organization_id; uuid asset_id; uuid transaction_id; string document_type; string original_filename; string storage_path; string mime_type; bigint size_bytes; string sha256; timestamptz archived_at }
  number_sequences { uuid organization_id PK; string sequence_key PK; string period_key PK; bigint next_value }
```

Pembagian tanggung jawab **container vs asset**:
- `asset_containers` = batas isolasi & binding definisi: kunci container (`CNT-` + ULID), state lifecycle container (`active | archived | closed`), pointer ke versi binding saat ini.
- `asset_container_versions` = **append-only**; setiap baris membekukan versi generik + versi tenant + `schema_snapshot` yang dipakai untuk menafsirkan atribut aset (termasuk secara historis). Dibuat saat registrasi (`reason=created`) dan setiap migrasi (`migrated`/`rollback`).
- `assets` = data inti yang sering dicari/difilter (kolom bertipe, terindeks).
- `asset_attribute_values` = nilai atribut dinamis bertipe (satu kolom nilai terisi sesuai `data_type`), `UNIQUE (asset_id, attribute_key)`; index `(organization_id, attribute_key, value_string)`, `(organization_id, attribute_key, value_number)`, `(organization_id, attribute_key, value_date)` untuk filter. Atribut yang dihapus pada versi baru tidak dihapus nilainya — ditandai `retired_at` agar migrasi dapat dibalik.

Constraint kunci:
- `UNIQUE (organization_id, asset_number)` **termasuk aset yang diarsipkan** → nomor tidak pernah dipakai ulang; sequence hanya bertambah (`UPDATE number_sequences SET next_value = next_value + 1 ... RETURNING` di dalam transaksi, row-locked).
- Composite FK `(organization_id, location_id) → locations(organization_id, id)`, sama untuk branch, department, condition, custodian user, container, tenant category, tenant definition version.
- `assets.status ∈ {active, inactive, under_maintenance, on_loan, disposed}` (CHECK). Arsip direpresentasikan oleh `archived_at`, terpisah dari status bisnis.
- Dokumen: path penyimpanan `org/{org_id}/assets/{asset_id}/{uuid}`; nama asli hanya metadata. Download via endpoint terotorisasi (stream), tidak ada URL publik.

## 4. Transaksi & workflow

```mermaid
erDiagram
  asset_transactions ||--o{ asset_transaction_items : ""
  assets ||--o{ asset_transaction_items : ""
  asset_transactions ||--o| workflow_instances : ""
  workflow_definitions ||--o{ workflow_versions : ""
  workflow_versions ||--o{ workflow_steps : ""
  workflow_versions ||--o{ workflow_instances : "dipin"
  workflow_instances ||--o{ transaction_approvals : ""
  asset_transactions ||--o| asset_transactions : "reverses"
  asset_transactions ||--o{ asset_documents : "lampiran"

  asset_transactions { uuid id PK; uuid organization_id; string transaction_number; string type_code; string status; date transaction_date; uuid requested_by_user_id; text reason; uuid reverses_transaction_id; timestamptz submitted_at; timestamptz posted_at; uuid posted_by; int lock_version }
  asset_transaction_items { uuid id PK; uuid organization_id; uuid transaction_id; uuid asset_id; int line_no; jsonb before_snapshot; jsonb proposed_changes; int asset_lock_version; bool holds_asset_lock; jsonb result }
  workflow_definitions { uuid id PK; uuid organization_id; string name; string type_code; uuid tenant_category_id; numeric min_amount; int priority; string status }
  workflow_versions { uuid id PK; uuid organization_id; uuid workflow_definition_id; int version_number; string status; bool allow_self_approval; bool rejection_reason_required; string on_reject }
  workflow_steps { uuid id PK; uuid organization_id; uuid workflow_version_id; int level; string name; string approver_type; uuid approver_role_id; uuid approver_user_id; int min_approvals }
  workflow_instances { uuid id PK; uuid organization_id; uuid transaction_id UK; uuid workflow_version_id; int current_level; string status }
  transaction_approvals { uuid id PK; uuid organization_id; uuid workflow_instance_id; int level; uuid approver_user_id; string decision; text comment; timestamptz decided_at }
  idempotency_keys { uuid id PK; uuid organization_id; uuid user_id; string key; string request_hash; int response_status; jsonb response_body; timestamptz expires_at }
```

Constraint kunci:
- `UNIQUE (organization_id, transaction_number)`.
- **Kunci transaksi terbuka**: `CREATE UNIQUE INDEX ... ON asset_transaction_items (asset_id) WHERE holds_asset_lock` — aset tidak dapat masuk dua transaksi terbuka.
- `UNIQUE (workflow_instance_id, level, approver_user_id)` — satu keputusan per approver per level.
- `UNIQUE (reverses_transaction_id)` — transaksi posted hanya dapat dibalik sekali.
- `UNIQUE (organization_id, user_id, key)` pada `idempotency_keys`.
- Transaksi `posted`/`reversed` ditolak untuk UPDATE kolom bisnis oleh trigger (hanya kolom `status → reversed` & tautan reversal yang boleh berubah).
- Workflow version `published` immutable (trigger); instance menyimpan `workflow_version_id` yang dipin saat submit.

## 5. Audit, ekspor, ops

```text
audit_logs (id, organization_id NULL=platform, actor_user_id, support_session_id,
            action, entity_type, entity_id, before jsonb, after jsonb, metadata jsonb,
            ip inet, user_agent, request_id, created_at)
  - index (organization_id, created_at desc), (organization_id, entity_type, entity_id)
  - append-only: role DB aplikasi hanya punya INSERT+SELECT; trigger menolak UPDATE/DELETE
export_jobs (id, organization_id, requested_by, report_key, filters jsonb, format, status,
             row_count, storage_path, error, expires_at, created_at, finished_at)
support_access_sessions (id, platform_user_id, organization_id, reason, starts_at, expires_at, ended_at)
asset_definition_migrations (id, organization_id, tenant_definition_id, from_version_id, to_version_id,
             status, dry_run_report jsonb, total, succeeded, failed, started_by, started_at, finished_at)
+ tabel framework Laravel: sessions, cache, jobs, failed_jobs
```

## 6. Strategi atribut dinamis

1. **Kolom inti stabil** (nomor, serial, nama, kategori, lokasi, PJ, status, kondisi, nilai, tanggal perolehan) ada di `assets`.
2. **Definisi atribut** di `generic_attribute_definitions` / `tenant_attribute_definitions`. Tipe yang didukung V1.0: `string`, `text`, `integer`, `decimal`, `boolean`, `date`, `enum`, `multi_enum`. Validasi deklaratif (`min`, `max`, `max_length`, `pattern`, `decimals`) diterjemahkan ke rule Laravel di backend dan ke Zod di frontend dari **effective schema yang sama**.
3. **Nilai bertipe** di `asset_attribute_values` dengan kolom per tipe → filter/sort/index tanpa casting JSON.
4. **Snapshot skema** di `asset_container_versions.schema_snapshot` agar nilai historis selalu dapat ditafsirkan walau definisi sudah berganti versi.

## 7. Row-Level Security (lapisan pertahanan ke-3)

- Aplikasi tersambung sebagai role `asset_app` (bukan owner tabel). Migrasi dijalankan sebagai `asset_owner`.
- Tabel bisnis tenant (`assets`, `asset_*`, `asset_transactions*`, `workflow_*`, `transaction_approvals`, `tenant_*`, `locations`, `branches`, `departments`, `roles*`, `user_roles`, `user_data_scopes`, `export_jobs`, `audit_logs`) memakai `ENABLE` + `FORCE ROW LEVEL SECURITY` dengan policy:
  `organization_id = nullif(current_setting('app.organization_id', true), '')::uuid OR current_setting('app.platform_context', true) = 'on'`.
- `app.platform_context` hanya diset oleh middleware platform (endpoint platform & support session read-only) serta command konsol terotorisasi.
- `users` tidak di-RLS (dibutuhkan saat login sebelum tenant diketahui); dilindungi oleh lapis aplikasi + composite FK.
- Test suite menjalankan skenario isolasi dengan koneksi `asset_app` agar RLS benar-benar diuji.
