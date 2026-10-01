<?php
declare(strict_types=1);

const ROOT = __DIR__ . '/..';

/**
 * Makers whose models this site may call. Canadian labs only.
 * The Hopscotch model id is "provider/model"; either the provider half or
 * the model family has to belong to one of these makers.
 */
const CANADIAN_MAKERS = [
    'cohere' => ['command', 'c4ai', 'aya'],
];

/** Hosts an answer may link to. Subdomains are included. */
const OFFICIAL_DOMAINS = [
    'canada.ca', 'gc.ca', 'elections.ca', 'forces.ca', 'canadapost-postescanada.ca',
    'ontario.ca', 'quebec.ca', 'gouv.qc.ca', 'gov.bc.ca', 'alberta.ca', 'saskatchewan.ca',
    'gov.mb.ca', 'gnb.ca', 'novascotia.ca', 'princeedwardisland.ca', 'gov.nl.ca',
    'yukon.ca', 'gov.nt.ca', 'gov.nu.ca',
];

function load_env(): void
{
    $file = ROOT . '/.env';
    if (!is_file($file)) {
        return;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        // Real environment variables win over the file.
        if (getenv($key) === false) {
            putenv($key . '=' . trim(trim($value), "\"'"));
        }
    }
}

function env(string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value === false || $value === '' ? $default : $value;
}

function is_canadian_model(string $model): bool
{
    [$provider, $name] = array_pad(explode('/', strtolower($model), 2), 2, '');
    if ($name === '') {
        return false;
    }
    foreach (CANADIAN_MAKERS as $maker => $families) {
        if ($provider === $maker) {
            return true;
        }
        foreach ($families as $family) {
            if (str_starts_with($name, $family . '-')) {
                return true;
            }
        }
    }
    return false;
}

function is_official_url(string $url): bool
{
    $parts = parse_url($url);
    if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
        return false;
    }
    $host = strtolower($parts['host']);
    foreach (OFFICIAL_DOMAINS as $domain) {
        if ($host === $domain || str_ends_with($host, '.' . $domain)) {
            return true;
        }
    }
    return false;
}

load_env();
