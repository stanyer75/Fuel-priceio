<?php

/*
 * Pre-flight check, run first by `composer setup` (or directly:
 * `php scripts/check-requirements.php`).
 *
 * Plain PHP with no dependencies, so it works on a bare machine before
 * `composer install`. Fails fast with the exact fix instead of a stack trace
 * half-way through setup. Found necessary by fresh-system testing: without
 * pdo_sqlite, setup died deep inside a migration with "could not find driver".
 */

$errors = [];
$warnings = [];
$windows = PHP_OS_FAMILY === 'Windows';
$ini = php_ini_loaded_file() ?: '(none loaded: copy php.ini-development to php.ini next to php.exe)';

// 1. PHP version (matches composer.json "php": "^8.3").
if (PHP_VERSION_ID < 80300) {
    $errors[] = 'PHP 8.3 or newer is required; this is PHP '.PHP_VERSION.'.';
}

// 2. Extensions: Laravel's own requirements, plus pdo_sqlite for the database.
$required = [
    'pdo_sqlite' => 'the local SQLite database',
    'mbstring' => 'Laravel (string handling)',
    'openssl' => 'HTTPS calls to the price and routing APIs',
    'fileinfo' => 'Laravel (file handling)',
    'ctype' => 'Laravel',
    'tokenizer' => 'Laravel',
    'dom' => 'Laravel',
    'xml' => 'Laravel',
];
foreach ($required as $extension => $why) {
    if (! extension_loaded($extension)) {
        $errors[] = $windows
            ? "PHP extension '{$extension}' is not enabled (needed for {$why}). In {$ini} remove the ';' from the line ';extension={$extension}'."
            : "PHP extension '{$extension}' is not installed (needed for {$why}). Install it, e.g. 'sudo apt install php8.3-".str_replace('pdo_', '', $extension)."' on Debian/Ubuntu.";
    }
}

// 3. Can PHP verify HTTPS certificates? Only a warning: the sample data and
//    the test suite work offline. Live searches need it.
if (extension_loaded('openssl')) {
    $caFile = ini_get('curl.cainfo') ?: ini_get('openssl.cafile') ?: openssl_get_cert_locations()['default_cert_file'] ?? '';
    $caDir = ini_get('openssl.capath') ?: openssl_get_cert_locations()['default_cert_dir'] ?? '';
    if (! (($caFile && is_file($caFile)) || ($caDir && is_dir($caDir)))) {
        $warnings[] = 'PHP has no CA certificate bundle, so live searches will fail with "cURL error 60: unable to get local issuer certificate".'
            ." In {$ini} set curl.cainfo and openssl.cafile to a CA bundle"
            .($windows ? ' (e.g. "C:\Program Files\Git\usr\ssl\certs\ca-bundle.crt", or download cacert.pem from https://curl.se/docs/caextract.html)' : '')
            .'. Do not disable TLS verification.';
    }
}

foreach ($warnings as $warning) {
    fwrite(STDERR, "WARNING: {$warning}\n");
}

if ($errors) {
    fwrite(STDERR, "\nFuel Detour can't be set up on this PHP yet:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, "  - {$error}\n");
    }
    fwrite(STDERR, "\nFix the above, open a new terminal, and run `composer setup` again.\n");
    exit(1);
}

echo 'Requirements OK: PHP '.PHP_VERSION.', all required extensions present'.($warnings ? ' (see warning above)' : '').".\n";
