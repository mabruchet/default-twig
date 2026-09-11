import { Controller } from '@hotwired/stimulus';

/**
 * "Buy X, get Y" coupon inputs: shows only the pickers the chosen options call
 * for, and writes the rule in plain words under the form as it is filled in.
 *
 * A hidden picker is also disabled, so it submits nothing: the two triggering
 * product pickers share one field name, and only the visible one is posted.
 */
export default class extends Controller {
    static targets = [
        'scopeRadio',
        'scopeProduct',
        'scopeProductInput',
        'scopeCategory',
        'scopeSelection',
        'triggerQuantity',
        'targetModeRadio',
        'targetProductWrap',
        'targetProductInput',
        'offeredQuantity',
        'discountTypeRadio',
        'discountValueWrap',
        'discountValue',
        'discountUnit',
        'preview',
    ];

    static values = {
        labels: Object,
        currency: String,
    };

    connect() {
        this.onScopeChange();
        this.onTargetModeChange();
        this.onDiscountTypeChange();
    }

    onScopeChange() {
        const scope = this.checkedValue(this.scopeRadioTargets);

        this.toggleBlock(this.hasScopeProductTarget ? this.scopeProductTarget : null, scope === 'product');
        this.toggleBlock(this.hasScopeCategoryTarget ? this.scopeCategoryTarget : null, scope === 'category');
        this.toggleBlock(this.hasScopeSelectionTarget ? this.scopeSelectionTarget : null, scope === 'selection');

        this.refreshPreview();
    }

    onTargetModeChange() {
        const mode = this.checkedValue(this.targetModeRadioTargets);

        this.toggleBlock(this.hasTargetProductWrapTarget ? this.targetProductWrapTarget : null, mode === 'product');

        this.refreshPreview();
    }

    onDiscountTypeChange() {
        const type = this.checkedValue(this.discountTypeRadioTargets);

        this.toggleBlock(this.hasDiscountValueWrapTarget ? this.discountValueWrapTarget : null, type !== 'free');

        if (this.hasDiscountUnitTarget) {
            this.discountUnitTarget.textContent = type === 'amount' ? this.currencyValue : '%';
        }

        // A percentage stops at 100; an amount has no ceiling the browser can know.
        if (this.hasDiscountValueTarget) {
            if (type === 'percentage') {
                this.discountValueTarget.max = '100';
            } else {
                this.discountValueTarget.removeAttribute('max');
            }
        }

        this.refreshPreview();
    }

    refreshPreview() {
        if (!this.hasPreviewTarget) {
            return;
        }

        const labels = this.labelsValue || {};
        const scope = this.checkedValue(this.scopeRadioTargets);
        const target = this.checkedValue(this.targetModeRadioTargets);
        const discountType = this.checkedValue(this.discountTypeRadioTargets);
        const triggerQuantity = this.hasTriggerQuantityTarget ? this.triggerQuantityTarget.value : '';
        const offeredQuantity = this.hasOfferedQuantityTarget ? this.offeredQuantityTarget.value : '';
        const discountValue = this.hasDiscountValueTarget ? this.discountValueTarget.value : '';

        const scopeLabel = labels[`scope_${scope}`];
        const targetLabel = labels[`target_${target}`];
        let discountLabel = labels[`discount_${discountType}`];

        if (!scopeLabel || !targetLabel || !discountLabel || !triggerQuantity || !offeredQuantity) {
            this.previewTarget.textContent = labels.empty || '';
            return;
        }

        if (discountType !== 'free') {
            if (!discountValue) {
                this.previewTarget.textContent = labels.empty || '';
                return;
            }
            discountLabel = discountLabel
                .replace('%value%', discountValue)
                .replace('%currency%', this.currencyValue || '');
        }

        // The whole cart forms a single lot, so "for every N items" would be a lie:
        // the quantity is a floor and the offer lands once.
        const pattern = ('cart' === scope && labels.pattern_cart) ? labels.pattern_cart : labels.pattern;

        this.previewTarget.textContent = (pattern || '')
            .replace('%quantity%', triggerQuantity)
            .replace('%scope%', scopeLabel)
            .replace('%offered_quantity%', offeredQuantity)
            .replace('%target%', targetLabel)
            .replace('%discount%', discountLabel);
    }

    /**
     * Hides a block and disables the controls it holds, so nothing the merchant
     * cannot see reaches the server.
     */
    toggleBlock(element, visible) {
        if (!element) {
            return;
        }

        element.hidden = !visible;
        element.querySelectorAll('input, select, textarea').forEach((field) => {
            field.disabled = !visible;
        });
    }

    checkedValue(radios) {
        return radios.find((radio) => radio.checked)?.value ?? '';
    }
}
