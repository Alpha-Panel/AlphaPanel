<template>
    <Head :title="t('Audit Logs')" />
    <ThemeProvider>
        <SidebarProvider>
            <AdminLayout>
                <PageBreadcrumb :pageTitle="t('Audit Logs')" />

                <div class="space-y-4">
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
                        <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ t('Audit Logs') }}</h3>
                            <div class="flex w-full flex-col gap-2 sm:flex-row lg:w-auto">
                                <input
                                    v-model="searchInput"
                                    @input="table.setSearch(searchInput)"
                                    type="text"
                                    :placeholder="t('Search logs, IP, port...')"
                                    class="h-10 w-full rounded-lg border border-gray-200 bg-transparent px-4 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 sm:w-72 dark:border-gray-800 dark:bg-gray-900 dark:text-white/90"
                                />
                                <button
                                    type="button"
                                    class="inline-flex h-10 items-center justify-center rounded-lg border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                                    @click="clearFilters"
                                >
                                    {{ t('Clear Filters') }}
                                </button>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-3 xl:grid-cols-4">
                            <div class="space-y-1">
                                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ t('Date Range') }}</span>
                                <div ref="datePickerRef" class="relative">
                                    <button type="button" class="filter-select-trigger" @click="toggleDatePicker">
                                        <span class="truncate">{{ dateRangeControlLabel }}</span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">▾</span>
                                    </button>

                                    <div v-if="datePickerOpen" class="date-range-dropdown">
                                        <div class="mb-2 flex items-center justify-between gap-3">
                                            <button type="button" class="calendar-nav-btn" @click="prevCalendarMonth">‹</button>
                                            <div class="grid flex-1 grid-cols-2 gap-2 text-center text-sm font-medium text-gray-700 dark:text-gray-200">
                                                <span>{{ formatMonthLabel(leftMonth) }}</span>
                                                <span>{{ formatMonthLabel(rightMonth) }}</span>
                                            </div>
                                            <button type="button" class="calendar-nav-btn" @click="nextCalendarMonth">›</button>
                                        </div>

                                        <div class="grid grid-cols-2 gap-2">
                                            <div>
                                                <div class="calendar-week-row">
                                                    <span v-for="label in weekdayLabels" :key="`left-week-${label}`">{{ label }}</span>
                                                </div>
                                                <div class="calendar-grid">
                                                    <button
                                                        v-for="cell in leftMonthCells"
                                                        :key="`left-${cell.key}`"
                                                        type="button"
                                                        :class="calendarDayClasses(cell)"
                                                        @click="selectCalendarDay(cell.date)"
                                                    >
                                                        {{ cell.day }}
                                                    </button>
                                                </div>
                                            </div>
                                            <div>
                                                <div class="calendar-week-row">
                                                    <span v-for="label in weekdayLabels" :key="`right-week-${label}`">{{ label }}</span>
                                                </div>
                                                <div class="calendar-grid">
                                                    <button
                                                        v-for="cell in rightMonthCells"
                                                        :key="`right-${cell.key}`"
                                                        type="button"
                                                        :class="calendarDayClasses(cell)"
                                                        @click="selectCalendarDay(cell.date)"
                                                    >
                                                        {{ cell.day }}
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-1">
                                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ t('Users') }}</span>
                                <div ref="userSelectRef" class="relative">
                                    <button type="button" class="filter-select-trigger" @click="toggleFilterMenu('users')">
                                        <span class="truncate">{{ userSelectLabel }}</span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">▾</span>
                                    </button>
                                    <div v-if="openFilterMenu === 'users'" class="multi-select-dropdown">
                                        <input ref="userSearchInputRef" v-model="userOptionSearch" type="text" :placeholder="t('Search users...')" class="filter-input h-9" />
                                        <div class="multi-select-options">
                                            <button
                                                v-for="option in userOptions"
                                                :key="`user-${option.value}`"
                                                type="button"
                                                class="multi-select-option"
                                                @click="toggleUserFilter(Number(option.value))"
                                            >
                                                <span class="truncate">{{ option.label }}</span>
                                                <span v-if="filters.user_ids.includes(Number(option.value))" class="text-brand-500">✓</span>
                                            </button>
                                            <p v-if="userOptions.length === 0" class="px-2 py-2 text-xs text-gray-500 dark:text-gray-400">{{ t('No results') }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-1">
                                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ t('Actions') }}</span>
                                <div ref="actionSelectRef" class="relative">
                                    <button type="button" class="filter-select-trigger" @click="toggleFilterMenu('actions')">
                                        <span class="truncate">{{ actionSelectLabel }}</span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">▾</span>
                                    </button>
                                    <div v-if="openFilterMenu === 'actions'" class="multi-select-dropdown">
                                        <input
                                            ref="actionSearchInputRef"
                                            v-model="actionOptionSearch"
                                            type="text"
                                            :placeholder="t('Search actions...')"
                                            class="filter-input h-9"
                                        />
                                        <div class="multi-select-options">
                                            <button
                                                v-for="option in actionOptions"
                                                :key="`action-${option.value}`"
                                                type="button"
                                                class="multi-select-option"
                                                @click="toggleActionFilter(String(option.value))"
                                            >
                                                <span class="truncate">{{ option.label }}</span>
                                                <span v-if="filters.actions.includes(String(option.value))" class="text-brand-500">✓</span>
                                            </button>
                                            <p v-if="actionOptions.length === 0" class="px-2 py-2 text-xs text-gray-500 dark:text-gray-400">{{ t('No results') }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-1">
                                <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ t('Domains') }}</span>
                                <div ref="domainSelectRef" class="relative">
                                    <button type="button" class="filter-select-trigger" @click="toggleFilterMenu('domains')">
                                        <span class="truncate">{{ domainSelectLabel }}</span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">▾</span>
                                    </button>
                                    <div v-if="openFilterMenu === 'domains'" class="multi-select-dropdown">
                                        <input
                                            ref="domainSearchInputRef"
                                            v-model="domainOptionSearch"
                                            type="text"
                                            :placeholder="t('Search domains...')"
                                            class="filter-input h-9"
                                        />
                                        <div class="multi-select-options">
                                            <button
                                                v-for="option in domainOptions"
                                                :key="`domain-${option.value}`"
                                                type="button"
                                                class="multi-select-option"
                                                @click="toggleDomainFilter(Number(option.value))"
                                            >
                                                <span class="truncate">{{ option.label }}</span>
                                                <span v-if="filters.domain_ids.includes(Number(option.value))" class="text-brand-500">✓</span>
                                            </button>
                                            <p v-if="domainOptions.length === 0" class="px-2 py-2 text-xs text-gray-500 dark:text-gray-400">{{ t('No results') }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                            <span v-if="selectedDateRangeLabel !== null" class="rounded-full bg-blue-light-500/15 px-2 py-1 text-blue-light-700 dark:text-blue-light-300">
                                {{ selectedDateRangeLabel }}
                            </span>
                            <span v-for="user in selectedUsers" :key="`chip-user-${user.value}`" class="rounded-full bg-gray-100 px-2 py-1 dark:bg-gray-800">
                                {{ user.label }}
                            </span>
                            <span v-for="action in selectedActions" :key="`chip-action-${action.value}`" class="rounded-full bg-gray-100 px-2 py-1 dark:bg-gray-800">
                                {{ action.label }}
                            </span>
                            <span v-for="domain in selectedDomains" :key="`chip-domain-${domain.value}`" class="rounded-full bg-gray-100 px-2 py-1 dark:bg-gray-800">
                                {{ domain.label }}
                            </span>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/3">
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr class="border-t border-gray-200 dark:border-gray-800">
                                        <th class="sortable-th" @click="toggleSort(0)">{{ t('Date') }} <span class="opacity-60">{{ sortIndicator(0) }}</span></th>
                                        <th class="sortable-th" @click="toggleSort(1)">{{ t('User') }} <span class="opacity-60">{{ sortIndicator(1) }}</span></th>
                                        <th class="sortable-th" @click="toggleSort(2)">{{ t('Action') }} <span class="opacity-60">{{ sortIndicator(2) }}</span></th>
                                        <th class="sortable-th" @click="toggleSort(3)">{{ t('Domain') }} <span class="opacity-60">{{ sortIndicator(3) }}</span></th>
                                        <th class="sortable-th" @click="toggleSort(4)">{{ t('Source') }} <span class="opacity-60">{{ sortIndicator(4) }}</span></th>
                                        <th class="sortable-th" @click="toggleSort(5)">{{ t('Summary') }} <span class="opacity-60">{{ sortIndicator(5) }}</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-if="table.loading.value" class="border-t border-gray-200 dark:border-gray-800">
                                        <td colspan="6" class="px-5 py-8 text-center text-gray-500">{{ t('Loading...') }}</td>
                                    </tr>
                                    <tr v-else-if="table.data.value.length === 0" class="border-t border-gray-200 dark:border-gray-800">
                                        <td colspan="6" class="px-5 py-8 text-center text-gray-500 dark:text-gray-400">{{ t('No logs found.') }}</td>
                                    </tr>
                                    <template v-for="log in table.data.value" :key="String(log.id)">
                                        <tr
                                            :class="[
                                                'border-t border-gray-200 dark:border-gray-800',
                                                log.details ? 'cursor-pointer hover:bg-gray-50 dark:hover:bg-white/2' : 'hover:bg-gray-50 dark:hover:bg-white/2',
                                            ]"
                                            @click="log.details ? toggleDetails(String(log.id)) : undefined"
                                        >
                                            <td class="px-5 py-4 text-sm text-gray-600 dark:text-gray-400">{{ log.created_at }}</td>
                                            <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90">{{ log.user }}</td>
                                            <td class="px-5 py-4" v-html="String(log.action_badge ?? '-')"></td>
                                            <td class="px-5 py-4 text-sm text-gray-600 dark:text-gray-400">
                                                <Link
                                                    v-if="log.domain_show_url"
                                                    :href="String(log.domain_show_url)"
                                                    class="text-brand-500 hover:text-brand-600"
                                                    @click.stop
                                                >
                                                    {{ log.domain }}
                                                </Link>
                                                <span v-else>{{ log.domain }}</span>
                                            </td>
                                            <td class="px-5 py-4 text-sm text-gray-600 dark:text-gray-400">
                                                <span class="font-mono">{{ String(log.source ?? '-') }}</span>
                                            </td>
                                            <td class="px-5 py-4 text-sm text-gray-600 dark:text-gray-400">
                                                <div class="flex items-center gap-2">
                                                    <span class="flex-1">{{ log.summary }}</span>
                                                    <svg
                                                        v-if="log.details"
                                                        class="h-4 w-4 shrink-0 text-gray-400 transition-transform duration-200"
                                                        :class="{ 'rotate-180': expandedDetails.has(String(log.id)) }"
                                                        fill="none"
                                                        viewBox="0 0 24 24"
                                                        stroke="currentColor"
                                                        stroke-width="2"
                                                    >
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                                                    </svg>
                                                </div>
                                            </td>
                                        </tr>
                                        <tr v-if="log.details && expandedDetails.has(String(log.id))" :key="`${String(log.id)}-details`" class="border-t border-gray-100 dark:border-gray-800/50">
                                            <td colspan="6" class="px-5 pb-4 pt-2">
                                                <div class="max-h-80 overflow-auto rounded-lg bg-gray-900 p-4">
                                                    <pre class="font-mono text-xs leading-relaxed text-gray-200">{{ log.details }}</pre>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <div v-if="table.totalPages.value > 1" class="flex items-center justify-between border-t border-gray-200 px-5 py-4 dark:border-gray-800">
                            <p class="text-sm text-gray-500">
                                {{ table.recordsFiltered.value }} {{ t('total') }}
                            </p>
                            <div class="flex gap-2">
                                <button
                                    v-for="pageNumber in table.totalPages.value"
                                    :key="pageNumber"
                                    @click="table.setPage(pageNumber)"
                                    :class="[
                                        'h-8 w-8 rounded-lg text-sm font-medium',
                                        pageNumber === table.currentPage.value ? 'bg-brand-500 text-white' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-400',
                                    ]"
                                >
                                    {{ pageNumber }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </AdminLayout>
        </SidebarProvider>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, type Ref, watch } from 'vue';
import axios from 'axios';
import { Head, Link } from '@inertiajs/vue3';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import SidebarProvider from '@/Components/Layout/SidebarProvider.vue';
import AdminLayout from '@/Components/Layout/AdminLayout.vue';
import PageBreadcrumb from '@/Components/Common/PageBreadcrumb.vue';
import { useDataTable } from '@/Composables/useDataTable';
import { useI18n } from '@/Composables/useI18n';

type SelectOptionValue = number | string;
type FilterMenuKey = 'users' | 'actions' | 'domains';

interface SelectOption {
    value: SelectOptionValue;
    label: string;
}

interface CalendarDayCell {
    key: string;
    date: Date;
    day: number;
    inCurrentMonth: boolean;
}

const { t, locale } = useI18n();
const searchInput = ref('');
const expandedDetails = ref<Set<string>>(new Set());

const toggleDetails = (logId: string): void => {
    const next = new Set(expandedDetails.value);
    if (next.has(logId)) {
        next.delete(logId);
    } else {
        next.add(logId);
    }
    expandedDetails.value = next;
};

const filters = reactive({
    date_from: '',
    date_to: '',
    user_ids: [] as number[],
    actions: [] as string[],
    domain_ids: [] as number[],
});

const userOptionSearch = ref('');
const actionOptionSearch = ref('');
const domainOptionSearch = ref('');

const userOptions = ref<SelectOption[]>([]);
const actionOptions = ref<SelectOption[]>([]);
const domainOptions = ref<SelectOption[]>([]);

const userLabelMap = ref<Record<number, string>>({});
const actionLabelMap = ref<Record<string, string>>({});
const domainLabelMap = ref<Record<number, string>>({});

const openFilterMenu = ref<FilterMenuKey | null>(null);
const datePickerOpen = ref(false);

const datePickerRef = ref<HTMLElement | null>(null);
const userSelectRef = ref<HTMLElement | null>(null);
const actionSelectRef = ref<HTMLElement | null>(null);
const domainSelectRef = ref<HTMLElement | null>(null);

const userSearchInputRef = ref<HTMLInputElement | null>(null);
const actionSearchInputRef = ref<HTMLInputElement | null>(null);
const domainSearchInputRef = ref<HTMLInputElement | null>(null);

const draftDateFrom = ref<Date | null>(null);
const draftDateTo = ref<Date | null>(null);
const calendarAnchorMonth = ref<Date>(new Date(new Date().getFullYear(), new Date().getMonth(), 1));

const normalizeLocaleForIntl = (value: string): string => {
    if (value === 'tr-gokturk' || value === 'gokturk-latin') {
        return 'tr';
    }

    return value;
};

const intlLocale = computed(() => normalizeLocaleForIntl(locale.value));

const dateFormatter = computed(() => {
    return new Intl.DateTimeFormat(intlLocale.value, {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
});

const monthFormatter = computed(() => {
    return new Intl.DateTimeFormat(intlLocale.value, {
        month: 'long',
        year: 'numeric',
    });
});

const weekdayFormatter = computed(() => {
    return new Intl.DateTimeFormat(intlLocale.value, {
        weekday: 'short',
    });
});

const weekdayLabels = computed(() => {
    const baseMonday = new Date(2024, 0, 1);

    return Array.from({ length: 7 }, (_, index) => {
        const current = new Date(baseMonday);
        current.setDate(baseMonday.getDate() + index);

        return weekdayFormatter.value.format(current);
    });
});

const extraParams = computed(() => ({
    date_from: filters.date_from || undefined,
    date_to: filters.date_to || undefined,
    user_ids: filters.user_ids,
    actions: filters.actions,
    domain_ids: filters.domain_ids,
}));

const table = useDataTable({
    url: route('audit-logs.json'),
    columns: ['created_at', 'user', 'action', 'domain', 'source', 'summary'],
    defaultOrderColumn: 0,
    defaultOrderDir: 'desc',
    extraParams,
});

const sortIndicator = (column: number): string => {
    if (table.orderColumn.value !== column) {
        return '↕';
    }

    return table.orderDir.value === 'asc' ? '↑' : '↓';
};

const toggleSort = (column: number): void => {
    if (table.orderColumn.value === column) {
        table.setOrder(column, table.orderDir.value === 'asc' ? 'desc' : 'asc');
        return;
    }

    table.setOrder(column, 'asc');
};

const dayStamp = (date: Date): number => {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime();
};

const stripTime = (date: Date): Date => {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate());
};

const parseFilterDate = (value: string): Date | null => {
    const trimmed = value.trim();
    if (trimmed === '') {
        return null;
    }

    const normalized = trimmed.includes(' ') ? trimmed.replace(' ', 'T') : trimmed;
    const parsed = new Date(normalized);
    if (Number.isNaN(parsed.getTime())) {
        return null;
    }

    return stripTime(parsed);
};

const formatDisplayDate = (date: Date): string => {
    return dateFormatter.value.format(date);
};

const formatMonthLabel = (date: Date): string => {
    return monthFormatter.value.format(date);
};

const toApiDateTime = (date: Date, endOfDay: boolean): string => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const time = endOfDay ? '23:59:59' : '00:00:00';

    return `${year}-${month}-${day} ${time}`;
};

const getMonthStart = (date: Date): Date => {
    return new Date(date.getFullYear(), date.getMonth(), 1);
};

const addMonths = (date: Date, count: number): Date => {
    return new Date(date.getFullYear(), date.getMonth() + count, 1);
};

const buildMonthCells = (monthStart: Date): CalendarDayCell[] => {
    const year = monthStart.getFullYear();
    const month = monthStart.getMonth();
    const firstOfMonth = new Date(year, month, 1);
    const firstWeekday = (firstOfMonth.getDay() + 6) % 7;
    const gridStart = new Date(year, month, 1 - firstWeekday);

    return Array.from({ length: 42 }, (_, index) => {
        const dayDate = new Date(gridStart);
        dayDate.setDate(gridStart.getDate() + index);

        return {
            key: `${dayDate.getFullYear()}-${dayDate.getMonth()}-${dayDate.getDate()}`,
            date: dayDate,
            day: dayDate.getDate(),
            inCurrentMonth: dayDate.getMonth() === month,
        };
    });
};

const leftMonth = computed(() => getMonthStart(calendarAnchorMonth.value));
const rightMonth = computed(() => addMonths(calendarAnchorMonth.value, 1));
const leftMonthCells = computed(() => buildMonthCells(leftMonth.value));
const rightMonthCells = computed(() => buildMonthCells(rightMonth.value));

const draftStartStamp = computed(() => (draftDateFrom.value ? dayStamp(draftDateFrom.value) : null));
const draftEndStamp = computed(() => (draftDateTo.value ? dayStamp(draftDateTo.value) : null));

const isDraftStart = (date: Date): boolean => {
    return draftStartStamp.value !== null && dayStamp(date) === draftStartStamp.value;
};

const isDraftEnd = (date: Date): boolean => {
    return draftEndStamp.value !== null && dayStamp(date) === draftEndStamp.value;
};

const isDraftInRange = (date: Date): boolean => {
    if (draftStartStamp.value === null || draftEndStamp.value === null) {
        return false;
    }

    const current = dayStamp(date);

    return current > draftStartStamp.value && current < draftEndStamp.value;
};

const calendarDayClasses = (cell: CalendarDayCell): string[] => {
    const classes = ['calendar-day'];

    if (!cell.inCurrentMonth) {
        classes.push('calendar-day-muted');
    }

    if (isDraftInRange(cell.date)) {
        classes.push('calendar-day-in-range');
    }

    if (isDraftStart(cell.date) || isDraftEnd(cell.date)) {
        classes.push('calendar-day-selected');
    }

    return classes;
};

const syncDraftRangeFromFilters = (): void => {
    draftDateFrom.value = parseFilterDate(filters.date_from);
    draftDateTo.value = parseFilterDate(filters.date_to);

    if (draftDateFrom.value !== null && draftDateTo.value !== null && dayStamp(draftDateTo.value) < dayStamp(draftDateFrom.value)) {
        const currentFrom = draftDateFrom.value;
        draftDateFrom.value = draftDateTo.value;
        draftDateTo.value = currentFrom;
    }

    const anchorDate = draftDateFrom.value ?? new Date();
    calendarAnchorMonth.value = getMonthStart(anchorDate);
};

const toggleDatePicker = (): void => {
    if (datePickerOpen.value) {
        datePickerOpen.value = false;
        return;
    }

    syncDraftRangeFromFilters();
    openFilterMenu.value = null;
    datePickerOpen.value = true;
};

const prevCalendarMonth = (): void => {
    calendarAnchorMonth.value = addMonths(calendarAnchorMonth.value, -1);
};

const nextCalendarMonth = (): void => {
    calendarAnchorMonth.value = addMonths(calendarAnchorMonth.value, 1);
};

const applyDraftRangeToFilters = (): void => {
    if (draftDateFrom.value === null || draftDateTo.value === null) {
        return;
    }

    filters.date_from = toApiDateTime(draftDateFrom.value, false);
    filters.date_to = toApiDateTime(draftDateTo.value, true);
    datePickerOpen.value = false;
};

const selectCalendarDay = (date: Date): void => {
    const selected = stripTime(date);

    if (draftDateFrom.value === null || (draftDateTo.value !== null)) {
        draftDateFrom.value = selected;
        draftDateTo.value = null;
        return;
    }

    if (dayStamp(selected) < dayStamp(draftDateFrom.value)) {
        draftDateTo.value = draftDateFrom.value;
        draftDateFrom.value = selected;
    } else {
        draftDateTo.value = selected;
    }

    applyDraftRangeToFilters();
};

const filterFromDate = computed(() => parseFilterDate(filters.date_from));
const filterToDate = computed(() => parseFilterDate(filters.date_to));

const dateRangeControlLabel = computed<string>(() => {
    if (filterFromDate.value === null || filterToDate.value === null) {
        return t('Date Range');
    }

    return `${formatDisplayDate(filterFromDate.value)} - ${formatDisplayDate(filterToDate.value)}`;
});

const selectedDateRangeLabel = computed<string | null>(() => {
    if (filterFromDate.value === null || filterToDate.value === null) {
        return null;
    }

    return `${t('Date Range')}: ${formatDisplayDate(filterFromDate.value)} - ${formatDisplayDate(filterToDate.value)}`;
});

const selectedUsers = computed(() => {
    return filters.user_ids.map((id) => ({
        value: id,
        label: userLabelMap.value[id] ?? `#${id}`,
    }));
});

const selectedActions = computed(() => {
    return filters.actions.map((action) => ({
        value: action,
        label: actionLabelMap.value[action] ?? action,
    }));
});

const selectedDomains = computed(() => {
    return filters.domain_ids.map((id) => ({
        value: id,
        label: domainLabelMap.value[id] ?? `#${id}`,
    }));
});

const buildSelectLabel = (values: string[], fallback: string): string => {
    if (values.length === 0) {
        return fallback;
    }

    if (values.length === 1) {
        return values[0];
    }

    if (values.length === 2) {
        return `${values[0]}, ${values[1]}`;
    }

    return `${values[0]}, ${values[1]} +${values.length - 2}`;
};

const userSelectLabel = computed(() => {
    return buildSelectLabel(
        selectedUsers.value.map((item) => item.label),
        t('Users'),
    );
});

const actionSelectLabel = computed(() => {
    return buildSelectLabel(
        selectedActions.value.map((item) => item.label),
        t('Actions'),
    );
});

const domainSelectLabel = computed(() => {
    return buildSelectLabel(
        selectedDomains.value.map((item) => item.label),
        t('Domains'),
    );
});

const mergeUserOptions = (options: SelectOption[]): void => {
    for (const option of options) {
        const key = Number(option.value);
        if (!Number.isNaN(key)) {
            userLabelMap.value[key] = option.label;
        }
    }
};

const mergeActionOptions = (options: SelectOption[]): void => {
    for (const option of options) {
        actionLabelMap.value[String(option.value)] = option.label;
    }
};

const mergeDomainOptions = (options: SelectOption[]): void => {
    for (const option of options) {
        const key = Number(option.value);
        if (!Number.isNaN(key)) {
            domainLabelMap.value[key] = option.label;
        }
    }
};

const fetchUserOptions = async (): Promise<void> => {
    const response = await axios.get(route('audit-logs.options.users'), {
        params: {
            q: userOptionSearch.value,
            selected: filters.user_ids,
        },
    });

    const options = Array.isArray(response.data?.data) ? response.data.data : [];
    userOptions.value = options.map((option: Record<string, unknown>) => ({
        value: Number(option.value),
        label: String(option.label ?? option.value ?? ''),
    }));
    mergeUserOptions(userOptions.value);
};

const fetchActionOptions = async (): Promise<void> => {
    const response = await axios.get(route('audit-logs.options.actions'), {
        params: {
            q: actionOptionSearch.value,
            selected: filters.actions,
        },
    });

    const options = Array.isArray(response.data?.data) ? response.data.data : [];
    actionOptions.value = options.map((option: Record<string, unknown>) => ({
        value: String(option.value ?? ''),
        label: String(option.label ?? option.value ?? ''),
    }));
    mergeActionOptions(actionOptions.value);
};

const fetchDomainOptions = async (): Promise<void> => {
    const response = await axios.get(route('audit-logs.options.domains'), {
        params: {
            q: domainOptionSearch.value,
            selected: filters.domain_ids,
        },
    });

    const options = Array.isArray(response.data?.data) ? response.data.data : [];
    domainOptions.value = options.map((option: Record<string, unknown>) => ({
        value: Number(option.value),
        label: String(option.label ?? option.value ?? ''),
    }));
    mergeDomainOptions(domainOptions.value);
};

const debounceWatch = (source: Ref<string>, callback: () => Promise<void>): void => {
    let timer: ReturnType<typeof setTimeout> | null = null;

    watch(source, () => {
        if (timer !== null) {
            clearTimeout(timer);
        }

        timer = setTimeout(() => {
            void callback();
        }, 250);
    });
};

debounceWatch(userOptionSearch, fetchUserOptions);
debounceWatch(actionOptionSearch, fetchActionOptions);
debounceWatch(domainOptionSearch, fetchDomainOptions);

const focusOpenFilterSearch = (): void => {
    if (openFilterMenu.value === 'users') {
        userSearchInputRef.value?.focus();
        return;
    }

    if (openFilterMenu.value === 'actions') {
        actionSearchInputRef.value?.focus();
        return;
    }

    if (openFilterMenu.value === 'domains') {
        domainSearchInputRef.value?.focus();
    }
};

const toggleFilterMenu = (menuKey: FilterMenuKey): void => {
    datePickerOpen.value = false;

    if (openFilterMenu.value === menuKey) {
        openFilterMenu.value = null;
        return;
    }

    openFilterMenu.value = menuKey;

    if (menuKey === 'users') {
        void fetchUserOptions();
    } else if (menuKey === 'actions') {
        void fetchActionOptions();
    } else {
        void fetchDomainOptions();
    }

    void nextTick(() => {
        focusOpenFilterSearch();
    });
};

const toggleUserFilter = (userId: number): void => {
    if (filters.user_ids.includes(userId)) {
        filters.user_ids = filters.user_ids.filter((id) => id !== userId);
    } else {
        filters.user_ids = [...filters.user_ids, userId];
    }
};

const toggleActionFilter = (action: string): void => {
    if (filters.actions.includes(action)) {
        filters.actions = filters.actions.filter((value) => value !== action);
    } else {
        filters.actions = [...filters.actions, action];
    }
};

const toggleDomainFilter = (domainId: number): void => {
    if (filters.domain_ids.includes(domainId)) {
        filters.domain_ids = filters.domain_ids.filter((id) => id !== domainId);
    } else {
        filters.domain_ids = [...filters.domain_ids, domainId];
    }
};

const clearFilters = (): void => {
    filters.date_from = '';
    filters.date_to = '';
    filters.user_ids = [];
    filters.actions = [];
    filters.domain_ids = [];

    draftDateFrom.value = null;
    draftDateTo.value = null;
    datePickerOpen.value = false;
    openFilterMenu.value = null;

    userOptionSearch.value = '';
    actionOptionSearch.value = '';
    domainOptionSearch.value = '';

    searchInput.value = '';
    table.setSearch('');
};

const handleClickOutside = (event: MouseEvent): void => {
    const target = event.target as Node;

    if (datePickerOpen.value && datePickerRef.value !== null && !datePickerRef.value.contains(target)) {
        datePickerOpen.value = false;
    }

    if (openFilterMenu.value === 'users' && userSelectRef.value !== null && !userSelectRef.value.contains(target)) {
        openFilterMenu.value = null;
        return;
    }

    if (openFilterMenu.value === 'actions' && actionSelectRef.value !== null && !actionSelectRef.value.contains(target)) {
        openFilterMenu.value = null;
        return;
    }

    if (openFilterMenu.value === 'domains' && domainSelectRef.value !== null && !domainSelectRef.value.contains(target)) {
        openFilterMenu.value = null;
    }
};

onMounted(() => {
    document.addEventListener('mousedown', handleClickOutside);
});

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', handleClickOutside);
});

void fetchUserOptions();
void fetchActionOptions();
void fetchDomainOptions();
</script>

<style scoped>
@reference "../../../css/app.css";

.filter-input {
    @apply h-10 w-full rounded-lg border border-gray-200 bg-transparent px-3 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90;
}

.filter-select-trigger {
    @apply flex h-10 w-full items-center justify-between gap-2 rounded-lg border border-gray-200 bg-transparent px-3 text-left text-sm text-gray-800 shadow-theme-xs hover:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90;
}

.multi-select-dropdown {
    @apply absolute left-0 top-full z-40 mt-2 w-full rounded-xl border border-gray-200 bg-white p-2 shadow-lg dark:border-gray-700 dark:bg-gray-900;
}

.multi-select-options {
    @apply mt-2 max-h-56 space-y-1 overflow-y-auto;
}

.multi-select-option {
    @apply flex w-full items-center justify-between gap-2 rounded-md px-2 py-1.5 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800;
}

.date-range-dropdown {
    @apply absolute left-0 top-full z-50 mt-2 w-170 max-w-[95vw] rounded-xl border border-gray-200 bg-white p-3 shadow-lg dark:border-gray-700 dark:bg-gray-900;
}

.calendar-nav-btn {
    @apply inline-flex h-8 w-8 items-center justify-center rounded-lg border border-gray-200 text-lg text-gray-600 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800;
}

.calendar-week-row {
    @apply mb-1 grid grid-cols-7 gap-1 text-center text-[11px] font-medium text-gray-500 dark:text-gray-400;
}

.calendar-grid {
    @apply grid grid-cols-7 gap-1;
}

.calendar-day {
    @apply h-8 rounded-md text-center text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800;
}

.calendar-day-muted {
    @apply text-gray-400 dark:text-gray-600;
}

.calendar-day-in-range {
    @apply bg-brand-500/10 text-brand-700 dark:text-brand-300;
}

.calendar-day-selected {
    @apply bg-brand-500 font-semibold text-white hover:bg-brand-500;
}

.sortable-th {
    @apply cursor-pointer px-5 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-gray-400;
}
</style>
