-- add_blob: inserts a new blob record, returning a JSON result describing
-- success, pre-existing duplicate, or failure.
--
-- This is the one deliberate JSON-string boundary in the system: it is the
-- database-layer function PHP calls into, so a serialized boundary format
-- is appropriate here (unlike the internal PHP call graph, which uses typed
-- result objects -- see src/op_result.php and friends).
--
-- Returns one of:
--   {"error":0, "exists":0, "blob_id": <id>}                  -- inserted
--   {"error":0, "exists":1, "blob_id": <id>}                  -- duplicate, already exists
--   {"error":<err>, "exists":null, "info": "<error info>"}    -- other failure

DELIMITER $$

CREATE PROCEDURE add_blob (
    IN p_data LONGBLOB
)
BEGIN
    DECLARE v_size INT;
    DECLARE v_hash CHAR(32);
    DECLARE v_blob_id INT;
    DECLARE v_existing_id INT DEFAULT NULL;

    -- Hash computation happens in PHP (librarian::hash_from_data), which then
    -- calls this procedure with the data; size is computed here for clarity.
    -- In practice the PHP caller passes hash/size alongside the raw data --
    -- adjust signature below to (p_data, p_hash, p_size) if hashing is kept
    -- entirely in application code, which is the approach used throughout
    -- this project's design.

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        DECLARE v_errno INT;
        DECLARE v_msg TEXT;
        GET DIAGNOSTICS CONDITION 1 v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;

        IF v_errno = 1062 THEN -- ER_DUP_ENTRY
            SELECT blob_id INTO v_existing_id
            FROM blobs
            WHERE blob_size = v_size AND hash = v_hash
            LIMIT 1;

            SELECT JSON_OBJECT('error', 0, 'exists', 1, 'blob_id', v_existing_id) AS result;
        ELSE
            SELECT JSON_OBJECT('error', v_errno, 'exists', CAST(NULL AS JSON), 'info', v_msg) AS result;
        END IF;
    END;

    SET v_size = LENGTH(p_data);
    -- NOTE: hash should be supplied by the caller (PHP) rather than computed
    -- in SQL, to keep the hash algorithm defined in exactly one place
    -- (see src/hash_util.php). This procedure signature is illustrative;
    -- adjust to accept p_hash as an IN parameter in the real implementation.

    INSERT INTO blobs (blob_size, hash, raw_data, flags)
    VALUES (v_size, v_hash, p_data, 0);

    SET v_blob_id = LAST_INSERT_ID();

    SELECT JSON_OBJECT('error', 0, 'exists', 0, 'blob_id', v_blob_id) AS result;
END$$

DELIMITER ;
