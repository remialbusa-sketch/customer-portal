<?php

namespace App\Support;

use App\Models\User;

/**
 * Resolve a customer's geographic region (4-broad code) from their
 * profile fields, so the ticket form can scope the TSP picker to
 * the team physically closest to the customer.
 *
 * Why this exists: customers don't have a guaranteed `region` value.
 * - Accounts auto-provisioned from the monday.com Customer Details
 *   board get a 4-broad `users.region` from the board group title.
 * - Older accounts often have
 *   `region = NULL` and the only address data we have is a free-text
 *   `branch` ("NCR - NATIONAL CAPITAL REGION", "QC", "St. Luke's BGC",
 *   "Cebu IT Park") and/or a free-text `address` ("32nd St., BGC,
 *   Taguig", "123 Test Street, Quezon City").
 *
 * Resolution priority:
 *   1. `users.region` if it already matches one of the 4-broad codes
 *   2. `users.branch` — keyword scan against the city/province list
 *   3. `users.address` — same scan
 *   4. null (caller should fall back to showing all regions or to
 *      Monday's default routing)
 *
 * This class is intentionally a static helper with no DB calls —
 * pure normalization of user-supplied data.
 */
class RegionResolver
{
    /**
     * The same 4 codes PersonnelDirectory uses, mirrored here so the
     * resolver can validate the `users.region` value before trusting it.
     */
    public const REGIONS = [
        'NCR',
        'NORTH LUZON',
        'VISAYAS',
        'MINDANAO',
    ];

    /**
     * Keyword → region. Lower-cased substring scan, longest match wins
     * (so "Cagayan de Oro" beats "Cagayan" which is a North Luzon province).
     *
     * City/province names collected from the PersonnelXlsxSeeder branch
     * column plus the major NCR cities the customer service team uses.
     * New ones can be added without code changes elsewhere.
     */
    private const KEYWORDS = [
        // NCR — Metro Manila + surrounding cities
        'metro manila'      => 'NCR',
        'manila'            => 'NCR',
        'quezon city'       => 'NCR',
        'makati'            => 'NCR',
        'taguig'            => 'NCR',
        'bgc'               => 'NCR',
        'pasig'             => 'NCR',
        'pasay'             => 'NCR',
        'mandaluyong'       => 'NCR',
        'san juan'          => 'NCR',
        'marikina'          => 'NCR',
        'caloocan'          => 'NCR',
        'malabon'           => 'NCR',
        'navotas'           => 'NCR',
        'parañaque'         => 'NCR',
        'paranaque'         => 'NCR',
        'las piñas'         => 'NCR',
        'las pinas'         => 'NCR',
        'muntinlupa'        => 'NCR',
        'pateros'           => 'NCR',
        'valenzuela'        => 'NCR',
        'ncr'               => 'NCR',
        'national capital'  => 'NCR',
        'nationcal capital' => 'NCR',   // common customer typo for "NATIONAL CAPITAL"
        'national'          => 'NCR',   // catches "National Capital" partials + edge cases
        'qc'                => 'NCR',   // common shorthand

        // NORTH LUZON — provinces/cities north of NCR
        'baguio'            => 'NORTH LUZON',
        'benguet'           => 'NORTH LUZON',
        'la union'          => 'NORTH LUZON',
        'pangasinan'        => 'NORTH LUZON',
        'dagupan'           => 'NORTH LUZON',
        'ilocos'            => 'NORTH LUZON',
        'laoag'             => 'NORTH LUZON',
        'vigan'             => 'NORTH LUZON',
        'tarlac'            => 'NORTH LUZON',
        'pampanga'          => 'NORTH LUZON',
        'angeles'           => 'NORTH LUZON',
        'san fernando'      => 'NORTH LUZON',
        'bataan'            => 'NORTH LUZON',
        'bulacan'           => 'NORTH LUZON',
        'malolos'           => 'NORTH LUZON',
        'nueva ecija'       => 'NORTH LUZON',
        'cabanatuan'        => 'NORTH LUZON',
        'aurora'            => 'NORTH LUZON',
        'batanes'           => 'NORTH LUZON',
        'tuguegarao'        => 'NORTH LUZON',
        'cagayan'           => 'NORTH LUZON',
        'isabela'           => 'NORTH LUZON',
        'santiago'          => 'NORTH LUZON',
        'ifugao'            => 'NORTH LUZON',
        'kalinga'           => 'NORTH LUZON',
        'apayao'            => 'NORTH LUZON',
        'abra'              => 'NORTH LUZON',
        'mt. province'      => 'NORTH LUZON',
        'mountain province' => 'NORTH LUZON',
        'north luzon'       => 'NORTH LUZON',
        'luzon'             => 'NORTH LUZON',

        // VISAYAS — central islands
        'cebu'              => 'VISAYAS',
        'mandaue'           => 'VISAYAS',
        'lapu-lapu'         => 'VISAYAS',
        'iloilo'            => 'VISAYAS',
        'ilo-ilo'           => 'VISAYAS',
        'bacolod'           => 'VISAYAS',
        'negros'            => 'VISAYAS',
        'tacloban'          => 'VISAYAS',
        'leyte'             => 'VISAYAS',
        'samar'             => 'VISAYAS',
        'bohol'             => 'VISAYAS',
        'aklan'             => 'VISAYAS',
        'antique'           => 'VISAYAS',
        'capiz'             => 'VISAYAS',
        'romblon'           => 'VISAYAS',
        'palawan'           => 'VISAYAS',
        'guimaras'          => 'VISAYAS',
        'ormoc'             => 'VISAYAS',
        'dumaguete'         => 'VISAYAS',
        'roxas'             => 'VISAYAS',
        'visayas'           => 'VISAYAS',

        // MINDANAO — southern island
        'davao'             => 'MINDANAO',
        'cdo'               => 'MINDANAO',
        'cagayan de oro'    => 'MINDANAO',
        'zamboanga'         => 'MINDANAO',
        'gensan'            => 'MINDANAO',
        'general santos'    => 'MINDANAO',
        'cotabato'          => 'MINDANAO',
        'butuan'            => 'MINDANAO',
        'surigao'           => 'MINDANAO',
        'iligan'            => 'MINDANAO',
        'tagum'             => 'MINDANAO',
        'panabo'            => 'MINDANAO',
        'davao del sur'     => 'MINDANAO',
        'davao del norte'   => 'MINDANAO',
        'davao de oro'      => 'MINDANAO',
        'davao occidental'  => 'MINDANAO',
        'davao oriental'    => 'MINDANAO',
        'lanao'             => 'MINDANAO',
        'bukidnon'          => 'MINDANAO',
        'misamis'           => 'MINDANAO',
        'agusan'            => 'MINDANAO',
        'camiguin'          => 'MINDANAO',
        'basilan'           => 'MINDANAO',
        'sulu'              => 'MINDANAO',
        'tawi-tawi'         => 'MINDANAO',
        'kidapawan'         => 'MINDANAO',
        'koronadal'         => 'MINDANAO',
        'tacurong'          => 'MINDANAO',
        'oroquieta'         => 'MINDANAO',
        'ozamiz'            => 'MINDANAO',
        'pagadian'          => 'MINDANAO',
        'dipolog'           => 'MINDANAO',
        'mindanao'          => 'MINDANAO',
    ];

    /**
     * Resolve a customer's region code from their profile fields.
     * Returns one of `self::REGIONS` or null if unresolvable.
     */
    public static function resolveForCustomer(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        // 1. Trust the column if it's already a valid 4-broad code.
        $colRegion = self::normalizeRegionCode($user->region);
        if ($colRegion !== null) {
            return $colRegion;
        }

        // 2. Branch (free text).
        $fromBranch = self::matchAgainst((string) ($user->branch ?? ''));
        if ($fromBranch !== null) {
            return $fromBranch;
        }

        // 3. Address (free text).
        $fromAddress = self::matchAgainst((string) ($user->address ?? ''));
        if ($fromAddress !== null) {
            return $fromAddress;
        }

        return null;
    }

    /**
     * Validate a stored `users.region` value. Some legacy rows have
     * free-text values like "Cebu" (the old 9-broad taxonomy) — this
     * upgrades them on the fly, but the caller is expected to persist
     * the normalized form on next save. Returns null if unresolvable.
     */
    public static function normalizeRegionCode(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $upper = strtoupper(trim($raw));
        if (in_array($upper, self::REGIONS, true)) {
            return $upper;
        }
        // Legacy: "Cebu" / "Davao" / "Bacolod" etc. — run the same
        // keyword scan to map them to the 4-broad code.
        return self::matchAgainst($raw);
    }

    /**
     * Substring scan of `$haystack` against the keyword table.
     * Longest match wins (so "cagayan de oro" beats "cagayan" even
     * though both are keys).
     */
    private static function matchAgainst(string $haystack): ?string
    {
        if ($haystack === '') {
            return null;
        }
        $needle = mb_strtolower($haystack);

        $best = null;
        $bestLen = 0;
        foreach (self::KEYWORDS as $keyword => $region) {
            $kwLen = mb_strlen($keyword);
            if ($kwLen < $bestLen) {
                // Already have a longer match; skip shorter ones.
                continue;
            }
            if (str_contains($needle, $keyword)) {
                $best = $region;
                $bestLen = $kwLen;
            }
        }
        return $best;
    }
}
