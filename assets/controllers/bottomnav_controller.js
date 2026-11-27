import { Controller } from '@hotwired/stimulus';
import { Dropdown } from 'bootstrap';

const MORE_CLASS = 'app-bottomnav-more';
const INSTANT_CLASS = 'app-bottomnav-instant';

/*
 * Bottom bar of phones and tablets held upright (templates/layout/_app_nav.html.twig): as many
 * entries as fit stand in the first row, "More" slides them out and the rest in, "Back" brings
 * the first row back (shell.css). Which entries fit depends on the width of the screen, so the
 * bar opens on the second row here, when the current page is one of the entries only there.
 */
export default class extends Controller {
    connect() {
        if (this.activeOnlyBehindMore()) {
            // Shown straight away, without sliding in on every page load
            this.element.classList.add(INSTANT_CLASS);
            this.setMore(true);
            requestAnimationFrame(() => requestAnimationFrame(() => {
                this.element.classList.remove(INSTANT_CLASS);
            }));
        } else {
            this.syncExpanded();
        }
    }

    showMore(event) {
        this.setMore(true);
        this.focusFirst('#appBottomnavMore', event);
    }

    showTabs(event) {
        this.element.querySelectorAll('[data-bs-toggle="dropdown"]').forEach((toggle) => {
            Dropdown.getInstance(toggle)?.hide();
        });
        this.setMore(false);
        this.focusFirst('#appBottomnavTabs', event);
    }

    setMore(more) {
        this.element.classList.toggle(MORE_CLASS, more);
        this.syncExpanded();
    }

    syncExpanded() {
        const more = this.element.classList.contains(MORE_CLASS);
        this.element.querySelector('[aria-controls="appBottomnavMore"]')?.setAttribute('aria-expanded', more ? 'true' : 'false');
    }

    /** The current entry is shown on the second row (display), so not in the first. */
    activeOnlyBehindMore() {
        const active = this.element.querySelector('#appBottomnavMore .app-bottomnav-item.active');
        return !!active && active.getClientRects().length > 0;
    }

    /** Keyboard users (a click without a pointer has no detail) continue on the row brought in. */
    focusFirst(selector, event) {
        if (event?.detail !== 0) {
            return;
        }
        const first = [...this.element.querySelectorAll(`${selector} .app-bottomnav-item`)]
            .find((item) => item.getClientRects().length > 0);
        first?.focus({ preventScroll: true });
    }
}
