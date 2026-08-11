<?php
// Cross-check DB FSE/ITS/Manager users against Personnel list_.xlsx
//
// Usage: php _crosscheck.php
//
// Compares all DB users with role in {fse, its, manager} against
// the 64 rows in `Personnel list_.xlsx`. For each DB user it tries
// to find a match by surname using a multi-word match strategy
// (last 1/2/3 words) and diacritic-insensitive comparison. Then
// it cross-validates the DB region against the xlsx branch.
//
// Pass:  php _crosscheck.php [path/to/file.xlsx]
$path = $argv[1] ?? 'C:\Users\USER\Documents\MONDAY.COM\Web Side Project\customer-portal\Personnel list_.xlsx';

if (! is_file($path)) { fwrite(STDERR, "xlsx not found: $path\n"); exit(1); }

// --- 1. Extract shared strings + sheet rows from the .xlsx zip ---
$tmp = sys_get_temp_dir() . '/xlsx_x_' . bin2hex(random_bytes(4));
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
            if (isset($si->t)) {
                $txt = (string) $si->t;
            } elseif (isset($si->r)) {
                foreach ($si->r as $r) $txt .= (string) $r->t;
            }
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
            $ref  = (string) ($attr['r'] ?? '');
            $col  = preg_replace('/[0-9]/', '', $ref);
            $type = (string) ($attr['t'] ?? '');
            $val  = '';
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
foreach (glob($tmp . '/*') as $f) @unlink($f);
@rmdir($tmp);

// --- 2. Build xlsx lookup table ---
function norm(string $s): string {
    $s = trim($s);
    if (function_exists('iconv')) {
        $clean = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($clean !== false) $s = $clean;
    }
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9 ]/', '', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

$xlsxEntries = [];        // all rows
$xlsxSurnames = [];       // normalized-surname -> entry
foreach ($rows as $r) {
    $name = trim($r['C'] ?? '');
    if ($name === '' || ! str_contains($name, ',')) continue;
    [$surname, $given] = array_map('trim', explode(',', $name, 2));
    $entry = [
        'raw'      => $name,
        'surname'  => $surname,
        'given'    => $given,
        'position' => trim($r['D'] ?? ''),
        'branch'   => trim($r['E'] ?? ''),
    ];
    $xlsxEntries[] = $entry;
    $xlsxSurnames[norm($surname)] = $entry;
}

// --- 3. Load DB users (FSE/ITS/Manager) ---
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
use App\Models\User;
$dbUsers = User::whereIn('role', ['fse','its','manager'])->orderBy('id')->get();

// --- 4. Surname matching with multi-word + diacritic + fuzzy tolerance ---
//
// Two-pass strategy:
//
//   Pass A: try exact normalized match for last 1, 2, 3 words of the
//           DB name. Handles "Ybañes ↔ Ybañez", "De Pio ↔ de Pio",
//           "Basuel, Brixton ↔ Brixxtonn Garcenila Basuel" etc.
//
//   Pass B: if pass A misses, try fuzzy match (edit distance <= 1 for
//           short surnames, <= 2 for long) against every xlsx surname.
//           Handles spelling variants like "Navares ↔ Navarro",
//           "Ybanez ↔ Ybañes" (in case the diacritic-strip produces
//           "ybanez" vs xlsx "ybanes" — distance=1).
//
// Returns ['match' => xlsxEntry, 'tried' => normalized-candidate,
//          'fuzzy' => bool] or null.

function matchDbSurname(string $name, array $xlsxSurnames, array $xlsxSurnamesKeys = []): ?array {
    $parts = preg_split('/\s+/', trim($name));
    $parts = array_values(array_filter($parts, fn($p) => $p !== ''));

    // Strip common suffixes that don't help surname matching
    // (Jr., Sr., II, III, IV). "Roberto S. de Pio Jr." ->
    // ["Roberto","S.","de","Pio"] (Jr. dropped).
    $parts = array_values(array_filter($parts, function ($p) {
        $clean = rtrim($p, '.');
        return ! in_array(strtolower($clean), ['jr', 'sr', 'ii', 'iii', 'iv'], true);
    }));

    // Pass A: exact
    for ($n = min(3, count($parts)); $n >= 1; $n--) {
        $candidate = norm(implode(' ', array_slice($parts, -$n)));
        if (isset($xlsxSurnames[$candidate])) {
            return ['match' => $xlsxSurnames[$candidate], 'tried' => $candidate, 'fuzzy' => false];
        }
    }

    // Pass B: fuzzy — single-word surname candidates vs single-word
    // xlsx surnames, edit distance <= 1 (or 2 if len >= 7).
    $candidates = [];
    for ($n = min(3, count($parts)); $n >= 1; $n--) {
        $candidate = norm(implode(' ', array_slice($parts, -$n)));
        if (! str_contains($candidate, ' ')) $candidates[] = $candidate;
    }
    if (! $candidates) return null;
    if (! $xlsxSurnamesKeys) $xlsxSurnamesKeys = array_keys($xlsxSurnames);

    foreach ($candidates as $cand) {
        if (strlen($cand) < 4) continue;  // too short to fuzzy-match safely
        $maxDist = strlen($cand) >= 7 ? 2 : 1;
        $best = null; $bestDist = PHP_INT_MAX; $bestKey = null;
        foreach ($xlsxSurnamesKeys as $xs) {
            if (str_contains($xs, ' ')) continue;  // single-word xlsx surnames only
            $d = levenshtein($cand, $xs);
            if ($d > 0 && $d <= $maxDist && $d < $bestDist) {
                $best = $xlsxSurnames[$xs]; $bestDist = $d; $bestKey = $xs;
            }
        }
        if ($best !== null) {
            return ['match' => $best, 'tried' => $cand . '~' . $bestKey, 'fuzzy' => true];
        }
    }
    return null;
}

// Region map: DB region code -> list of xlsx branch tokens that
// should match. Used to cross-validate the region assignment.
$regionMap = [
    'ncr'       => ['ncr'],
    'davao'     => ['davao'],
    'cdo'       => ['cdo'],
    'nluzon'    => ['north luzon', 'nluzon'],
    'visayas'   => ['cebu', 'bacolod', 'ilo-ilo', 'tacloban'],
    'mindanao'  => ['davao', 'cdo', 'zamboanga'],
];

// --- 5. Report ---
printf("\n%-3s | %-30s | %-10s | %-8s | %-25s | %-25s | %-12s | %s\n",
    'id', 'DB name', 'DB region', 'DB role', 'xlsx name', 'xlsx position', 'xlsx branch', 'status');
echo str_repeat('-', 165) . "\n";

$ok = 0; $mismatched = []; $missing = [];
foreach ($dbUsers as $u) {
    $name = (string) ($u->name ?? '');
    $res = matchDbSurname($name, $xlsxSurnames);
    if ($res) {
        $xmatch  = $res['match'];
        $xname   = $xmatch['raw'] . ($res['fuzzy'] ? '  (fuzzy)' : '');
        $xpos    = $xmatch['position'];
        $xbranch = $xmatch['branch'];
        $status  = 'OK';
        $dbReg   = strtolower((string) ($u->region ?? ''));
        $xBr     = strtolower($xbranch);
        $regMatch = false;
        if ($dbReg && $xBr) {
            foreach ($regionMap as $variants) {
                if (in_array($dbReg, $variants, true) && in_array($xBr, $variants, true)) {
                    $regMatch = true; break;
                }
            }
        }
        if (! $regMatch && $xbranch) {
            $status = 'REGION MISMATCH';
            $mismatched[] = [
                'db' => $name, 'db_region' => $u->region, 'xlsx_branch' => $xbranch,
                'role' => $u->role, 'email' => $u->email,
            ];
        }
        $ok++;
    } else {
        $xname = $xpos = $xbranch = '-';
        $status = 'NOT IN XLSX';
        $missing[] = ['name' => $name, 'role' => $u->role, 'email' => $u->email, 'region' => $u->region];
    }
    printf("%-3s | %-30s | %-10s | %-8s | %-25s | %-25s | %-12s | %s\n",
        $u->id,
        substr($name, 0, 30),
        substr((string)($u->region ?? '(null)'), 0, 10),
        $u->role,
        substr($xname, 0, 25),
        substr($xpos, 0, 25),
        substr($xbranch, 0, 12),
        $status
    );
}

echo "\nMatched: $ok / " . $dbUsers->count() . "\n";

if ($mismatched) {
    echo "\n--- REGION MISMATCH (DB region != xlsx branch) ---\n";
    foreach ($mismatched as $m) {
        echo sprintf("  %-30s [%-7s] DB=%-10s xlsx=%-12s | %s\n",
            $m['db'], $m['role'], $m['db_region'] ?? '(null)', $m['xlsx_branch'], $m['email']);
    }
}
if ($missing) {
    echo "\n--- NOT IN XLSX ---\n";
    foreach ($missing as $m) {
        echo sprintf("  %-30s [%-7s] region=%s | %s\n",
            $m['name'], $m['role'], $m['region'] ?? '(null)', $m['email']);
    }
}

echo "\n--- XLSX rows NOT in DB (FSE/IT/Manager/SrEng only) ---\n";
$dbMatchedSurnames = [];
foreach ($dbUsers as $u) {
    $res = matchDbSurname((string) $u->name, $xlsxSurnames);
    if ($res) $dbMatchedSurnames[$res['tried']] = true;
}
$portalRoles = [
    'Field Service Engineer', 'Senior Service Engineer', 'Field Mechanical Engineer',
    'IT Specialist', 'Senior IT Specialist',
    'Regional Service Manager', 'Regional IT Manager',
];
$unmatched = 0;
foreach ($xlsxEntries as $info) {
    if (! in_array($info['position'], $portalRoles, true)) continue;
    $s = norm($info['surname']);
    if (isset($dbMatchedSurnames[$s])) continue;
    $unmatched++;
    printf("  %-30s | %-25s | %s\n", $info['raw'], $info['position'], $info['branch']);
}
echo "\nUnmatched portal-role xlsx rows: $unmatched\n";
