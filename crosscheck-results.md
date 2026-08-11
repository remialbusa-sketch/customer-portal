# Cross-check: DB FSE/ITS/Manager vs Personnel list_.xlsx

**Date**: 2026-08-07 (re-run with fuzzy matching)  
**Source**: `C:\Users\USER\Documents\MONDAY.COM\Web Side Project\customer-portal\Personnel list_.xlsx`  
**Method**: Custom xlsx reader (no PHP xlsx library installed) → simplexml + ZipArchive, with namespace handling + diacritic normalization + smart multi-word surname matching + Levenshtein fuzzy match for spelling variants.

## Summary

- **64 rows in xlsx** (mix of field staff, IT, managers, warehouse, executives)
- **30 DB users** in FSE/ITS/Manager roles
- **30 of 30** matched (3 via fuzzy/Levenshtein fallback)
- **0 DB users NOT in xlsx** (all 30 found)
- **21 xlsx rows NOT in DB** (FSE/IT/Manager/SrEng positions only)
- **14 region mismatches** where DB region differs from xlsx branch assignment

## Fuzzy matches (3 of 30)

| DB name | DB region | xlsx match | Why fuzzy | Notes |
|---|---|---|---|---|
| Adonis Ybanez (id=4) | Davao | Ybañes, Adonis | diacritic + "anez" vs "anes" (Levenshtein=1) | Same person |
| Elthon Jay D. Navares (id=8) | Visayas | Navarro, Elthon Jay | "Navares" vs "Navarro" (Levenshtein=1) | Likely same person (typo) |
| Roberto S. de Pio Jr. (id=16) | NCR | De Pio, Roberto Siazar, Jr. | "Jr." suffix stripped | Same person (middle name "Siazar" not in DB) |

## Region mismatches (14)

DB has many users with `region='NCR'` whose xlsx branch is different (e.g., Bacolod, Cebu, North Luzon, CDO). This may reflect:
- xlsx is from 2025-01-15 (per file metadata) — before DB was populated
- DB regions were assigned when accounts were created in 2026, may be inaccurate
- Some FSEs may have transferred branches

1. Padon (DB Mindanao → xlsx North Luzon)
2. Canaveral, Fajardo, Suba, Amper, Andoque, Sevilla (all DB NCR → xlsx North Luzon)
3. Montellin (DB NCR → xlsx CDO)
4. Calderon (DB NCR → xlsx Bacolod)
5. Ibardolasa, Pepito, Borinaga (DB NCR → xlsx Cebu)
6. de Pio (DB NCR → xlsx Ilo-Ilo)
7. Navares (DB Visayas → xlsx Cebu)
8. Remial Busa (DB NCR → xlsx NCR) — but the underlying data fix is what made NCR tickets claimable for him (was null → NCR, see TSP-region-warning below)

## Region mismatches (13)

DB has many users with `region='NCR'` whose xlsx branch is different (e.g., Bacolod, Cebu, North Luzon, CDO). This may reflect:
- xlsx is from 2025-01-15 (per file metadata) — before DB was populated
- DB regions were assigned when accounts were created in 2026, may be inaccurate
- Some FSEs may have transferred branches

Mismatches (DB NCR ≠ xlsx non-NCR):
1. Joven Padon (DB Mindanao → xlsx North Luzon)
2. Lance Canaveral (DB NCR → xlsx North Luzon)
3. Leander Fajardo (DB NCR → xlsx Tacloban)
4. Sherwin Montellin (DB NCR → xlsx CDO)
5. Warren Suba (DB NCR → xlsx North Luzon)
6. Mark Niel Amper (DB NCR → xlsx North Luzon)
7. Orfe Lyle Calderon (DB NCR → xlsx Bacolod)
8. Jomer Ibardolasa (DB NCR → xlsx Cebu)
9. John Lourence Andoque (DB NCR → xlsx North Luzon)
10. Hannah Pepito (DB NCR → xlsx Cebu)
11. Francis Conrad Sevilla (DB NCR → xlsx North Luzon)
12. Randee Borinaga (DB NCR → xlsx Cebu)
13. Remial Busa (DB null → xlsx NCR)

## xlsx staff NOT in DB (37)

Excluding executives/warehouse (Cabral, Rivas, Vallarta, Yangco, etc.), the FSE/IT/Manager-level staff in xlsx that are NOT in the DB portal:

| xlsx Name | Position | Branch |
|---|---|---|
| Aballa, Nashie | Service Coordinator | NCR |
| Aguinaldo, Gio Sam | FSE | Davao |
| Ayos, Ishel Maricar Plaza | Service Coordinator | Davao |
| Bagasbas, Roel Cabalse | Senior Service Engineer | Cebu |
| Bautista, Joshua Aquino | FSE | NCR |
| Cornel, Nikkamae | Service Coordinator | Cebu |
| Digamon, Anfernee Y. | FSE | Davao |
| Gadia, Gasim Asim | National IT Manager | CDO |
| Gomez, Lenard Joey Tapnio | Regional IT Manager | North Luzon |
| Gumatay, Glenn Salintes | IT Specialist | Davao |
| Lapay, Jhonjie | Service Assistant | Cebu |
| Lim, Dexter Carreon | FSE | Cebu |
| Maestre, Jaypee Lopez | Regional IT Manager | CDO |
| Nacpil, Nolybert Calara | National Service Manager | North Luzon |
| Navarro, Ricardo Gallemit Jr. | Senior Service Engineer | CDO |
| Panimdim, Ador Alfon | Regional Service Manager | Davao |
| Pardillo, Kerwin Nacario | National Service Manager | Cebu |
| Pardillo, Keryl Nacario | FSE | Cebu |
| Pineda, Ruffy Gallego | Senior Service Engineer | Bacolod |
| Tuala, Kenneth Montecastro | Customer Service Manager | NCR |
| Tumaroy, Joey Nichols Bustillo | Regional Service Manager | Cebu |
| Velasco, Jemimah Orsua | Service Coordinator | North Luzon |
| Zamora, Soteri Forteza | Regional Service Manager | NCR |
| Hernandez, John Erick | FSE | NCR |
| De Jesus, Fredamdy Sengseng | Service Assistant | North Luzon |
| Genovate, Paul Anthony | FSE | Bacolod |
| Paran, Sean Rafael | Service Assistant | Davao |
| Dawang, Khline Iverson | FSE | Zamboanga |

(Plus executives: Cabral, Rivas, Vallarta, Yangco, Tuala, Zamora — ~9 more not relevant to portal)

## xlsx file metadata

- File: `Personnel list_.xlsx`, 15,604 bytes
- Last modified: 2026-04-28 by "MFC ZGC"
- Originally created: 2025-01-15 by "Voltaire Vallarta"
- 1 sheet, 1 header row at row 5, data rows 6-66 (64 entries with rows 54-55 empty)

## Code: PHP xlsx reader key patterns

```php
// 1. Use children(NS)->si WITHOUT the "as $i => $si" syntax:
//    $i becomes the ELEMENT NAME ('si'), not a numeric index!
//    Use a counter instead:
$idx = 0;
foreach ($sx->children($NS)->si as $si) {
    ...
    $shared[$idx++] = $txt;
}

// 2. Attributes are unqualified even on namespaced elements:
$attr = $c->attributes();  // NO $NS argument for attrs
$ref = (string) ($attr['r'] ?? '');

// 3. Strip diacritics for cross-checking:
function norm($s) {
    $clean = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    return strtolower(preg_replace('/[^a-z0-9 ]/', '', $clean));
}

// 4. Multi-word surnames (San Juan, De Pio, De Lara, De Jesus):
//    Try last 1, 2, or 3 words as candidate surname.
```
