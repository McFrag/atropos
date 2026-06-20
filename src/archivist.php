<?php

/**
 * Abstract base for all cold-storage backends. Collapsed from a separate
 * i_archivist interface + shared trait into a single abstract class, since
 * this project only ever needs implementations that can share this base
 * (fs_archivist, s3_archivist) -- no implementation needs to extend some
 * unrelated third-party base class instead. If that need ever arises,
 * the contract here can be split back out into an interface at that point.
 */
abstract class archivist
{
    /**
     * Reads backend-specific configuration from a JSON file.
     *
     * @param string $json_file
     * @return void
     */
    abstract public function init($json_file);

    /**
     * Retrieves data from cold storage.
     *
     * @param string $hash
     * @param int $size
     * @return fetch_result
     */
    abstract public function fetch($hash, $size);

    /**
     * Saves data to cold storage. Hash and size are derived internally
     * from $data (via get_info()), never trusted from the caller --
     * this guarantees the storage key always matches the actual stored
     * content by construction.
     *
     * @param string $data
     * @return op_result error/info shape; value is null on success
     */
    abstract public function store($data);

    /**
     * Backend-specific key/path derivation from a known hash + size.
     * Each subclass implements this for its own storage scheme
     * (filesystem path, S3 object key, etc.).
     *
     * @param string $hash
     * @param int $size
     * @return string
     */
    abstract protected function key_from_info($hash, $size);

    /**
     * Computes [hash, size] from raw data. Shared by every backend so the
     * hash algorithm is defined in exactly one place (hash_util).
     *
     * @param string $data
     * @return array{0:string,1:int} [$hash, $size]
     */
    protected function get_info($data)
    {
        $hash = hash_util::hash_from_data($data);
        $size = strlen($data);
        return [$hash, $size];
    }

    /**
     * Derives the storage key/path directly from raw data, without the
     * caller needing to compute hash/size itself first.
     *
     * @param string $data
     * @return string
     */
    protected function key_from_data($data)
    {
        [$hash, $size] = $this->get_info($data);
        return $this->key_from_info($hash, $size);
    }
}
