// Lightweight entry for guest/auth pages (login, register, welcome).
// No Pusher/Echo, no Alpine component factories — just the <mc-logo>
// brand element (used in the sign-in header), the CSS, and Alpine.js
// base (already loaded by Livewire). This keeps the auth pages ~50KB
// lighter than the full app.js bundle.
//
// The boot buffer's controller is NOT here — it is an inline <script>
// in layouts/guest.blade.php, because a Livewire SPA navigation
// re-runs inline body scripts but never re-runs a head module.
import './mc-logo.js';
