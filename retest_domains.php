<?php
/**
 * retest_domains.php
 *
 * This script re-tests all domains in working_domains.txt to ensure they are still active.
 * It uses parallel DNS queries via PCNTL in batches (to handle large lists, e.g. 300K+ domains).
 * For each batch (set to 1000 domains), it runs concurrent DNS checks, and:
 *   - Appends domains that still resolve ("active") to a temporary working file.
 *   - Merges domains that fail ("inactive") with the existing inactive_domains.txt (via a temporary file).
 *
 * After processing all batches, the temporary files replace the originals and a final commit is made.
 *
 * This way, if a domain is not working anymore it is removed from working_domains.txt and added to inactive_domains.txt.
 */

// Enable error reporting for debugging.
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define file paths.
$workingFile   = __DIR__ . '/working_domains.txt';         // Original working domains.
$inactiveFile  = __DIR__ . '/inactive_domains.txt';          // Existing inactive domains.
$workingTemp   = __DIR__ . '/working_domains_new.txt';       // Temporary working file.
$inactiveTemp  = __DIR__ . '/inactive_domains_new.txt';        // Temporary inactive file.

// Load domains from working file.
if (!file_exists($workingFile)) {
    die("Error: working_domains.txt not found.\n");
}
$domains = file($workingFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$totalDomains = count($domains);
echo "Retesting $totalDomains domains from working_domains.txt...\n";
flush();

// Load previous inactive domains (if any).
$prevInactive = file_exists($inactiveFile) ? file($inactiveFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

// Partition the domains into batches.
$batchSize = 1000;
$batches = array_chunk($domains, $batchSize);
$totalBatches = count($batches);
echo "Processing in $totalBatches batches (batch size: $batchSize).\n";
flush();

// Initialize temporary files: start with empty working file and preserve any previous inactive domains.
file_put_contents($workingTemp, ""); 
file_put_contents($inactiveTemp, implode("\n", $prevInactive) . "\n");

// Initialize cumulative arrays.
$finalWorking   = [];  // Domains that remain active from this retest.
$finalInactive  = $prevInactive;  // Combine previously inactive with newly failed domains.

// Define function for parallel DNS checking using PCNTL.
function parallelDnsCheck(array $domains, $concurrency = 10) {
    $active = [];
    $inactive = [];
    $childPids = []; // Map child PID to domain.
    $counter = 0;
    
    foreach ($domains as $domain) {
        $counter++;
        // Print progress in current batch every 100 domains.
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
            // Fork failed; check synchronously.
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

// Process each batch.
foreach ($batches as $batchIndex => $batch) {
    $currentBatchNumber = $batchIndex + 1;
    echo "Processing batch $currentBatchNumber of $totalBatches (" . count($batch) . " domains)...\n";
    flush();
    
    if (function_exists('pcntl_fork')) {
        list($batchActive, $batchInactive) = parallelDnsCheck($batch, 10);
    } else {
        // Fallback to synchronous checks.
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
    echo "Batch $currentBatchNumber completed: " . count($batchActive) . " active, " . count($batchInactive) . " inactive.\n";
    flush();
    
    // Merge batch results into cumulative arrays.
    $finalWorking   = array_values(array_unique(array_merge($finalWorking, $batchActive)));
    $finalInactive  = array_values(array_unique(array_merge($finalInactive, $batchInactive)));
    
    // Write cumulative results to temporary files.
    file_put_contents($workingTemp, implode("\n", $finalWorking) . "\n");
    file_put_contents($inactiveTemp, implode("\n", $finalInactive) . "\n");
    
    echo "Committing batch $currentBatchNumber results...\n";
    flush();
    // Commit incremental changes.
    exec("git add " . escapeshellarg($workingTemp) . " " . escapeshellarg($inactiveTemp));
    exec("git config user.name 'github-actions[bot]'");
    exec("git config user.email 'github-actions[bot]@users.noreply.github.com'");
    $commitMessage = "Retest incremental update: Processed batch $currentBatchNumber of $totalBatches (" . date('Y-m-d H:i') . ")";
    exec("git commit -m " . escapeshellarg($commitMessage));
    exec("git push");
    echo "Batch $currentBatchNumber committed.\n";
    flush();
}

// After processing all batches, replace original files with the new temporary files.
rename($workingTemp, $workingFile);
rename($inactiveTemp, $inactiveFile);

echo "Retesting completed.\n";
echo "Total domains retested: $totalDomains\n";
echo "Final working domains: " . count($finalWorking) . "\n";
echo "Total inactive domains (new + previous): " . count($finalInactive) . "\n";
flush();

// Commit the final update.
exec("git add " . escapeshellarg($workingFile) . " " . escapeshellarg($inactiveFile));
exec("git commit -m " . escapeshellarg("Final retest update: Completed retesting domains (" . date('Y-m-d H:i') . ")"));
exec("git push");
echo "Final update committed.\n";
flush();
?>
