# Atropos

A PHP/MySQL content-addressed blob storage system with three tiers:
MySQL (hot), local filesystem cache (warm), and pluggable cold storage
(`fs_archivist`, `s3_archivist`).

## Layout

```
sql/
  schema.sql        -- blobs and files tables
  add_blob.sql       -- stored procedure used by librarian::add_blob()

src/
  hash_util.php      -- single source of truth for the hash algorithm
                        (SHA-256 truncated to 128 bits)
  op_result.php       -- op_result base class + id_result/handle_result/
                        save_result -- typed "stepping stone" results used
                        internally within librarian's private call graph
  data_token.php      -- terminal value object returned by get_blob() /
                        get_archive_data()
  fetch_result.php    -- terminal value object returned by archivist::fetch()
  blob_token.php      -- carries blob identity/content between librarian's
                        internal methods
  archivist.php       -- abstract base class for cold storage backends
  fs_archivist.php     -- filesystem-backed cold storage implementation
  s3_archivist.php     -- S3-backed cold storage implementation
                        (requires aws/aws-sdk-php via composer)
  librarian.php        -- main orchestrator; add_blob() and get_blob() are
                        the only public entry points

cron/
  archive_cron.php     -- runs every minute: archives pending blobs to cold
                        storage, retries previously-failed ones, and evicts
                        stale cache entries

config.example.json          -- example librarian options file
fs_archivist.example.json     -- example fs_archivist backend config
s3_archivist.example.json     -- example s3_archivist backend config
```

## Important: deployment requirement

The options JSON file (see `config.example.json`) contains the symmetric
key used to encrypt/decrypt blob handles. **This file must be stored
outside any web-served directory** and be readable only by the user the
PHP process runs as. If it leaks, every handle in the system becomes
reversible and every `blob_id` becomes enumerable.

## Implementation notes / TODOs

Several methods are left as documented placeholders pending a concrete
choice of DB driver (mysqli vs PDO):

- `librarian::call_sql_add_blob()`
- `librarian::fetch_blob_row()`
- `librarian::touch_last_access()`
- all DB helper functions in `cron/archive_cron.php`

`s3_archivist` requires `composer require aws/aws-sdk-php`.

## Design notes

- **Deduplication**: `(blob_size, hash)` is a UNIQUE constraint on `blobs`.
  Two blobs only collide if they match on both dimensions.
- **`blobs.flags` bits 0-1** track cold storage state:
  - `flags&3 = 0` -- pending, never attempted
  - `flags&3 = 1` -- successfully archived (`raw_data` is NULL)
  - `flags&3 = 2` -- failed at least once, will retry
  - `flags&3 = 3` -- permanently failed (too many errors); `raw_data`
    remains populated as the safety net
- **`blob_token.flags`** is unrelated to `blobs.flags` despite the shared
  name -- it tracks which fields on the PHP object are populated, not
  cold storage state.
- **Cache eviction** never touches the `blobs` table or `raw_data` --
  only cache files and `files` rows are removed, so a blob's data is
  never made unrecoverable by eviction alone.
- **`last_access`** is only updated if the existing value is more than a
  day old, to avoid a write on every single read.
