<?php
$j = json_decode(file_get_contents(__DIR__ . '/IVData.json'), true);
$hits = 0;
foreach ($j as $r) {
    foreach ($r as $k => $v) {
        if (is_string($v) && strpos($v, '10890') !== false) {
            echo "FIELD:$k = $v\n  full row: " . json_encode($r) . "\n\n";
            $hits++;
            break;
        }
    }
}
echo "TOTAL HITS: $hits\n";
