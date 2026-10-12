# API — Master Data (Fase 3)

Konvensi umum mengikuti [02-architecture.md §5](../02-architecture.md): JSON `snake_case`, UUID, error envelope `{ "error": { code, message, details, request_id } }`. Semua endpoint tenant menurunkan organisasi dari akun yang login; `organization_id` dari request diabaikan. ID milik organisasi lain selalu menghasilkan **404**.

## Pola daftar master

Berlaku untuk semua endpoint `GET` daftar master (cabang, departemen, lokasi, kategori, kondisi, definisi, katalog generik, referensi).

| Parameter | Arti |
|---|---|
| `search` | Cari kode atau nama (case-insensitive) |
| `status` | `active` · `inactive` · `archived`. Tanpa parameter: aktif + nonaktif (arsip disembunyikan) |
| `sort` | Kolom yang diizinkan per endpoint, awali `-` untuk menurun (mis. `-created_at`); lokasi & kategori default `tree` (urutan hierarki) |
| `page`, `per_page` | Paginasi, `per_page` maks 100 |
| `all=1` | Tanpa paginasi (maks 1000 baris) — untuk dropdown |

Pola tulis yang sama untuk master sederhana:

| Aksi | Endpoint | Catatan |
|---|---|---|
| Buat | `POST /{resource}` | `code` di-uppercase, unik per organisasi, **tidak dapat diubah** setelah dibuat |
| Ubah | `PATCH /{resource}/{id}` | `status` hanya `active`/`inactive` |
| Arsip | `POST /{resource}/{id}/archive` `{reason?}` | Ditolak 409 bila masih dipakai (`BRANCH_IN_USE`, `LOCATION_HAS_CHILDREN`, `CATEGORY_IN_USE`) |
| Pulihkan | `POST /{resource}/{id}/restore` | Induk harus aktif |

Referensi ke master lain harus milik organisasi yang sama **dan aktif**; selain itu 422 `INVALID_REFERENCE`.

## Endpoint tenant

| Resource | Endpoint | Permission (lihat/buat/ubah/arsip) |
|---|---|---|
| Cabang | `/api/branches` | `master.tenant.view/create/update/archive` |
| Departemen | `/api/departments` (`?branch_id=`) | idem |
| Lokasi | `/api/locations` (`?branch_id=&parent_id=&location_type_id=`) — `depth` untuk indentasi; sub-lokasi harus di cabang yang sama, pemindahan memperbarui seluruh subtree, siklus ditolak (`INVALID_PARENT`) | idem |
| Kategori perusahaan | `/api/tenant-masters/categories` — kategori utama wajib `generic_category_id`; subkategori mewarisi induk | idem |
| Kondisi aset | `/api/tenant-masters/conditions`; `POST /api/tenant-masters/conditions/import-generic` menyalin kondisi standar yang belum ada (idempoten) | idem |
| Definisi aset | `/api/asset-definitions` — `POST` = **adopsi** `{generic_definition_id, generic_version_id?, code, name, tenant_category_id?, description?}` | idem |

### Versi definisi perusahaan

| Endpoint | Permission | Keterangan |
|---|---|---|
| `POST /api/asset-definitions/{id}/versions` `{generic_version_id?}` | `master.tenant.update` | Draft baru; menyalin atribut tambahan & override versi terakhir. Tanpa `generic_version_id` tetap memakai versi generik yang sama (upgrade harus eksplisit). 409 `DRAFT_EXISTS` |
| `GET /api/asset-definition-versions/{id}` | `master.tenant.view` | Termasuk `attributes`, `overrides`, dan `effective_schema` (bila dirilis) |
| `PATCH /api/asset-definition-versions/{id}` `{changelog?, generic_definition_version_id?}` | `master.tenant.update` | Hanya draft (409 `VERSION_IMMUTABLE`) |
| `DELETE /api/asset-definition-versions/{id}` | `master.tenant.update` | Buang draft |
| `GET /api/asset-definition-versions/{id}/effective-schema` | `master.tenant.view` | Draft: pratinjau + `meta.issues`; rilis: skema beku (`meta.frozen = true`) |
| `POST /api/asset-definition-versions/{id}/attributes` | `master.tenant.update` | Atribut tambahan (lihat skema atribut) |
| `PATCH/DELETE /api/asset-definition-attributes/{id}` | `master.tenant.update` | Hanya pada draft |
| `PUT /api/asset-definition-versions/{id}/overrides` `{overrides: [{generic_attribute_key, overrides: {...}}]}` | `master.tenant.update` | Mengganti seluruh override; 422 `INVALID_OVERRIDE` + `details.issues` bila ada yang melanggar |
| `POST /api/asset-definition-versions/{id}/release` | `master.tenant.update` | 422 `SCHEMA_INVALID` + `details.issues`; v>1 wajib `changelog` |
| `POST /api/asset-definition-versions/{id}/deprecate` | `master.tenant.update` | Hanya versi `released` |

### Katalog Generic Master (baca saja, `catalog.generic.view`)

`GET /api/generic-masters/{categories, definitions, definitions/{id}, versions/{id}, units, location-types, conditions, transaction-types}`. Hanya definisi yang memiliki versi rilis yang tampil; versi **draft tidak pernah terlihat** (404).

## Endpoint platform (`/api/platform/generic-masters`)

| Endpoint | Permission |
|---|---|
| `GET categories`, `GET definitions`, `GET definitions/{id}`, `GET versions/{id}`, `GET units|location-types|conditions|transaction-types` | `master.generic.view` |
| `POST/PATCH categories…`, `archive/restore`, `POST/PATCH definitions…`, `POST definitions/{id}/versions`, `PATCH/DELETE versions/{id}`, `POST versions/{id}/attributes`, `PATCH/DELETE attributes/{id}`, `POST/PATCH units|location-types|conditions`, `PATCH transaction-types/{id}` | `master.generic.manage` |
| `POST versions/{id}/release`, `POST versions/{id}/deprecate` | `master.generic.release` |

`POST definitions` membuat definisi beserta draft v1 kosong. Rilis mewajibkan `changelog`; versi > 1 juga `migration_notes`. Versi rilis dan atributnya immutable (API 409 `VERSION_IMMUTABLE`; trigger DB menolak perubahan langsung). Kode jenis transaksi tetap (ditentukan kode program); hanya nama/deskripsi yang dapat diubah.

## Skema atribut

```json
{
  "key": "nomor_polisi",            // hanya saat dibuat; ^[a-z][a-z0-9_]{0,49}$, bukan kunci inti aset
  "label": "Nomor Polisi",
  "help_text": null,
  "data_type": "string",            // string | text | integer | decimal | boolean | date | enum | multi_enum
  "is_required": true,
  "validation": {"max_length": 12}, // lihat tabel di bawah
  "options": null,                  // enum/multi_enum: [{"value": "SOLAR", "label": "Solar"}]
  "unit_id": null,
  "default_value": null,            // divalidasi terhadap aturan efektif
  "is_searchable": true,
  "sort_order": 10,
  "is_controlled": true,            // platform saja: hanya berubah lewat transaksi koreksi (Fase 5)
  "overridable": ["label", "help_text"] // platform saja
}
```

| Tipe | Kunci validasi |
|---|---|
| `string` | `min_length`, `max_length` (≤ 255), `pattern` (regex) |
| `text` | `min_length`, `max_length` (≤ 5000) |
| `integer` | `min`, `max` (bilangan bulat) |
| `decimal` | `min`, `max`, `decimals` (0–6) |
| `date` | `min`, `max` (`YYYY-MM-DD`) |
| `boolean`, `enum`, `multi_enum` | — |

### Aturan override (perusahaan)

Properti hanya dapat di-override bila tercantum di `overridable` atribut generik **dan** di allowlist sistem:

| Properti | Batasan |
|---|---|
| `label`, `help_text` | Bebas (label wajib, maks 100) |
| `required` | Hanya `false → true` |
| `options` | Subset tidak kosong dari nilai generik (label & urutan generik dipertahankan) |
| `validation` | Hanya mempersempit `min`/`max`/`min_length`/`max_length`/`decimals`; `pattern` tidak dapat di-override |
| `default_value` | Harus valid terhadap aturan efektif |

`data_type`, `key`, dan `unit` tidak pernah dapat di-override.

### Effective schema

```json
{
  "schema_version": 1,
  "generic": {"definition_id": "…", "definition_code": "GEN-KENDARAAN-RODA4", "version_id": "…", "version_number": 1},
  "tenant":  {"definition_id": "…", "definition_code": "MOBIL-DIREKSI", "version_id": "…", "version_number": 1},
  "attributes": [
    {"key": "jenis_bahan_bakar", "label": "BBM", "data_type": "enum", "required": true, "validation": {},
     "options": [{"value": "BENSIN", "label": "Bensin"}, {"value": "SOLAR", "label": "Solar"}], "unit": null,
     "default_value": null, "searchable": false, "controlled": false, "sort_order": 70,
     "source": "generic", "overridden": ["label", "options", "required"]}
  ]
}
```

Urutan: atribut generik (sort_order, key) lalu atribut perusahaan. `schema_hash` = SHA-256 dari JSON kanonik (kunci objek diurutkan rekursif), sehingga input yang sama selalu menghasilkan hash yang sama — juga setelah disimpan di `jsonb`.
