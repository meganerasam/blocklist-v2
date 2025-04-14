<?php
/**
 * This script aggregates domain data from various remote sources,
 * normalizes and filters them (excluding any already known domains),
 * tests each domain via asynchronous DNS queries (using Amp),
 * and writes the active and inactive domains to separate files stored in the repository.
 * 
 * The final files will be available at:
 *  - https://raw.githubusercontent.com/meganerasam/blocklist-v2/main/working_domains.txt
 *  - https://raw.githubusercontent.com/meganerasam/blocklist-v2/main/inactive_domains.txt
 *
 * Note: This version uses Amp for asynchronous DNS checks. Make sure to install the required dependencies:
 *   composer require amphp/dns amphp/amp
 */

require __DIR__ . '/vendor/autoload.php'; // Load Composer autoloader

// Enable error reporting (adjust for production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// File paths (stored locally in the repository)
$activeFile = __DIR__ . '/working_domains.txt';
$inactiveFile = __DIR__ . '/inactive_domains.txt';

// Load previously stored active and inactive domains (if available)
$prevActiveDomains = file_exists($activeFile) ? file($activeFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
$prevInactiveDomains = file_exists($inactiveFile) ? file($inactiveFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

// Define source URLs

// In this example, we'll only process CSV sources. You can uncomment or add TXT URLs as needed.
// $txtUrls = [
//     'https://raw.githubusercontent.com/anudeepND/blacklist/master/adservers.txt',
//     'https://pgl.yoyo.org/adservers/serverlist.php?hostformat=hosts&showintro=0&mimetype=plaintext',
//     'https://v.firebog.net/hosts/AdguardDNS.txt',
//     'https://v.firebog.net/hosts/Admiral.txt',
//     'https://v.firebog.net/hosts/Easylist.txt',
//     'https://raw.githubusercontent.com/StevenBlack/hosts/refs/heads/master/data/KADhosts/hosts'
// ];
$txtUrls = [];

// Whitelist URLs (use GitHub raw URLs for direct content)
$txtUrlsWhitelist = [
    'https://raw.githubusercontent.com/meganerasam/whitelist-domains/master/whitelistes.txt',
    'https://raw.githubusercontent.com/meganerasam/whitelist-domains/master/whitelistes2.txt'
];

// CSV domain lists
$csvUrls = [
    'https://raw.githubusercontent.com/meganerasam/blocklist/main/blocklist.csv'
];

/**
 * Normalize a domain string.
 */
function normalizeDomain($domain) {
    // Remove any leading IP patterns (e.g. "0.0.0.0 " or "127.0.0.1 ")
    $domain = preg_replace('/^(0\.0\.0\.0|127\.0\.0\.1)\s+/', '', $domain);
    $domain = trim($domain);
    $domain = str_replace('"', '', $domain);
    $domain = rtrim($domain, ',');
    // Remove http:// or https://
    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    // Remove any trailing slash
    $domain = rtrim($domain, '/');
    return $domain;
}

// Container for new domains retrieved from sources
$newDomains = [];

/*
 * 1. Fetch TXT source domains.
 */
foreach ($txtUrls as $txtUrl) {
    $txtContent = file_get_contents($txtUrl);
    if ($txtContent === false) {
        die("Error: Unable to fetch TXT data from $txtUrl.");
    }
    $lines = explode("\n", $txtContent);
    foreach ($lines as $line) {
        $line = trim($line);
        // Skip empty lines and comments
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        $domain = normalizeDomain($line);
        // Only add if not already in the previous active or inactive lists.
        if (!in_array($domain, $prevActiveDomains) && !in_array($domain, $prevInactiveDomains)) {
            $newDomains[] = $domain;
        }
    }
}

/*
 * 2. Fetch CSV source domains.
 */
foreach ($csvUrls as $csvUrl) {
    $csvContent = file_get_contents($csvUrl);
    if ($csvContent === false) {
        die("Error: Unable to fetch CSV data from $csvUrl.");
    }
    $lines = explode("\n", $csvContent);
    $rows = [];
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $rows[] = str_getcsv($line);
    }
    if (count($rows) < 1) {
        die("Error: CSV data is empty or invalid.");
    }
    // Assume first row is headers.
    $headers = array_shift($rows);
    $colIndex = array_search("Block List v3", $headers);
    if ($colIndex === false) {
        die("Error: 'Block List v3' column not found in CSV.");
    }
    foreach ($rows as $row) {
        $domain = normalizeDomain($row[$colIndex]);
        if ($domain === '' || $domain === "Grand Total") {
            continue;
        }
        if (!in_array($domain, $prevActiveDomains) && !in_array($domain, $prevInactiveDomains)) {
            $newDomains[] = $domain;
        }
    }
}

/*
 * 3. Fetch and normalize whitelist domains.
 */
$whitelistDomains = [];
foreach ($txtUrlsWhitelist as $txtUrl) {
    $txtContent = file_get_contents($txtUrl);
    if ($txtContent === false) {
        die("Error: Unable to fetch TXT data from $txtUrl.");
    }
    $lines = explode("\n", $txtContent);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        $whitelistDomains[] = normalizeDomain($line);
    }
}
// Remove any whitelisted domains from the newly fetched domains.
$newDomains = array_diff($newDomains, $whitelistDomains);
// Remove duplicates and reindex.
$newDomains = array_values(array_unique($newDomains));

/*
 * 4. Asynchronous DNS Check using Amp:
 * Classify new domains as active or inactive by performing concurrent DNS lookups.
 */
use Amp\Loop;
use Amp\Dns;
use Amp\Promise;
use function Amp\call;
use function Amp\Promise\all;

$activeDomains = [];
$inactiveDomains = [];

// Run the asynchronous event loop.
Loop::run(function () use ($newDomains, &$activeDomains, &$inactiveDomains) {
    // Create an array of promises, one per domain.
    $promises = [];
    foreach ($newDomains as $domain) {
        // For each domain, create a promise that resolves to true (working) or false (inactive).
        $promises[$domain] = call(function () use ($domain) {
            try {
                // Amp's DNS query returns an array of records on success.
                yield Dns\query($domain, 'A');
                return true;
            } catch (\Throwable $e) {
                return false;
            }
        });
    }
    // Wait for all the promises to resolve.
    $results = yield all($promises);
    // Classify domains based on the result.
    foreach ($results as $domain => $isWorking) {
        if ($isWorking) {
            $activeDomains[] = $domain;
        } else {
            $inactiveDomains[] = $domain;
        }
    }
});

/*
 * 5. Build final domain lists.
 * Merge previously known domains with newly validated ones.
 */
$finalActiveDomains = array_values(array_unique(array_merge($prevActiveDomains, $activeDomains)));
$finalInactiveDomains = array_values(array_unique(array_merge($prevInactiveDomains, $inactiveDomains)));

// Write the updated lists to the respective files.
file_put_contents($activeFile, implode("\n", $finalActiveDomains));
file_put_contents($inactiveFile, implode("\n", $finalInactiveDomains));

// Optionally output a summary
echo "DNS Check Completed.\n";
echo "New active domains: " . count($activeDomains) . "\n";
echo "New inactive domains: " . count($inactiveDomains) . "\n";
?>
