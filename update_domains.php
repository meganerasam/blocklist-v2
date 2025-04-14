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
 * It uses PHP's built‑in PCNTL functions for parallel processing and commits
 * changes incrementally (every 1000 domains) so that progress is saved.
 */

// Enable error reporting for debugging (adjust as needed)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// File paths (stored locally in the repository)
$activeFile   = __DIR__ . '/working_domains.txt';
$inactiveFile = __DIR__ . '/inactive_domains.txt';

// Load previously stored active and inactive domains (if available)
$prevActiveDomains   = file_exists($activeFile) ? file($activeFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
$prevInactiveDomains = file_exists($inactiveFile) ? file($inactiveFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

// Define source URLs

// TXT domain lists – uncomment or add as needed
$txtUrls = [
    'https://raw.githubusercontent.com/anudeepND/blacklist/master/adservers.txt',
    'https://pgl.yoyo.org/adservers/serverlist.php?hostformat=hosts&showintro=0&mimetype=plaintext',
    'https://v.firebog.net/hosts/AdguardDNS.txt'
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
        die("Error: Unable to fetch TXT data from $txtUrl.\n");
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
        die("Error: Unable to fetch CSV data from $csvUrl.\n");
    }
    $lines = explode("\n", $csvContent);
    $rows = [];
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $rows[] = str_getcsv($line);
    }
    if (count($rows) < 1) {
        die("Error: CSV data is empty or invalid.\n");
    }
    $headers = array_shift($rows);
    $colIndex = array_search("Block List v3", $headers);
    if ($colIndex === false) {
        die("Error: 'Block List v3' column not found in CSV.\n");
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
        die("Error: Unable to fetch TXT data from $txtUrl.\n");
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

echo "Fetched " . count($newDomains) . " new domains.\n";
flush();

// Partition newDomains into batches of 1000.
$batchSize = 1000;
$batches = array_chunk($newDomains, $batchSize);

echo "Processing in " . count($batches) . " batches (batch size: $batchSize).\n";
flush();

/*
 * 4. Define a function for parallel DNS checking using PCNTL.
 */
function parallelDnsCheck(array $domains, $concurrency = 10) {
    $active = [];
    $inactive = [];
    $childPids = []; // Mapping pid => domain
    $counter = 0;    // Counter within this batch

    foreach ($domains as $domain) {
        $counter++;
        // Print progress within the batch every 100 domains.
        if ($counter % 100 === 0) {
            echo "Processed $counter domains in current batch.\n";
            flush();
        }

        // Enforce concurrency limit.
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
            // Fork failed: fallback synchronous check.
            if (checkdnsrr($domain, 'A')) {
                $active[] = $domain;
            } else {
                $inactive[] = $domain;
            }
        } elseif ($pid === 0) {
            // Child process: perform DNS check.
            if (checkdnsrr($domain, 'A')) {
                exit(0);
            } else {
                exit(1);
            }
        } else {
            // Parent: record child PID.
            $childPids[$pid] = $domain;
        }
    }
    // Wait for remaining children.
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

/*
 * 5. Process each batch.
 * For each batch, run the DNS check, merge results with previously stored domains,
 * update files, and commit changes.
 */
$totalActive   = $prevActiveDomains;
$totalInactive = $prevInactiveDomains;

foreach ($batches as $batchIndex => $batch) {
    echo "Processing batch " . ($batchIndex + 1) . " of " . count($batches) . " (" . count($batch) . " domains)...\n";
    flush();

    if (function_exists('pcntl_fork')) {
        list($batchActive, $batchInactive) = parallelDnsCheck($batch, 10);
    } else {
        // Fallback: synchronous checking.
        $batchActive = [];
        $batchInactive = [];
        foreach ($batch as $domain) {
            if (checkdnsrr($domain, 'A')) {
                $batchActive[] = $domain;
            } else {
                $batchInactive[] = $domain;
            }
        }
    }
    echo "Batch " . ($batchIndex + 1) . " completed: " . count($batchActive) . " active, " . count($batchInactive) . " inactive.\n";
    flush();

    // Merge the new batch results with cumulative results.
    $totalActive   = array_values(array_unique(array_merge($totalActive, $batchActive)));
    $totalInactive = array_values(array_unique(array_merge($totalInactive, $batchInactive)));

    // Write updated lists to files.
    file_put_contents($activeFile, implode("\n", $totalActive));
    file_put_contents($inactiveFile, implode("\n", $totalInactive));

    // Commit changes incrementally for this batch.
    echo "Committing batch " . ($batchIndex + 1) . " results...\n";
    flush();
    exec("git add " . escapeshellarg($activeFile) . " " . escapeshellarg($inactiveFile));
    exec("git config user.name 'github-actions[bot]'");
    exec("git config user.email 'github-actions[bot]@users.noreply.github.com'");
    $lastDomain = end($batch);
    $commitMessage = "Incremental update: Processed batch " . ($batchIndex + 1) . " up to domain " . $lastDomain . " (" . date('Y-m-d H:i') . ")";
    exec("git commit -m " . escapeshellarg($commitMessage));
    exec("git push");
    echo "Batch " . ($batchIndex + 1) . " committed.\n";
    flush();

    // Optionally update $prevActiveDomains and $prevInactiveDomains for subsequent batches.
    $prevActiveDomains   = $totalActive;
    $prevInactiveDomains = $totalInactive;
}

echo "DNS Check Completed.\n";
echo "New active domains (this run): " . (count($totalActive) - count($prevActiveDomains)) . "\n";
echo "New inactive domains (this run): " . (count($totalInactive) - count($prevInactiveDomains)) . "\n";
echo "Total active domains: " . count($totalActive) . "\n";
echo "Total inactive domains: " . count($totalInactive) . "\n";
flush();
?>
