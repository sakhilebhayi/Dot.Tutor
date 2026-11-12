// Pure helpers for the tutor workspace's price preview (tested in tests/js/teach.test.mjs).

/** "250", "250.5", "250,50" -> 25050 minor units; null when it is not a price. */
export function toMinor(raw) {
    const text = String(raw ?? '').trim().replace(',', '.');
    if (!/^\d{1,7}(\.\d{1,2})?$/.test(text)) {
        return null;
    }
    const [whole, frac = ''] = text.split('.');
    return Number(whole) * 100 + Number((frac + '00').slice(0, 2));
}

/** 125050 -> "R1 250.50" in the kit's money style (narrow no-break space between thousands). */
export function formatMinor(minor, currency = 'ZAR') {
    const symbols = { ZAR: 'R', USD: '$', EUR: '€', GBP: '£' };
    const symbol = symbols[currency] ?? `${currency} `;
    const negative = minor < 0;
    const abs = Math.abs(minor);
    const whole = String(Math.floor(abs / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    const cents = String(abs % 100).padStart(2, '0'); // taste-skill-ignore: cents of a price
    return `${negative ? '−' : ''}${symbol}${whole}.${cents}`;
}
