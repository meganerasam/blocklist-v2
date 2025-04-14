<?php
/**
 * update_domains.php
 *
 * This script aggregates domain data from various remote sources,
 * normalizes and filters them (excluding any already known domains),
 * tests each domain via DNS queries in parallel using PCNTL,
 * and writes the active and inactive domains to separate files stored
 * in the repository.
 *
 * The final files will be available at:
 *  - https://raw.githubusercontent.com/meganerasam/blocklist-v2/main/working_domains.txt
 *  - https://raw.githubusercontent.com/meganerasam/blocklist-v2/main/inactive_domains.txt
 *
 * This version does not rely on Composer/third‑party packages.
 * It uses PHP's built‑in PCNTL functions for parallel processing.
 */

// Enable error reporting for debugging (adjust as needed)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// File paths (stored locally in the repository)
$activeFile = __DIR__ . '/working_domains.txt';
$inactiveFile = __DIR__ . '/inactive_domains.txt';

// Load previously stored active and inactive domains (if available)
$prevActiveDomains = file_exists($activeFile) ? file($activeFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
$prevInactiveDomains = file_exists($inactiveFile) ? file($inactiveFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

// Define source URLs

// TXT domain lists (uncomment and add URLs as needed)
// $txtUrls = [
//     'https://raw.githubusercontent.com/anudeepND/blacklist/master/adservers.txt',
//     'https://pgl.yoyo.org/adservers/serverlist.php?hostformat=hosts&showintro=0&mimetype=plaintext',
//     'https://v.firebog.net/hosts/AdguardDNS.txt',
//     'https://v.firebog.net/hosts/Admiral.txt',
//     'https://v.firebog.net/hosts/Easylist.txt',
//     'https://raw.githubusercontent.com/StevenBlack/hosts/refs/heads/master/data/KADhosts/hosts'
// ];
$txtUrls = [
    'https://raw.githubusercontent.com/anudeepND/blacklist/master/adservers.txt',
    'https://pgl.yoyo.org/adservers/serverlist.php?hostformat=hosts&showintro=0&mimetype=plaintext'
];

// Whitelist URLs (using GitHub raw URLs)
$txtUrlsWhitelist = [
    'https://raw.githubusercontent.com/meganerasam/whitelist-domains/master/whitelistes.txt',
    'https://raw.githubusercontent.com/meganerasam/whitelist-domains/master/whitelistes2.txt'
];

// CSV domain lists
$csvUrls = [
    'https://raw.githubusercontent.com/meganerasam/blocklist/main/blocklist.csv'
];

/**
 * Normalize a domain string by removing IP prefixes, quotes, URL schemes, and trailing slashes.
 */
function normalizeDomain($domain) {
    $domain = preg_replace('/^(0\.0\.0\.0|127\.0\.0\.1)\s+/', '', $domain);
    $domain = trim($domain);
    $domain = str_replace('"', '', $domain);
    $domain = rtrim($domain, ',');
    $domain = preg_replace('/^https?:\/\//i', '', $domain);
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
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        $domain = normalizeDomain($line);
        // Only add if not already in previous active or inactive lists.
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
// Remove whitelisted domains from newDomains.
$newDomains = array_diff($newDomains, $whitelistDomains);
// Remove duplicates and reindex.
$newDomains = array_values(array_unique($newDomains));

/*
 * 4. DNS Check (Parallel using PCNTL if available).
 */
$activeDomains = [];
$inactiveDomains = [];

/**
 * Performs parallel DNS checks using pcntl_fork.
 *
 * @param array $domains List of domains to check.
 * @param int   $concurrency Maximum number of simultaneous child processes.
 * @return array Tuple: [activeDomains, inactiveDomains]
 */
function parallelDnsCheck(array $domains, $concurrency = 10) {
    $active = [];
    $inactive = [];
    $childPids = []; // Mapping pid => domain

    foreach ($domains as $domain) {
        // Enforce concurrency limit: wait until there's room.
        while (count($childPids) >= $concurrency) {
            $exitedPid = pcntl_wait($status);
            if ($exitedPid > 0 && isset($childPids[$exitedPid])) {
                $exitCode = pcntl_wexitstatus($status);
                if ($exitCode === 0) {
                    $active[] = $childPids[$exitedPid];
                } else {
                    $inactive[] = $childPids[$exitedPid];
                }
                unset($childPids[$exitedPid]);
            }
        }
        // Fork a child process.
        $pid = pcntl_fork();
        if ($pid == -1) {
            // Fork failed; process synchronously as fallback.
            if (checkdnsrr($domain, 'A')) {
                $active[] = $domain;
            } else {
                $inactive[] = $domain;
            }
        } elseif ($pid === 0) {
            // Child process: perform DNS check and exit with code 0 (active) or 1 (inactive)
            if (checkdnsrr($domain, 'A')) {
                exit(0);
            } else {
                exit(1);
            }
        } else {
            // Parent process: record child PID and associated domain.
            $childPids[$pid] = $domain;
        }
    }
    // Wait for any remaining child processes.
    while (count($childPids) > 0) {
        $exitedPid = pcntl_wait($status);
        if ($exitedPid > 0 && isset($childPids[$exitedPid])) {
            $exitCode = pcntl_wexitstatus($status);
            if ($exitCode === 0) {
                $active[] = $childPids[$exitedPid];
            } else {
                $inactive[] = $childPids[$exitedPid];
            }
            unset($childPids[$exitedPid]);
        }
    }
    return [$active, $inactive];
}

// Use parallel DNS checking if PCNTL is available.
if (function_exists('pcntl_fork')) {
    list($activeDomains, $inactiveDomains) = parallelDnsCheck($newDomains, 10);
} else {
    // Fallback: synchronous DNS check.
    foreach ($newDomains as $domain) {
        if (checkdnsrr($domain, 'A')) {
            $activeDomains[] = $domain;
        } else {
            $inactiveDomains[] = $domain;
        }
    }
}

/*
 * 5. Build final domain lists.
 * Merge previously known domains with newly validated ones.
 */
$finalActiveDomains = array_values(array_unique(array_merge($prevActiveDomains, $activeDomains)));
$finalInactiveDomains = array_values(array_unique(array_merge($prevInactiveDomains, $inactiveDomains)));

// Write the updated lists to the respective files.
file_put_contents($activeFile, implode("\n", $finalActiveDomains));
file_put_contents($inactiveFile, implode("\n", $finalInactiveDomains));

echo "DNS Check Completed.\n";
echo "New active domains: " . count($activeDomains) . "\n";
echo "New inactive domains: " . count($inactiveDomains) . "\n";
