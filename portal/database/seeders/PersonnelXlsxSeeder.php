<?php
/**
 * One-shot seeder: synchronizes the FSE/ITS roster with the personnel
 * list in the project-root xlsx (Personnel list_.xlsx).
 *
 * Strategy (idempotent, non-destructive):
 *   - Read the xlsx (B=#, C=Name, D=Position, E=Branch).
 *   - For each row whose position maps to an FSE or ITS role, ensure a
 *     matching portal user exists.
 *   - Match existing portal users by:
 *       (1) any of several email candidate variants generated from
 *           the xlsx name (first.last, firstlast, first_last, etc.)
 *       (2) last-name tokens + first-name-prefix match, ignoring
 *           Spanish particles ("de", "del", "san", "sta")
 *   - If matched, update region + team + role.
 *   - If no match, create a new user.
 *   - Existing portal users NOT in the xlsx are left alone (they may be
 *     OAuth probes, managers, or recently added; we don't delete).
 *
 * Run with:
 *     php artisan db:seed --class=PersonnelXlsxSeeder
 *
 * Dry run (recommended first — prints every change without touching rows):
 *     PERSONNEL_SYNC_DRY_RUN=1 php artisan db:seed --class=PersonnelXlsxSeeder
 *
 * Idempotent: re-running is safe; matched users are updated, no new
 * rows for already-matched names.
 */

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PersonnelXlsxSeeder extends Seeder
{
    /** Spanish/Tagalog particles to ignore when picking a "real" last name */
    private const PARTICLES = ['de', 'del', 'la', 'las', 'san', 'santa', 'sta', 'sto'];

    /**
     * Map a free-text Position from the xlsx to the portal's
     * (role, team) tuple. Returns null for non-field positions.
     */
    private function mapPosition(string $pos): ?array
    {
        $p = strtolower($pos);
        if (str_contains($p, 'senior service engineer')) {
            return ['fse', 'FSE-Sr'];
        }
        if (str_contains($p, 'field service engineer')) {
            return ['fse', 'FSE'];
        }
        if (str_contains($p, 'field mechanical engineer')) {
            return ['fse', 'FSE'];
        }
        if (str_contains($p, 'senior it specialist')) {
            return ['its', 'ITS-Sr'];
        }
        if (str_contains($p, 'it specialist')) {
            return ['its', 'ITS'];
        }
        return null;
    }

    /**
     * Normalize a name for fuzzy matching: lowercase, strip accents,
     * collapse whitespace, drop non-alphanumerics.
     */
    private function norm(string $s): string
    {
        $s = strtolower($s);
        $s = strtr($s, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss',
        ]);
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    /**
     * Parse "LastName, FirstName Middle Jr." from xlsx and return:
     *   - 'name':      flipped "FirstName LastName" in portal format
     *   - 'lastReal':  the "real" last name (skips particles)
     *   - 'lastTokens':all last-name tokens (for matching users with
     *                  multi-word surnames like "San Juan" or "De Pio")
     */
    private function flipName(string $xlsxName): array
    {
        $parts = explode(',', $xlsxName, 2);
        if (count($parts) !== 2) {
            return ['name' => trim($xlsxName), 'lastReal' => '', 'lastTokens' => []];
        }
        $last  = trim($parts[0]);
        $first = trim($parts[1]);

        $flipped = trim("{$first} {$last}");
        $lastTokens = preg_split('/\s+/', $last);

        // Real last name = skip particles at the start
        $realTokens = $lastTokens;
        while (!empty($realTokens) && in_array(strtolower($realTokens[0]), self::PARTICLES, true)) {
            array_shift($realTokens);
        }
        $lastReal = $realTokens ? end($realTokens) : end($lastTokens);

        return [
            'name'       => $flipped,
            'lastReal'   => $lastReal,
            'lastTokens' => $lastTokens,
        ];
    }

    /**
     * Generate a list of plausible email candidates for an xlsx person.
     * These are the variants portal users actually use in @mcbtsi.com.
     */
    private function candidateEmails(string $xlsxName, string $branch = ''): array
    {
        $parts = explode(',', $xlsxName, 2);
        $first = $parts[1] ?? '';
        $last  = $parts[0] ?? $xlsxName;
        $first = trim($first);
        $last  = trim($last);

        $firstTokens = preg_split('/\s+/', $first);
        $origLastTokens = preg_split('/\s+/', $last);

        // Real last tokens (particle-stripped)
        $realLastTokens = $origLastTokens;
        while (!empty($realLastTokens) && in_array(strtolower($realLastTokens[0]), self::PARTICLES, true)) {
            array_shift($realLastTokens);
        }

        $firstName = $firstTokens[0] ?? '';
        $lastName  = end($realLastTokens) ?: ($realLastTokens[0] ?? '');
        if (!$firstName || !$lastName) return [];

        $firstSlug = $this->norm($firstName);
        $lastSlug  = $this->norm($lastName);
        $allLast   = implode('', array_map(fn($t) => $this->norm($t), $realLastTokens));

        // Variants seen in the portal:
        //   first.last           e.g. roberto.depio
        //   firstlast            e.g. juan.delacruz  (very common)
        //   first_last           e.g. neildarwin_sanjuan
        //   firstlastall         e.g. neildarwinsanjuan (all last-name tokens)
        $candidates = [
            "{$firstSlug}.{$lastSlug}@mcbtsi.com",
            "{$firstSlug}{$lastSlug}@mcbtsi.com",
            "{$firstSlug}_{$lastSlug}@mcbtsi.com",
            "{$firstSlug}{$allLast}@mcbtsi.com",
        ];

        // If the last-name had leading particles (e.g. "De Pio"), also
        // try with the particle preserved in the slug:
        //   roberto.depio, robertodepio
        if (!empty($origLastTokens) && count($origLastTokens) > count($realLastTokens)) {
            $withPart = implode('', array_map(fn($t) => $this->norm($t), array_slice($origLastTokens, -2)));
            $candidates[] = "{$firstSlug}.{$withPart}@mcbtsi.com";
            $candidates[] = "{$firstSlug}{$withPart}@mcbtsi.com";
        }

        return array_values(array_unique(array_filter(
            array_map(fn (string $e) => preg_replace('/\s+/', '', $e), $candidates)
        )));
    }

    /**
     * Compare an xlsx name (first + last) against a portal user name.
     * Returns true if they're likely the same person.
     *
     * @param string $xFirst     xlsx first name (e.g. "Neil Darwin")
     * @param string $xLast      xlsx last name (real, particle-stripped)
     * @param array  $lastTokens all xlsx last-name tokens (for multi-word surnames)
     */
    private function nameMatchesPortal(string $portalName, string $xFirst, string $xLast, array $lastTokens): bool
    {
        $pParts = preg_split('/\s+/', trim($portalName));
        if (empty($pParts)) return false;

        $pFirst = $this->norm($pParts[0]);
        $xFirstN = $this->norm(explode(' ', $xFirst)[0]); // first token of xlsx first
        $xLastN  = $this->norm($xLast);

        // First-name match: 2+ chars, prefix-equality (e.g. "Adonis" vs "Adonis",
        // "Ybanes" vs "Ybanez" — wait, that won't match; see below)
        $firstOk = strlen($pFirst) >= 2 && strlen($xFirstN) >= 2
            && (str_starts_with($pFirst, $xFirstN)
                || str_starts_with($xFirstN, $pFirst)
                || levenshtein($pFirst, $xFirstN) <= 2);  // tolerate 2 typos

        // Last-name match: portal's "real" last must equal xlsx lastReal,
        // or the portal must contain the same full last-token sequence
        $pLastTokens = $pParts;
        while (!empty($pLastTokens) && in_array(strtolower($pLastTokens[0]), self::PARTICLES, true)) {
            array_shift($pLastTokens);
        }
        $pLast = $this->norm(end($pLastTokens) ?: '');
        $lastOk = $pLast === $xLastN
            || levenshtein($pLast, $xLastN) <= 2
            || (count($lastTokens) > 1 && $this->portalHasLastTokenSequence($pParts, $lastTokens));

        return $firstOk && $lastOk;
    }

    /**
     * Helper: does the portal name's tail contain the xlsx last-token
     * sequence (in order, ignoring particles)?
     * e.g. portal "Rogel de Lara" + xlsx lastTokens ["De","Lara"] -> true
     */
    private function portalHasLastTokenSequence(array $pParts, array $lastTokens): bool
    {
        $xNorms = array_map(fn($t) => $this->norm($t), $lastTokens);
        $pNorms = array_map(fn($t) => $this->norm($t), $pParts);
        $len = count($xNorms);
        for ($i = 0; $i + $len <= count($pNorms); $i++) {
            $slice = array_slice($pNorms, $i, $len);
            // Skip particles in the slice
            $sliceNoPart = array_values(array_filter(
                $slice,
                fn($t) => !in_array(strtolower($t), self::PARTICLES, true)
            ));
            if (count($sliceNoPart) === count($xNorms) && $sliceNoPart === $xNorms) {
                return true;
            }
        }
        return false;
    }

    public function run(): void
    {
        // PERSONNEL_SYNC_DRY_RUN=1: report every change without writing
        // (safe to run against production data; nothing is modified).
        // Env var, not a CLI flag, because `db:seed` swallows unknown
        // options into the --class argument.
        $dryRun = (env('PERSONNEL_SYNC_DRY_RUN') === '1');
        if ($dryRun) {
            $this->command?->warn('DRY RUN — no rows will be written.');
        }

        $xlsxPath = base_path('..' . DIRECTORY_SEPARATOR . 'Personnel list_.xlsx');
        if (! is_readable($xlsxPath)) {
            $this->command?->error("XLSX not readable at: {$xlsxPath}");
            return;
        }

        // Extract the xlsx (it's a zip) and parse the single sheet.
        $tmp = sys_get_temp_dir() . '/personnel_xlsx_' . uniqid();
        @mkdir($tmp);
        $zip = new \ZipArchive();
        if ($zip->open($xlsxPath) !== true) {
            $this->command?->error("Could not open xlsx as zip.");
            return;
        }
        $zip->extractTo($tmp);
        $zip->close();

        $shared = [];
        $ssXml  = simplexml_load_file($tmp . '/xl/sharedStrings.xml');
        foreach ($ssXml->si as $si) {
            $text = '';
            foreach ($si->t as $t) $text .= (string) $t;
            foreach ($si->r as $r) $text .= (string) $r->t;
            $shared[] = $text;
        }

        $rows = [];
        $shXml = simplexml_load_file($tmp . '/xl/worksheets/sheet1.xml');
        foreach ($shXml->sheetData->row as $row) {
            $r = [];
            foreach ($row->c as $c) {
                $col  = preg_replace('/[0-9]/', '', (string) $c['r']);
                $type = (string) $c['t'];
                $val  = (string) $c->v;
                if ($type === 's') $val = $shared[(int) $val] ?? '';
                $r[$col] = $val;
            }
            $rows[(int) $row['r']] = $r;
        }

        foreach (glob($tmp . '/*') as $f) @unlink($f);
        @rmdir($tmp);

        // Build the target list
        $target = [];
        foreach ($rows as $i => $row) {
            if ($i < 6) continue;
            $name = trim($row['C'] ?? '');
            $pos  = trim($row['D'] ?? '');
            $br   = trim($row['E'] ?? '');
            if ($name === '') continue;

            // Map xlsx branch name to Monday.com "Customer User Account"
            // board group title. The xlsx lists 9 specific branches
            // (NCR, North Luzon, Cebu, Ilo-Ilo, Bacolod, Tacloban,
            // Davao, CDO, Zamboanga) but the portal mirrors Monday's
            // 4-broad-region grouping (NCR, NORTH LUZON, VISAYAS,
            // MINDANAO) so the TSP dropdown aligns with the same
            // groups the customer service team uses.
            static $branchToRegion = [
                'NCR'         => 'NCR',
                'North Luzon' => 'NORTH LUZON',
                'NLuzon'      => 'NORTH LUZON',
                'Cebu'        => 'VISAYAS',
                'Ilo-Ilo'     => 'VISAYAS',
                'Bacolod'     => 'VISAYAS',
                'Tacloban'    => 'VISAYAS',
                'Davao'       => 'MINDANAO',
                'CDO'         => 'MINDANAO',
                'Zamboanga'   => 'MINDANAO',
            ];
            $region = $branchToRegion[$br] ?? null;

            $mapped = $this->mapPosition($pos);
            if ($mapped === null) continue;

            $flipped = $this->flipName($name);
            // xlsxFirst = "Neil Darwin" portion of "San Juan, Neil Darwin Lozada"
            $xFirst  = trim(explode(',', $name, 2)[1] ?? '');
            $target[] = [
                'xlsx_name'   => $name,
                'portal_name' => $flipped['name'],
                'xFirst'      => $xFirst,
                'lastReal'    => $flipped['lastReal'],
                'lastTokens'  => $flipped['lastTokens'],
                'branch'      => $br,
                'region'      => $region ?? $br,
                'role'        => $mapped[0],
                'team'        => $mapped[1],
                'emails'      => $this->candidateEmails($name, $br),
            ];
        }

        $this->command?->info("Target roster from xlsx: " . count($target) . " FSE/ITS rows.");

        // Load all existing FSE/ITS users once (plus managers to avoid
        // colliding; we won't modify managers).
        $portal = User::whereIn('role', ['fse', 'its', 'manager'])->get();

        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $manual = 0;

        foreach ($target as $t) {
            $matched = null;

            // 1) Email-candidate lookup
            foreach ($t['emails'] as $email) {
                $m = $portal->firstWhere('email', $email);
                if ($m) { $matched = $m; break; }
            }

            // 2) Fuzzy name match
            if (!$matched) {
                foreach ($portal as $u) {
                    if ($this->nameMatchesPortal($u->name, $t['xFirst'], $t['lastReal'], $t['lastTokens'])) {
                        $matched = $u;
                        break;
                    }
                }
            }

            if ($matched) {
                $changes = [];
                if ($matched->region !== $t['region']) {
                    $changes['region'] = $t['region'];
                }
                if ($matched->team !== $t['team']) {
                    $changes['team'] = $t['team'];
                }
                // Never reclassify admin/superadmin accounts from the
                // roster — it would lock the account owner out of the
                // admin area.
                if (! in_array($matched->role, ['admin', 'superadmin'], true)
                    && $matched->role !== $t['role']) {
                    $changes['role'] = $t['role'];
                }
                if (!empty($changes)) {
                    if ($dryRun) {
                        $this->command?->info("  WOULD update: {$matched->name} (#{$matched->id}) -> " . json_encode($changes));
                    } else {
                        $matched->update($changes);
                        $this->command?->info("  updated: {$matched->name} (#{$matched->id}) -> " . json_encode($changes));
                    }
                    $updated++;
                } else {
                    $unchanged++;
                }
                continue;
            }

            // 3) Create a new user
            $email = $t['emails'][0] ?? null;
            if (!$email) {
                $this->command?->warn("  skipped: {$t['xlsx_name']} (no email candidate)");
                $manual++;
                continue;
            }
            if (User::where('email', $email)->exists()) {
                $email = str_replace('@mcbtsi.com', '+' . strtolower(str_replace(' ', '', $t['branch'])) . '@mcbtsi.com', $email);
            }
            if (User::where('email', $email)->exists()) {
                $this->command?->warn("  could not create {$t['portal_name']}: email {$email} already in use");
                $manual++;
                continue;
            }

            if ($dryRun) {
                $this->command?->info("  WOULD create: {$t['portal_name']} ({$email}) -> region={$t['region']} team={$t['team']} role={$t['role']}");
                $created++;
                continue;
            }

            $u = User::create([
                'name'     => $t['portal_name'],
                'email'    => $email,
                'password' => Hash::make('Password!123'),
                'role'     => $t['role'],
                'team'     => $t['team'],
                'region'   => $t['region'],
                'status'   => 'active',
            ]);
            $this->command?->info("  created: {$u->name} ({$u->email}) -> region={$u->region} team={$u->team}");
            $created++;
        }

        $this->command?->info("Done. created={$created} updated={$updated} unchanged={$unchanged} skipped={$manual}"
            . ($dryRun ? ' (dry run — nothing written)' : ''));
    }
}
