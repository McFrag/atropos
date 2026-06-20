<?php

/**
 * Filesystem-backed cold storage. Files are stored under a path derived
 * from the popcount of each 32-bit word of the content hash, plus the
 * hash and size in the filename itself -- making the filename a proper
 * content address (collision-safe by construction, independent of the
 * directory-spreading scheme used above it).
 */
class fs_archivist extends archivist
{
    /** @var string */
    private $base_path;

    /** @var int milliseconds-equivalent; seconds between lock-wait retries */
    private $lock_retry_wait_seconds = 1;

    /** @var int number of lock-wait retries before giving up */
    private $lock_retry_count = 3;

    public function init($json_file)
    {
        $options = json_decode(file_get_contents($json_file), true);
        $this->base_path = rtrim($options['storage_path'], '/');

        if (isset($options['lock_retry_wait_seconds'])) {
            $this->lock_retry_wait_seconds = (int) $options['lock_retry_wait_seconds'];
        }
        if (isset($options['lock_retry_count'])) {
            $this->lock_retry_count = (int) $options['lock_retry_count'];
        }
    }

    public function fetch($hash, $size)
    {
        $path = $this->path_from_info($hash, $size);

        if (!is_file($path)) {
            return new fetch_result(1, "file not found in cold storage: {$path}");
        }

        $data = file_get_contents($path);
        if ($data === false) {
            return new fetch_result(2, "failed to read file from cold storage: {$path}");
        }

        if (strlen($data) !== $size) {
            return new fetch_result(3, "size mismatch reading from cold storage: {$path}");
        }

        return new fetch_result(0, $data);
    }

    public function store($data)
    {
        $path = $this->key_from_data($data);
        $expected_size = strlen($data);

        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                return new op_result(1, "failed to create directory: {$dir}");
            }
        }

        $fh = @fopen($path, 'xb'); // exclusive create -- fails if file already exists
        if ($fh !== false) {
            $written = fwrite($fh, $data);
            fclose($fh);

            if ($written !== $expected_size) {
                return new op_result(2, "incomplete write to cold storage: {$path}");
            }

            return new op_result(0, null);
        }

        // File already exists or is locked by a concurrent writer.
        // Wait and check whether the existing file matches the expected
        // size, rather than re-attempting a write that could race with
        // the other writer.
        for ($attempt = 0; $attempt < $this->lock_retry_count; $attempt++) {
            sleep($this->lock_retry_wait_seconds);

            clearstatcache(true, $path);
            if (is_file($path) && filesize($path) === $expected_size) {
                return new op_result(0, null);
            }
        }

        return new op_result(3, "could not confirm cold storage write after retries: {$path}");
    }

    /**
     * Public on this implementation since the path scheme is meaningful
     * and potentially useful for external tooling/debugging on a
     * filesystem-backed archivist specifically.
     *
     * Returns PATH0/X0/X1/X2/X3/hash-MMMMMMMM where Xi is the number of
     * bits set in the i-th 32-bit word of the (128-bit) hash, and
     * MMMMMMMM is the 8-char hex representation of size.
     *
     * @param string $hash
     * @param int $size
     * @return string
     */
    public function path_from_info($hash, $size)
    {
        $words = str_split($hash, 8); // four 32-bit hex words from a 128-bit hash

        $popcounts = array_map(function ($word) {
            return (string) substr_count(
                str_pad(base_convert($word, 16, 2), 32, '0', STR_PAD_LEFT),
                '1'
            );
        }, $words);

        $size_hex = sprintf('%08x', $size);

        return sprintf(
            '%s/%s/%s/%s/%s/%s-%s',
            $this->base_path,
            $popcounts[0],
            $popcounts[1],
            $popcounts[2],
            $popcounts[3],
            $hash,
            $size_hex
        );
    }

    protected function key_from_info($hash, $size)
    {
        return $this->path_from_info($hash, $size);
    }
}
