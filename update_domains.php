<?php
/**
 * This script aggregates domain data from various remote sources,
 * normalizes and filters them (excluding any already known domains),
 * tests each domain via DNS, and writes the active and inactive domains
 * to separate files stored in the repository.
 * 
 * The final files will be available at:
 *  - https://raw.githubusercontent.com/meganerasam/blocklist-v2/main/working_domains.txt
 *  - https://raw.githubusercontent.com/meganerasam/blocklist-v2/main/inactive_domains.txt
 */

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

// TXT domain lists
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

/**
 * Check if a domain is working by verifying a DNS A record.
 */
function isDomainWorking($domain) {
    return checkdnsrr($domain, 'A');
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
 * 4. DNS Check: Classify new domains as active or inactive.
 */
$activeDomains = [];
$inactiveDomains = [];
foreach ($newDomains as $domain) {
    if (isDomainWorking($domain)) {
        $activeDomains[] = $domain;
    } else {
        $inactiveDomains[] = $domain;
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

/*
 * 6. Output the current working domains in one of several formats.
 */
// $format = isset($_GET['format']) ? $_GET['format'] : "1";
// $randomCount = isset($_GET['random']) ? intval($_GET['random']) : 0;
// // By default, output the final active domains.
// $outputDomains = $finalActiveDomains;
// if ($randomCount > 0 && $randomCount < count($outputDomains)) {
//     shuffle($outputDomains);
//     $outputDomains = array_slice($outputDomains, 0, $randomCount);
// }

// switch ($format) {
//     case "1":
//         header('Content-Type: text/plain');
//         $total = count($outputDomains);
//         foreach ($outputDomains as $index => $domain) {
//             echo '"' . $domain . '"';
//             echo ($index === $total - 1) ? "\n" : ",\n";
//         }
//         break;
//     case "2":
//         $result = [];
//         foreach ($outputDomains as $domain) {
//             $result[] = ["redirect" => $domain];
//         }
//         header('Content-Type: application/json');
//         echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
//         break;
//     case "3":
//         header('Content-Type: application/json');
//         echo json_encode($outputDomains, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
//         break;
//     default:
//         header('Content-Type: text/plain');
//         echo "Invalid format specified. Use format=1, format=2, or format=3.";
//         break;
// }
?>
