<?php

require_once __DIR__ . '/hash_util.php';
require_once __DIR__ . '/op_result.php';
require_once __DIR__ . '/data_token.php';
require_once __DIR__ . '/fetch_result.php';
require_once __DIR__ . '/blob_token.php';
require_once __DIR__ . '/archivist.php';

/**
 * Main orchestrator for the blob storage system. Public surface is
 * deliberately minimal: add_blob() and get_blob() are the only two
 * entry points external callers need. Everything else is an internal
 * implementation detail.
 */
class librarian
{
    /** @var array */
    private $options;

    /** @var archivist|null lazily initialized */
    private $archivist_instance;

    /** @var mysqli|PDO database handle -- type depends on chosen driver */
    private $db;

    public function __construct($json_file)
    {
        $this->options = json_decode(file_get_contents($json_file), true);
        // $this->db = ... (database connection setup using $this->options['db'])
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Adds a new blob. Returns a JSON string (the deliberate external
     * boundary format for this public method):
     *   {"error":0, "handle": "<handle>"}
     *   {"error":<err>, "info": "<error info>"}
     *
     * @param string $data
     * @return string JSON
     */
    public function add_blob($data)
    {
        $sql_result = $this->call_sql_add_blob($data);
        $decoded = json_decode($sql_result, true);

        if ($decoded['error'] !== 0) {
            return json_encode(['error' => $decoded['error'], 'info' => $decoded['info'] ?? 'unknown error']);
        }

        $blob_id = $decoded['blob_id'];

        if (!empty($decoded['exists'])) {
            $handle_result = $this->id_to_handle($blob_id);
            if ($handle_result->error !== 0) {
                return json_encode(['error' => $handle_result->error, 'info' => $handle_result->value]);
            }
            return json_encode(['error' => 0, 'handle' => $handle_result->value]);
        }

        $token = new blob_token();
        $token->set_blob_id($blob_id);
        $token->set_data($data);

        $save_result = $this->save($token);
        if ($save_result->error !== 0) {
            return json_encode(['error' => $save_result->error, 'info' => $save_result->value]);
        }

        $handle_result = $this->id_to_handle($blob_id);
        if ($handle_result->error !== 0) {
            return json_encode(['error' => $handle_result->error, 'info' => $handle_result->value]);
        }

        return json_encode(['error' => 0, 'handle' => $handle_result->value]);
    }

    /**
     * Retrieves a blob by its public handle.
     *
     * @param string $handle
     * @return data_token
     */
    public function get_blob($handle)
    {
        $id_result = $this->handle_to_id($handle);
        if ($id_result->error !== 0) {
            return new data_token($id_result->error, $id_result->value);
        }
        $blob_id = $id_result->value;

        $row = $this->fetch_blob_row($blob_id);
        if ($row === null) {
            return new data_token(1, "blob not found for id {$blob_id}");
        }

        $this->touch_last_access($blob_id);

        $flags3 = $row['flags'] & 3;

        // Pending (never attempted) or permanently failed: raw_data is
        // still in MySQL either way, since it is only nulled once
        // successfully archived (flags&3 === 1).
        if ($flags3 === 0 || $flags3 === 3) {
            return new data_token(0, $row['raw_data']);
        }

        $cache_check = $this->check_cache($blob_id, $row['blob_size']);
        if ($cache_check !== null) {
            return new data_token(0, $cache_check);
        }

        $archive_result = $this->get_archive_data($row['hash'], $row['blob_size']);
        if ($archive_result->error !== 0) {
            return new data_token($archive_result->error, $archive_result->data);
        }

        $token = new blob_token();
        $token->set_blob_id($blob_id);
        $token->set_hash($row['hash']);
        $token->set_data($archive_result->data);

        $save_result = $this->save($token);
        if ($save_result->error !== 0) {
            // Data was retrieved successfully even though the cache
            // repopulation failed -- still return the data to the caller,
            // but the failure is visible in the error code/info for logging.
            return new data_token(0, $archive_result->data);
        }

        return new data_token(0, $archive_result->data);
    }

    // ------------------------------------------------------------------
    // Internal: hashing
    // ------------------------------------------------------------------

    private function hash_from_data($data)
    {
        return hash_util::hash_from_data($data);
    }

    // ------------------------------------------------------------------
    // Internal: handle <-> blob_id translation
    // ------------------------------------------------------------------

    /**
     * @param string $handle
     * @return id_result
     */
    private function handle_to_id($handle)
    {
        $key = $this->options['symmetric_key'];
        $cipher = 'aes-256-cbc';
        $iv_length = openssl_cipher_iv_length($cipher);

        $raw = base64_decode($handle, true);
        if ($raw === false || strlen($raw) <= $iv_length) {
            return new id_result(1, 'malformed handle');
        }

        $iv = substr($raw, 0, $iv_length);
        $ciphertext = substr($raw, $iv_length);

        $decrypted = openssl_decrypt($ciphertext, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        if ($decrypted === false || !ctype_digit($decrypted)) {
            return new id_result(2, 'failed to decrypt handle');
        }

        return new id_result(0, (int) $decrypted);
    }

    /**
     * @param int $blob_id
     * @return handle_result
     */
    private function id_to_handle($blob_id)
    {
        $key = $this->options['symmetric_key'];
        $cipher = 'aes-256-cbc';
        $iv_length = openssl_cipher_iv_length($cipher);
        $iv = openssl_random_pseudo_bytes($iv_length);

        $ciphertext = openssl_encrypt((string) $blob_id, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            return new handle_result(1, 'failed to encrypt blob_id');
        }

        return new handle_result(0, base64_encode($iv . $ciphertext));
    }

    // ------------------------------------------------------------------
    // Internal: cache management
    // ------------------------------------------------------------------

    private function path_from_id($blob_id)
    {
        $hex = sprintf('%08x', $blob_id);
        $cache_path = rtrim($this->options['cache_path'], '/');

        return sprintf(
            '%s/%s/%s/%s',
            $cache_path,
            substr($hex, 0, 2),
            substr($hex, 2, 3),
            substr($hex, 5, 3)
        );
    }

    /**
     * Returns cached data if a valid cache file exists for $blob_id with
     * the expected $expected_size, otherwise null.
     *
     * @param int $blob_id
     * @param int $expected_size
     * @return string|null
     */
    private function check_cache($blob_id, $expected_size)
    {
        $path = $this->path_from_id($blob_id);

        clearstatcache(true, $path);
        if (!is_file($path) || filesize($path) !== $expected_size) {
            return null;
        }

        $data = file_get_contents($path);
        return $data === false ? null : $data;
    }

    /**
     * Ensures a cache file exists for $blob. blob_id MUST be valid on
     * the token. Falls back to cold storage if data is not already
     * present on the token and no valid cache file exists yet.
     *
     * @param blob_token $blob
     * @return save_result
     */
    private function save($blob)
    {
        if (!$blob->has_valid_blob_id()) {
            return new save_result(1, 'blob_token has no valid blob_id');
        }

        $path = $this->path_from_id($blob->blob_id);

        clearstatcache(true, $path);
        if (is_file($path) && $blob->has_valid_data() && filesize($path) === strlen($blob->data)) {
            return new save_result(0, $path);
        }

        if ($blob->has_valid_data()) {
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                return new save_result(2, "failed to create cache directory: {$dir}");
            }

            $written = @file_put_contents($path, $blob->data, LOCK_EX);
            if ($written === false || $written !== strlen($blob->data)) {
                return new save_result(3, "failed to write cache file: {$path}");
            }

            return new save_result(0, $path);
        }

        // No data on the token yet -- must come from cold storage.
        if (!$blob->has_valid_hash()) {
            return new save_result(4, 'cannot retrieve from archive: blob_token has no valid hash');
        }

        $row = $this->fetch_blob_row($blob->blob_id);
        if ($row === null) {
            return new save_result(5, "could not look up size for blob_id {$blob->blob_id}");
        }

        $archive_result = $this->get_archive_data($blob->hash, $row['blob_size']);
        if ($archive_result->error !== 0) {
            return new save_result($archive_result->error, $archive_result->data);
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return new save_result(6, "failed to create cache directory: {$dir}");
        }

        $written = @file_put_contents($path, $archive_result->data, LOCK_EX);
        if ($written === false || $written !== strlen($archive_result->data)) {
            return new save_result(7, "failed to write cache file: {$path}");
        }

        return new save_result(0, $path);
    }

    // ------------------------------------------------------------------
    // Internal: cold storage access
    // ------------------------------------------------------------------

    /**
     * @param string $hash
     * @param int $size
     * @return data_token
     */
    private function get_archive_data($hash, $size)
    {
        $archivist = $this->get_archivist();

        $fetch_result = $archivist->fetch($hash, $size);
        if ($fetch_result->error !== 0) {
            return new data_token($fetch_result->error, $fetch_result->data);
        }

        return new data_token(0, $fetch_result->data);
    }

    /**
     * @return archivist
     */
    private function get_archivist()
    {
        if ($this->archivist_instance === null) {
            $class = $this->options['archivist']['class'];
            $config_file = $this->options['archivist']['config_file'];

            $this->archivist_instance = new $class();
            $this->archivist_instance->init($config_file);
        }

        return $this->archivist_instance;
    }

    // ------------------------------------------------------------------
    // Internal: database access (placeholders -- adapt to chosen driver)
    // ------------------------------------------------------------------

    /**
     * Calls the SQL add_blob procedure and returns its JSON result string.
     *
     * @param string $data
     * @return string JSON
     */
    private function call_sql_add_blob($data)
    {
        // Implementation depends on chosen DB driver (mysqli/PDO).
        // e.g.: CALL add_blob(?) with $data bound as a LONGBLOB parameter,
        // returning the single JSON result column described in
        // sql/add_blob.sql.
        throw new RuntimeException('call_sql_add_blob() not yet implemented');
    }

    /**
     * @param int $blob_id
     * @return array{blob_size:int,hash:string,raw_data:?string,flags:int}|null
     */
    private function fetch_blob_row($blob_id)
    {
        // Implementation depends on chosen DB driver.
        // SELECT blob_size, hash, raw_data, flags FROM blobs WHERE blob_id = ?
        throw new RuntimeException('fetch_blob_row() not yet implemented');
    }

    /**
     * Updates files.last_access for $blob_id, but only if the existing
     * value is more than a day old (or no row exists yet), to avoid a
     * write on every single read.
     *
     * @param int $blob_id
     * @return void
     */
    private function touch_last_access($blob_id)
    {
        // Implementation depends on chosen DB driver.
        // INSERT INTO files (blob_id, last_access, errors) VALUES (?, ?, 0)
        // ON DUPLICATE KEY UPDATE
        //   last_access = IF(last_access < ?, ?, last_access)
        throw new RuntimeException('touch_last_access() not yet implemented');
    }
}
