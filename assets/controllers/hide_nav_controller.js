import { Controller } from '@hotwired/stimulus';
import { onSwipe, isPageSwipeTarget } from '../js/swipe.js';

/**
 * On touch screens the navbar stays at the top while the page scrolls (app.css).
 * Swiping up hides it for more room, like in the reservation overview, and
 * swiping down brings it back wherever the page stands.
 *
 * Sits on the navbar of base.html.twig. The choice holds for the browser tab
 * across pages: the class sits on <html>, which Turbo keeps across visits, and
 * sessionStorage carries it over a reload. Pages without this controller (the
 * reservation overview has its own steps) always show the navbar. The
 * browser's pull-to-refresh is off meanwhile, since it would take the swipe down.
 */
const ACTIVE_CLASS = 'hide-nav-enabled';
const HIDDEN_CLASS = 'hide-nav-hidden';
const STORAGE_KEY = 'hide-nav-hidden';

export default class extends Controller {
    connect() {
        const root = document.documentElement.classList;
        root.add(ACTIVE_CLASS);
        root.toggle(HIDDEN_CLASS, sessionStorage.getItem(STORAGE_KEY) === '1');
        this.abort = new AbortController();
        onSwipe(document, this.abort.signal, (dx, dy, target, allowed) => {
            if (allowed && Math.abs(dy) >= 50 && Math.abs(dy) >= 1.5 * Math.abs(dx)) {
                this.setHidden(dy < 0);
            }
        }, (event) => isPageSwipeTarget(event.target));
    }

    disconnect() {
        this.abort?.abort();
        // Stimulus disconnects the old page before it connects the next one,
        // so a following page with this controller sets both classes again
        document.documentElement.classList.remove(ACTIVE_CLASS, HIDDEN_CLASS);
    }

    setHidden(hidden) {
        if (document.documentElement.classList.contains(HIDDEN_CLASS) === hidden) {
            return;
        }
        document.documentElement.classList.toggle(HIDDEN_CLASS, hidden);
        // Lists that fit the screen (fit_rows_controller.js) measure again
        document.dispatchEvent(new CustomEvent('hide-nav:change', { detail: { hidden } }));
        if (hidden) {
            sessionStorage.setItem(STORAGE_KEY, '1');
        } else {
            sessionStorage.removeItem(STORAGE_KEY);
        }
    }
}
