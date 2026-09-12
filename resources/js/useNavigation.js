import { computed, reactive } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function useNavigation() {
    const page = usePage();
    return reactive({
        path: computed(() => page.props.navigation.path),
        query: computed(() => page.props.navigation.query),
        params: computed(() => page.props.navigation.params),
        fullPath: computed(() => page.url),
    });
}
