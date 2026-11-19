/**
 * A paged list states its count in its search field (SearchField with count) instead of in its
 * footer, so the footer keeps to one line. The list template carries the count in
 * data-search-count and its full text in data-search-count-label; after the list was reloaded
 * (search, paging) this hands both on to the field, so a search shows the number of hits.
 *
 * @param {Element|null} container  the element the list was rendered into
 * @param {Element|null} field  the search field's form, or an element inside it
 */
export function updateSearchCount(container, field) {
    const list = container?.querySelector('[data-search-count]');
    const target = field?.closest('.search-field')?.querySelector('[data-search-field-count]');
    if (!list || !target) {
        return;
    }
    const [number, label] = target.children;
    number.textContent = list.dataset.searchCount;
    label.textContent = list.dataset.searchCountLabel;
}
