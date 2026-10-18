/** The completion has already been booked; this timer only returns to the provider. */
export function initCompletionRedirect(root, options = {}) {
    const link = root.querySelector('[data-completion-link]');
    const seconds = Number(root.getAttribute('data-completion-delay'));
    if (!link || !Number.isInteger(seconds) || seconds < 0 || seconds > 2147483 || root.dataset.redirectStarted) return;
    const target = new URL(link.getAttribute('href'), options.baseUrl || document.baseURI);
    if (!['http:', 'https:'].includes(target.protocol)) return;
    root.dataset.redirectStarted = '1';
    const now = options.now || Date.now;
    const later = options.later || window.setTimeout.bind(window);
    const navigate = options.navigate || ((url) => window.location.assign(url));
    const deadline = now() + seconds * 1000;
    let returned = false;
    const tick = () => {
        const remaining = Math.max(0, Math.ceil((deadline - now()) / 1000));
        root.querySelectorAll('[data-completion-seconds]').forEach((node) => { node.textContent = String(remaining); });
        if (remaining === 0) {
            if (!returned) { returned = true; navigate(target.href); }
        } else { later(tick, Math.min(1000, Math.max(1, deadline - now()))); }
    };
    // Following the manual link must not also trigger the automatic return.
    link.addEventListener('click', () => { returned = true; });
    tick();
}
