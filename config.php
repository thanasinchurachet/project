<?php
/**
 * Central settings. Values come from `.env` in the project root (see `.env.example`),
 * then from `backend/.env`, then from real environment variables. Nothing secret lives in the code.
 */
if (!function_exists('env')) {
    function bb_load_env_file(string $file): void
    {
        if (!is_readable($file)) return;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            $v = trim($v, "\"'");
            if ($k !== '' && getenv($k) === false) putenv("$k=$v");
        }
    }
    bb_load_env_file(__DIR__ . '/.env');
    bb_load_env_file(__DIR__ . '/backend/.env');

    /** Read a setting, with a default for local development. */
    function env(string $key, $default = '')
    {
        $v = getenv($key);
        return ($v === false || $v === '') ? $default : $v;
    }
}
