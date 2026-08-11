<?php
$path = $argv[1] ?? null;
if (! $path) { fwrite(STDERR, "usage: php _read_xlsx.php <file.xlsx>\n"); exit(1); }

$tmp = sys_get_temp_dir() . '/xlsx_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$zip = new ZipArchive();
$zip->open($path);
$zip->extractTo($tmp);
$zip->close();

$NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

$shared = [];
if (is_file($tmp . '/xl/sharedStrings.xml')) {
    $sx = simplexml_load_file($tmp . '/xl/sharedStrings.xml');
    if ($sx) {
        $idx = 0;
        foreach ($sx->children($NS)->si as $si) {
            $txt = '';
            if (isset($si->t)) $txt = (string) $si->t;
            elseif (isset($si->r)) foreach ($si->r as $r) $txt .= (string) $r->t;
            $shared[$idx++] = $txt;
        }
    }
}

$rows = [];
$ws = simplexml_load_file($tmp . '/xl/worksheets/sheet1.xml');
if ($ws) {
    foreach ($ws->children($NS)->sheetData->row as $row) {
        $cells = [];
        foreach ($row->children($NS)->c as $c) {
            $attr = $c->attributes();
            $ref = (string) ($attr['r'] ?? '');
            $col = preg_replace('/[0-9]/', '', $ref);
            $type = (string) ($attr['t'] ?? '');
            $val = '';
            if ($type === 's') {
                $v = (string) $c->children($NS)->v;
                $val = $shared[(int) $v] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = (string) $c->children($NS)->is->t;
            } else {
                $v = (string) $c->children($NS)->v;
                $val = $v;
            }
            $cells[$col] = $val;
        }
        $rows[] = $cells;
    }
}

$headerRow = -1;
foreach ($rows as $idx => $r) {
    foreach ($r as $v) {
        if (strtoupper(trim($v)) === 'NAME') { $headerRow = $idx; break 2; }
    }
}
if ($headerRow < 0) { fwrite(STDERR, "no NAME column found\n"); exit(1); }

printf("\nHeader row %d: %s\n\n", $headerRow + 1, json_encode($rows[$headerRow], JSON_UNESCAPED_UNICODE));
printf("%-4s | %-40s | %-25s | %-15s\n", '#', 'NAME', 'POSITION', 'BRANCH');
echo str_repeat('-', 95) . "\n";
$count = 0;
foreach (array_slice($rows, $headerRow + 1) as $r) {
    if (empty($r['C']) && empty($r['B'])) continue;
    printf("%-4s | %-40s | %-25s | %-15s\n",
        $r['B'] ?? '',
        $r['C'] ?? '',
        $r['D'] ?? '',
        $r['E'] ?? ''
    );
    $count++;
}
echo "\nTotal: $count rows\n";

$branches = []; $positions = [];
foreach (array_slice($rows, $headerRow + 1) as $r) {
    if (! empty($r['C'])) {
        $b = trim($r['E'] ?? '');
        $p = trim($r['D'] ?? '');
        if ($b) $branches[$b] = ($branches[$b] ?? 0) + 1;
        if ($p) $positions[$p] = ($positions[$p] ?? 0) + 1;
    }
}
echo "\nBranches (" . count($branches) . "):\n";
foreach ($branches as $b => $c) printf("  %-20s %d\n", $b, $c);
echo "\nPositions (" . count($positions) . "):\n";
foreach ($positions as $p => $c) printf("  %-30s %d\n", $p, $c);

foreach (glob($tmp . '/*') as $f) @unlink($f);
@rmdir($tmp);
