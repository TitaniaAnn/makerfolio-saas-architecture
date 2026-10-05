# 08 — Uploads, storage & the image pipeline

**Audience:** developers working on makerfolio-saas.
**Scope:** image/file upload, GD resize/crop/rotate/thumbnail, the pluggable `Storage` backend, key→URL resolution, and the gallery edit/delete handlers.

> Every uploaded byte goes through one `Storage` interface (`Storage.php`) with two implementations — `LocalStorage` (disk, dev/self-host) and `S3Storage` (R2/S3, prod) — chosen at runtime by `STORAGE_DRIVER` via the `get_storage()` singleton. Rows reference their files only by a `*_storage_key` column shaped `<tenant_id>/<rel>`; `StorageUrl::urlFor()` turns a key into a public URL at render time. Phase 5.1 dropped the legacy `*_path` columns, so the storage key is now the single source of truth on the read path.

## Map — the files
| File | Role |
| --- | --- |
| `includes/Storage.php` | Interface: `put/get/delete/exists/listPrefix/tenantUsageBytes`. |
| `includes/LocalStorage.php` | Disk backend under `UPLOAD_PATH`; atomic copy+rename, realpath traversal guard. |
| `includes/S3Storage.php` | S3/R2 backend via aws-sdk; `STORAGE_TEST_FAKE=1` delegates to a LocalStorage. |
| `includes/StorageUrl.php` | `keyForTenant()` (key builder + traversal guard) and `urlFor()`/`publicUrl()`. |
| `includes/TenantStorage.php` | Tenant-scoped decorator — the ONLY producer of `<tenant_id>/` key roots; foreign-tenant keys throw. |
| `includes/StorageFactory.php` | Driver construction (`backend()`) + tenant binding (`forTenant()`); `get_storage()` delegates here. |
| `includes/UploadsTree.php` | Invariant scan: every file under `UPLOAD_PATH` must be tenant-rooted (`bin/cron/check-uploads-tree.php`). |
| `includes/ImageUpload.php` | Validate → cap → shrink → thumbnail → storage put (SaaS) / disk write (self-host); GD `crop/rotate/resize/thumbnail`. |
| `includes/MultiFileUpload.php` | Reshapes `$_FILES['k'][i]` parallel arrays into per-file arrays. |
| `includes/ImageCropHandler.php` | Crop one gallery image, regen thumb, repoint row+parent (new keys). |
| `includes/ImageRotateHandler.php` | Rotate one gallery image + its thumb (new keys). |
| `includes/ImageDeleteHandler.php` | Delete one image, promote next primary, sync parent keys. |
| `includes/TemplateFileUploader.php` | Downloadable template files (PDF + raster) for `piece_templates`. |
| `public/admin/pieces/{crop,rotate,delete}-image.php` · `public/admin/shop/{crop,rotate}-image.php` | Thin JSON endpoints wiring `$_POST`/`$_GET` into the handlers. |
| `public/uploads/.htaccess` | Denies `.php*` execution in the upload tree (self-host Apache). |
| `includes/bootstrap.php` | `get_storage()` singleton (`bootstrap.php:288`). |

## The flow

**Upload (single).** `ImageUpload::upload($file, $subdir)` (`ImageUpload.php:6`) is the spine:
1. Reject non-`UPLOAD_ERR_OK` and oversize files. The per-file cap is the tenant's plan limit when resolvable (`Plan::tenantUploadBytes`), else `MAX_IMAGE_SIZE` (`ImageUpload.php:14-23`).
2. `enforceStorageCap()` refuses *before touching disk* if the bytes would push the tenant over `max_storage_bytes` (`Plan::canCreate('storage_bytes', …)`) (`ImageUpload.php:29`, `ImageUpload.php:322`).
3. MIME sniff via `finfo` — only `image/jpeg|png|webp`; **GIF is intentionally rejected** (no thumbnail branch) (`ImageUpload.php:34-40`).
4. `uniqid('pottery_', true)`-derived filename. **SaaS tenant context** (`tenantStore()` returns the request's `TenantStorage`): the original + thumb are staged in temp files, shrunk/thumbnailed there, and `put()` through the tenant-scoped handle — keys come from `TenantStorage::keyFor`, never assembled locally, and **nothing is written under the flat `UPLOAD_PATH`**. A storage failure **aborts the upload** (post-5.1 there is no disk fallback, so a null key would render broken). **Self-host / no tenant**: the inherited `move_uploaded_file` into `UPLOAD_PATH/<subdir>` with NULL storage keys.
5. `resizeOriginalIfLarger()` shrinks the original to `MAX_ORIGINAL_DIMENSION` on the longer edge; `createThumbnail()` writes the `thumb_` sibling at `THUMB_WIDTH×THUMB_HEIGHT`.
Returns `path/thumb/url/thumb_url/storage_key/thumb_storage_key`; the controller persists `storage_key`/`thumb_storage_key`.

**Multi-file.** `MultiFileUpload::parse($_FILES['k'])` reshapes the parallel arrays into per-file arrays *keyed by original index* (so a caller can correlate POSTed labels), skipping empty/errored slots (`MultiFileUpload.php:18`). Each entry then feeds `ImageUpload::upload`.

**Display.** Templates read only `*_storage_key` and call `StorageUrl::urlFor($key)` → public URL, or `''` for a null key (templates already treat empty `src` as "no image") (`StorageUrl.php:75`).

**Crop / rotate / delete (gallery edits).** The three handlers share an identical guard shape: whitelist `imagesTable`/`parentTable`/`parentIdColumn` against fixed literals (PG can't parameterise identifiers), load the row by `id + parentIdColumn`, mutate, repoint the gallery row, and *if `is_primary`* repoint the parent's cached cover keys (`ImageCropHandler.php:42`, `ImageRotateHandler.php:39`, `ImageDeleteHandler.php:51`).
- **Crop** maps fractional rect (0..1, clamped) onto the object's real pixels, crops via `cropImageFile`, *regenerates* the thumb from the cropped original (aspect ratio changed), writes both under **new** keys, repoints, then drops the old objects best-effort (`ImageCropHandler.php:117-165`).
- **Rotate** turns original + thumb a quarter turn (`rotateImageFile`), writes under new keys, repoints, drops old (`ImageRotateHandler.php:65-105`).
- **Delete** drops both storage objects best-effort, deletes the row, then promotes the next image (`sort_order, id`) as primary and syncs the parent; `blockLastImage` refuses deleting the only image (`ImageDeleteHandler.php:69-149`).

`hasThumbSibling` distinguishes `piece` (parent carries `image_thumb_storage_key`) from `products` (parent has `image_storage_key` only) — set per endpoint (`pieces/crop-image.php:16` vs `shop/crop-image.php:17`). Endpoints are thin: `requireLogin` → `require_role(OWNER, EDITOR)` → (shop) `enforce_plan_flag('allow_shop', …)` → `csrf_verify()` → handler → `json_encode`.

**Downloadable files.** `TemplateFileUploader::upload($file)` (`TemplateFileUploader.php:21`) validates extension+MIME against its own allowlist and enforces the storage cap. SaaS: staged in a temp file and `put()` through `TenantStorage` (failure aborts — downloads stream from `file_storage_key`); self-host: moved into `public/uploads/templates/files/`. Returns `file_path/file_name/file_size/file_ext/file_storage_key`.

## Storage backends

`get_storage()` (`bootstrap.php`) delegates to `StorageFactory`: `STORAGE_DRIVER=s3` → `S3Storage`, anything else → `LocalStorage` — and, **whenever a tenant is resolved, wraps the driver in a `TenantStorage` bound to that tenant**. Relative keys get the `<tid>/` root from the wrapper; DB-stored full keys pass through only when the root matches; foreign-tenant keys throw. Platform-scope code (rollup cron, `Tenant::hardDelete` purge, backfills) uses `get_storage_backend()` / `StorageFactory::backend()` for the raw driver.

- **LocalStorage** maps keys 1:1 to paths under `UPLOAD_PATH` (default root via `defaultRoot()`). `put` does an **atomic** copy-to-`.tmp.<rand>`-then-rename to avoid a partial-file race (`LocalStorage.php:41-50`); `get` realpath-confirms the file stays under root before copying to a temp (`LocalStorage.php:62-64`); `delete` is idempotent (`LocalStorage.php:81`); `listPrefix` recursively walks and normalises separators to `/` (`LocalStorage.php:106`).
- **S3Storage** uses `aws/aws-sdk-php` (lazily constructed) with `use_path_style_endpoint` (R2/MinIO) and `ACL: public-read` on `put` (`S3Storage.php:52-74`). `listPrefix` paginates `ListObjectsV2` with the continuation token (`S3Storage.php:123-138`). `STORAGE_TEST_FAKE=1` transparently delegates every method to an internal LocalStorage rooted at a unique temp dir, so smokes/tests exercise the full contract with no network (`S3Storage.php:31-40`).
- **URL resolution.** `StorageUrl::publicUrl()` prefers `MEDIA_URL_BASE` (prod R2 behind a CDN hostname), else falls back to `UPLOAD_URL` (Caddy serving the mounted `/uploads/` volume) (`StorageUrl.php:56-66`).
- **Backfill.** `bin/migrate-uploads-to-storage.php` is the Phase-5 one-shot that pushes pre-Phase-5 on-disk files into Storage and stamps `*_storage_key`; idempotent, per-tenant try/finally with `Database::resetSchema` between tenants (`migrate-uploads-to-storage.php:1-20`).

## Invariants & gotchas

- **Keys start with `<tenant_id>/`** — `keyForTenant()` enforces it and is the cross-tenant isolation boundary (listing a prefix counts only that tenant's bytes). It rejects empty/absolute paths, `\0`, backslashes, and any `.`/`..`/empty segment (`StorageUrl.php:34-53`). `tenantUsageBytes` relies on this prefix shape.
- **Edits write NEW keys, never overwrite.** `StorageUrl::urlFor` has no cache-busting, so crop/rotate mint a fresh random basename and repoint the row; the new URL forces the browser/CDN to refetch (`ImageCropHandler.php:8-9`, `ImageRotateHandler.php:6-8`). Old objects are dropped best-effort — **a failed delete must not undo a successful edit** (orphan keys are cheap to GC).
- **Storage is canonical on both paths in tenant context.** Uploads abort on a failed `put()` (there is no disk fallback post-5.1); gallery-edit deletes of superseded objects stay best-effort. The daily `check-uploads-tree` cron asserts nothing lands outside a `<tenant_id>/` root; `bin/migrate-flat-uploads.php` repairs a tree that already has flat files.
- **Identifier whitelists, not parameters.** Table/column names are interpolated into SQL (Postgres can't bind identifiers), so all three handlers hard-check against `SAFE_*` literal lists; today's callers pass hardcoded strings, the check is defensive against a future caller routing user input (`ImageDeleteHandler.php:14-24`).
- **GIF unsupported by design** — no `IMAGETYPE_GIF` branch in `createThumbnail`; uploads of `image/gif` are rejected at MIME check (`ImageUpload.php:31-40`).
- **`ImageUpload::delete()` traversal guard** — refuses `..`, `\0`, and any realpath escaping `UPLOAD_PATH`; also removes the sibling `thumb_` file (`ImageUpload.php:345-371`).
- **`.htaccess` PHP-exec lockdown** — `public/uploads/.htaccess` denies `.php/.phtml/.phar/.pht/.php[3-8]` and disables the PHP handler/type, defeating `image.jpg.php` double-extension tricks (`.htaccess:12-27`). Self-host/Apache only; the SaaS Caddy config achieves the same separately.
- **`TemplateFileUploader` limits**: `ALLOWED_EXTS = pdf,png,jpg,jpeg,webp`, `ALLOWED_MIMES = application/pdf,image/png,image/jpeg,image/webp`, `MAX_SIZE = 10485760` (10 MB). **SVG (script-bearing) and ZIP are intentionally excluded** (`TemplateFileUploader.php:9-15`).
- **Storage cap fails open.** Any exception inside `Plan::canCreate` is logged and the upload proceeds — a billing glitch must not block real work (`ImageUpload.php:329-334`).

## Tests & verification

- `bin/storage-smoke.php` — runs both backends (S3 in test-fake mode) through the full `Storage` contract against the dev container's `UPLOAD_PATH`, proving the bootstrap singleton works.
- `bin/uploads-fallback-smoke.php` — asserts the read-path URL for legacy/new/dual/empty rows against live `UPLOAD_URL`+`MEDIA_URL_BASE` wiring.
- `bin/migrate-uploads-to-storage.php` — the idempotent Phase-5 backfill (also a verification: a clean second run is a no-op).
- `tests/StorageTest.php`, `tests/StorageUrlTest.php`, `tests/TenantStorageTest.php`, `tests/UploadsTreeTest.php`, `tests/ImageUploadTest.php`, `tests/MultiFileUploadTest.php`, `tests/TemplateFileUploaderTest.php` — unit cover the key builder, the tenant-scoped wrapper (flat keys unrepresentable, foreign-tenant keys throw), the tree invariant, URL resolution, parser, allowlists, and the pure GD `crop/rotate/resize` helpers. GD-dependent assertions use the `requireGd()` skip pattern so the suite stays green where GD is absent.

## See also
- [`07-themes-content-rendering.md`](./07-themes-content-rendering.md), [`02-auth-and-security.md`](./02-auth-and-security.md)
- `MODELS.md`, `ARCHITECTURE.md`
