(function () {
    const THEME_KEY = 'costaricaTheme';
    const LANGUAGE_KEY = 'costaricaLanguage';
    const translatedNodes = new WeakMap();
    let translationObserver;

    function applyTheme(theme) {
        const isDark = theme === 'dark';
        document.documentElement.classList.toggle('dark-mode', isDark);
        document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';

        const button = document.getElementById('themeToggle');
        const icon = button && button.querySelector('[data-theme-icon]');
        const label = button && button.querySelector('[data-theme-label]');
        if (icon) icon.className = isDark ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
        if (label) label.textContent = isDark ? 'فاتح' : 'داكن';
    }

    function toggleTheme() {
        const isDark = !document.documentElement.classList.contains('dark-mode');
        localStorage.setItem(THEME_KEY, isDark ? 'dark' : 'light');
        applyTheme(isDark ? 'dark' : 'light');
    }

    function translateTextNodes(root) {
        if (!root) return;
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        while (walker.nextNode()) {
            const node = walker.currentNode;
            const parent = node.parentElement;
            if (!parent || parent.closest('script, style, noscript')) continue;
            const text = node.nodeValue.trim();
            if (!text) continue;
            if (!translatedNodes.has(node)) translatedNodes.set(node, node.nodeValue);
            if (window.SITE_TRANSLATIONS[text]) {
                node.nodeValue = node.nodeValue.replace(text, window.SITE_TRANSLATIONS[text]);
            }
        }
    }

    function restoreTextNodes(root) {
        if (!root) return;
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        while (walker.nextNode()) {
            const node = walker.currentNode;
            if (translatedNodes.has(node)) node.nodeValue = translatedNodes.get(node);
        }
    }

    function applyLanguage(language) {
        const isArabic = language !== 'en';
        document.documentElement.lang = isArabic ? 'ar' : 'en';
        document.documentElement.dir = isArabic ? 'rtl' : 'ltr';
        document.documentElement.classList.toggle('language-ar', isArabic);
        document.documentElement.classList.toggle('language-en', !isArabic);

        document.querySelectorAll('.ar-text').forEach(function (element) {
            const parent = element.parentElement;
            const explicitEnglish = Boolean(parent && parent.querySelector(':scope > .en-text')) ||
                Boolean(element.nextElementSibling && element.nextElementSibling.classList.contains('en-text'));
            element.classList.toggle('hidden', !isArabic && explicitEnglish);
            element.classList.toggle('auto-translated', !isArabic && !explicitEnglish);
        });
        document.querySelectorAll('.en-text').forEach(function (element) {
            element.classList.toggle('hidden', isArabic);
        });

        document.querySelectorAll('[data-i18n-ar][data-i18n-en]').forEach(function (element) {
            element.textContent = isArabic ? element.dataset.i18nAr : element.dataset.i18nEn;
        });

        restoreTextNodes(document.body);
        if (!isArabic) translateTextNodes(document.body);

        document.querySelectorAll('[placeholder]').forEach(function (element) {
            if (!element.dataset.originalPlaceholder) {
                element.dataset.originalPlaceholder = element.getAttribute('placeholder') || '';
            }
            const originalPlaceholder = element.dataset.originalPlaceholder;
            const translatedPlaceholder = element.dataset.i18nPlaceholderEn ||
                window.SITE_TRANSLATIONS[originalPlaceholder];
            element.setAttribute('placeholder',
                isArabic ? (element.dataset.i18nPlaceholderAr || originalPlaceholder) : (translatedPlaceholder || originalPlaceholder));
        });

        const languageButton = document.getElementById('languageToggle');
        const label = languageButton && languageButton.querySelector('[data-language-label]');
        if (label) label.textContent = isArabic ? 'EN' : 'عربي';
    }

    function toggleLanguage() {
        const nextLanguage = document.documentElement.lang === 'ar' ? 'en' : 'ar';
        localStorage.setItem(LANGUAGE_KEY, nextLanguage);
        applyLanguage(nextLanguage);
    }

    function addControlButtons() {
        const actions = document.querySelector('header.sticky > div:first-child > div.flex.items-center');
        if (!actions) return;

        if (!document.getElementById('languageToggle')) {
            const languageButton = document.createElement('button');
            languageButton.id = 'languageToggle';
            languageButton.type = 'button';
            languageButton.className = 'px-3 py-2 rounded-full border border-darkBrown text-darkBrown hover:bg-darkBrown hover:text-lightBeige transition-all text-xs font-bold';
            languageButton.setAttribute('aria-label', 'تغيير اللغة');
            languageButton.innerHTML = '<span data-language-label></span>';
            languageButton.addEventListener('click', toggleLanguage);
            actions.insertBefore(languageButton, actions.firstChild);
        }

        if (!document.getElementById('themeToggle')) {
            const themeButton = document.createElement('button');
            themeButton.id = 'themeToggle';
            themeButton.type = 'button';
            themeButton.className = 'px-3 py-2 rounded-full border border-darkBrown text-darkBrown hover:bg-darkBrown hover:text-lightBeige transition-all text-xs font-bold flex items-center gap-2';
            themeButton.setAttribute('aria-label', 'تبديل الوضع الليلي');
            themeButton.innerHTML = '<i data-theme-icon aria-hidden="true"></i><span data-theme-label></span>';
            themeButton.addEventListener('click', toggleTheme);
            actions.appendChild(themeButton);
        }
    }

    function initialize() {
        addControlButtons();
        const language = localStorage.getItem(LANGUAGE_KEY) === 'en' ? 'en' : 'ar';
        applyLanguage(language);
        applyTheme(localStorage.getItem(THEME_KEY) || 'light');

        translationObserver = new MutationObserver(function (mutations) {
            if (document.documentElement.lang !== 'en') return;
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType === Node.ELEMENT_NODE) translateTextNodes(node);
                    if (node.nodeType === Node.TEXT_NODE && node.parentElement && window.SITE_TRANSLATIONS[node.nodeValue.trim()]) {
                        node.nodeValue = window.SITE_TRANSLATIONS[node.nodeValue.trim()];
                    }
                });
            });
        });
        translationObserver.observe(document.body, { childList: true, subtree: true });
    }

    window.toggleTheme = toggleTheme;
    window.toggleLanguage = toggleLanguage;
    window.addEventListener('storage', function (event) {
        if (event.key === THEME_KEY) applyTheme(event.newValue || 'light');
        if (event.key === LANGUAGE_KEY) applyLanguage(event.newValue === 'en' ? 'en' : 'ar');
    });
    applyTheme(localStorage.getItem(THEME_KEY) || 'light');
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }
})();
