// The landing's stage shelf: a one-open-at-a-time accordion of books (disclosure buttons, so it reads as a
// list of expandable sections to assistive technology). Without script every book stays open.
export function openBook(books, target) {
    books.forEach((book) => {
        const open = book === target;
        book.toggleAttribute('data-open', open);
        book.querySelector('.lp-spine')?.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
}

export function initShelf(root = document) {
    root.querySelectorAll('[data-lp-shelf]:not([data-td-ready])').forEach((shelf) => {
        shelf.dataset.tdReady = '1';
        const books = Array.from(shelf.querySelectorAll('[data-lp-book]'));
        const spines = books.map((book) => book.querySelector('.lp-spine'));
        openBook(books, books.find((book) => book.hasAttribute('data-open')) || books[0]);
        shelf.classList.add('is-ready');

        spines.forEach((spine, index) => {
            spine.addEventListener('click', () => openBook(books, books[index]));
            spine.addEventListener('keydown', (event) => {
                const keys = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 };
                let next = null;
                if (event.key in keys) {
                    next = (index + keys[event.key] + spines.length) % spines.length;
                } else if (event.key === 'Home') {
                    next = 0;
                } else if (event.key === 'End') {
                    next = spines.length - 1;
                }
                if (next !== null) {
                    event.preventDefault();
                    spines[next].focus();
                }
            });
        });
    });
}
