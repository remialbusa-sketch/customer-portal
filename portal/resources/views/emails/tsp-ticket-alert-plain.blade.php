Hello {{ $tsp->name }},

A new service ticket was opened in your region. Please take a look and acknowledge it when you've had a chance to review it.

================================================================
TICKET #{{ $mondayTicketId }}    Region: {{ $regionCode ?: '—' }}    Type: {{ $requestType ?? 'Request' }}

Subject: {{ $subject }}
@if ($brand || $model)
Equipment: {{ trim(($brand ?? '') . ' ' . ($model ?? '')) }}
@endif
@if ($customerName)
Customer: {{ $customerName }}
@endif
@if ($customerEmail)
Reply to: {{ $customerEmail }}
@endif
================================================================

@if ($alreadyAcknowledged)
You've already acknowledged this ticket. If you'd like to claim it, head to the ticket page and use the Claim button.

View the ticket here:
{{ $viewUrl }}
@else
ACKNOWLEDGEMENT (so the customer knows you're on it):
{{ $acknowledgeUrl }}

VIEW THE TICKET (for full details before you decide):
{{ $viewUrl }}

Acknowledging lets the customer know you're on it. It doesn't claim the ticket — you can still review the full details before deciding who handles it.
@endif

---

Next steps
1. Click "Acknowledge" so the customer sees you've picked it up
2. Open the ticket for full context (equipment history, contact info)
3. Use the "Claim" button on the ticket page to assign it to yourself and flip the response status to RESPONDED

--
{{ $appName }}
© {{ date('Y') }} {{ $appName }}. All rights reserved.
