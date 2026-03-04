const globalI18n = typeof window !== 'undefined' ? window.AlphaPanelI18n : null;

const translations = (globalI18n && typeof globalI18n === 'object' && globalI18n.translations)
    ? globalI18n.translations
    : {};

export function t(key, replacements = {}) {
    let translated = translations[key] ?? key;

    Object.entries(replacements).forEach(([name, value]) => {
        translated = translated.replaceAll(`:${name}`, String(value));
    });

    return translated;
}

