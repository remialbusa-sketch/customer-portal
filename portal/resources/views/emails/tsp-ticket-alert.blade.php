@component('mail::message')
{{-- Email header --}}
@slot('header')
@component('mail::header', ['url' => config('app.url')])
{{ $appName }}
@endcomponent
@endslot

# A new ticket just came in

Hello **{{ $tsp->name }}**,

A new service ticket was opened in your region. Please take a look and acknowledge it when you've had a chance to review it.

@component('mail::panel')
**Ticket #{{ $mondayTicketId }}** · {{ $regionCode ?: '—' }} · {{ $requestType ?? 'Request' }}

## {{ $subject }}

@if ($brand || $model)
- **Equipment:** {{ trim(($brand ?? '') . ' ' . ($model ?? '')) }}
@endif
@if ($customerName)
- **Customer:** {{ $customerName }}
@endif
@if ($customerEmail)
- **Reply to:** {{ $customerEmail }}
@endif
@endcomponent

@if ($alreadyAcknowledged)
@component('mail::button', ['url' => $viewUrl, 'color' => 'primary'])
View ticket
@endcomponent

You've already acknowledged this ticket. If you'd like to claim it, head to the ticket page and use the **Claim** button.
@else
@component('mail::button', ['url' => $acknowledgeUrl, 'color' => 'success'])
Acknowledge this ticket
@endcomponent

@component('mail::button', ['url' => $viewUrl, 'color' => 'primary'])
View ticket
@endcomponent

Acknowledging lets the customer know you're on it. It doesn't claim the ticket — you can still review the full details before deciding who handles it.
@endif

---

**Next steps**
1. Click **Acknowledge** so the customer sees you've picked it up
2. Open the ticket for full context (equipment history, contact info)
3. Use **Claim** on the ticket page to assign it to yourself and flip the response status to RESPONDED

@slot('subcopy')
@component('mail::subcopy')
You're receiving this because a ticket was created in your region ({{ $regionCode ?: 'unspecified' }}).
If you think you shouldn't be on the alert list for this region, reply to this email and your service coordinator will adjust the routing.
@endcomponent
@endslot

@slot('footer')
@component('mail::footer')
© {{ date('Y') }} {{ $appName }}. All rights reserved.
@endcomponent
@endslot
@endcomponent
