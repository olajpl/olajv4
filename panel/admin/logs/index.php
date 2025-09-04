<?php
// admin/logs/index.php
// 1. opis czynności lub funkcji
// Panel „Mocne Logi”: filtry listy + prosty panel ustawień (suadmin).
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

require_once __DIR__ . '/../../layout/layout_header.php';

// 2. opis czynności: detekcja suadmina (fallback, jeśli nie masz helpera)
$is_suadmin = false;
if (function_exists('is_suadmin')) {
    $is_suadmin = is_suadmin();
} else {
    $role = strtolower($_SESSION['user']['role'] ?? '');
    $is_suadmin = in_array($role, ['superadmin', 'suadmin'], true); // akceptuj oba
}
?>
<div class="max-w-7xl mx-auto p-6 space-y-6">

    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold">🧱 Mocne Logi</h1>
        <div class="text-xs text-gray-500">
            rola: <code><?= htmlspecialchars($_SESSION['user']['role'] ?? 'n/a') ?></code>
            <?php if ($is_suadmin): ?>
                <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded bg-black text-white text-[10px]">SUADMIN</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- 3. opis czynności: Filtry listy -->
    <form id="logFilters" class="grid md:grid-cols-6 gap-3">
        <input type="date" name="from" class="border rounded px-3 py-2" />
        <input type="date" name="to" class="border rounded px-3 py-2" />
        <select name="level" class="border rounded px-3 py-2">
  <option value="">level (wszystkie)</option>
  <option>debug</option>
  <option>info</option>
  <option>warning</option>
  <option>error</option>
</select>

        <input name="channel" placeholder="channel" class="border rounded px-3 py-2" />
        <input name="request_id" placeholder="request_id" class="border rounded px-3 py-2" />
        <input name="q" placeholder="szukaj w message" class="border rounded px-3 py-2" />
    </form>

    <!-- 4. opis czynności: Lista -->
    <div id="logTable" class="bg-white rounded-xl shadow border">
        <div class="p-3 text-sm text-gray-600">Ładowanie…</div>
    </div>

    <!-- 5. opis czynności: Ustawienia globalne (suadmin only) -->
    <?php if ($is_suadmin): ?>
        <div class="bg-white rounded-xl shadow border">
            <div class="p-4 border-b">
                <h2 class="text-lg font-semibold">⚙️ Ustawienia logowania (global)</h2>
                <div class="text-sm text-gray-500">Włącz/wyłącz logowanie do bazy i pliku, ustaw próg, retencję.</div>
            </div>
            <form id="logSettings" class="p-4 grid md:grid-cols-5 gap-3">
                <label class="flex items-center gap-2"><input type="checkbox" name="enabled_db" /> DB</label>
                <label class="flex items-center gap-2"><input type="checkbox" name="enabled_file" /> Plik</label>
                <select name="min_level" class="border rounded px-3 py-2">
                    <option>debug</option>
                    <option selected>info</option>
                    <option>notice</option>
                    <option>warning</option>
                    <option>error</option>
                    <option>critical</option>
                    <option>alert</option>
                    <option>emergency</option>
                </select>
                <input type="number" min="1" name="retention_days" class="border rounded px-3 py-2" placeholder="retencja (dni)" />
                <input name="file_path" class="border rounded px-3 py-2" placeholder="../var/app.log" />
                <div class="md:col-span-5">
                    <button id="btnSave" class="px-4 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700">Zapisz</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

</div>

<script>
    (function() {
        // 6. opis czynności: Ajax lista
        const tableEl = document.querySelector('#logTable');
        const form = document.querySelector('#logFilters');

        function serialize(f) {
            return new URLSearchParams(new FormData(f)).toString();
        }
        async function loadList(page = 1) {
            const params = new URLSearchParams(new FormData(form));
            params.set('page', page);
            tableEl.innerHTML = '<div class="p-3 text-sm text-gray-600">Ładowanie…</div>';
            const res = await fetch('ajax_list.php?' + params.toString());
            tableEl.innerHTML = await res.text();
        }
        form.addEventListener('change', () => loadList(1));
        form.addEventListener('input', () => {
            clearTimeout(window._flt);
            window._flt = setTimeout(() => loadList(1), 250);
        });
        loadList();

        // 7. opis czynności: Ustawienia (load/save)
        const settingsForm = document.querySelector('#logSettings');
        if (settingsForm) {
            async function loadSettings() {
                const r = await fetch('ajax_settings.php?mode=load');
                const d = await r.json();
                settingsForm.querySelector('[name=enabled_db]').checked = !!(+d.enabled_db || 0);
                settingsForm.querySelector('[name=enabled_file]').checked = !!(+d.enabled_file || 0);
                settingsForm.querySelector('[name=min_level]').value = d.min_level || 'info';
                settingsForm.querySelector('[name=retention_days]').value = d.retention_days || 30;
                settingsForm.querySelector('[name=file_path]').value = d.file_path || '../var/app.log';
            }
            loadSettings();

            settingsForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const body = new FormData(settingsForm);
                const r = await fetch('ajax_settings.php', {
                    method: 'POST',
                    body
                });
                const d = await r.json();
                if (d.ok) {
                    alert('Zapisano.');
                } else {
                    alert('Błąd: ' + (d.error || 'unknown'));
                }
            });

            // „Zapisz” po kliknięciu przycisku
            document.querySelector('#btnSave')?.addEventListener('click', e => {
                e.preventDefault();
                settingsForm.dispatchEvent(new Event('submit'));
            });
        }

        // 8. opis czynności: paginacja z listy (rebind per load)
        window.pageGo = (p) => loadList(p);
        window.showLogDetails = async (id) => {
            const html = await (await fetch('ajax_details.php?id=' + id)).text();
            const div = document.createElement('div');
            div.className = 'fixed inset-0 bg-black/40 flex';
            div.innerHTML = '<div class="m-auto bg-white rounded-xl shadow-xl max-w-3xl w-full">' + html + '</div>';
            div.addEventListener('click', e => {
                if (e.target === div) div.remove();
            });
            document.body.appendChild(div);
        };
    })();
</script>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>