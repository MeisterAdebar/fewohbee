import { Controller } from '@hotwired/stimulus';

/**
 * Touch screens: a finger moving up (scrolling down) makes room; the navigation
 * comes back only once the page is scrolled back to its top, or with a finger
 * moving down while the page is at its top (a page that does not scroll, or
 * hardly: a tablet held upright shows nearly the whole list). Where
 * the frame is a sidebar (lg and up, e.g. a tablet held sideways) the sidebar
 * collapses to its rail of icons; below (a tablet upright, a phone) the bars at
 * the top and at the bottom slide away (shell.css) and the page takes the room
 * of the bottom bar.
 *
 * Hiding is taken from the finger rather than from the scroll position: at the
 * end of a page the browser bounces back or slides its address bar in and out,
 * which moves the scroll position up without the user scrolling up.
 *
 * Sits on the main area of base.html.twig; the reservation overview scrolls
 * its own table and leaves it out. Each page starts with the navigation shown.
 * A rail chosen with the sidebar's own button stays as it is; reaching the top
 * only opens a sidebar that scrolling down collapsed.
 */
const HIDDEN_CLASS = 'hide-nav-hidden';
// Kept while the bars slide back (shell.css: .2s), see apply()
const RETURNING_CLASS = 'hide-nav-returning';
const RETURN_MS = 250;
const RAIL_CLASS = 'app-nav-rail';
const TOUCH = window.matchMedia('not all and (hover: hover) and (pointer: fine)');
const SIDEBAR = window.matchMedia('(min-width: 992px)');
// How far the finger moves in one direction before the navigation follows, so
// a jitter does not flip it back and forth
const DISTANCE = 24;

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
        // Turned from upright to sideways with the bars away: the sidebar comes as a rail
        SIDEBAR.addEventListener('change', () => {
            if (document.documentElement.classList.contains(HIDDEN_CLASS)) {
                this.apply(true);
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
        const touch = event.touches.length === 1 ? event.touches[0] : null;
        // Not in a dialog, a menu or a field: they scroll or take the finger themselves
        this.touchY = touch && !this.isBusy()
            && !event.target.closest?.('.modal, .offcanvas, .dropdown-menu, .popover, input, textarea, select')
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
        if (window.scrollY <= 0 && scrollable) {
            this.setHidden(false);
        }
    }

    /** An open dialog or menu keeps the navigation as it is */
    isBusy() {
        return document.querySelector('.modal.show, .offcanvas.show, .offcanvas.showing');
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
        // The sidebar's rail: set here only where it was open, and opened again
        // only where it was set here. Not the cookie of the sidebar's button: the
        // choice there stays the user's.
        if (SIDEBAR.matches || !hidden) {
            if (hidden && !root.classList.contains(RAIL_CLASS)) {
                root.classList.add(RAIL_CLASS);
                root.dataset.hideNavRail = '';
            } else if (!hidden && 'hideNavRail' in root.dataset) {
                root.classList.remove(RAIL_CLASS);
                delete root.dataset.hideNavRail;
            }
        }
    }
}
