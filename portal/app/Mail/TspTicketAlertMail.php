<?php

namespace App\Mail;

use App\Models\TicketAcknowledgement;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Alert email sent to a TSP when a new ticket is created in their
 * region. The email has two CTAs:
 *
 *   1. "Acknowledge" — records a row in `ticket_acknowledgements` and
 *      redirects the TSP to the ticket detail page. This is the
 *      "I saw it" signal the customer service team tracks.
 *
 *   2. "View ticket" — direct link to the ticket detail page. The TSP
 *      can skip acknowledgement and just dive in (the existing
 *      "claim" button is on that page).
 *
 * Both links are signed so the URL can't be tampered with or
 * guessed. The acknowledge link is single-purpose — it carries the
 * target TSP's id in the signature payload and is rejected if the
 * signed-in user doesn't match.
 *
 * NOTE: not `ShouldQueue` — cPanel target has no worker. Sends
 * synchronously via the configured SMTP driver.
 */
class TspTicketAlertMail extends Mailable
{
    use SerializesModels;

    /**
     * @param  \App\Models\User  $tsp  The receiving TSP. Their email
     *         is the To: address; their id is baked into the signed URL.
     * @param  string  $mondayTicketId  Monday item id of the new ticket
     * @param  string  $ticketSubject  Ticket subject
     * @param  string|null  $brand  Affected machine brand
     * @param  string|null  $model  Affected machine model
     * @param  string|null  $requestType  'Issue' | 'Request'
     * @param  string|null  $regionCode  The customer's resolved region
     * @param  string|null  $customerName  Who opened the ticket
     * @param  string|null  $customerEmail  Reply-to address for the TSP
     * @param  string  $acknowledgeUrl  Pre-signed, points to
     *         /tsp/tickets/{id}/acknowledge?token=...&user={id}
     * @param  string  $viewUrl  Pre-signed, points to /tsp/tickets/{id}
     */
    public function __construct(
        public readonly User $tsp,
        public readonly string $mondayTicketId,
        public readonly string $ticketSubject,
        public readonly ?string $brand,
        public readonly ?string $model,
        public readonly ?string $requestType,
        public readonly ?string $regionCode,
        public readonly ?string $customerName,
        public readonly ?string $customerEmail,
        public readonly string $acknowledgeUrl,
        public readonly string $viewUrl,
        public readonly bool $alreadyAcknowledged = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('mail.from.address'),
                config('mail.from.name', 'MC BioTechnical Solutions')
            ),
            // Keep the subject short — mobile notification truncation
            // happens around 60-80 chars on most carriers.
            subject: "New ticket #{$this->mondayTicketId} in your region: {$this->truncate($this->ticketSubject, 50)}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.tsp-ticket-alert',
            with: [
                'tsp'                  => $this->tsp,
                'mondayTicketId'       => $this->mondayTicketId,
                'subject'              => $this->ticketSubject,
                'brand'                => $this->brand,
                'model'                => $this->model,
                'requestType'          => $this->requestType,
                'regionCode'           => $this->regionCode,
                'customerName'         => $this->customerName,
                'customerEmail'        => $this->customerEmail,
                'acknowledgeUrl'       => $this->acknowledgeUrl,
                'viewUrl'              => $this->viewUrl,
                'alreadyAcknowledged'  => $this->alreadyAcknowledged,
                'appName'              => config('app.name', 'MC BioTechnical Solutions'),
            ],
        );
    }

    /**
     * Plain-text fallback for clients that can't render HTML.
     */
    public function build()
    {
        return $this->view('emails.tsp-ticket-alert-plain');
    }

    private function truncate(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        return mb_substr($s, 0, $max - 1) . '…';
    }
}
