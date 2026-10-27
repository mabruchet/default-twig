import { Controller } from '@hotwired/stimulus';

/**
 * The edit screen of a catalog price rule: shows the fields that only make sense for
 * the chosen effect and audience, loads the products of picked categories into the
 * named-products list, and asks the server for the price preview of the form as it
 * stands.
 *
 * Hidden fields stay in the form and keep being posted; the server decides what a
 * value means. The preview posts the whole form to a fragment endpoint that writes
 * nothing, so what the merchant sees is what saving would give.
 */
export default class extends Controller {
    static targets = [
        'form',
        'effectType',
        'percentageField',
        'currencyField',
        'audience',
        'reservedField',
        'groupsField',
        'productCategories',
        'productZone',
        'productEmptyHint',
        'productFilter',
        'productCount',
        'previewZone',
    ];

    static values = {
        productsUrl: String,
        previewUrl: String,
        percentageType: String,
        reservedMode: String,
        groupsMode: String,
        countTemplate: String,
        previewFailed: String,
    };

    connect() {
        this.update();
        this.refreshProducts();
    }

    update() {
        if (this.hasEffectTypeTarget) {
            const percentage = this.effectTypeTarget.value === this.percentageTypeValue;
            this.percentageFieldTargets.forEach((el) => el.classList.toggle('d-none', !percentage));
            this.currencyFieldTargets.forEach((el) => el.classList.toggle('d-none', percentage));
        }

        const audience = this.audienceTargets.find((input) => input.checked);
        const reserved = !!audience && audience.value === this.reservedModeValue;
        this.reservedFieldTargets.forEach((el) => el.classList.toggle('d-none', !reserved));

        const forGroups = !!audience && audience.value === this.groupsModeValue;
        this.groupsFieldTargets.forEach((el) => el.classList.toggle('d-none', !forGroups));
    }

    async loadProducts() {
        if (!this.hasProductCategoriesTarget) {
            return;
        }

        const categoryIds = Array.from(this.productCategoriesTarget.selectedOptions).map((option) => option.value);
        if (categoryIds.length === 0) {
            return;
        }

        let products = [];
        try {
            const response = await fetch(`${this.productsUrlValue}?categories=${encodeURIComponent(categoryIds.join(','))}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (response.ok) {
                const data = await response.json();
                products = Array.isArray(data.products) ? data.products : [];
            }
        } catch {
            return;
        }

        if (this.hasProductEmptyHintTarget) {
            this.productEmptyHintTarget.remove();
        }

        for (const product of products) {
            if (this.productZoneTarget.querySelector(`[data-rule-product-row][data-product-id="${product.id}"]`)) {
                continue;
            }
            this.productZoneTarget.appendChild(this.buildRow(product));
        }

        this.filterProducts();
    }

    filterProducts() {
        const term = this.term;
        this.rows.forEach((row) => {
            row.classList.toggle('d-none', term !== '' && !(row.dataset.search || '').includes(term));
        });
        this.refreshProducts();
    }

    refreshProducts() {
        if (!this.hasProductZoneTarget || !this.hasProductCountTarget) {
            return;
        }

        const selected = this.rows.filter((row) => row.querySelector('input[type="checkbox"]')?.checked).length;
        this.productCountTarget.textContent = this.countTemplateValue.replace('%count%', String(selected));
        this.productCountTarget.dataset.selectedCount = String(selected);
    }

    /** The search box lives inside the form: Enter must not save the rule. */
    blockEnter(event) {
        event.preventDefault();
    }

    async preview() {
        if (!this.hasFormTarget || !this.hasPreviewZoneTarget) {
            return;
        }

        this.previewZoneTarget.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(this.previewUrlValue, {
                method: 'POST',
                body: new FormData(this.formTarget),
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const html = await response.text();
            this.previewZoneTarget.innerHTML = html !== '' ? html : `<div class="alert alert-danger mb-0">${this.previewFailedValue}</div>`;
        } catch {
            this.previewZoneTarget.innerHTML = `<div class="alert alert-danger mb-0">${this.previewFailedValue}</div>`;
        } finally {
            this.previewZoneTarget.removeAttribute('aria-busy');
        }
    }

    get term() {
        return this.hasProductFilterTarget ? (this.productFilterTarget.value || '').trim().toLocaleLowerCase() : '';
    }

    get rows() {
        return Array.from(this.productZoneTarget.querySelectorAll('[data-rule-product-row]'));
    }

    buildRow(product) {
        const wrapper = document.createElement('div');
        wrapper.className = 'form-check mb-1';
        wrapper.dataset.ruleProductRow = '1';
        wrapper.dataset.productId = product.id;
        wrapper.dataset.search = `${product.ref} ${product.title}`.toLocaleLowerCase();

        const input = document.createElement('input');
        input.className = 'form-check-input';
        input.type = 'checkbox';
        input.name = 'products[]';
        input.value = product.id;
        input.id = `rule-product-${product.id}`;
        input.checked = true;
        input.dataset.testid = `catalog-price-rule-product-check-${product.id}`;

        const label = document.createElement('label');
        label.className = 'form-check-label';
        label.htmlFor = input.id;
        const ref = document.createElement('code');
        ref.textContent = product.ref;
        label.append(ref, ` ${product.title}`);

        wrapper.append(input, label);
        return wrapper;
    }
}
