<?php
$j = json_decode(file_get_contents(__DIR__ . '/IVData.json'), true);
$targets = ['18-118-447', '18118447', '93139', '31463', '31461', 'MB100', 'MIRLON'];
foreach ($targets as $t) {
    $hits = 0;
    foreach ($j as $r) {
        if (!is_array($r)) continue;
        foreach ($r as $k => $v) {
            if (is_string($v) && stripos($v, $t) !== false) {
                echo "[$t] FIELD:$k = $v\n";
                $hits++;
                if ($hits > 3) break 2;
                break;
            }
        }
    }
    echo "[$t] hits: $hits\n\n";
}
