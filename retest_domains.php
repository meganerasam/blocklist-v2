<?php
/**
 * retest_domains.php
 *
 * This script re-tests all domains in working_domains.txt to ensure they are still active.
 * It uses parallel DNS queries via PCNTL in batches (to handle large lists, e.g. 300K+ domains).
 * Domains that fail are removed from the working list and added to inactive_domains.txt.
 * Afterwards, any domains in the daily recent file (working_domains_YYYYMMDD.txt)
 * that have become inactive are also pruned out.
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// File paths
$workingFile       = __DIR__ . '/working_domains.txt';
$inactiveFile      = __DIR__ . '/inactive_domains.txt';
$recentFile        = __DIR__ . "/working_domains_20250416.txt";

// 1. Load existing working domains
if (!file_exists($workingFile)) {
    die("Error: working_domains.txt not found.\n");
}
$domains = file($workingFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$totalDomains = count($domains);
echo "Retesting $totalDomains domains from working_domains.txt...\n";
flush();

// 2. Load existing inactive domains
$prevInactive = file_exists($inactiveFile)
    ? file($inactiveFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
    : [];

// 3. Prepare temporary files
$workingTemp  = __DIR__ . '/working_domains_new.txt';
$inactiveTemp = __DIR__ . '/inactive_domains_new.txt';

// Initialize: empty working temp, preload inactive temp
file_put_contents($workingTemp, "");
file_put_contents($inactiveTemp, implode("\n", $prevInactive) . "\n");

// Cumulative arrays
$finalWorking  = [];
$finalInactive = $prevInactive;

/**
 * parallelDnsCheck()
 * Uses pcntl_fork() to check DNS in parallel.
 * Returns [activeDomains, inactiveDomains]
 */
function parallelDnsCheck(array $batch, int $concurrency = 10): array {
    $active = [];
    $inactive = [];
    $pids    = [];
    $count   = 0;

    foreach ($batch as $domain) {
        $count++;
        if ($count % 1000 === 0) {
            echo "  Checked $count domains in this batch...\n";
            flush();
        }
        // throttle child processes
        while (count($pids) >= $concurrency) {
            $ended = pcntl_wait($status);
            if ($ended > 0 && isset($pids[$ended])) {
                $code = pcntl_wexitstatus($status);
                if ($code === 0) {
                    $active[] = $pids[$ended];
                } else {
                    $inactive[] = $pids[$ended];
                }
                unset($pids[$ended]);
            }
        }
        $pid = pcntl_fork();
        if ($pid === -1) {
            // fallback synchronous
            if (checkdnsrr($domain, 'A')) {
                $active[] = $domain;
            } else {
                $inactive[] = $domain;
            }
        } elseif ($pid === 0) {
            // child
            exit(checkdnsrr($domain, 'A') ? 0 : 1);
        } else {
            // parent
            $pids[$pid] = $domain;
        }
    }
    // wait remaining
    while (count($pids) > 0) {
        $ended = pcntl_wait($status);
        if ($ended > 0 && isset($pids[$ended])) {
            $code = pcntl_wexitstatus($status);
            if ($code === 0) {
                $active[] = $pids[$ended];
            } else {
                $inactive[] = $pids[$ended];
            }
            unset($pids[$ended]);
        }
    }
    return [$active, $inactive];
}

// 4. Batch processing
$batchSize    = 2500;
$batches      = array_chunk($domains, $batchSize);
$totalBatches = count($batches);
echo "Processing in $totalBatches batches (up to $batchSize each)...\n";
flush();

foreach ($batches as $i => $batch) {
    $num = $i + 1;
    echo "Batch $num/$totalBatches: testing " . count($batch) . " domains...\n";
    flush();

    if (function_exists('pcntl_fork')) {
        list($batchActive, $batchInactive) = parallelDnsCheck($batch, 10);
    } else {
        // fallback
        $batchActive   = [];
        $batchInactive = [];
        foreach ($batch as $domain) {
            if (checkdnsrr($domain, 'A')) {
                $batchActive[] = $domain;
            } else {
                $batchInactive[] = $domain;
            }
        }
    }

    echo "  Active: " . count($batchActive)
       . "  Inactive: " . count($batchInactive) . "\n";
    flush();

    // Merge results
    $finalWorking  = array_values(array_unique(array_merge($finalWorking,  $batchActive)));
    $finalInactive = array_values(array_unique(array_merge($finalInactive, $batchInactive)));

    // Write temps
    file_put_contents($workingTemp,  implode("\n", $finalWorking)  . "\n");
    file_put_contents($inactiveTemp, implode("\n", $finalInactive) . "\n");

    // Commit this batch’s progress
    echo "  Committing batch $num changes...\n";
    flush();
    exec("git add " . escapeshellarg($workingTemp) . " " . escapeshellarg($inactiveTemp));
    exec("git config user.name 'github-actions[bot]'");
    exec("git config user.email 'github-actions[bot]@users.noreply.github.com'");
    $msg = "Retest batch $num/$totalBatches (" . date('Y-m-d H:i') . ")";
    exec("git commit -m " . escapeshellarg($msg));
    exec("git push");
    echo "  Batch $num committed.\n";
    flush();
}

// 5. Replace originals
rename($workingTemp,  $workingFile);
rename($inactiveTemp, $inactiveFile);

echo "DNS retest complete: $totalDomains total domains.\n";
echo "Final working count: " . count($finalWorking) . "\n";
echo "Final inactive count: " . count($finalInactive) . "\n";
flush();

// 6. Prune the recent file of any newly inactive domains
if (file_exists($recentFile)) {
    $recent = file($recentFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    // remove any domain now in finalInactive
    $pruned = array_values(array_diff($recent, $finalInactive));
    $removedCount = count($recent) - count($pruned);

    echo "Pruning recent file: removing $removedCount inactive domains...\n";
    flush();

    file_put_contents($recentFile, implode("\n", $pruned) . "\n");

    // Commit the prune
    exec("git add " . escapeshellarg($recentFile));
    exec("git config user.name 'github-actions[bot]'");
    exec("git config user.email 'github-actions[bot]@users.noreply.github.com'");
    $msg2 = "Pruned recent file of $removedCount inactive domains (" . date('Y-m-d H:i') . ")";
    exec("git commit -m " . escapeshellarg($msg2));
    exec("git push");
    echo "Recent file pruned and committed.\n";
    flush();
}

echo "Retest process fully complete.\n";
flush();
