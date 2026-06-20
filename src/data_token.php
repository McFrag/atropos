<?php

/**
 * Terminal value object returned by librarian::get_blob() and
 * librarian::get_archive_data(). Deliberately does NOT extend op_result --
 * this is a final answer handed back to the public caller, not an
 * intermediate value meant to be unwrapped and discarded.
 */
class data_token
{
    /** @var int 0 on success */
    public $error;

    /** @var mixed actual data if error===0, else an error message */
    public $data;

    public function __construct($error, $data)
    {
        $this->error = $error;
        $this->data = $data;
    }
}
