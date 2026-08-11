<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Services\MondayClient;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class CustomerDashboardController extends Controller
{
    public function index(Request $request, MondayClient $monday): View
    {
        $user = $request->user();

        // Pull tickets from Monday that belong to this customer.
        $tickets = $monday->ticketsForCustomer($user->email);

        // Newest first
        usort($tickets, fn ($a, $b) => strcmp($b['id'], $a['id']));

        // Compute ticket stats for the dashboard stat cards.
        $stats = ['total' => count($tickets), 'open' => 0, 'in_progress' => 0, 'resolved' => 0];
        foreach ($tickets as $t) {
            $s = strtolower((string) ($t['status_text'] ?? ''));
            if (str_contains($s, 'resolved') || str_contains($s, 'closed') || str_contains($s, 'done') || str_contains($s, 'complete')) {
                $stats['resolved']++;
            } elseif (str_contains($s, 'progress')) {
                $stats['in_progress']++;
            } elseif ($s !== '' && $s !== '—') {
                $stats['open']++;
            }
        }

        // Flatten tickets into a clean JSON payload for Alpine filtering.
        // Each row carries only the fields the template renders, plus
        // a pre-computed _statusBucket for fast client-side filtering.
        $ticketsJson = array_map(function (array $t) {
            $brand = $t['item']['column_values']['text_mm5apcrc']['text'] ?? null;
            $model = $t['item']['column_values']['text_mm5am2kf']['text'] ?? null;

            // Resolve assigned TSP name(s) for the badge.
            $tspPersonIds = array_map('strval', $t['tsp_person_ids'] ?? []);
            $tspNameMap = MondayClient::resolveTspNames($tspPersonIds);
            $assignedNames = [];
            foreach ($tspPersonIds as $pid) {
                $name = $tspNameMap[$pid] ?? null;
                $assignedNames[] = $name ?? 'TSP #' . $pid;
            }

            // Status bucket matching the existing Blade match() logic.
            $s = strtolower((string) ($t['status_text'] ?? ''));
            if (str_contains($s, 'resolved') || str_contains($s, 'closed') || str_contains($s, 'done') || str_contains($s, 'complete')) {
                $bucket = 'resolved';
            } elseif (str_contains($s, 'progress')) {
                $bucket = 'in_progress';
            } elseif (str_contains($s, 'awaiting')) {
                $bucket = 'awaiting';
            } elseif ($s === '' || $s === '—') {
                $bucket = 'uncategorised';
            } else {
                $bucket = 'open';
            }

            return [
                'id'                => $t['id'],
                'name'              => $t['name'],
                'status_text'       => $t['status_text'] ?? '—',
                'subject_text'      => $t['subject_text'] ?: $t['name'],
                'request_type_text' => $t['request_type_text'] ?? null,
                'account_name'      => $t['account_name'] ?? null,
                'brand'             => $brand,
                'model'             => $model,
                'assigned_names'    => $assignedNames,
                '_statusBucket'     => $bucket,
            ];
        }, $tickets);

        return view('customer.dashboard', [
            'user'    => $user,
            'tickets' => $tickets,
            'stats'   => $stats,
            'ticketsJson' => $ticketsJson,
        ]);
    }
}
