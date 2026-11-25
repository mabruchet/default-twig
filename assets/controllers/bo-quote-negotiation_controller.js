import { Controller } from '@hotwired/stimulus';

/**
 * The screen where the shop prices a quote line by line.
 *
 * A change of a field, an added product or a removed line saves the draft in the background,
 * after a short pause so that several changes make one request, with at most one request on its
 * way and the last change always the one saved. The totals and the discount granted on the lines
 * are the server's, taken from the answer: the screen never works them out. A refusal or a
 * failure is shown on the screen and leaves the fields as they are. The products to add come
 * from a search of their own, which answers twenty rows at most; the catalogue price of an added
 * line is the server's too, the field only starts from the price the search showed.
 */
export default class extends Controller {
    static targets = [
        'form', 'lines', 'lineTemplate', 'empty',
        'search', 'searchStatus', 'results', 'resultTemplate',
        'status', 'error', 'totals', 'goneNotice',
    ];

    static values = {
        searchUrl: String,
        autosaveUrl: String,
        nextIndex: { type: Number, default: 0 },
    };

    connect() {
        this.searchSequence = 0;
        this.searchRequest = null;
        this.searchTimer = null;
        this.results = [];
        this.dirty = false;
        this.changes = 0;
        this.saveTimer = null;
        this.saveRequest = null;
        this.saveAgain = false;
        this.sending = false;
        this.onBeforeUnload = (event) => {
            if (this.dirty) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', this.onBeforeUnload);
        this.refreshEmpty();
    }

    disconnect() {
        window.removeEventListener('beforeunload', this.onBeforeUnload);
        this.searchRequest?.abort();
        clearTimeout(this.searchTimer);
        clearTimeout(this.saveTimer);
        this.saveRequest?.abort();
    }

    // Enter in the search box searches, it does not save the draft.
    keydown(event) {
        if (event.key !== 'Enter' || event.target !== this.searchTarget) {
            return;
        }

        event.preventDefault();
        clearTimeout(this.searchTimer);
        this.search();
    }

    // A form that goes away through its own buttons is not abandoned. A background save on its way is cancelled and
    // none is started again: it would reach the server after the revision was sent and write a draft nobody asked for.
    submitted() {
        this.sending = true;
        this.dirty = false;
        this.saveAgain = false;
        clearTimeout(this.saveTimer);
        this.saveRequest?.abort();
    }

    // Typing only marks the form as changed; the save waits for the field to be left.
    typed(event) {
        if (event?.target === this.searchTarget) {
            return;
        }

        this.dirty = true;
        this.changes++;
    }

    changed(event) {
        if (event?.target === this.searchTarget) {
            return;
        }

        this.markDirty();
    }

    markDirty() {
        this.dirty = true;
        this.changes++;
        this.scheduleSave();
    }

    scheduleSave() {
        clearTimeout(this.saveTimer);
        this.saveTimer = setTimeout(() => this.save(), 600);
    }

    async save() {
        clearTimeout(this.saveTimer);

        if (this.sending) {
            return;
        }

        if (this.saveRequest) {
            this.saveAgain = true;

            return;
        }

        this.saveAgain = false;

        // What is sent is what the screen holds now: the rows too, to give them the ids the
        // server stores them under.
        const rows = [...this.linesTarget.children].filter((row) => row.querySelector('[name$="[pse]"]'));
        const body = new FormData(this.formTarget);
        const sent = this.changes;
        const controller = new AbortController();
        this.saveRequest = controller;
        this.showStatus(this.formTarget.dataset.saving || '');
        this.hideError();
        this.totalsTarget.classList.add('opacity-50');

        try {
            const response = await fetch(this.autosaveUrlValue, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });
            let answer = null;

            try {
                answer = await response.json();
            } catch (error) {
                answer = null;
            }

            if (!response.ok || !answer || !answer.totals) {
                throw new SaveRefused(answer && answer.error ? answer.error : (this.formTarget.dataset.saveFailed || ''));
            }

            this.applySaved(answer, rows);

            if (sent === this.changes) {
                this.dirty = false;
            }

            this.showStatus(this.formTarget.dataset.saved || '');
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }

            this.showStatus('');
            this.showError(error instanceof SaveRefused && error.message !== '' ? error.message : (this.formTarget.dataset.saveFailed || String(error)));
        } finally {
            this.saveRequest = null;
            this.totalsTarget.classList.remove('opacity-50');

            if (this.saveAgain && this.element.isConnected) {
                this.save();
            }
        }
    }

    applySaved(answer, rows) {
        Object.entries(answer.totals).forEach(([key, value]) => {
            const output = this.totalsTarget.querySelector(`[data-total="${key}"]`);
            if (output) {
                output.textContent = value;
            }
        });

        Object.entries(answer.granted || {}).forEach(([key, value]) => {
            const output = this.totalsTarget.querySelector(`[data-granted="${key}"]`);
            if (output) {
                output.textContent = value;
            }
        });

        // A row the screen had no id for, added since the last save, now has its stored line.
        (answer.lines || []).forEach((stored, position) => {
            const field = rows[position]?.querySelector('[name$="[item]"]');
            if (field) {
                field.value = String(stored.item);
            }
        });

        // The lines of a product that is gone were left out of the draft.
        this.linesTarget.querySelectorAll('tr[data-unavailable]').forEach((row) => row.remove());
        if (this.hasGoneNoticeTarget) {
            this.goneNoticeTarget.hidden = true;
        }
        this.refreshEmpty();
    }

    showStatus(message) {
        this.statusTarget.textContent = message;
        this.statusTarget.hidden = message === '';
    }

    showError(message) {
        this.errorTarget.textContent = message;
        this.errorTarget.hidden = message === '';
    }

    hideError() {
        this.showError('');
    }

    searchTyped() {
        clearTimeout(this.searchTimer);
        this.searchTimer = setTimeout(() => this.search(), 300);
    }

    async search() {
        const term = this.searchTarget.value.trim();
        this.searchRequest?.abort();
        const sequence = ++this.searchSequence;

        if (term.length < 2) {
            this.clearResults();

            return;
        }

        const controller = new AbortController();
        this.searchRequest = controller;

        try {
            const url = new URL(this.searchUrlValue, window.location.origin);
            url.searchParams.set('q', term);
            const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const rows = await response.json();

            if (sequence !== this.searchSequence) {
                return;
            }

            this.showResults(Array.isArray(rows) ? rows : []);
        } catch (error) {
            if (error.name === 'AbortError' || sequence !== this.searchSequence) {
                return;
            }

            this.results = [];
            this.resultsTarget.replaceChildren();
            this.resultsTarget.hidden = true;
            this.setSearchStatus(this.formTarget.dataset.searchFailed || String(error));
        }
    }

    showResults(rows) {
        this.results = rows;
        this.resultsTarget.replaceChildren();

        rows.forEach((row, index) => {
            const item = this.resultTemplateTarget.content.firstElementChild.cloneNode(true);
            item.querySelector('[data-slot="title"]').textContent = row.title;
            item.querySelector('[data-slot="detail"]').textContent = this.detailOf(row);
            item.querySelector('[data-slot="price"]').textContent = row.price_label;
            item.querySelector('button').dataset.resultIndex = String(index);
            this.resultsTarget.append(item);
        });

        this.resultsTarget.hidden = rows.length === 0;
        this.setSearchStatus(rows.length === 0 ? (this.formTarget.dataset.noResult || '') : '');
    }

    clearResults() {
        this.results = [];
        this.resultsTarget.replaceChildren();
        this.resultsTarget.hidden = true;
        this.setSearchStatus('');
    }

    setSearchStatus(message) {
        this.searchStatusTarget.textContent = message;
        this.searchStatusTarget.hidden = message === '';
    }

    detailOf(row) {
        return [row.pse_ref || row.product_ref, row.combination]
            .filter((part) => part)
            .join(' · ');
    }

    addProduct(event) {
        const row = this.results[Number(event.currentTarget.dataset.resultIndex)];

        if (!row) {
            return;
        }

        const index = this.nextIndexValue++;
        const line = this.lineTemplateTarget.content.firstElementChild.cloneNode(true);
        const label = `${row.title}${row.combination ? ` (${row.combination})` : ''}`;

        line.querySelector('[data-slot="title"]').textContent = row.title;
        line.querySelector('[data-slot="detail"]').textContent = this.detailOf(row);
        line.querySelector('[data-slot="catalog-price"]').textContent = row.price_label;

        line.querySelectorAll('[data-field]').forEach((field) => {
            const name = field.dataset.field;
            field.name = `lines[${index}][${name}]`;
            field.id = `quote-line-${index}-${name}`;

            if (name === 'pse') {
                field.value = String(row.pse_id);
            } else if (name === 'price') {
                field.value = row.price;
            }
        });

        ['quantity', 'price'].forEach((name) => {
            const labelElement = line.querySelector(`[data-slot="${name}-label"]`);
            labelElement.htmlFor = `quote-line-${index}-${name}`;
            labelElement.textContent = `${labelElement.textContent} - ${label}`;
        });

        this.linesTarget.append(line);
        this.refreshEmpty();
        this.markDirty();
        line.querySelector('[data-field="quantity"]').focus();
        this.clearResults();
        this.searchTarget.value = '';
    }

    removeLine(event) {
        event.currentTarget.closest('tr').remove();
        this.refreshEmpty();
        this.markDirty();
        this.searchTarget.focus();
    }

    refreshEmpty() {
        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = this.linesTarget.children.length > 0;
        }
    }
}

// What the server answered to a save it refused, worded for the shop.
class SaveRefused extends Error {}
