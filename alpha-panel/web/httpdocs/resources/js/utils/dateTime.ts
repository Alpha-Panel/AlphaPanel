const pad = (value: number): string => {
    return String(value).padStart(2, '0');
};

export const formatDateTime = (value: string | number | Date | null | undefined): string => {
    if (value === null || value === undefined || value === '') {
        return '-';
    }

    const date = value instanceof Date ? value : new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '-';
    }

    return `${pad(date.getDate())}.${pad(date.getMonth() + 1)}.${date.getFullYear()} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
};
