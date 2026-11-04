(function () {
    'use strict';

    const pageName = location.pathname.endsWith('/index.php')
        ? 'index.html'
        : location.pathname.split('/').pop() || 'index.html';
    const isEditorPreview = new URLSearchParams(location.search).has('editor-preview');
    let editing = false;
    let selectedElement = null;
    let siteContent = window.SITE_CONTENT || null;
    const originalValues = new Map();

    function safeLink(value) {
        if (typeof value !== 'string') return false;
        const trimmed = value.trim();
        return /^(https?:\/\/|mailto:|tel:|#|\/|\.{0,2}\/)[^\s]*$/i.test(trimmed)
            && !/^javascript:/i.test(trimmed);
    }

    function selectPath(element) {
        if (element.id && /^[A-Za-z][A-Za-z0-9_-]*$/.test(element.id)) {
            return '#' + element.id;
        }

        const parts = [];
        let current = element;
        while (current && current !== document.body && current.nodeType === 1) {
            let part = current.tagName.toLowerCase();
            let index = 1;
            let sibling = current;
            while ((sibling = sibling.previousElementSibling)) {
                if (sibling.tagName === current.tagName) index++;
            }
            part += ':nth-of-type(' + index + ')';
            parts.unshift(part);
            current = current.parentElement;
        }
        return 'body > ' + parts.join(' > ');
    }

    function applyOverride(item) {
        if (!item || item.page !== pageName || typeof item.selector !== 'string') return;
        let element;
        try {
            element = document.querySelector(item.selector);
        } catch (_error) {
            return;
        }
        if (!element) return;

        const key = [item.page, item.selector, item.type, item.property || ''].join('|');
        if (!originalValues.has(key)) {
            if (item.type === 'text') originalValues.set(key, element.textContent);
            else if (item.type === 'image') originalValues.set(key, element.getAttribute('src'));
            else if (item.type === 'link') {
                originalValues.set(key, {
                    text: element.textContent,
                    href: element.getAttribute('href')
                });
            } else if (item.type === 'color') {
                originalValues.set(key, {
                    value: element.style.getPropertyValue(item.property),
                    priority: element.style.getPropertyPriority(item.property)
                });
            } else if (item.type === 'list') {
                originalValues.set(key, Array.from(element.childNodes, node => node.cloneNode(true)));
            }
        }

        if (item.type === 'text' && typeof item.value === 'string') {
            if (element.textContent !== item.value) element.textContent = item.value;
        } else if (item.type === 'image' && typeof item.value === 'string' && safeLink(item.value)) {
            if (element.tagName === 'IMG' && element.getAttribute('src') !== item.value) {
                element.setAttribute('src', item.value);
            }
        } else if (item.type === 'link' && element.tagName === 'A' && item.value) {
            if (typeof item.value.text === 'string' && element.textContent !== item.value.text) {
                element.textContent = item.value.text;
            }
            if (safeLink(item.value.href) && element.getAttribute('href') !== item.value.href) {
                element.setAttribute('href', item.value.href);
            }
        } else if (item.type === 'color' && typeof item.value === 'string'
            && /^#[0-9a-f]{6}$/i.test(item.value)
            && ['color', 'background-color', 'border-color'].includes(item.property)) {
            element.style.setProperty(item.property, item.value, 'important');
        } else if (item.type === 'list' && Array.isArray(item.value)
            && ['UL', 'OL'].includes(element.tagName)) {
            const current = Array.from(element.children).map(child => {
                const anchor = child.querySelector('a');
                return anchor ? child.textContent.trim() + ' | ' + anchor.getAttribute('href') : child.textContent.trim();
            });
            if (JSON.stringify(current) !== JSON.stringify(item.value)) {
                const firstItem = element.querySelector(':scope > li');
                const itemClass = firstItem?.className || '';
                const firstAnchor = firstItem?.querySelector('a');
                element.replaceChildren(...item.value.map(line => {
                    const li = document.createElement('li');
                    li.className = itemClass;
                    const separator = line.indexOf(' | ');
                    const label = separator < 0 ? line : line.slice(0, separator).trim();
                    const href = separator < 0 ? '' : line.slice(separator + 3).trim();
                    if (href && safeLink(href)) {
                        const anchor = document.createElement('a');
                        if (firstAnchor) anchor.className = firstAnchor.className;
                        anchor.href = href;
                        anchor.textContent = label;
                        li.append(anchor);
                    } else {
                        li.textContent = label;
                    }
                    return li;
                }));
            }
        }
    }

    function applyContent(data) {
        if (!data || !Array.isArray(data.overrides)) return;
        siteContent = data;
        if (data.theme) {
            if (/^#[0-9a-f]{6}$/i.test(data.theme.background || '')) {
                if (document.body.style.backgroundColor !== data.theme.background) {
                    document.body.style.backgroundColor = data.theme.background;
                }
            }
            if (/^#[0-9a-f]{6}$/i.test(data.theme.text || '')) {
                if (document.body.style.color !== data.theme.text) {
                    document.body.style.color = data.theme.text;
                }
            }
            const accent = /^#[0-9a-f]{6}$/i.test(data.theme.accent || '') ? data.theme.accent : '#4A3B32';
            if (document.documentElement.style.getPropertyValue('--site-accent') !== accent) {
                document.documentElement.style.setProperty('--site-accent', accent);
            }
            let themeRules = document.getElementById('site-editor-theme-rules');
            if (!themeRules) {
                themeRules = document.createElement('style');
                themeRules.id = 'site-editor-theme-rules';
                document.head.append(themeRules);
            }
            const rules = '.text-accentBrown, .hover\\:text-accentBrown:hover { color: var(--site-accent) !important; } .bg-accentBrown { background-color: var(--site-accent) !important; }';
            if (themeRules.textContent !== rules) themeRules.textContent = rules;
        }
        data.overrides.forEach(applyOverride);
    }

    function describe(element) {
        return {
            selector: selectPath(element),
            tag: element.tagName.toLowerCase(),
            text: element.textContent.trim().slice(0, 2000),
            src: element.tagName === 'IMG' ? element.getAttribute('src') || '' : '',
            href: element.tagName === 'A' ? element.getAttribute('href') || '' : '',
            colors: {
                color: getComputedStyle(element).color,
                'background-color': getComputedStyle(element).backgroundColor,
                'border-color': getComputedStyle(element).borderColor
            }
        };
    }

    window.addEventListener('message', event => {
        if (event.origin !== location.origin || !event.data) return;
        if (event.data.type === 'site-editor-mode') {
            editing = event.data.enabled === true;
            if (!editing && selectedElement) {
                selectedElement.style.outline = '';
                selectedElement = null;
            }
        } else if (event.data.type === 'site-editor-highlight'
            && typeof event.data.selector === 'string') {
            try {
                const next = document.querySelector(event.data.selector);
                if (selectedElement) selectedElement.style.outline = '';
                selectedElement = next;
                if (selectedElement) selectedElement.style.outline = '3px solid #2563eb';
            } catch (_error) {
                selectedElement = null;
            }
        } else if (event.data.type === 'site-editor-apply' && event.data.item) {
            if (!siteContent || !Array.isArray(siteContent.overrides)) return;
            siteContent.overrides = siteContent.overrides.filter(item =>
                !(item.page === event.data.item.page
                    && item.selector === event.data.item.selector
                    && item.type === event.data.item.type
                    && (item.type !== 'color' || item.property === event.data.item.property)));
            siteContent.overrides.push(event.data.item);
            applyOverride(event.data.item);
        } else if (event.data.type === 'site-editor-remove'
            && event.data.page === pageName && typeof event.data.selector === 'string') {
            if (!siteContent || !Array.isArray(siteContent.overrides)) return;
            siteContent.overrides = siteContent.overrides.filter(item =>
                !(item.page === event.data.page && item.selector === event.data.selector));
            const prefix = [event.data.page, event.data.selector];
            originalValues.forEach((value, key) => {
                const parts = key.split('|');
                if (parts[0] !== prefix[0] || parts[1] !== prefix[1]) return;
                let element;
                try {
                    element = document.querySelector(event.data.selector);
                } catch (_error) {
                    return;
                }
                if (!element) return;
                const type = parts[2];
                if (type === 'text') element.textContent = value;
                else if (type === 'image') {
                    if (value === null) element.removeAttribute('src');
                    else element.setAttribute('src', value);
                } else if (type === 'link') {
                    element.textContent = value.text;
                    if (value.href === null) element.removeAttribute('href');
                    else element.setAttribute('href', value.href);
                } else if (type === 'color') {
                    if (value.value) element.style.setProperty(parts[3], value.value, value.priority);
                    else element.style.removeProperty(parts[3]);
                } else if (type === 'list') {
                    element.replaceChildren(...value.map(node => node.cloneNode(true)));
                }
                originalValues.delete(key);
            });
        }
    });

    document.addEventListener('click', event => {
        if (!editing || !window.parent || window.parent === window) return;
        const target = event.target instanceof Element ? event.target : event.target.parentElement;
        if (!target) return;
        const element = target.closest('ul, ol')
            || target.closest('img, a, h1, h2, h3, h4, h5, h6, p, span, button, label, li, div, section');
        if (!element || element === document.body || element === document.documentElement) return;
        event.preventDefault();
        event.stopPropagation();
        if (selectedElement) selectedElement.style.outline = '';
        selectedElement = element;
        selectedElement.style.outline = '3px solid #2563eb';
        window.parent.postMessage({ type: 'site-editor-select', element: describe(element) }, location.origin);
    }, true);

    async function initialize() {
        if (!siteContent) {
            const response = await fetch('data.json', { cache: 'no-store' });
            if (!response.ok) throw new Error('Unable to load site data.');
            siteContent = await response.json();
        }
        applyContent(siteContent);
        new MutationObserver(() => applyContent(siteContent)).observe(document.body, {
            childList: true,
            subtree: true
        });
        if (isEditorPreview) document.documentElement.classList.add('site-editor-preview');
    }

    initialize().catch(error => console.error('Site content could not be applied.', error));
})();
