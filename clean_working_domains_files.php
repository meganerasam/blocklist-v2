<?php
/**
 * clean_working_domains_files.php
 *
 * This script cleans up a dated working-domains file by removing any domains
 * that have since become inactive. It:
 *
 *  1. Loads domains from working_domains_YYYYMMDD.txt.
 *  2. Loads all domains from inactive_domains.txt.
 *  3. Removes any overlap (i.e. drops any domain from the recent file that now appears in inactive_domains).
 *  4. Writes the pruned list back to working_domains_YYYYMMDD.txt.
 *  5. Commits both the dated working file and the inactive_domains.txt.
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Paths
$workingFileRecent  = __DIR__ . "/working_domains_20250416.txt";
$inactiveFile       = __DIR__ . '/inactive_domains.txt';

// 1. Load recent working domains
if (!file_exists($workingFileRecent)) {
    die("Error: {$workingFileRecent} not found.\n");
}
$recent = file($workingFileRecent, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$total  = count($recent);
echo "Loaded $total domains from {$workingFileRecent}.\n";
flush();

// 2. Load inactive domains
$inactive = file_exists($inactiveFile)
    ? file($inactiveFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
    : [];
echo "Loaded " . count($inactive) . " inactive domains.\n";
flush();

// 3. Filter out any of the recent domains that are now inactive
$filtered = array_values(array_diff($recent, $inactive));
$removedCount = $total - count($filtered);
echo "Removing $removedCount domains that are in inactive list.\n";
flush();

// 4. Write the cleaned list back
file_put_contents($workingFileRecent, implode("\n", $filtered) . "\n");
echo "Wrote " . count($filtered) . " domains back to {$workingFileRecent}.\n";
flush();

// 5. Commit both files to Git
exec("git add " . escapeshellarg($workingFileRecent));
exec("git config user.name 'github-actions[bot]'");
exec("git config user.email 'github-actions[bot]@users.noreply.github.com'");
$msg = "Prune retested domains: removed $removedCount inactive entries from working_domains_20250416.txt";
exec("git commit -m " . escapeshellarg($msg));
exec("git push");

echo "Committed updated {$workingFileRecent} and {$inactiveFile}.\n";
flush();
?>
