<?php

/**
 * Carries blob identity/content between librarian's internal methods.
 * The `flags` bitmask here tracks which fields on THIS object are
 * currently populated/valid -- distinct from, and unrelated to, the
 * `blobs.flags` column in MySQL, which tracks cold-storage archival
 * state. The two are intentionally named the same but mean different
 * things at different layers; do not confuse them when reading code
 * that touches both.
 */
class blob_token
{
    // bit positions for $flags
    const FLAG_BLOB_ID_VALID = 1 << 0;
    const FLAG_HASH_VALID    = 1 << 1;
    const FLAG_HANDLE_VALID  = 1 << 2;
    const FLAG_DATA_VALID    = 1 << 3;

    /** @var int|null */
    public $blob_id;

    /** @var string|null */
    public $hash;

    /** @var string|null */
    public $handle;

    /** @var string|null raw blob data */
    public $data;

    /** @var int bitmask of FLAG_* constants indicating which fields are valid */
    public $flags = 0;

    public function set_blob_id($blob_id)
    {
        $this->blob_id = $blob_id;
        $this->flags |= self::FLAG_BLOB_ID_VALID;
    }

    public function set_hash($hash)
    {
        $this->hash = $hash;
        $this->flags |= self::FLAG_HASH_VALID;
    }

    public function set_handle($handle)
    {
        $this->handle = $handle;
        $this->flags |= self::FLAG_HANDLE_VALID;
    }

    public function set_data($data)
    {
        $this->data = $data;
        $this->flags |= self::FLAG_DATA_VALID;
    }

    public function has_valid_blob_id()
    {
        return (bool) ($this->flags & self::FLAG_BLOB_ID_VALID);
    }

    public function has_valid_hash()
    {
        return (bool) ($this->flags & self::FLAG_HASH_VALID);
    }

    public function has_valid_handle()
    {
        return (bool) ($this->flags & self::FLAG_HANDLE_VALID);
    }

    public function has_valid_data()
    {
        return (bool) ($this->flags & self::FLAG_DATA_VALID);
    }
}
