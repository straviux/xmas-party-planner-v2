/**
 * The element that scrolls guest pages.
 *
 * GuestLayout's .guest-shell is an exactly-viewport-tall inner
 * scroller: the document itself never scrolls on guest pages, because
 * iOS momentum-scrolls the root scroller into a rubber-band bounce
 * that neither overscroll-behavior nor touch handlers can stop.
 * Scroll save/restore and scroll locking must therefore target the
 * shell's scrollTop/overflow, never window.scrollY or the body.
 *
 * Falls back to the document scroller anywhere the shell is absent,
 * so callers get the same shape ({ scrollTop, style }) either way.
 */
export function pageScroller() {
    return document.querySelector('.guest-shell') || document.scrollingElement;
}
