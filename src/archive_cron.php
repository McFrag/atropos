<?php

// Bare filenames, relying on `src/` being in PHP's include path.
require_once 'hash_util.php';
require_once 'op_result.php';
require_once 'data_token.php';
require_once 'fetch_result.php';
require_once 'archivist.php';
require_once 'archivist_loader.php';

/**
 * Runs every 1 minute (schedule this script via system crontab).
 * Usage: php archive_cron.php <path-to-options.json>
 *
 * Part 1: archives pending/retry-pending blobs to cold storage.
 * Part 2: evicts stale cache files (does not touch `blobs` -- raw_data
 *         is the safety net for any blob whose cold storage archival
 *         never succeeded).
 */

if ($argc < 2) {
    fwrite(STDERR, "usage: php archive_cron.php <options.json>\n");
    exit(1);
}

$options_file = $argv[1];
$options = json_decode(file_get_contents($options_file), true);

if ($options === null) {
    fwrite(STDERR, "failed to parse options file: {$options_file}\n");
    exit(1);
}

$db = get_db_connection($options); // implement per chosen driver

/** @var archivist $archivist */
$archivist = archivist_loader::load($options['archivist']);

$error_threshold = (int) $options['cold_storage_error_threshold'];
$batch_size = (int) ($options['cron_batch_size'] ?? 5);

// ---------------------------------------------------------------------
// Part 1: archive pending and retry-pending blobs
// ---------------------------------------------------------------------

$new_rows = fetch_blobs_by_flags($db, 0, $batch_size);    // flags&3 = 0, never attempted
$retry_rows = fetch_blobs_by_flags($db, 2, $batch_size);  // flags&3 = 2, failed before

foreach (array_merge($new_rows, $retry_rows) as $row) {
    process_blob_archival($db, $archivist, $row, $error_threshold);
}

// ---------------------------------------------------------------------
// Part 2: evict stale cache entries
// ---------------------------------------------------------------------

$eviction_years = (float) $options['cache_eviction_years'];
$cache_path = rtrim($options['cache_path'], '/');
$cutoff = time() - (int) ($eviction_years * 365.25 * 86400);

$stale_files = fetch_stale_files($db, $cutoff);

foreach ($stale_files as $file_row) {
    $path = cache_path_from_blob_id($cache_path, $file_row['blob_id']);

    if (is_file($path)) {
        @unlink($path);
    }

    delete_files_row($db, $file_row['file_id']);
    // `blobs` row is intentionally left untouched -- the data remains
    // retrievable via raw_data (if archival never succeeded) or via a
    // fresh cold-storage fetch (if it did) on the next get_blob() call.
}

// =======================================================================
// Helper functions -- implementations are placeholders for the chosen
// DB driver (mysqli/PDO); wire up to the actual schema in sql/schema.sql.
// =======================================================================

function process_blob_archival($db, archivist $archivist, array $row, $error_threshold)
{
    $result = $archivist->store($row['raw_data']);

    if ($result->error !== 0) {
        $errors = increment_files_error_count($db, $row['blob_id']);

        if ($errors >= $error_threshold) {
            set_blob_flags($db, $row['blob_id'], 3); // permanent failure
        } else {
            set_blob_flags($db, $row['blob_id'], 2); // transient failure, will retry
        }

        fwrite(STDERR, "archival failed for blob_id={$row['blob_id']}: {$result->value}\n");
        return;
    }

    set_blob_flags($db, $row['blob_id'], 1); // successfully archived
    reset_files_error_count($db, $row['blob_id']);
    null_raw_data($db, $row['blob_id']);
}

function get_db_connection($options)
{
    throw new RuntimeException('get_db_connection() not yet implemented');
}

/**
 * @return array<int, array{blob_id:int, hash:string, blob_size:int, raw_data:string}>
 */
function fetch_blobs_by_flags($db, $flags3_value, $limit)
{
    // SELECT blob_id, hash, blob_size, raw_data FROM blobs
    // WHERE flags & 3 = ? ORDER BY blob_id ASC LIMIT ?
    throw new RuntimeException('fetch_blobs_by_flags() not yet implemented');
}

function increment_files_error_count($db, $blob_id)
{
    // INSERT INTO files (blob_id, last_access, errors) VALUES (?, ?, 1)
    // ON DUPLICATE KEY UPDATE errors = errors + 1
    // then return the resulting errors count
    throw new RuntimeException('increment_files_error_count() not yet implemented');
}

function reset_files_error_count($db, $blob_id)
{
    // INSERT INTO files (blob_id, last_access, errors) VALUES (?, ?, 0)
    // ON DUPLICATE KEY UPDATE errors = 0
    throw new RuntimeException('reset_files_error_count() not yet implemented');
}

function set_blob_flags($db, $blob_id, $flags3_value)
{
    // UPDATE blobs SET flags = (flags & ~3) | ? WHERE blob_id = ?
    throw new RuntimeException('set_blob_flags() not yet implemented');
}

function null_raw_data($db, $blob_id)
{
    // UPDATE blobs SET raw_data = NULL WHERE blob_id = ?
    throw new RuntimeException('null_raw_data() not yet implemented');
}

/**
 * @return array<int, array{file_id:int, blob_id:int}>
 */
function fetch_stale_files($db, $cutoff_timestamp)
{
    // SELECT file_id, blob_id FROM files WHERE last_access < ?
    throw new RuntimeException('fetch_stale_files() not yet implemented');
}

function delete_files_row($db, $file_id)
{
    // DELETE FROM files WHERE file_id = ?
    throw new RuntimeException('delete_files_row() not yet implemented');
}

function cache_path_from_blob_id($cache_path, $blob_id)
{
    $hex = sprintf('%08x', $blob_id);
    return sprintf(
        '%s/%s/%s/%s',
        $cache_path,
        substr($hex, 0, 2),
        substr($hex, 2, 3),
        substr($hex, 5, 3)
    );
}
