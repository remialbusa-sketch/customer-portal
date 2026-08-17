<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Sync TSP / ITS / Manager accounts from Monday.com into the local users table.
 *
 * Roles:
 *   - 'fse'     = Field Service Engineer (incl. Senior Service Engineer)
 *   - 'its'     = IT Specialist (internal support)
 *   - 'manager' = Service Manager / Regional Service Manager / Regional IT Manager
 *
 * These accounts are created with status='invited' and a temporary password
 * (email is also their password, lowercased + '!Temp1'). The customer-portal
 * will email them a one-time invite link on first login.
 *
 * To re-sync after Monday changes, just run:
 *     php artisan db:seed --class=TspUsersSeeder
 * which will upsert (insert or update) the monday_id + name + email fields.
 */
class TspUsersSeeder extends Seeder
{
    /**
     * Source-of-truth roster pulled live from Monday.com.
     * Tuple shape: [monday_id, name, email, role, team, region]
     */
    private array $roster = [
        // ── FSEs (including Senior Service Engineers) ────────────────────
        ['77787452',  'Adonis Ybanez',                'adonis.ybanez@mcbtsi.com',         'fse',     'FSE',     'Davao'],
        ['77787508',  'Brixxtonn Garcenila Basuel',   'brixxtonn.basuel@mcbtsi.com',      'fse',     'FSE',     'NCR'],
        ['77787515',  'Christopher C. Auditor',       'christopher.auditor@mcbtsi.com',   'fse',     'FSE',     'NCR'],
        ['77787519',  'Daniel L. Igano',              'daniel.igano@mcbtsi.com',          'fse',     'FSE',     'NCR'],
        ['77787523',  'Elthon Jay D. Navares',        'elthon.navares@mcbtsi.com',        'fse',     'FSE',     'Visayas'],
        ['77787536',  'Harvyn Brian D. Honorica',     'harvyn.honorica@mcbtsi.com',       'fse',     'FSE',     'NCR'],
        ['77787543',  'Jhon Carlo Faustino',          'carlo.faustino@mcbtsi.com',        'fse',     'FSE',     'NCR'],
        ['77787550',  'Joven C. Padon',               'joven.padon@mcbtsi.com',           'fse',     'FSE',     'Mindanao'],
        ['77787557',  'Lance Canaveral',              'lance.canaveral@mcbtsi.com',       'fse',     'FSE',     'NCR'],
        ['77787561',  'Leander Eamon Fajardo',        'leander.fajardo@mcbtsi.com',       'fse',     'FSE',     'NCR'],
        ['77787566',  'Mart Russel Santos',           'mart.santos@mcbtsi.com',           'fse',     'FSE',     'NCR'],
        ['77787569',  'Neil Darwin San Juan',         'neildarwin.sanjuan@mcbtsi.com',    'fse',     'FSE',     'NCR'],
        ['77787574',  'Roberto S. de Pio Jr.',        'roberto.depio@mcbtsi.com',         'fse',     'FSE',     'NCR'],
        ['77787591',  'Sherwin U. Montellin',         'sherwin.montellin@mcbtsi.com',     'fse',     'FSE',     'NCR'],
        ['77787601',  'Warren Suba',                  'warren.suba@mcbtsi.com',           'fse',     'FSE',     'NCR'],
        ['101400913', 'Bell Anthony A. Peñaloza',     'bellanthony.penaloza@mcbtsi.com',  'fse',     'FSE',     'CDO'],
        // Senior Service Engineers (also FSE role, just flagged in team)
        ['77787530',  'Gary Walter Vivas',            'gary.vivas@mcbtsi.com',            'fse',     'FSE-Sr',  'NCR'],
        ['77787564',  'Mark Niel Amper',              'mark.amper@mcbtsi.com',            'fse',     'FSE-Sr',  'NCR'],
        ['77787583',  'Rogel de Lara',                'rogel.delara@mcbtsi.com',          'fse',     'FSE-Sr',  'NCR'],

        // ── IT Specialists ──────────────────────────────────────────────
        ['77716300',  'Orfe Lyle C. Calderon',        'orfe.calderon@mcbtsi.com',         'its',     'ITS',     'NCR'],
        ['77717201',  'Jomer Ibardolasa',             'jomer.ibardolasa@mcbtsi.com',      'its',     'ITS',     'NCR'],
        ['77717204',  'John Lourence Andoque',        'lourence.andoque@mcbtsi.com',      'its',     'ITS',     'NCR'],
        ['77717206',  'Kenneth Amor',                 'kenneth.amor@mcbtsi.com',          'its',     'ITS',     'NCR'],
        ['77717999',  'Hannah Pepito',                'hannah.pepito@mcbtsi.com',         'its',     'ITS',     'NCR'],
        ['89907887',  'Francis Conrad Sevilla',       'francis.sevilla@mcbtsi.com',       'its',     'ITS',     'NCR'],
        ['93755673',  'Roger S. Opialda',             'roger.opialda@mcbtsi.com',         'its',     'ITS',     'CDO'],
        ['77561926',  'Remial Busa',                  'remial.busa@mcbtsi.com',           'its',     'ITS',     'NCR'],

        // ── Service Managers (new 'manager' role) ───────────────────────
        ['77717213',  'Randee A. Borinaga',           'randee.borinaga@mcbtsi.com',       'manager', 'MGR',     'NCR'],
        ['77787531',  'Gerald Ricafranca',            'gerald.ricafranca@mcbtsi.com',     'manager', 'MGR',     'NCR'],
        ['77787540',  'Jefferson D. Yaranon',         'jefferson.yaranon@mcbtsi.com',     'manager', 'MGR',     'NLuzon'],
    ];

    public function run(): void
    {
        $created = 0;
        $updated = 0;

        foreach ($this->roster as [$mondayId, $name, $email, $role, $team, $region]) {
            $user = User::where('email', $email)->first();

            if ($user) {
                // Update monday-linked fields; don't touch role for admin/customer accounts.
                if (! in_array($user->role, ['admin', 'customer'], true)) {
                    $user->update([
                        'monday_id' => $mondayId,
                        'name'      => $name,
                        'role'      => $role,
                        'team'      => $team,
                        'region'    => $region,
                        'status'    => $user->status ?: 'active',
                    ]);
                    $updated++;
                }
                continue;
            }

            User::create([
                'name'      => $name,
                'email'     => $email,
                'password'  => Hash::make('Password!123'), // temp; user will reset on first login
                'role'      => $role,
                'status'    => 'active',
                'monday_id' => $mondayId,
                'team'      => $team,
                'region'    => $region,
            ]);
            $created++;
        }

        $this->command?->info("TspUsersSeeder: created {$created}, updated {$updated}, total in roster: " . count($this->roster));
    }
}
