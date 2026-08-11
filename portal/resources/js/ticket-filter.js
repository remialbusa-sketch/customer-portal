/*
 * Alpine.js component for the customer ticket dashboard filter bar.
 *
 *   <div x-data="ticketFilter({ tickets: [...] })">
 *
 * Provides client-side search, status-bucket filtering, and
 * newest/oldest sorting over an immutable ticket array — zero
 * extra round trips to the server.
 *
 * Conventions (project-wide)
 * --------------------------
 * 1. Defined as `window.ticketFilter` so Blade templates can
 *    reference it via `x-data="ticketFilter({...})"`.
 * 2. Imported in `app.js` (loaded via Vite in <head>) so the
 *    factory is available *before* Livewire starts Alpine.
 * 3. Follows the same pattern as chat-panel.js,
 *    internal-notes-panel.js, and time-tracker.js.
 */
window.ticketFilter = (initial) => ({
    tickets: initial.tickets,
    query: '',
    statusFilter: [],
    sort: 'newest',

    /** Filtered + sorted ticket list (computed). */
    get filteredTickets() {
        let list = this.tickets;

        // Text search — subject, name, or ticket id
        if (this.query) {
            const q = this.query.toLowerCase();
            list = list.filter(t =>
                (t.subject_text || '').toLowerCase().includes(q) ||
                (t.name || '').toLowerCase().includes(q) ||
                (t.id || '').includes(q)
            );
        }

        // Status bucket filter
        if (this.statusFilter.length) {
            list = list.filter(t => this.statusFilter.includes(t._statusBucket));
        }

        // Sort (default newest — already in desc order from controller)
        if (this.sort === 'oldest') {
            list = [...list].reverse();
        }

        return list;
    },

    /** Toggle a status bucket in/out of the filter. */
    toggleStatus(bucket) {
        const idx = this.statusFilter.indexOf(bucket);
        if (idx >= 0) {
            this.statusFilter.splice(idx, 1);
        } else {
            this.statusFilter.push(bucket);
        }
    },

    /** Compute badge class + dot colour from status text. */
    statusBadge(text) {
        const s = (text || '').toLowerCase();
        if (s.includes('new') || s.includes('open')) return { class: 'badge-info', dot: 'bg-info' };
        if (s.includes('progress')) return { class: 'badge-warning', dot: 'bg-warning' };
        if (s.includes('awaiting')) return { class: 'badge-accent', dot: 'bg-accent' };
        if (s.includes('resolved') || s.includes('closed') || s.includes('done') || s.includes('complete'))
            return { class: 'badge-success', dot: 'bg-success' };
        return { class: 'badge-ghost', dot: 'bg-base-content/40' };
    },

    /** Number of active filter dimensions (for showing the Clear button). */
    get activeFilterCount() {
        let n = 0;
        if (this.query) n++;
        if (this.statusFilter.length) n++;
        return n;
    },

    /** Reset all filters to defaults. */
    clearFilters() {
        this.query = '';
        this.statusFilter = [];
        this.sort = 'newest';
    },
});
