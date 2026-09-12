import { computed, reactive } from 'vue';

export function useTicketSearch(view) {
    const terms = reactive({ active: '', archive: '', spam: '', trash: '' });
    const scope = computed(() => ['archive', 'spam', 'trash'].includes(view.value) ? view.value : 'active');
    const search = computed({ get: () => terms[scope.value], set: value => { terms[scope.value] = value; } });
    const label = computed(() => ({ active: 'Search recent tickets', archive: 'Search archive', spam: 'Search spam', trash: 'Search trash' }[scope.value]));
    return { search, label };
}
