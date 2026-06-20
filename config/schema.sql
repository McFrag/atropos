-- Atropos blob storage schema
-- Hot tier: MySQL holds metadata and (until archived) raw blob data.

CREATE TABLE blobs (
    blob_id    INT AUTO_INCREMENT PRIMARY KEY,
    blob_size  INT NOT NULL,
    hash       CHAR(32) NOT NULL,          -- SHA-256 truncated to 128 bits (hex)
    raw_data   LONGBLOB NULL,              -- NULL once successfully archived to cold storage
    flags      INT NOT NULL DEFAULT 0,
    -- flags bits:
    --   bit 0: set once successfully archived to cold storage (raw_data is then NULL)
    --   bit 1: set when a cold-storage save attempt failed (will retry)
    --   flags&3 = 0 -> pending, never attempted
    --   flags&3 = 1 -> successfully archived
    --   flags&3 = 2 -> failed at least once, will retry
    --   flags&3 = 3 -> permanently failed (too many errors, will not retry)

    UNIQUE KEY uq_blob_size_hash (blob_size, hash)
) ENGINE=InnoDB;

CREATE TABLE files (
    file_id     INT AUTO_INCREMENT PRIMARY KEY,
    blob_id     INT NOT NULL UNIQUE,
    last_access INT NOT NULL,              -- unix timestamp
    errors      INT NOT NULL DEFAULT 0,    -- consecutive cold-storage archival error count

    CONSTRAINT fk_files_blob
        FOREIGN KEY (blob_id) REFERENCES blobs(blob_id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_files_last_access ON files(last_access);
CREATE INDEX idx_blobs_flags ON blobs(flags);
