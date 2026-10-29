import { Controller } from '@hotwired/stimulus';

/**
 * Shows the pricing fields of a bundle only while the product is one, and the discount only
 * while the price is the sum of the components. The fields are hidden, never disabled: the
 * server removes what is not sent for a bundle and a hidden field still posts its value.
 */
export default class extends Controller {
    static targets = ['trigger', 'mode', 'field', 'discount'];

    connect() {
        this.update();
    }

    update() {
        const bundle = this.hasTriggerTarget && this.triggerTarget.checked;
        const computed = this.hasModeTarget && this.modeTarget.value === 'computed';

        this.fieldTargets.forEach((el) => el.classList.toggle('d-none', !bundle));
        this.discountTargets.forEach((el) => el.classList.toggle('d-none', !bundle || !computed));
    }
}
