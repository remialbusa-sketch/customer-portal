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
