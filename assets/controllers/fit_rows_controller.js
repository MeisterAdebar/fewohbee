import { Controller } from '@hotwired/stimulus';
import { morphElements } from '@hotwired/turbo';

/**
 * A paged list that shows as many rows as fit the screen, so the page does not
 * scroll and paging (arrows, swipes) goes screen by screen, like the
 * reservation overview.
 *
 * The browser measures how many rows fit and keeps the number in a cookie;
 * the server takes it as page size (bounded) on every request, so links stay
 * as they are. When the number changes - the screen turned, the navbar hidden
 * or shown - the list is fetched again at the page that holds the row shown at
 * the top before. Only this element is morphed into the fresh one, so rows are
 * added or removed in place instead of the whole page being drawn anew.
 *
 * The height of a row is that of the lowest row on the page, i.e. one line of
 * text; it is the same on every page, so paging does not change the count. A
 * page with wrapped rows may scroll a little.
 *
 * Values: cookie (its name), perPage / page / total / search as the server
 * used them; search because a list searched in place keeps it out of the
 * address. Target rows: the tbody of the list.
 */
const MIN_ROWS = 5;
const MAX_ROWS = 100;

export default class extends Controller {
    static targets = ['rows'];
    static values = { cookie: String, perPage: Number, page: Number, total: Number, search: String };

    connect() {
        this.abort = new AbortController();
        const signal = this.abort.signal;
        const later = () => {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.fit(), 250);
        };
        // Turning the screen changes the width. A browser's address bar that
        // slides in and out while scrolling changes the height by a little
        // only; that must not reload the page each time.
        let width = window.innerWidth;
        let height = window.innerHeight;
        window.addEventListener('resize', () => {
            if (window.innerWidth !== width || Math.abs(window.innerHeight - height) > 120) {
                width = window.innerWidth;
                height = window.innerHeight;
                later();
            }
        }, { signal });
        // The layout has changed already: no need to wait
        document.addEventListener('hide-nav:change', () => requestAnimationFrame(() => this.fit()), { signal });
        requestAnimationFrame(() => this.fit());
    }

    disconnect() {
        this.abort?.abort();
        this.loading?.abort();
        clearTimeout(this.timer);
    }

    fit() {
        // An open form or on-screen keyboard changes the height only for a while
        if (!this.hasRowsTarget
            || document.documentElement.hasAttribute('data-turbo-preview')
            || document.querySelector('.modal.show, .offcanvas.show, .offcanvas.showing')
            || document.activeElement?.matches('input, textarea, select')) {
            return;
        }
        const rows = [...this.rowsTarget.rows].filter((row) => !row.querySelector('td[colspan]'));
        if (rows.length === 0) {
            return;
        }
        // Measured at its natural height, stretched again below
        this.element.style.minHeight = '';
        const heights = rows.map((row) => row.getBoundingClientRect().height);
        // Rounded up: the same row is 30.2 px high on one page and 30.8 px on
        // the next (subpixel layout), which tipped the count from 12 to 13 and
        // loaded the page a second time
        const rowHeight = Math.ceil(Math.min(...heights));
        const rowsHeight = heights.reduce((sum, height) => sum + height, 0);

        // Everything but the rows: navbar, headings, table head, paging, margins
        const style = getComputedStyle(this.element);
        const bottom = this.element.getBoundingClientRect().bottom + window.scrollY + parseFloat(style.marginBottom);
        const free = window.innerHeight - (bottom - rowsHeight);
        const count = Math.max(MIN_ROWS, Math.min(MAX_ROWS, Math.floor(free / rowHeight)));

        // Down to the bottom of the screen, so the paging (app.css) stays in its
        // place however much room is left below the last whole row
        const top = this.element.getBoundingClientRect().top + window.scrollY;
        this.element.style.minHeight = `${window.innerHeight - top - parseFloat(style.marginBottom)}px`;

        // A row with a badge is a pixel taller, which can tip the count by one
        // and make paging switch back and forth. So off by one, take a row less
        // only when the page would scroll, and a row more only when it fits even
        // at the height of the tallest row.
        const overflows = document.documentElement.scrollHeight > window.innerHeight + 1;
        const oneMoreFits = count * Math.ceil(Math.max(...heights)) <= free;
        if (count === this.perPageValue
            || (count === this.perPageValue - 1 && !overflows)
            || (count === this.perPageValue + 1 && !oneMoreFits)) {
            this.storeCount(this.perPageValue);
            return;
        }
        this.storeCount(count);
        // Both page sizes show all rows at once: nothing to reload
        if (this.totalValue <= Math.min(count, this.perPageValue)) {
            return;
        }
        const first = (this.pageValue - 1) * this.perPageValue;
        const url = new URL(window.location.href);
        url.searchParams.set('page', String(Math.floor(first / count) + 1));
        if (this.searchValue !== '') {
            url.searchParams.set('search', this.searchValue);
        }
        this.load(url.toString());
    }

    /**
     * Fetches the list at the new page size and morphs this element into it.
     * Anything unexpected (a redirect to the login, an error, no list in the
     * answer) falls back to loading the whole page.
     */
    async load(url) {
        this.loading?.abort();
        const loading = new AbortController();
        this.loading = loading;
        try {
            const response = await fetch(url, {
                headers: { Accept: 'text/html' },
                credentials: 'same-origin',
                signal: loading.signal,
            });
            const html = response.ok && !response.redirected ? await response.text() : '';
            const fresh = new DOMParser().parseFromString(html, 'text/html')
                .querySelector('[data-controller~="fit-rows"]');
            if (!fresh) {
                throw new Error('No list in the answer');
            }
            // The fresh element brings no height of its own: keep the one set by fit()
            const { minHeight } = this.element.style;
            morphElements(this.element, fresh);
            this.element.style.minHeight = minHeight;
            window.history.replaceState(window.history.state, '', url);
        } catch (error) {
            if (loading.signal.aborted) {
                return;
            }
            if (window.Turbo) {
                window.Turbo.visit(url, { action: 'replace' });
            } else {
                window.location.replace(url);
            }
        }
    }

    storeCount(count) {
        document.cookie = `${this.cookieValue}=${count}; path=/; max-age=31536000; SameSite=Lax`;
    }
}
