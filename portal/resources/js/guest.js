// Lightweight entry for guest/auth pages (login, register, welcome).
// No Pusher/Echo, no Alpine component factories — just the <mc-logo>
// brand element (used in the sign-in header), the CSS, and Alpine.js
// base (already loaded by Livewire). This keeps the auth pages ~50KB
// lighter than the full app.js bundle.
import './mc-logo.js';
