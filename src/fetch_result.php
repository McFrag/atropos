<?php

/**
 * Terminal value object returned by archivist::fetch(). Like data_token,
 * deliberately does NOT extend op_result -- it is the final answer at the
 * archivist layer, not an intermediate value to be unwrapped.
 */
class fetch_result
{
    /** @var int 0 on success */
    public $error;

    /** @var mixed the object's data if error===0 */
    public $data;

    public function __construct($error, $data)
    {
        $this->error = $error;
        $this->data = $data;
    }
}
