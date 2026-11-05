import { Controller } from '@hotwired/stimulus';

/**
 * Shows the preorder limit only while the sale mode at zero stock is the
 * limited one. The field stays in the form either way: hidden, it is still sent.
 */
export default class extends Controller {
    static targets = ['policy', 'limit'];

    // The value of the "limited" mode, given by the page: the core owns the number.
    static values = { limited: String };

    connect() {
        this.toggle();

        // A reset puts the select back to its stored value after the event: the limit follows on the next tick.
        this.form = this.element.closest('form');
        this.onReset = () => setTimeout(() => this.toggle(), 0);
        this.form?.addEventListener('reset', this.onReset);
    }

    disconnect() {
        this.form?.removeEventListener('reset', this.onReset);
        this.form = null;
    }

    toggle() {
        const limited = this.policyTarget.value === this.limitedValue;

        this.limitTargets.forEach((element) => element.classList.toggle('d-none', !limited));
    }
}
