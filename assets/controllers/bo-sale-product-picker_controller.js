import { Controller } from '@hotwired/stimulus';

/**
 * Loads the products of the categories selected for a sale and renders them as
 * checkboxes, then drives the toolbar above the list: a client-side filter on
 * reference and title, a live count of what is ticked, and select / deselect
 * over the rows the filter currently shows.
 *
 * Loading a category never rebuilds the list: a row is keyed by its product id
 * and an existing one is left exactly as the operator left it, so a manual tick
 * or untick survives every further load. A row hidden by the filter keeps its
 * checkbox in the form, so filtering can never drop a selection either — which
 * is also why the count is the total, not what the filter shows.
 */
export default class extends Controller {
    static targets = ['categories', 'productZone', 'emptyHint', 'filter', 'count', 'noMatch'];
    static values = {
        productsUrl: String,
        attributesLabel: String,
        countTemplate: String,
        shownTemplate: String,
    };

    connect() {
        this.refresh();
    }

    async loadProducts() {
        const categoryIds = Array.from(this.categoriesTarget.selectedOptions).map((option) => option.value);
        if (categoryIds.length === 0) {
            return;
        }

        const url = `${this.productsUrlValue}?categories=${encodeURIComponent(categoryIds.join(','))}`;
        let products = [];
        try {
            const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (response.ok) {
                const data = await response.json();
                products = Array.isArray(data.products) ? data.products : [];
            }
        } catch {
            return;
        }

        if (this.hasEmptyHintTarget) {
            this.emptyHintTarget.remove();
        }

        for (const product of products) {
            if (this.productZoneTarget.querySelector(`[data-sale-product-row][data-product-id="${product.id}"]`)) {
                continue;
            }
            this.productZoneTarget.appendChild(this.buildRow(product));
        }

        // A freshly appended row has to obey the filter already in the box.
        this.filter();
    }

    /** Hides the rows whose reference and title do not match the search box. */
    filter() {
        const term = this.term;
        this.rows.forEach((row) => {
            const matches = term === '' || (row.dataset.search || '').includes(term);
            row.classList.toggle('d-none', !matches);
        });

        this.refresh();
    }

    selectAllVisible() {
        this.setVisible(true);
    }

    deselectAllVisible() {
        this.setVisible(false);
    }

    /** The search box lives inside the sale form: Enter must not save the sale. */
    blockEnter(event) {
        event.preventDefault();
    }

    refresh() {
        if (!this.hasProductZoneTarget) {
            return;
        }

        const rows = this.rows;
        const selected = rows.filter((row) => this.checkboxOf(row)?.checked).length;
        const shown = rows.filter((row) => !row.classList.contains('d-none')).length;
        const filtering = this.term !== '';

        if (this.hasCountTarget) {
            const parts = [this.countTemplateValue.replace('%count%', String(selected))];
            if (filtering) {
                parts.push(this.shownTemplateValue.replace('%count%', String(shown)));
            }
            this.countTarget.textContent = parts.join(' · ');
            this.countTarget.dataset.selectedCount = String(selected);
            this.countTarget.dataset.shownCount = String(shown);
        }

        if (this.hasNoMatchTarget) {
            this.noMatchTarget.classList.toggle('d-none', !filtering || shown > 0);
        }
    }

    setVisible(checked) {
        this.rows
            .filter((row) => !row.classList.contains('d-none'))
            .forEach((row) => {
                const checkbox = this.checkboxOf(row);
                if (checkbox) {
                    checkbox.checked = checked;
                }
            });

        this.refresh();
    }

    get term() {
        return this.hasFilterTarget ? (this.filterTarget.value || '').trim().toLocaleLowerCase() : '';
    }

    get rows() {
        // The row marker, not the product id: the attributes button of a row carries
        // that id too.
        return Array.from(this.productZoneTarget.querySelectorAll('[data-sale-product-row]'));
    }

    checkboxOf(row) {
        return row.querySelector('input[type="checkbox"]');
    }

    buildRow(product) {
        const wrapper = document.createElement('div');
        wrapper.className = 'd-flex align-items-center gap-2 mb-1';
        wrapper.dataset.saleProductRow = '1';
        wrapper.dataset.productId = product.id;
        wrapper.dataset.search = `${product.ref} ${product.title}`.toLocaleLowerCase();

        const check = document.createElement('div');
        check.className = 'form-check flex-grow-1 mb-0';

        const input = document.createElement('input');
        input.className = 'form-check-input';
        input.type = 'checkbox';
        input.name = 'products[]';
        input.value = product.id;
        input.id = `sale-product-${product.id}`;
        input.checked = true;
        input.dataset.testid = `sale-product-check-${product.id}`;

        const label = document.createElement('label');
        label.className = 'form-check-label';
        label.htmlFor = input.id;
        const ref = document.createElement('code');
        ref.textContent = product.ref;
        label.append(ref, ` ${product.title}`);

        check.append(input, label);
        wrapper.append(check, this.buildAttributesButton(product));
        return wrapper;
    }

    buildAttributesButton(product) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-sm btn-outline-secondary';
        button.dataset.bsToggle = 'modal';
        button.dataset.bsTarget = '#sale-product-attributes-modal';
        button.dataset.productId = product.id;
        button.dataset.productLabel = product.title;
        button.title = this.attributesLabelValue;

        const icon = document.createElement('i');
        icon.className = 'bi bi-sliders';
        icon.setAttribute('aria-hidden', 'true');

        const badge = document.createElement('span');
        badge.className = 'badge bg-secondary d-none ms-1';
        badge.dataset.boSaleProductAttributesTarget = 'badge';
        badge.textContent = '0';

        button.append(icon, badge);
        return button;
    }
}
