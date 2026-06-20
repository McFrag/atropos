<?php

/**
 * Shared hash utility. Defines the hash algorithm used throughout the
 * project (for deduplication keys and content-addressed storage paths)
 * in exactly one place, so it never drifts between librarian and the
 * various archivist implementations.
 */
class hash_util
{
    /**
     * Returns SHA-256 truncated to 128 bits (32 hex chars).
     *
     * @param string $data
     * @return string
     */
    public static function hash_from_data($data)
    {
        return substr(hash('sha256', $data), 0, 32);
    }
}
