#!/usr/bin/env php
<?php

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
 *   The CLI reads a bootstrap file named "atropos_cli.json" from the
 *   process's current working directory. This file is DIFFERENT from
 *   the librarian options file -- it only tells the CLI how to find
 *   librarian itself and which options file to hand it:
 *
 *     {
 *       "atropos": {
 *         "path": "../../src",                       // where librarian.php lives
 *         "config": "../../config/config.example.json" // librarian's own options file
 *       }
 *     }
 *
 *   Both "path" and "config" are resolved relative to the directory
 *   atropos_cli.json itself lives in (not the CLI's own __DIR__, and
 *   not the process's CWD beyond locating atropos_cli.json itself).
 *
 * Exit codes:
 *   0  success
 *   1  usage error (bad/missing arguments, or missing/invalid bootstrap config)
 *   2  operation failed (error reported by librarian)
 */

const BOOTSTRAP_FILENAME = 'atropos_cli.json';

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

/**
 * Resolves a path that may be relative, against $base_dir.
 *
 * @param string $path
 * @param string $base_dir
 * @return string
 */
function resolve_path($path, $base_dir)
{
    if ($path === '' || $path[0] === '/') {
        return $path; // already absolute (or empty -- caller's problem)
    }
    return rtrim($base_dir, '/') . '/' . $path;
}

// -----------------------------------------------------------------------

if ($argc < 2) {
    usage_and_exit('missing command');
}

$command = $argv[1];

$bootstrap_file = getcwd() . '/' . BOOTSTRAP_FILENAME;

if (!is_file($bootstrap_file) || !is_readable($bootstrap_file)) {
    fwrite(STDERR, "cannot read bootstrap config file: {$bootstrap_file}\n");
    fwrite(STDERR, "(expected '" . BOOTSTRAP_FILENAME . "' in the current directory)\n");
    exit(1);
}

$bootstrap = json_decode(file_get_contents($bootstrap_file), true);

if (!isset($bootstrap['atropos']['path'], $bootstrap['atropos']['config'])) {
    fwrite(STDERR, "bootstrap config file is missing 'atropos.path' or 'atropos.config': {$bootstrap_file}\n");
    exit(1);
}

$bootstrap_dir = dirname($bootstrap_file);
$src_path = resolve_path($bootstrap['atropos']['path'], $bootstrap_dir);
$librarian_options_file = resolve_path($bootstrap['atropos']['config'], $bootstrap_dir);

require_once rtrim($src_path, '/') . '/librarian.php';
require_once rtrim($src_path, '/') . '/data_token.php';

if (!is_file($librarian_options_file) || !is_readable($librarian_options_file)) {
    fwrite(STDERR, "cannot read librarian options file: {$librarian_options_file}\n");
    exit(1);
}

$librarian = new librarian($librarian_options_file);

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
