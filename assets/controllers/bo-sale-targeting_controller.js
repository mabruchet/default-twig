import { Controller } from '@hotwired/stimulus';

/**
 * Shows the fields that only make sense for the current audience and countdown of a
 * sale: the customer picker and the hide-products switch while the sale is reserved,
 * the lead-hours input while the countdown counts a number of hours before the end.
 *
 * Hidden fields stay in the form and keep being posted — the server decides what a
 * value means, this only spares the shop owner the settings that do not apply.
 */
export default class extends Controller {
    static targets = ['audience', 'reservedField', 'countdown', 'leadHoursField'];

    static values = {
        reserved: String,
        leadHours: String,
    };

    connect() {
        this.update();
    }

    update() {
        const audience = this.audienceTargets.find((input) => input.checked);
        const reserved = !!audience && audience.value === this.reservedValue;
        this.reservedFieldTargets.forEach((el) => el.classList.toggle('d-none', !reserved));

        const leadHours = this.hasCountdownTarget && this.countdownTarget.value === this.leadHoursValue;
        this.leadHoursFieldTargets.forEach((el) => el.classList.toggle('d-none', !leadHours));
    }
}
