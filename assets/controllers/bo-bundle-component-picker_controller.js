import { Controller } from '@hotwired/stimulus';

/**
 * Picks the component of a bundle in three steps: a category, then one of its products, then
 * one combination of that product. Both lists come from the server, which already leaves out
 * the bundles and the bundle being edited; the URL templates carry 0 where the id goes.
 */
export default class extends Controller {
    static targets = ['category', 'product', 'combination', 'productGroup', 'combinationGroup', 'empty', 'error'];

    static values = {
        productsUrl: String,
        combinationsUrl: String,
    };

    categoryChanged() {
        this.reset(this.combinationTarget, this.combinationGroupTarget);
        this.reset(this.productTarget, this.productGroupTarget);
        this.hideMessages();

        const categoryId = this.categoryTarget.value;
        if (!categoryId) {
            return;
        }

        this.load(this.fill(this.productsUrlValue, categoryId), (item) => item.title + (item.ref ? ` (${item.ref})` : ''))
            .then((options) => this.show(this.productTarget, this.productGroupTarget, options));
    }

    productChanged() {
        this.reset(this.combinationTarget, this.combinationGroupTarget);
        this.hideMessages();

        const productId = this.productTarget.value;
        if (!productId) {
            return;
        }

        this.load(this.fill(this.combinationsUrlValue, productId), (item) => item.title)
            .then((options) => this.show(this.combinationTarget, this.combinationGroupTarget, options));
    }

    // Puts the id where the template has its 0: the last "/0" of the path, before the query.
    fill(template, id) {
        const [path, query] = template.split('?');
        const index = path.lastIndexOf('/0');

        return `${path.slice(0, index)}/${id}${path.slice(index + 2)}${query ? `?${query}` : ''}`;
    }

    load(url, label) {
        return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                return response.json();
            })
            .then((items) => (Array.isArray(items) ? items.map((item) => ({ value: String(item.id), text: label(item) })) : []))
            // null, not an empty list: a failed request is not an empty category.
            .catch(() => null);
    }

    hideMessages() {
        this.emptyTarget.classList.add('d-none');
        this.errorTarget.classList.add('d-none');
    }

    show(select, group, options) {
        if (options === null) {
            this.errorTarget.classList.remove('d-none');

            return;
        }

        if (options.length === 0) {
            this.emptyTarget.classList.remove('d-none');

            return;
        }

        options.forEach((option) => {
            const element = document.createElement('option');
            element.value = option.value;
            element.textContent = option.text;
            select.appendChild(element);
        });
        group.classList.remove('d-none');

        // A product with one combination only: nothing left to choose.
        if (select === this.combinationTarget && options.length === 1) {
            select.value = options[0].value;
        }
    }

    reset(select, group) {
        while (select.options.length > 1) {
            select.remove(1);
        }
        select.value = '';
        group.classList.add('d-none');
    }
}
