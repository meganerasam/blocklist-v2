<?php
// Usage: php merge_chunks.php <num_chunks>
$numChunks = isset($argv[1]) ? intval($argv[1]) : 30;
$workingAll = [];
$inactiveAll = [];

for ($i = 1; $i <= $numChunks; $i++) {
    $w = file(__DIR__ . "/working_domains_chunk_{$i}_result.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $workingAll = array_merge($workingAll, $w);
    $in = file(__DIR__ . "/inactive_domains_chunk_{$i}_result.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $inactiveAll = array_merge($inactiveAll, $in);
}
$workingAll = array_unique($workingAll);
$inactiveAll = array_unique($inactiveAll);

file_put_contents(__DIR__ . "/working_domains.txt", implode("\n", $workingAll) . "\n");
file_put_contents(__DIR__ . "/inactive_domains.txt", implode("\n", $inactiveAll) . "\n");
echo "Merged all chunks.\n";