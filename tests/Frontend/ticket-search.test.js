import test from 'node:test';
import assert from 'node:assert/strict';
import { ref } from 'vue';
import { useTicketSearch } from '../../resources/js/useTicketSearch.js';

test('archive has its own search term while status views share the active search', () => {
    const view = ref('all');
    const { search, label } = useTicketSearch(view);
    search.value = 'recent order';
    view.value = 'Pending';
    assert.equal(search.value, 'recent order');
    view.value = 'archive';
    assert.equal(search.value, '');
    assert.equal(label.value, 'Search archive');
    search.value = 'old invoice';
    view.value = 'all';
    assert.equal(search.value, 'recent order');
    assert.equal(label.value, 'Search recent tickets');
    view.value = 'archive';
    assert.equal(search.value, 'old invoice');
});

test('spam and trash searches stay separate from archive and active tickets', () => {
    const view = ref('spam');
    const { search, label } = useTicketSearch(view);
    search.value = 'blocked sender';
    view.value = 'trash';
    assert.equal(search.value, '');
    assert.equal(label.value, 'Search trash');
    search.value = 'removed ticket';
    view.value = 'spam';
    assert.equal(search.value, 'blocked sender');
    view.value = 'archive';
    assert.equal(search.value, '');
    view.value = 'unassigned';
    assert.equal(search.value, '');
});
