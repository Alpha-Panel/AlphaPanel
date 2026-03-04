import { ref, watch, type Ref } from 'vue';
import axios from 'axios';

interface DataTableOptions {
    url: string;
    columns: string[];
    defaultLength?: number;
    defaultOrderColumn?: number;
    defaultOrderDir?: 'asc' | 'desc';
    extraParams?: Ref<Record<string, unknown>> | (() => Record<string, unknown>);
}

interface DataTableRow {
    [key: string]: unknown;
}

interface DataTableState {
    data: Ref<DataTableRow[]>;
    loading: Ref<boolean>;
    recordsTotal: Ref<number>;
    recordsFiltered: Ref<number>;
    currentPage: Ref<number>;
    perPage: Ref<number>;
    search: Ref<string>;
    orderColumn: Ref<number>;
    orderDir: Ref<'asc' | 'desc'>;
    totalPages: Ref<number>;
    fetch: () => Promise<void>;
    setPage: (page: number) => void;
    setSearch: (value: string) => void;
    setOrder: (column: number, dir: 'asc' | 'desc') => void;
}

export function useDataTable(options: DataTableOptions): DataTableState {
    const data = ref<DataTableRow[]>([]);
    const loading = ref(false);
    const recordsTotal = ref(0);
    const recordsFiltered = ref(0);
    const currentPage = ref(1);
    const perPage = ref(options.defaultLength ?? 25);
    const search = ref('');
    const orderColumn = ref(options.defaultOrderColumn ?? 0);
    const orderDir = ref<'asc' | 'desc'>(options.defaultOrderDir ?? 'asc');
    const totalPages = ref(0);

    let drawCounter = 0;
    let searchDebounce: ReturnType<typeof setTimeout> | null = null;

    const resolveExtraParams = (): Record<string, unknown> => {
        if (!options.extraParams) {
            return {};
        }

        if (typeof options.extraParams === 'function') {
            return options.extraParams();
        }

        return options.extraParams.value;
    };

    const fetch = async () => {
        loading.value = true;
        drawCounter++;
        const draw = drawCounter;

        try {
            const response = await axios.get(options.url, {
                params: {
                    draw,
                    start: (currentPage.value - 1) * perPage.value,
                    length: perPage.value,
                    'search[value]': search.value,
                    'order[0][column]': orderColumn.value,
                    'order[0][dir]': orderDir.value,
                    ...resolveExtraParams(),
                },
            });

            if (response.data.draw === draw) {
                data.value = response.data.data;
                recordsTotal.value = response.data.recordsTotal;
                recordsFiltered.value = response.data.recordsFiltered;
                totalPages.value = Math.ceil(recordsFiltered.value / perPage.value);
            }
        } catch (error) {
            console.error('DataTable fetch error:', error);
        } finally {
            loading.value = false;
        }
    };

    const setPage = (page: number) => {
        currentPage.value = page;
    };

    const setSearch = (value: string) => {
        search.value = value;
        currentPage.value = 1;
    };

    const setOrder = (column: number, dir: 'asc' | 'desc') => {
        orderColumn.value = column;
        orderDir.value = dir;
    };

    watch([currentPage, perPage, orderColumn, orderDir], () => {
        fetch();
    });

    watch(search, () => {
        if (searchDebounce) clearTimeout(searchDebounce);
        searchDebounce = setTimeout(() => {
            fetch();
        }, 300);
    });

    if (options.extraParams) {
        watch(
            () => JSON.stringify(resolveExtraParams()),
            () => {
                currentPage.value = 1;
                fetch();
            },
        );
    }

    fetch();

    return {
        data,
        loading,
        recordsTotal,
        recordsFiltered,
        currentPage,
        perPage,
        search,
        orderColumn,
        orderDir,
        totalPages,
        fetch,
        setPage,
        setSearch,
        setOrder,
    };
}
