/**
 * A Turnstile token can be used only once. A double click on the submit button would send it twice, and the second
 * request would fail although the first one went through. Further submits of a form with the widget are therefore
 * ignored for a few seconds.
 *
 * The handler sits on the document, so handlers on the form itself have already had the chance to cancel the submit.
 */
jQuery(document).on('submit', 'form', function (e) {
    if (e.isDefaultPrevented()) return;
    if (!this.querySelector('.cf-turnstile')) return;

    const now = Date.now();
    if (now - (jQuery(this).data('turnstileSent') || 0) < 10000) {
        e.preventDefault();
        return;
    }
    jQuery(this).data('turnstileSent', now);
});
