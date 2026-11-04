<?php
declare(strict_types=1);

$secureCookie = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly' => true,
    'secure' => $secureCookie,
    'samesite' => 'Strict',
    'path' => '/'
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, private');

const DASHBOARD_PIN = '3350';
const DATA_FILE = __DIR__ . '/data.json';
const MAX_UPLOAD_BYTES = 5242880;

$allowedPages = ['index.html', 'products.html', 'product.html', 'customize.html', 'contact.html', 'gallery.html'];

if (empty($_SESSION['dashboard_csrf'])) {
    $_SESSION['dashboard_csrf'] = bin2hex(random_bytes(32));
}

function dashboardJson(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function requireDashboardAccess(): void
{
    if (empty($_SESSION['dashboard_authenticated'])) {
        dashboardJson(401, ['error' => 'انتهت الجلسة. افتح لوحة التحكم وسجّل رمز الدخول مجدداً.']);
    }

    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($provided) || !hash_equals((string) $_SESSION['dashboard_csrf'], $provided)) {
        dashboardJson(403, ['error' => 'تعذر التحقق من الطلب. حدّث الصفحة ثم حاول مجدداً.']);
    }
}

function validAssetUrl($value): bool
{
    if (!is_string($value) || strlen($value) > 1000) {
        return false;
    }

    $value = trim($value);
    if (preg_match('~^https?://~i', $value)) {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }
    if (preg_match('~^(mailto:|tel:|#)~i', $value)) {
        return preg_match('~^[A-Za-z0-9:#?&=._%+@/-]+$~', $value) === 1;
    }
    if (isset($value[0]) && $value[0] === '/' && substr($value, 0, 2) !== '//') {
        return preg_match('~^/[A-Za-z0-9_./?&=%#-]*$~', $value) === 1
            && !preg_match('~(?:^|/)\.\.(?:/|$)~', $value);
    }

    return preg_match('~^(?!.*(?:^|/)\.\.(?:/|$))[A-Za-z0-9_./-]+$~', $value) === 1;
}

function validEditorSelector($value): bool
{
    if (!is_string($value) || strlen($value) > 500) {
        return false;
    }

    return preg_match(
        '~^(?:#[A-Za-z][A-Za-z0-9_-]*|body(?: > [a-z][a-z0-9]*(?::nth-of-type\([1-9][0-9]*\))?)+)$~',
        $value
    ) === 1;
}

function writeSiteData(array $data): bool
{
    $encoded = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if (!is_string($encoded)) {
        return false;
    }

    $temporaryPath = tempnam(__DIR__, '.site-data-');
    if ($temporaryPath === false) {
        return false;
    }

    $written = file_put_contents($temporaryPath, $encoded . PHP_EOL, LOCK_EX);
    if ($written === false || !rename($temporaryPath, DATA_FILE)) {
        @unlink($temporaryPath);
        return false;
    }

    return true;
}

$action = $_GET['action'] ?? '';

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $attempts = (int) ($_SESSION['dashboard_login_attempts'] ?? 0);
    $lockedUntil = (int) ($_SESSION['dashboard_locked_until'] ?? 0);
    if ($lockedUntil > time()) {
        $_SESSION['dashboard_login_error'] = 'محاولات كثيرة. انتظر قليلاً ثم حاول مرة أخرى.';
        header('Location: dashboard.php');
        exit;
    }

    $csrf = $_POST['csrf'] ?? '';
    $pin = $_POST['pin'] ?? '';
    if (!is_string($csrf) || !hash_equals((string) $_SESSION['dashboard_csrf'], $csrf)) {
        $_SESSION['dashboard_login_error'] = 'انتهت صلاحية النموذج. حاول مرة أخرى.';
    } elseif (is_string($pin) && hash_equals(DASHBOARD_PIN, $pin)) {
        session_regenerate_id(true);
        $_SESSION['dashboard_authenticated'] = true;
        $_SESSION['dashboard_login_attempts'] = 0;
        $_SESSION['dashboard_csrf'] = bin2hex(random_bytes(32));
        header('Location: dashboard.php');
        exit;
    } else {
        $attempts++;
        $_SESSION['dashboard_login_attempts'] = $attempts;
        if ($attempts >= 5) {
            $_SESSION['dashboard_locked_until'] = time() + 300;
            $_SESSION['dashboard_login_attempts'] = 0;
        }
        $_SESSION['dashboard_login_error'] = 'الرمز السري غير صحيح.';
    }

    header('Location: dashboard.php');
    exit;
}

if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if (is_string($csrf) && hash_equals((string) $_SESSION['dashboard_csrf'], $csrf)) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['dashboard_csrf'] = bin2hex(random_bytes(32));
    }
    header('Location: dashboard.php');
    exit;
}

if ($action !== '') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $action !== 'load') {
        dashboardJson(405, ['error' => 'طريقة الطلب غير مدعومة.']);
    }
    if ($action !== 'login') {
        requireDashboardAccess();
    }

    if ($action === 'load') {
        $data = is_file(DATA_FILE) ? json_decode((string) file_get_contents(DATA_FILE), true) : null;
        if (!is_array($data)) {
            dashboardJson(500, ['error' => 'تعذر قراءة data.json أو أن محتواه غير صالح.']);
        }
        dashboardJson(200, ['data' => $data, 'csrf' => $_SESSION['dashboard_csrf']]);
    }

    if ($action === 'save') {
        $raw = file_get_contents('php://input');
        $payload = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
            dashboardJson(400, ['error' => 'صيغة البيانات غير صحيحة.']);
        }

        $data = $payload['data'];
        $theme = $data['theme'] ?? [];
        $overrides = $data['overrides'] ?? [];
        if (!is_array($theme) || !is_array($overrides) || count($overrides) > 500) {
            dashboardJson(400, ['error' => 'عدد التعديلات أو إعدادات الألوان غير صالح.']);
        }

        $cleanTheme = [];
        foreach (['background', 'text', 'accent'] as $key) {
            $color = $theme[$key] ?? '';
            if (!is_string($color) || !preg_match('/^#[0-9a-f]{6}$/i', $color)) {
                dashboardJson(400, ['error' => 'اختر ألواناً بصيغة HEX صحيحة.']);
            }
            $cleanTheme[$key] = strtoupper($color);
        }

        $cleanOverrides = [];
        foreach ($overrides as $item) {
            if (!is_array($item)
                || !in_array($item['page'] ?? '', $allowedPages, true)
                || !validEditorSelector($item['selector'] ?? null)) {
                dashboardJson(400, ['error' => 'تعذر حفظ أحد التعديلات: الصفحة أو العنصر غير صالح.']);
            }

            $type = $item['type'] ?? '';
            $value = $item['value'] ?? null;
            $clean = [
                'page' => $item['page'],
                'selector' => $item['selector'],
                'type' => $type
            ];

            if ($type === 'text' && is_string($value) && strlen($value) <= 4000) {
                $clean['value'] = $value;
            } elseif ($type === 'image' && validAssetUrl($value)) {
                $clean['value'] = trim($value);
            } elseif ($type === 'link' && is_array($value)
                && isset($value['text'], $value['href'])
                && is_string($value['text']) && strlen($value['text']) <= 1000
                && validAssetUrl($value['href'])) {
                $clean['value'] = ['text' => $value['text'], 'href' => trim($value['href'])];
            } elseif ($type === 'color' && is_string($value)
                && preg_match('/^#[0-9a-f]{6}$/i', $value)
                && in_array($item['property'] ?? '', ['color', 'background-color', 'border-color'], true)) {
                $clean['value'] = strtoupper($value);
                $clean['property'] = $item['property'];
            } elseif ($type === 'list' && is_array($value) && count($value) <= 100) {
                foreach ($value as $listItem) {
                    if (!is_string($listItem) || strlen($listItem) > 500) {
                        dashboardJson(400, ['error' => 'كل عنصر في القائمة يجب أن يكون نصاً قصيراً.']);
                    }
                }
                $clean['value'] = array_values($value);
            } else {
                dashboardJson(400, ['error' => 'قيمة أحد التعديلات غير صالحة.']);
            }

            $cleanOverrides[] = $clean;
        }

        if (!writeSiteData(['theme' => $cleanTheme, 'overrides' => $cleanOverrides])) {
            dashboardJson(500, ['error' => 'تعذر حفظ data.json. تأكد من صلاحيات الكتابة للمجلد.']);
        }
        dashboardJson(200, ['ok' => true]);
    }

    if ($action === 'upload') {
        if (!isset($_FILES['image']) || !is_array($_FILES['image'])
            || $_FILES['image']['error'] !== UPLOAD_ERR_OK
            || $_FILES['image']['size'] > MAX_UPLOAD_BYTES
            || !is_uploaded_file($_FILES['image']['tmp_name'])) {
            dashboardJson(400, ['error' => 'اختر صورة صالحة لا يتجاوز حجمها 5 ميجابايت.']);
        }

        $imageInfo = getimagesize($_FILES['image']['tmp_name']);
        $mime = is_array($imageInfo) ? ($imageInfo['mime'] ?? '') : '';
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime])) {
            dashboardJson(400, ['error' => 'الصيغ المدعومة هي JPG وPNG وWebP فقط.']);
        }

        $uploadDirectory = __DIR__ . '/uploads/editor';
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
            dashboardJson(500, ['error' => 'تعذر إنشاء مجلد الصور.']);
        }

        try {
            $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        } catch (Throwable $error) {
            dashboardJson(500, ['error' => 'تعذر تجهيز اسم آمن للصورة.']);
        }

        $destination = $uploadDirectory . '/' . $filename;
        if (!move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
            dashboardJson(500, ['error' => 'تعذر حفظ الصورة المرفوعة.']);
        }

        dashboardJson(200, ['path' => 'uploads/editor/' . $filename]);
    }

    dashboardJson(404, ['error' => 'الطلب غير معروف.']);
}

$authenticated = !empty($_SESSION['dashboard_authenticated']);
$csrf = htmlspecialchars((string) $_SESSION['dashboard_csrf'], ENT_QUOTES, 'UTF-8');
$loginError = htmlspecialchars((string) ($_SESSION['dashboard_login_error'] ?? ''), ENT_QUOTES, 'UTF-8');
unset($_SESSION['dashboard_login_error']);
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>لوحة تعديل الموقع | Costarica Gallery</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; color: #362922; background: #f3eee6; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; }
        button, input, select, textarea { font: inherit; }
        button { cursor: pointer; }
        .login { width: min(420px, calc(100% - 32px)); margin: 12vh auto; padding: 28px; background: white; border-radius: 18px; box-shadow: 0 12px 40px #36292220; }
        .login h1 { margin-top: 0; }
        .layout { display: grid; grid-template-columns: minmax(300px, 370px) 1fr; min-height: 100vh; }
        aside { padding: 20px; background: white; border-left: 1px solid #ddd2c4; overflow: auto; max-height: 100vh; }
        main { padding: 16px; min-width: 0; }
        .toolbar, .row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        label { display: block; font-size: 14px; font-weight: 650; margin: 14px 0 6px; }
        input:not([type=color]), select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #cbbba8; border-radius: 9px; background: #fff; }
        input[type=color] { width: 48px; height: 40px; border: 0; background: none; }
        textarea { min-height: 130px; resize: vertical; }
        button, .button { border: 0; border-radius: 9px; padding: 10px 14px; background: #362922; color: white; }
        button.secondary { background: #ebe1d3; color: #362922; }
        button.danger { background: #9c2f2f; }
        .note { color: #6b625b; font-size: 13px; line-height: 1.6; }
        .error { color: #a32121; margin-top: 12px; }
        .status { min-height: 24px; margin-top: 12px; font-weight: 650; }
        .preview { width: 100%; height: calc(100vh - 32px); background: white; border: 1px solid #cbbba8; border-radius: 12px; }
        .group { padding: 14px 0; border-top: 1px solid #e8dfd5; }
        .hidden { display: none !important; }
        @media (max-width: 850px) {
            .layout { grid-template-columns: 1fr; }
            aside { max-height: none; }
            .preview { height: 70vh; }
        }
    </style>
</head>
<body>
<?php if (!$authenticated): ?>
    <form class="login" method="post" action="?action=login" autocomplete="off">
        <h1>تعديل الموقع</h1>
        <p class="note">أدخل رمز الإدارة للمتابعة. لا تشارك الرمز مع أحد.</p>
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <label for="pin">الرمز السري</label>
        <input id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" required autofocus>
        <?php if ($loginError !== ''): ?><p class="error"><?= $loginError ?></p><?php endif; ?>
        <button type="submit" style="margin-top:16px;width:100%">دخول</button>
    </form>
<?php else: ?>
    <div class="layout" id="editor" data-csrf="<?= $csrf ?>">
        <aside>
            <h1>لوحة تعديل الموقع</h1>
            <p class="note">اختر صفحة، ثم انقر على عنصر في المعاينة لتعديله. يتم حفظ تغييرات النصوص والصور والروابط والألوان والقوائم في data.json.</p>
            <div class="group">
                <label for="page">الصفحة</label>
                <select id="page">
                    <option value="index.html">الرئيسية</option>
                    <option value="products.html">المنتجات</option>
                    <option value="product.html">تفاصيل المنتج</option>
                    <option value="customize.html">صمم هديتك</option>
                    <option value="contact.html">تواصل معنا</option>
                    <option value="gallery.html">السياسات والمعرض</option>
                </select>
                <p class="note">انقر على النص أو الصورة أو الرابط المطلوب داخل المعاينة.</p>
                <div id="selection" class="note">لم يتم اختيار عنصر بعد.</div>
            </div>
            <div class="group">
                <label for="textValue">النص</label>
                <textarea id="textValue" placeholder="اكتب النص الجديد"></textarea>
                <div id="linkFields" class="hidden">
                    <label for="linkValue">رابط العنصر</label>
                    <input id="linkValue" type="url" placeholder="https://example.com أو مسار داخلي">
                </div>
                <div id="imageFields" class="hidden">
                    <label for="imageFile">استبدال الصورة</label>
                    <input id="imageFile" type="file" accept="image/jpeg,image/png,image/webp">
                    <p class="note">JPG أو PNG أو WebP، بحد أقصى 5 ميجابايت.</p>
                </div>
                <div id="listFields" class="hidden">
                    <p class="note">عنصر واحد في كل سطر. للرابط اكتب: النص | URL. تُنشأ العناصر كنص آمن.</p>
                </div>
                <div id="colorFields" class="row">
                    <label for="colorProperty" style="flex:1">لون العنصر</label>
                    <select id="colorProperty" style="max-width:150px">
                        <option value="color">لون النص</option>
                        <option value="background-color">لون الخلفية</option>
                        <option value="border-color">لون الحدود</option>
                    </select>
                    <input id="elementColor" type="color" value="#362922" aria-label="لون العنصر">
                </div>
                <div class="row" style="margin-top:12px">
                    <button id="applyOverride" type="button">تطبيق التعديل</button>
                    <button id="removeOverride" type="button" class="secondary">إزالة تعديل العنصر</button>
                </div>
            </div>
            <div class="group">
                <strong>ألوان الموقع</strong>
                <div class="row">
                    <label for="themeBackground">الخلفية</label><input id="themeBackground" type="color">
                    <label for="themeText">النص</label><input id="themeText" type="color">
                    <label for="themeAccent">التأكيد</label><input id="themeAccent" type="color">
                </div>
            </div>
            <div class="row">
                <button id="save" type="button">حفظ كل التغييرات</button>
                <form method="post" action="?action=logout">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <button type="submit" class="secondary">خروج</button>
                </form>
            </div>
            <div id="status" class="status" role="status"></div>
            <p class="note">ملاحظة: PIN من 4 أرقام وسيلة حماية بسيطة وليس مناسباً لموقع حساس. استخدم HTTPS ولا تضع بيانات سرية في الموقع.</p>
        </aside>
        <main>
            <iframe id="preview" class="preview" title="معاينة الموقع في وضع التعديل"></iframe>
        </main>
    </div>
    <script>
        (() => {
            'use strict';
            const root = document.getElementById('editor');
            const csrf = root.dataset.csrf;
            const pageSelect = document.getElementById('page');
            const frame = document.getElementById('preview');
            const status = document.getElementById('status');
            const selectedInfo = document.getElementById('selection');
            const textValue = document.getElementById('textValue');
            const linkValue = document.getElementById('linkValue');
            const imageFile = document.getElementById('imageFile');
            const elementColor = document.getElementById('elementColor');
            const colorProperty = document.getElementById('colorProperty');
            const linkFields = document.getElementById('linkFields');
            const imageFields = document.getElementById('imageFields');
            const listFields = document.getElementById('listFields');
            const state = { theme: {}, overrides: [] };
            let selected = null;
            let selectedType = 'text';

            function setStatus(message, isError) {
                status.textContent = message;
                status.style.color = isError ? '#a32121' : '#276749';
            }

            function setFramePage() {
                selected = null;
                selectedInfo.textContent = 'لم يتم اختيار عنصر بعد.';
                frame.src = pageSelect.value === 'index.html'
                    ? 'index.php?editor-preview=1'
                    : pageSelect.value + '?editor-preview=1';
                frame.onload = () => frame.contentWindow.postMessage(
                    { type: 'site-editor-mode', enabled: true },
                    location.origin
                );
            }

            async function loadData() {
                const response = await fetch('?action=load', {
                    cache: 'no-store',
                    headers: { 'X-CSRF-Token': csrf }
                });
                const result = await response.json();
                if (!response.ok) throw new Error(result.error || 'تعذر تحميل بيانات الموقع.');
                state.theme = result.data.theme || {};
                state.overrides = result.data.overrides || [];
                document.getElementById('themeBackground').value = state.theme.background || '#D5C2A5';
                document.getElementById('themeText').value = state.theme.text || '#362922';
                document.getElementById('themeAccent').value = state.theme.accent || '#4A3B32';
            }

            window.addEventListener('message', event => {
                if (event.origin !== location.origin || event.source !== frame.contentWindow
                    || !event.data || event.data.type !== 'site-editor-select') return;

                selected = event.data.element;
                selectedType = selected.tag === 'img' ? 'image'
                    : selected.tag === 'a' ? 'link'
                    : ['ul', 'ol'].includes(selected.tag) ? 'list' : 'text';
                selectedInfo.textContent = selected.tag.toUpperCase() + ' — ' + selected.selector;
                textValue.value = selectedType === 'list'
                    ? Array.from(frame.contentDocument.querySelector(selected.selector).children)
                        .map(item => {
                            const anchor = item.querySelector('a');
                            return anchor ? item.textContent.trim() + ' | ' + anchor.getAttribute('href') : item.textContent.trim();
                        }).join('\n')
                    : selected.text;
                linkValue.value = selected.href || '';
                linkFields.classList.toggle('hidden', selectedType !== 'link');
                imageFields.classList.toggle('hidden', selectedType !== 'image');
                listFields.classList.toggle('hidden', selectedType !== 'list');
                textValue.disabled = selectedType === 'image';
                const color = selected.colors?.[colorProperty.value];
                const match = typeof color === 'string' ? color.match(/^#[0-9a-f]{6}$/i) : null;
                elementColor.value = match ? match[0] : '#362922';
            });

            document.getElementById('applyOverride').addEventListener('click', async () => {
                if (!selected) {
                    setStatus('اختر عنصراً من المعاينة أولاً.', true);
                    return;
                }
                let item;
                if (selectedType === 'image') {
                    if (!imageFile.files.length) {
                        setStatus('اختر صورة لرفعها أولاً.', true);
                        return;
                    }
                    try {
                        const formData = new FormData();
                        formData.append('image', imageFile.files[0]);
                        const upload = await fetch('?action=upload', {
                            method: 'POST',
                            headers: { 'X-CSRF-Token': csrf },
                            body: formData
                        });
                        const result = await upload.json();
                        if (!upload.ok) throw new Error(result.error || 'تعذر رفع الصورة.');
                        item = { type: 'image', value: result.path };
                        imageFile.value = '';
                    } catch (error) {
                        setStatus(error.message || 'تعذر رفع الصورة.', true);
                        return;
                    }
                } else if (selectedType === 'link') {
                    item = { type: 'link', value: { text: textValue.value, href: linkValue.value } };
                } else if (selectedType === 'list') {
                    item = { type: 'list', value: textValue.value.split(/\r?\n/).map(value => value.trim()).filter(Boolean) };
                } else {
                    item = { type: 'text', value: textValue.value };
                }
                const record = { page: pageSelect.value, selector: selected.selector, ...item };
                state.overrides = state.overrides.filter(existing => !(existing.page === record.page
                    && existing.selector === record.selector && existing.type === record.type));
                state.overrides.push(record);
                frame.contentWindow.postMessage({
                    type: 'site-editor-apply',
                    item: { ...record, page: pageSelect.value }
                }, location.origin);
                frame.contentWindow.postMessage({ type: 'site-editor-highlight', selector: selected.selector }, location.origin);
                setStatus('تم تطبيق التعديل في المعاينة. اضغط «حفظ كل التغييرات» لتثبيته.', false);
            });

            document.getElementById('removeOverride').addEventListener('click', () => {
                if (!selected) return;
                state.overrides = state.overrides.filter(item =>
                    !(item.page === pageSelect.value && item.selector === selected.selector));
                frame.contentWindow.postMessage({
                    type: 'site-editor-remove',
                    page: pageSelect.value,
                    selector: selected.selector
                }, location.origin);
                setStatus('أزيلت تعديلات العنصر. احفظ التغييرات لتثبيت الإزالة.', false);
            });

            colorProperty.addEventListener('change', () => {
                if (!selected) return;
                const color = selected.colors?.[colorProperty.value];
                const match = typeof color === 'string' ? color.match(/^#[0-9a-f]{6}$/i) : null;
                elementColor.value = match ? match[0] : '#362922';
            });

            elementColor.addEventListener('change', () => {
                if (!selected) return;
                const record = {
                    page: pageSelect.value,
                    selector: selected.selector,
                    type: 'color',
                    property: colorProperty.value,
                    value: elementColor.value
                };
                state.overrides = state.overrides.filter(item =>
                    !(item.page === record.page && item.selector === record.selector
                        && item.type === 'color' && item.property === record.property));
                state.overrides.push(record);
                frame.contentWindow.postMessage({ type: 'site-editor-apply', item: record }, location.origin);
                frame.contentWindow.postMessage({ type: 'site-editor-highlight', selector: selected.selector }, location.origin);
                setStatus('تم تطبيق اللون في المعاينة. اضغط «حفظ كل التغييرات» لتثبيته.', false);
            });

            document.getElementById('save').addEventListener('click', async () => {
                state.theme = {
                    background: document.getElementById('themeBackground').value,
                    text: document.getElementById('themeText').value,
                    accent: document.getElementById('themeAccent').value
                };
                try {
                    const response = await fetch('?action=save', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                        body: JSON.stringify({ data: state })
                    });
                    const result = await response.json();
                    if (!response.ok) throw new Error(result.error || 'تعذر حفظ التغييرات.');
                    setStatus('تم حفظ التغييرات في data.json.', false);
                    setFramePage();
                } catch (error) {
                    setStatus(error.message, true);
                }
            });

            pageSelect.addEventListener('change', setFramePage);
            Promise.all([loadData()]).then(setFramePage).catch(error => setStatus(error.message, true));
        })();
    </script>
<?php endif; ?>
</body>
</html>
