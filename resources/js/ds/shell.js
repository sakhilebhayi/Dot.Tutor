// The signed-in shell's rail: a long rail (the operations one) scrolls on its own, so the current destination is brought into view on load
// (R-47 fix D-2404). Opening, closing, focus and scroll lock on a phone belong to the shared header (js/dot-nav.js, adopt mode).
export function initShell(root = document) {
    const nav = root.querySelector('.td-rail__nav');
    const current = nav && nav.querySelector('[aria-current="page"]');
    if (!current || nav.scrollHeight <= nav.clientHeight) {
        return;
    }
    const navBox = nav.getBoundingClientRect();
    const itemBox = current.getBoundingClientRect();
    if (itemBox.bottom > navBox.bottom || itemBox.top < navBox.top) {
        nav.scrollTop += itemBox.top - navBox.top - (navBox.height - itemBox.height) / 2;
    }
}
