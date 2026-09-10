import { Controller } from '@hotwired/stimulus';

/**
 * Category filter driving a product select.
 *
 * On the multiple select, the picked products are held in a set of their own:
 * the filter only decides which options are listed, never which products are
 * part of the selection, so a merchant can pick in one category, switch to
 * another, and still submit both.
 */
export default class extends Controller {
    static targets = ['category', 'product', 'productMulti'];

    static values = {
        data: Object,
        initialProduct: String,
        initialMulti: Array,
    };

    connect() {
        if (this.hasCategoryTarget && this.hasProductTarget) {
            this.refreshSingle(this.categoryTarget.value);
        }

        this.selectedIds = new Set(
            this.hasProductMultiTarget
                ? Array.from(this.productMultiTarget.selectedOptions).map((option) => option.value)
                : [],
        );
    }

    onCategoryChange(event) {
        this.refreshSingle(event.target.value);
    }

    refreshSingle(categoryId) {
        if (!this.hasProductTarget) {
            return;
        }

        const select = this.productTarget;
        const data = this.dataValue || {};
        const products = data[categoryId] || data[String(categoryId)] || [];

        select.innerHTML = '<option value="">- Select a product -</option>';
        products.forEach((p) => {
            const opt = document.createElement('option');
            opt.value = String(p.id);
            opt.textContent = p.title;
            if (String(this.initialProductValue || '') === String(p.id)) {
                opt.selected = true;
            }
            select.appendChild(opt);
        });
    }

    /**
     * Records what the merchant just picked (or unpicked) among the options
     * currently listed, leaving the products hidden by the filter untouched.
     */
    onSelectionChange() {
        if (!this.hasProductMultiTarget) {
            return;
        }

        const select = this.productMultiTarget;

        Array.from(select.options).forEach((option) => this.selectedIds.delete(option.value));
        Array.from(select.selectedOptions).forEach((option) => this.selectedIds.add(option.value));
    }

    onCategoryChangeMulti(event) {
        if (!this.hasProductMultiTarget) {
            return;
        }

        this.onSelectionChange();

        const categoryId = event.target.value;
        const data = this.dataValue || {};
        const select = this.productMultiTarget;
        const products = categoryId
            ? data[categoryId] || data[String(categoryId)] || []
            : this.allProducts();

        select.innerHTML = '';
        const listed = new Set();
        products.forEach((product) => {
            this.appendOption(select, product);
            listed.add(String(product.id));
        });

        // Products picked under another filter stay in the list, and stay posted:
        // an option removed from the DOM is an option the form never submits.
        this.allProducts().forEach((product) => {
            const id = String(product.id);
            if (!listed.has(id) && this.selectedIds.has(id)) {
                this.appendOption(select, product);
                listed.add(id);
            }
        });
    }

    allProducts() {
        const products = [];
        Object.values(this.dataValue || {}).forEach((categoryProducts) => products.push(...categoryProducts));

        return products;
    }

    appendOption(select, product) {
        const opt = document.createElement('option');
        opt.value = String(product.id);
        opt.textContent = product.title;
        if (this.selectedIds.has(opt.value)) {
            opt.selected = true;
        }
        select.appendChild(opt);
    }
}
