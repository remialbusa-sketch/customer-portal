/*
 * Alpine.js component for the customer ticket dashboard.
 *
 *   <div x-data="ticketFilter({ tickets: [...] })">
 *
 * Provides client-side search, tab + priority filtering, newest/oldest
 * sorting, and pagination over an immutable ticket array — zero extra
 * round trips to the server.
 *
 * Conventions (project-wide)
 * --------------------------
 * 1. Defined as `window.ticketFilter` so Blade templates can
 *    reference it via `x-data="ticketFilter({...})"`.
 * 2. Imported in `app.js` (loaded via Vite in <head>) so the
 *    factory is available *before* Livewire starts Alpine.
 */
window.ticketFilter = (initial) => ({
    tickets: initial.tickets,
    query: '',
    statusFilter: [],
    priorityFilter: [],
    tab: 'all',
    sort: 'newest',
    page: 1,
    perPage: 5,

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

        // Tab (single status bucket) — kept in sync with statusFilter
        if (this.tab && this.tab !== 'all') {
            list = list.filter(t => t._statusBucket === this.tab);
        } else if (this.statusFilter.length) {
            list = list.filter(t => this.statusFilter.includes(t._statusBucket));
        }

        // Priority filter (exact, case-insensitive)
        if (this.priorityFilter.length) {
            const wanted = this.priorityFilter.map(p => p.toLowerCase());
            list = list.filter(t => wanted.includes((t.priority_text || '').toLowerCase()));
        }

        // Sort (default newest — already in desc order from controller)
        if (this.sort === 'oldest') {
            list = [...list].reverse();
        }

        return list;
    },

    /** Current page slice. */
    get pagedTickets() {
        const start = (this.page - 1) * this.perPage;
        return this.filteredTickets.slice(start, start + this.perPage);
    },

    get pageCount() {
        return Math.max(1, Math.ceil(this.filteredTickets.length / this.perPage));
    },

    get showingFrom() {
        if (this.filteredTickets.length === 0) return 0;
        return (this.page - 1) * this.perPage + 1;
    },

    get showingTo() {
        return Math.min(this.filteredTickets.length, this.page * this.perPage);
    },

    /** Reset to page 1 whenever a filter dimension changes. */
    resetPage() {
        this.page = 1;
    },

    /** Select a tab (single-select status filter). */
    selectTab(bucket) {
        this.tab = bucket;
        this.statusFilter = [];
        this.resetPage();
    },

    /** Toggle a status bucket in/out of the filter. */
    toggleStatus(bucket) {
        const idx = this.statusFilter.indexOf(bucket);
        if (idx >= 0) {
            this.statusFilter.splice(idx, 1);
        } else {
            this.statusFilter.push(bucket);
        }
        this.tab = 'all';
        this.resetPage();
    },

    /** Toggle a priority value in/out of the filter. */
    togglePriority(priority) {
        const idx = this.priorityFilter.indexOf(priority);
        if (idx >= 0) {
            this.priorityFilter.splice(idx, 1);
        } else {
            this.priorityFilter.push(priority);
        }
        this.resetPage();
    },

    /** Compute badge class + dot colour from status text. */
    statusBadge(text) {        const s = (text || '').toLowerCase();
        if (s.includes('new') || s.includes('open')) return { class: 'badge-info', dot: 'bg-info' };
        if (s.includes('progress')) return { class: 'badge-warning', dot: 'bg-warning' };
        if (s.includes('awaiting')) return { class: 'badge-accent', dot: 'bg-accent' };
        if (s.includes('resolved') || s.includes('closed') || s.includes('done') || s.includes('complete'))
            return { class: 'badge-success', dot: 'bg-success' };
        return { class: 'badge-ghost', dot: 'bg-base-content/40' };
    },

    /** Figma pill language keyed by status bucket (table + activity). */
    statusPill(bucket) {
        switch (bucket) {
            case 'resolved':    return { bg: 'bg-[#E3F7F3]', text: 'text-[#17847A]' };
            case 'open':        return { bg: 'bg-[#EBF2FD]', text: 'text-[#3977E8]' };
            case 'in_progress': return { bg: 'bg-[#FEF3C7]', text: 'text-[#B45309]' };
            case 'awaiting':    return { bg: 'bg-[#FDF2F8]', text: 'text-[#C1447E]' };
            default:            return { bg: 'bg-base-200', text: 'text-base-content/60' };
        }
    },

    /** Compute pill classes from priority text (Figma pill language). */    priorityPill(text) {
        const s = (text || '').toLowerCase().trim();
        if (!s || s === '—' || s === '-') return null;
        if (s.includes('critical')) return { bg: 'bg-error/10', text: 'text-error', label: text };
        if (s.includes('high')) return { bg: 'bg-[#FFF4E5]', text: 'text-[#B45309]', label: text };
        if (s.includes('medium')) return { bg: 'bg-base-200', text: 'text-base-content/60', label: text };
        if (s.includes('low')) return { bg: 'bg-base-200/60', text: 'text-base-content/50', label: text };
        return { bg: 'bg-base-200', text: 'text-base-content/60', label: text };
    },

    /** Number of active filter dimensions (for showing the Clear button). */
    get activeFilterCount() {
        let n = 0;
        if (this.query) n++;
        if (this.statusFilter.length || (this.tab && this.tab !== 'all')) n++;
        if (this.priorityFilter.length) n++;
        return n;
    },

    /** Reset all filters to defaults. */
    clearFilters() {
        this.query = '';
        this.statusFilter = [];
        this.priorityFilter = [];
        this.tab = 'all';
        this.sort = 'newest';
        this.resetPage();
    },
});
