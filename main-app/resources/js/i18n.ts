import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';

// Keys are the English strings themselves, so a missing translation falls back to the key.
i18n.use(initReactI18next).init({
    lng: 'en',
    fallbackLng: 'en',
    resources: {},
    interpolation: { escapeValue: false },
    returnEmptyString: false,
});

/** Called on every Inertia visit with the translations shared by the server. */
export function syncTranslations(lang: string, translations: Record<string, string>) {
    i18n.addResourceBundle(lang, 'translation', translations, true, true);
    if (i18n.language !== lang) {
        i18n.changeLanguage(lang);
    }
}

export default i18n;
