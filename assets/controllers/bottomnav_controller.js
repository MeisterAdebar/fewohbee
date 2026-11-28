import { Controller } from '@hotwired/stimulus';

// Share of the first entry beyond the right edge that stays in view, as a hint to swipe
const PEEK = 0.5;
// A press this long on an entry picks it up to be moved (ms), shorter than Android's long press
// (400 ms), which would open the link's menu and end the touch; moving farther first scrolls the row
const HOLD = 350;
const SLOP = 12;
// Near an edge while moving an entry, the row scrolls along (px from the edge, px per frame)
const EDGE = 40;
const EDGE_SPEED = 6;
// The order the user gave the entries, as their addresses; kept in this browser only
const STORAGE_KEY = 'feb-bottomnav-order';

/*
 * The bottom bar on phones and tablets held upright (templates/layout/_app_nav.html.twig): one row
 * of every page that scrolls sideways.
 *
 * - When the row does not fit, every entry gets a few pixels more (--bottomnav-extra), so that at
 *   the start the right edge cuts the next entry in half instead of falling between two entries:
 *   a cut entry shows there is more.
 * - On loading the current page is scrolled into view, with an entry cut at the right edge as well.
 * - While there is more beyond an edge, the row carries app-bottomnav-beyond-start / -end, and
 *   shell.css fades that edge.
 * - A long press picks an entry up; moving the finger then moves it along the row, which scrolls
 *   along at its edges. The new order is kept in this browser (localStorage) and applies on every
 *   page; pages added later go to the end.
 */
export default class extends Controller {
    connect() {
        this.restoreOrder();
        this.width = 0;
        this.fit();
        this.showActive();
        this.update();
        this.resizeObserver = new ResizeObserver(() => {
            this.fit();
            this.update();
        });
        this.resizeObserver.observe(this.element);

        this.abort = new AbortController();
        const signal = this.abort.signal;
        this.element.addEventListener('touchstart', (event) => this.onTouchStart(event), { passive: true, signal });
        // Not passive: once an entry is held, the finger moves it instead of scrolling the row
        this.element.addEventListener('touchmove', (event) => this.onTouchMove(event), { passive: false, signal });
        this.element.addEventListener('touchend', () => this.onTouchEnd(), { signal });
        this.element.addEventListener('touchcancel', () => this.onTouchEnd(), { signal });
        // Browsers lift a long-pressed link to drag it into another app (Chrome on Android, Safari
        // on iOS); that would take the touch from moving the entry
        this.element.addEventListener('dragstart', (event) => event.preventDefault(), { signal });
        // The long press would open the link's menu. Android announces it this way, at times
        // before the timer: the entry is picked up then. A link just moved is not followed.
        this.element.addEventListener('contextmenu', (event) => {
            event.preventDefault();
            if (this.touch && !this.drag) {
                clearTimeout(this.holdTimer);
                this.press = this.touch;
                this.startDrag();
            }
        }, { signal });
        this.element.addEventListener('click', (event) => {
            if (this.justMoved) {
                event.preventDefault();
                this.justMoved = false;
            }
        }, { capture: true, signal });
    }

    disconnect() {
        this.resizeObserver?.disconnect();
        this.abort?.abort();
        this.endDrag();
    }

    /**
     * Scrolls the current page into view, unless it is in view at the start already: as near the
     * middle as a position allows that cuts an entry at the right edge (at PEEK). scrollLeft, as
     * scrollIntoView would move the page too.
     */
    showActive() {
        const active = this.element.querySelector('.app-bottomnav-item.active');
        const row = this.element;
        if (!active || row.scrollWidth <= row.clientWidth) {
            return;
        }
        // Fully in view at the start: the row stays there, with the first pages
        const cut = row.clientWidth - PEEK * row.lastElementChild.offsetWidth;
        if (active.offsetLeft + active.offsetWidth <= cut) {
            return;
        }
        const max = row.scrollWidth - row.clientWidth;
        const centred = active.offsetLeft - (row.clientWidth - active.offsetWidth) / 2;
        let best = Math.min(Math.max(centred, 0), max);
        let bestDistance = Infinity;
        for (const item of this.items()) {
            const left = item.offsetLeft + PEEK * item.offsetWidth - row.clientWidth;
            const activeInView = active.offsetLeft >= left && active.offsetLeft + active.offsetWidth <= left + row.clientWidth;
            if (left >= 0 && left <= max && activeInView && Math.abs(left - centred) < bestDistance) {
                best = left;
                bestDistance = Math.abs(left - centred);
            }
        }
        row.scrollLeft = best;
    }

    /** Spreads the room left at the right edge over the entries, so the edge cuts one in half. */
    fit() {
        const available = this.element.clientWidth;
        if (available === this.width) {
            return;
        }
        this.width = available;
        this.element.style.setProperty('--bottomnav-extra', '0px');
        const style = getComputedStyle(this.element);
        const room = available - parseFloat(style.paddingLeft);
        const widths = this.items().map((item) => item.getBoundingClientRect().width);
        if (widths.reduce((sum, width) => sum + width, 0) <= room) {
            return;
        }
        // The most entries that still fit whole, with the next one cut at PEEK
        let used = 0;
        let extra = 0;
        for (let count = 0; count < widths.length - 1; count++) {
            used += widths[count];
            const rest = room - used - PEEK * widths[count + 1];
            if (rest < 0) {
                break;
            }
            extra = rest / (count + 1 + PEEK);
        }
        this.element.style.setProperty('--bottomnav-extra', `${extra}px`);
    }

    update() {
        const { scrollLeft, scrollWidth, clientWidth } = this.element;
        this.element.classList.toggle('app-bottomnav-beyond-start', scrollLeft > 1);
        this.element.classList.toggle('app-bottomnav-beyond-end', scrollLeft + clientWidth < scrollWidth - 1);
    }

    items() {
        return [...this.element.querySelectorAll('.app-bottomnav-item')];
    }

    // ------------------------------------------------------------------ order

    restoreOrder() {
        let order = [];
        try {
            order = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '[]');
        } catch {
            return;
        }
        if (!Array.isArray(order) || order.length === 0) {
            return;
        }
        const rank = (item) => {
            const index = order.indexOf(item.getAttribute('href'));
            return index === -1 ? order.length : index;
        };
        // A stable sort: pages the saved order does not know keep their place among themselves
        this.items()
            .map((item, index) => ({ item, index }))
            .sort((a, b) => rank(a.item) - rank(b.item) || a.index - b.index)
            .forEach(({ item }) => this.element.appendChild(item));
    }

    saveOrder() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(this.items().map((item) => item.getAttribute('href'))));
        } catch {
            // Private mode or storage blocked: the order holds until the page is left
        }
    }

    // ------------------------------------------------------------------ moving an entry

    onTouchStart(event) {
        const item = event.target.closest('.app-bottomnav-item');
        if (!item || event.touches.length !== 1) {
            return;
        }
        const touch = event.touches[0];
        // The touch, kept to its end: Android's long press may come after the timer gave up
        this.touch = { item, x: touch.clientX, y: touch.clientY };
        this.press = this.touch;
        this.holdTimer = setTimeout(() => this.startDrag(), HOLD);
    }

    onTouchMove(event) {
        const touch = event.touches[0];
        if (this.touch) {
            this.touch = { ...this.touch, x: touch.clientX };
        }
        if (this.drag) {
            event.preventDefault();
            this.drag.x = touch.clientX;
            this.moveDragged();
            return;
        }
        // Moving before the press is long enough is scrolling the row (or the page): no pick-up
        if (this.press && Math.hypot(touch.clientX - this.press.x, touch.clientY - this.press.y) > SLOP) {
            this.cancelPress();
        }
    }

    onTouchEnd() {
        this.cancelPress();
        this.touch = null;
        if (this.drag) {
            this.endDrag();
            this.saveOrder();
            this.width = 0;
            this.fit();
            this.update();
        }
    }

    cancelPress() {
        clearTimeout(this.holdTimer);
        this.press = null;
    }

    startDrag() {
        const { item, x } = this.press;
        this.press = null;
        this.drag = {
            item,
            x,
            startX: x,
            startLeft: item.offsetLeft,
            startScroll: this.element.scrollLeft,
        };
        // The finger lifted after moving would follow the link otherwise
        this.justMoved = true;
        // Safari may have selected the icon (a glyph) during the long press
        window.getSelection()?.removeAllRanges();
        this.element.classList.add('app-bottomnav-sorting');
        item.classList.add('app-bottomnav-dragging');
        navigator.vibrate?.(10);
        this.edgeFrame = requestAnimationFrame(() => this.scrollAtEdge());
    }

    endDrag() {
        cancelAnimationFrame(this.edgeFrame);
        if (!this.drag) {
            return;
        }
        const { item } = this.drag;
        // The entry settles into its place from where the finger let go
        const offset = item.style.transform;
        item.classList.remove('app-bottomnav-dragging');
        item.style.transform = '';
        if (offset) {
            item.animate([{ transform: offset }, { transform: 'none' }], { duration: 150, easing: 'ease-out' });
        }
        this.element.classList.remove('app-bottomnav-sorting');
        window.getSelection()?.removeAllRanges();
        this.drag = null;
        // A tap right after a move opens the page again
        setTimeout(() => { this.justMoved = false; }, 400);
    }

    /** Places the held entry under the finger and moves it past a neighbour whose middle it crossed. */
    moveDragged() {
        const { item, x, startX, startLeft, startScroll } = this.drag;
        // Within the row: pushed past its end, the entry would lengthen the row and the edge
        // would keep scrolling into nothing
        const first = this.element.firstElementChild;
        const last = this.element.lastElementChild;
        const left = Math.min(
            Math.max(startLeft + (x - startX) + (this.element.scrollLeft - startScroll), first.offsetLeft),
            last.offsetLeft + last.offsetWidth - item.offsetWidth,
        );
        const middle = left + item.offsetWidth / 2;
        const previous = item.previousElementSibling;
        const next = item.nextElementSibling;
        // The neighbour is moved, not the held entry: Safari sends no more touch events once the
        // touched element is taken out of the page, even for a moment
        if (next && middle > next.offsetLeft + next.offsetWidth / 2) {
            this.slide(next, () => item.before(next));
        } else if (previous && middle < previous.offsetLeft + previous.offsetWidth / 2) {
            this.slide(previous, () => item.after(previous));
        }
        item.style.transform = `translateX(${left - item.offsetLeft}px)`;
    }

    /** Moves the held entry past a neighbour, which slides to its new place instead of jumping. */
    slide(neighbour, move) {
        const before = neighbour.offsetLeft;
        move();
        neighbour.animate(
            [{ transform: `translateX(${before - neighbour.offsetLeft}px)` }, { transform: 'none' }],
            { duration: 150, easing: 'ease-out' },
        );
    }

    scrollAtEdge() {
        if (!this.drag) {
            return;
        }
        // Only towards an edge the finger moved to: an entry picked up at the edge stays put
        const bounds = this.element.getBoundingClientRect();
        const { x, startX } = this.drag;
        const step = x < bounds.left + EDGE && x < startX - SLOP ? -EDGE_SPEED
            : x > bounds.right - EDGE && x > startX + SLOP ? EDGE_SPEED : 0;
        if (step !== 0) {
            const before = this.element.scrollLeft;
            this.element.scrollLeft += step;
            if (this.element.scrollLeft !== before) {
                this.moveDragged();
                this.update();
            }
        }
        this.edgeFrame = requestAnimationFrame(() => this.scrollAtEdge());
    }
}
