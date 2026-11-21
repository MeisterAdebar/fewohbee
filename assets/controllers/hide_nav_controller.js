import { Controller } from '@hotwired/stimulus';

/**
 * Touch screens: a finger moving up (scrolling down) makes room; the navigation
 * comes back only once the page is scrolled back to its top, or with a finger
 * moving down while the page is at its top (a page that does not scroll, or
 * hardly: a tablet held upright shows nearly the whole list). The bars at the
 * top and at the bottom slide away (shell.css) and the page takes the room of
 * the bottom bar. Only below lg (a phone, a tablet upright): where the frame is
 * a sidebar (a tablet held sideways) it stays as it is - collapsing and opening
 * with every scroll was too restless there, and the sidebar's own button
 * collapses it.
 *
 * Hiding is taken from the finger rather than from the scroll position: at the
 * end of a page the browser bounces back or slides its address bar in and out,
 * which moves the scroll position up without the user scrolling up.
 *
 * Sits on the main area of base.html.twig; the reservation overview scrolls
 * its own table and leaves it out. Each page starts with the navigation shown.
 */
const HIDDEN_CLASS = 'hide-nav-hidden';
// Kept while the bars slide back (shell.css: .2s), see apply()
const RETURNING_CLASS = 'hide-nav-returning';
const RETURN_MS = 250;
const TOUCH = window.matchMedia('not all and (hover: hover) and (pointer: fine)');
const SIDEBAR = window.matchMedia('(min-width: 992px)');
// How far the finger moves in one direction before the navigation follows, so
// a jitter does not flip it back and forth
const DISTANCE = 24;
// Bootstrap's slide-in panels, also the responsive ones such as the menu (offcanvas-lg)
const OFFCANVAS = ['', '-sm', '-md', '-lg', '-xl', '-xxl'].map((size) => `.offcanvas${size}`).join(', ');
const OFFCANVAS_OPEN = ['', '-sm', '-md', '-lg', '-xl', '-xxl']
    .map((size) => `.offcanvas${size}.show, .offcanvas${size}.showing`).join(', ');

export default class extends Controller {
    connect() {
        if (!TOUCH.matches) {
            return;
        }
        this.abort = new AbortController();
        const signal = this.abort.signal;
        this.touchY = null;
        document.addEventListener('touchstart', (event) => this.onTouchStart(event), { passive: true, signal });
        document.addEventListener('touchmove', (event) => this.onTouchMove(event), { passive: true, signal });
        window.addEventListener('scroll', () => this.onScroll(), { passive: true, signal });
        // Turned from upright to sideways with the bars away: the sidebar comes back as it was
        SIDEBAR.addEventListener('change', () => {
            if (SIDEBAR.matches) {
                this.setHidden(false);
            }
        }, { signal });
    }

    disconnect() {
        this.abort?.abort();
        // The next page starts with the navigation shown
        if (document.documentElement.classList.contains(HIDDEN_CLASS)) {
            this.apply(false);
        }
    }

    onTouchStart(event) {
        const touch = event.touches.length === 1 && !SIDEBAR.matches ? event.touches[0] : null;
        // Not in a dialog, a menu or a field: they scroll or take the finger themselves
        this.touchY = touch && !this.isBusy()
            && !event.target.closest?.(`.modal, ${OFFCANVAS}, .dropdown-menu, .popover, input, textarea, select`)
            ? { x: touch.clientX, y: touch.clientY }
            : null;
    }

    onTouchMove(event) {
        if (!this.touchY || event.touches.length !== 1) {
            return;
        }
        const dx = event.touches[0].clientX - this.touchY.x;
        const dy = event.touches[0].clientY - this.touchY.y;
        // Sideways is paging (swipe-nav), not scrolling
        if (Math.abs(dy) < DISTANCE || Math.abs(dy) < 1.5 * Math.abs(dx)) {
            return;
        }
        this.touchY = { x: event.touches[0].clientX, y: event.touches[0].clientY };
        if (dy < 0) {
            this.setHidden(true);
        } else if (window.scrollY <= 0) {
            this.setHidden(false);
        }
    }

    /**
     * Back at the top of the page (or bouncing above it) the navigation returns. Not on a page
     * that no longer scrolls: hiding the bars made it shorter, and the browser moving it back
     * to its top is no scrolling up (a finger moving down brings them back there).
     */
    onScroll() {
        const scrollable = document.documentElement.scrollHeight > window.innerHeight;
        if (window.scrollY <= 0 && scrollable && !SIDEBAR.matches) {
            this.setHidden(false);
        }
    }

    /** An open dialog or menu keeps the navigation as it is */
    isBusy() {
        return document.querySelector(`.modal.show, ${OFFCANVAS_OPEN}`);
    }

    setHidden(hidden) {
        if (document.documentElement.classList.contains(HIDDEN_CLASS) !== hidden) {
            this.apply(hidden);
        }
    }

    apply(hidden) {
        const root = document.documentElement;
        root.classList.toggle(HIDDEN_CLASS, hidden);
        // The top bar clips its slide-in menu until it stands still again (shell.css)
        clearTimeout(this.returnTimer);
        root.classList.toggle(RETURNING_CLASS, !hidden);
        if (!hidden) {
            this.returnTimer = setTimeout(() => root.classList.remove(RETURNING_CLASS), RETURN_MS);
        }
    }
}
