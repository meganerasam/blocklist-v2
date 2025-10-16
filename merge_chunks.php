<?php
// Usage: php merge_chunks.php <num_chunks>
$numChunks = isset($argv[1]) ? intval($argv[1]) : 30;
$workingAll = [];
$inactiveAll = [];

for ($i = 1; $i <= $numChunks; $i++) {
    $wFile = __DIR__ . "/results/retest-results-{$i}/working_domains_chunk_{$i}_result.txt";
    $inFile = __DIR__ . "/results/retest-results-{$i}/inactive_domains_chunk_{$i}_result.txt";
    if (file_exists($wFile)) {
        $w = file($wFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $workingAll = array_merge($workingAll, $w);
    }
    if (file_exists($inFile)) {
        $in = file($inFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $inactiveAll = array_merge($inactiveAll, $in);
    }
}
$workingAll = array_unique($workingAll);
$inactiveAll = array_unique($inactiveAll);

file_put_contents(__DIR__ . "/working_domains.txt", implode("\n", $workingAll) . "\n");
file_put_contents(__DIR__ . "/inactive_domains.txt", implode("\n", $inactiveAll) . "\n");
echo "Merged all chunks.\n";