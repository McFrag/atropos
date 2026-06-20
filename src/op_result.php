<?php

/**
 * Base class for internal operation results used across librarian's
 * private call graph.
 *
 * Shape: on success, $value holds the payload and $error is 0.
 * On failure, $value holds the error info/message and $error is non-zero.
 *
 * This is a "stepping stone" object: it is meant to be unwrapped by the
 * caller and discarded, not handed back as a final answer to an external
 * caller. Contrast with data_token / fetch_result, which ARE final answers
 * at their respective layers and intentionally do not extend this class.
 */
class op_result
{
    /** @var int */
    public $error;

    /** @var mixed */
    public $value;

    public function __construct($error, $value)
    {
        $this->error = $error;
        $this->value = $value;
    }

    /**
     * Returns the success value, or throws if this result represents
     * a failure. Use this when the calling method has no sensible way
     * to proceed past a failure.
     *
     * @return mixed
     * @throws RuntimeException
     */
    public function unwrap()
    {
        if ($this->error !== 0) {
            $message = is_string($this->value)
                ? $this->value
                : "operation failed with error code {$this->error}";
            throw new RuntimeException($message);
        }
        return $this->value;
    }
}

/** Result of librarian::handle_to_id() -- value is the decrypted blob_id. */
class id_result extends op_result {}

/** Result of librarian::id_to_handle() -- value is the encrypted handle. */
class handle_result extends op_result {}

/** Result of librarian::save() -- value is the cache filename. */
class save_result extends op_result {}
