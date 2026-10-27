import { Controller } from '@hotwired/stimulus';

/**
 * Shows the fields that only make sense for the current audience and countdown of a
 * sale: the customer picker while the sale is reserved for named customers, the group picker
 * while it is reserved for customer groups, the hide-products switch while it is reserved at all,
 * the lead-hours input while the countdown counts a number of hours before the end.
 *
 * Hidden fields stay in the form and keep being posted — the server decides what a
 * value means, this only spares the shop owner the settings that do not apply.
 */
export default class extends Controller {
    static targets = ['audience', 'reservedField', 'groupsField', 'anyReservedField', 'countdown', 'leadHoursField'];

    static values = {
        reserved: String,
        groups: String,
        leadHours: String,
    };

    connect() {
        this.update();
    }

    update() {
        const audience = this.audienceTargets.find((input) => input.checked);
        const reserved = !!audience && audience.value === this.reservedValue;
        this.reservedFieldTargets.forEach((el) => el.classList.toggle('d-none', !reserved));

        const forGroups = !!audience && audience.value === this.groupsValue;
        this.groupsFieldTargets.forEach((el) => el.classList.toggle('d-none', !forGroups));
        this.anyReservedFieldTargets.forEach((el) => el.classList.toggle('d-none', !reserved && !forGroups));

        const leadHours = this.hasCountdownTarget && this.countdownTarget.value === this.leadHoursValue;
        this.leadHoursFieldTargets.forEach((el) => el.classList.toggle('d-none', !leadHours));
    }
}
