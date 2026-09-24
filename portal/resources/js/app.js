// Global app JS entry. Most interactivity is in Livewire components
// and per-page Alpine / vanilla bundles, but we expose:
//   - window.echo()                → shared Pusher / Echo instance
//   - window.chatPanel             → Alpine component factory for the chat panel
//   - window.internalNotesPanel    → Alpine component factory for the TSP-only
//                                    internal-notes panel
//
//   const echo = window.echo();
//   echo.private(`ticket.${mondayId}`).listen('.message.sent', (e) => { ... });

import { getEcho } from './echo.js';
import './chat-panel.js';
import './internal-notes-panel.js';
import './time-tracker.js';
import './service-report-form.js';
import './ticket-status-banner.js';
import './realtime-dashboard.js';
import './customer-ticket-banner.js';
import './ticket-filter.js';

window.echo = getEcho;

// ---------------------------------------------------------------------
//  Notification bell realtime boost
//  ---------------------------------------------------------------------
//  When Pusher is configured, a NotificationCreated broadcast lands on
//  the user's private `user.{id}` channel. We re-dispatch it as a
//  `bell-refresh` window event, which the Bell Livewire component
//  listens for. When Pusher ISN'T configured (cPanel build), getEcho()
//  returns null and the bell's 45s wire:poll is the only path.
(function () {
    const meta = document.querySelector('meta[name="auth-user-id"]');
    const userId = meta ? meta.getAttribute('content') : null;
    if (! userId) return;
    const echo = window.echo();
    if (! echo) return;
    try {
        echo.private('user.' + userId).listen('.notifications.updated', () => {
            window.dispatchEvent(new CustomEvent('bell-refresh'));
        });
    } catch (e) {
        // Echo channel subscription is best-effort; polling covers it.
    }
})();
