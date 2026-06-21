#!/usr/bin/env php
<?php

// This file lives in atropos/cli/, one level below the project root,
// as a sibling of atropos/src/ -- not inside it. So unlike files within
// src/ (which rely on src/ being in PHP's include path), this entry
// point resolves its dependencies explicitly relative to its own
// location, since a bare include path is not guaranteed for a
// standalone CLI invocation.
require_once __DIR__ . '/../src/librarian.php';
require_once __DIR__ . '/../src/data_token.php';

/**
 * atropos_cli -- command line interface to librarian.
 *
 * Usage:
 *   atropos_cli add_blob [source_file]
 *     Adds a blob. Reads from source_file if given, otherwise from stdin.
 *     Prints the JSON result from librarian::add_blob().
 *
 *   atropos_cli get_blob [handle]
 *     Retrieves a blob. Uses handle if given, otherwise reads a handle
 *     from stdin. Prints the blob's data on success, or an error message
 *     on failure (see exit codes below).
 *
 * Configuration:
 *   The librarian options are read from a JSON file named
 *   "atropos_config.json" in the process's current working directory
 *   (i.e. wherever the CLI is invoked from -- not relative to this
 *   script's own location).
 *
 * Exit codes:
 *   0  success
 *   1  usage error (bad/missing arguments, or missing config)
 *   2  operation failed (error reported by librarian)
 */

const CONFIG_FILENAME = 'atropos_config.json';

function usage_and_exit($message = null)
{
    if ($message !== null) {
        fwrite(STDERR, "{$message}\n");
    }
    fwrite(STDERR, "usage:\n");
    fwrite(STDERR, "  atropos_cli add_blob [source_file]\n");
    fwrite(STDERR, "  atropos_cli get_blob [handle]\n");
    exit(1);
}

function read_all_stdin()
{
    $data = stream_get_contents(STDIN);
    if ($data === false) {
        fwrite(STDERR, "failed to read from stdin\n");
        exit(1);
    }
    return $data;
}

function read_source_data($source_file)
{
    if ($source_file !== null) {
        if (!is_file($source_file) || !is_readable($source_file)) {
            fwrite(STDERR, "cannot read source file: {$source_file}\n");
            exit(1);
        }
        $data = file_get_contents($source_file);
        if ($data === false) {
            fwrite(STDERR, "failed to read source file: {$source_file}\n");
            exit(1);
        }
        return $data;
    }

    return read_all_stdin();
}

function read_handle($handle_arg)
{
    if ($handle_arg !== null) {
        return $handle_arg;
    }

    // Trim a single trailing newline if present (e.g. piped via echo),
    // but otherwise leave the handle untouched.
    $handle = read_all_stdin();
    return rtrim($handle, "\n");
}

// -----------------------------------------------------------------------

if ($argc < 2) {
    usage_and_exit('missing command');
}

$command = $argv[1];

// Resolved against the CURRENT WORKING DIRECTORY of the process, not
// this script's own location -- so the CLI must be run from wherever
// atropos_config.json actually lives.
$config_file = getcwd() . '/' . CONFIG_FILENAME;

if (!is_file($config_file) || !is_readable($config_file)) {
    fwrite(STDERR, "cannot read config file: {$config_file}\n");
    fwrite(STDERR, "(expected '" . CONFIG_FILENAME . "' in the current directory)\n");
    exit(1);
}

$librarian = new librarian($config_file);

switch ($command) {
    case 'add_blob':
        $source_file = $argv[2] ?? null;
        $data = read_source_data($source_file);

        $result_json = $librarian->add_blob($data);
        echo $result_json . "\n";

        $decoded = json_decode($result_json, true);
        exit(($decoded['error'] ?? 1) === 0 ? 0 : 2);

    case 'get_blob':
        $handle_arg = $argv[2] ?? null;
        $handle = read_handle($handle_arg);

        /** @var data_token $token */
        $token = $librarian->get_blob($handle);

        if ($token->error !== 0) {
            fwrite(STDERR, "error: {$token->data}\n");
            exit(2);
        }

        // Write raw data to stdout -- may be binary, so no trailing
        // newline is added.
        echo $token->data;
        exit(0);

    default:
        usage_and_exit("unknown command: {$command}");
}
