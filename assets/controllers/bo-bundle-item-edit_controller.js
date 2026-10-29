import { Controller } from '@hotwired/stimulus';

/**
 * Fills the modal that edits a component of a bundle from the row action that opened it:
 * the quantities and the required flag travel in the data attributes of the button.
 */
export default class extends Controller {
    static targets = ['itemId', 'label', 'combination', 'quantity', 'min', 'max', 'required'];

    connect() {
        this.boundOnShow = this.onShow.bind(this);
        this.element.addEventListener('show.bs.modal', this.boundOnShow);
    }

    disconnect() {
        this.element.removeEventListener('show.bs.modal', this.boundOnShow);
    }

    onShow(event) {
        const trigger = event.relatedTarget;
        if (!trigger) {
            return;
        }

        this.itemIdTarget.value = trigger.getAttribute('data-item-id') ?? '';
        this.labelTarget.textContent = trigger.getAttribute('data-label') ?? '';
        this.combinationTarget.value = trigger.getAttribute('data-combination') || '-';
        this.quantityTarget.value = trigger.getAttribute('data-quantity') ?? '';
        this.minTarget.value = trigger.getAttribute('data-min-quantity') ?? '';
        this.maxTarget.value = trigger.getAttribute('data-max-quantity') ?? '';
        this.requiredTarget.checked = trigger.getAttribute('data-required') === '1';
    }
}
