@php
    $mpTableExists = \Abedin\MultiPay\Support\GatewayConfigStore::tableExists();
    $mpGateways = $mpTableExists ? \Abedin\MultiPay\Support\GatewayConfigStore::all() : [];
    $mpAs = config('multipay.internal_routes.as', 'multipay.internal.');
@endphp

<div class="mp-admin" id="mp-admin">
    <style>
        .mp-admin { font-family: inherit; color: inherit; }
        .mp-admin * { box-sizing: border-box; }
        .mp-admin .mp-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; gap: 12px; flex-wrap: wrap; }
        .mp-admin .mp-title { font-size: 20px; font-weight: 700; margin: 0; }
        .mp-admin .mp-note { font-size: 13px; color: #6b7280; margin: 4px 0 0; }
        .mp-admin .mp-alert { padding: 14px 16px; border-radius: 10px; background: #fef2f2; color: #b91c1c; font-size: 14px; }
        .mp-admin .mp-toast { position: fixed; right: 20px; bottom: 20px; z-index: 10000; padding: 12px 18px; border-radius: 10px; color: #fff; font-size: 14px; box-shadow: 0 10px 30px rgba(0,0,0,.2); opacity: 0; transform: translateY(8px); transition: .25s; pointer-events: none; }
        .mp-admin .mp-toast.mp-show { opacity: 1; transform: none; }
        .mp-admin .mp-toast.mp-ok { background: #047857; }
        .mp-admin .mp-toast.mp-err { background: #b91c1c; }
        .mp-admin .mp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
        .mp-admin .mp-card { border: 1px solid #e5e7eb; border-radius: 14px; padding: 16px; background: #fff; display: flex; flex-direction: column; gap: 12px; }
        .mp-admin .mp-card-head { display: flex; align-items: center; gap: 10px; }
        .mp-admin .mp-icon { width: 38px; height: 38px; border-radius: 10px; background: #f3f4f6; display: flex; align-items: center; justify-content: center; overflow: hidden; flex: none; font-weight: 700; color: #6b7280; }
        .mp-admin .mp-icon img, .mp-admin .mp-icon svg { width: 100%; height: 100%; object-fit: contain; display: block; padding: 4px; }
        .mp-admin .mp-name { font-weight: 700; font-size: 15px; }
        .mp-admin .mp-key { font-size: 12px; color: #9ca3af; }
        .mp-admin .mp-badges { display: flex; gap: 6px; flex-wrap: wrap; }
        .mp-admin .mp-badge { font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 999px; }
        .mp-admin .mp-badge.mp-on { background: #ecfdf5; color: #047857; }
        .mp-admin .mp-badge.mp-off { background: #f3f4f6; color: #6b7280; }
        .mp-admin .mp-badge.mp-warn { background: #fffbeb; color: #b45309; }
        .mp-admin .mp-actions { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: auto; }
        .mp-admin .mp-btn { border: 1px solid #e5e7eb; background: #fff; color: #111827; border-radius: 9px; padding: 8px 14px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .mp-admin .mp-btn:hover { background: #f9fafb; }
        .mp-admin .mp-switch { position: relative; width: 44px; height: 24px; flex: none; cursor: pointer; }
        .mp-admin .mp-switch input { opacity: 0; width: 0; height: 0; }
        .mp-admin .mp-slider { position: absolute; inset: 0; background: #d1d5db; border-radius: 999px; transition: .2s; }
        .mp-admin .mp-slider:before { content: ""; position: absolute; width: 18px; height: 18px; left: 3px; top: 3px; background: #fff; border-radius: 50%; transition: .2s; }
        .mp-admin .mp-switch input:checked + .mp-slider { background: #047857; }
        .mp-admin .mp-switch input:checked + .mp-slider:before { transform: translateX(20px); }
        .mp-admin .mp-modal-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,.55); z-index: 9998; display: none; }
        .mp-admin .mp-modal { position: fixed; inset: 0; z-index: 9999; display: none; align-items: center; justify-content: center; padding: 20px; }
        .mp-admin.mp-modal-open .mp-modal, .mp-admin.mp-modal-open .mp-modal-backdrop { display: flex; }
        .mp-admin .mp-modal-card { background: #fff; border-radius: 16px; width: 100%; max-width: 480px; max-height: 85vh; display: flex; flex-direction: column; box-shadow: 0 24px 70px rgba(15,23,42,.25); }
        .mp-admin .mp-modal-head { display: flex; align-items: center; justify-content: space-between; padding: 18px 20px; border-bottom: 1px solid #f3f4f6; }
        .mp-admin .mp-modal-title { font-size: 16px; font-weight: 700; margin: 0; }
        .mp-admin .mp-close { border: 0; background: none; font-size: 20px; cursor: pointer; color: #9ca3af; line-height: 1; }
        .mp-admin .mp-modal-body { padding: 18px 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 12px; }
        .mp-admin .mp-field label { display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 5px; }
        .mp-admin .mp-field small { color: #9ca3af; font-weight: 400; }
        .mp-admin .mp-field input { width: 100%; border: 1px solid #e5e7eb; border-radius: 9px; padding: 9px 12px; font-size: 13px; font-family: ui-monospace, monospace; }
        .mp-admin .mp-field input:focus { outline: 2px solid #04785733; border-color: #047857; }
        .mp-admin .mp-modal-foot { padding: 14px 20px; border-top: 1px solid #f3f4f6; display: flex; justify-content: flex-end; gap: 10px; }
        .mp-admin .mp-btn.mp-primary { background: #111827; border-color: #111827; color: #fff; }
        .mp-admin .mp-btn.mp-primary:disabled { opacity: .6; cursor: wait; }
        .mp-admin .mp-empty-fields { font-size: 13px; color: #6b7280; }
    </style>

    <div class="mp-head">
        <div>
            <h2 class="mp-title">Payment Gateways</h2>
            <p class="mp-note">Toggle a gateway on/off and manage its credentials. Changes apply instantly — no deploy needed.</p>
        </div>
    </div>

    @if (!$mpTableExists)
        <div class="mp-alert">
            The <code>{{ config('multipay.data_table', 'gateways') }}</code> table does not exist yet.
            Run <code>php artisan migrate</code> (MultiPay ships a migration that creates it if missing).
        </div>
    @else
        <div class="mp-grid">
            @foreach ($mpGateways as $g)
                <div class="mp-card" data-mp-gateway="{{ $g['name'] }}">
                    <div class="mp-card-head">
                        <div class="mp-icon">
                            @if (!empty($g['icon']))
                                <img src="{{ $g['icon'] }}" alt="">
                            @elseif (!empty($g['icon_svg']))
                                {{-- data-URI <img>: scripts inside an SVG can never run this way --}}
                                <img src="data:image/svg+xml;base64,{{ base64_encode($g['icon_svg']) }}" alt="">
                            @else
                                {{ strtoupper(substr($g['name'], 0, 2)) }}
                            @endif
                        </div>
                        <div>
                            <div class="mp-name">{{ $g['label'] }}</div>
                            <div class="mp-key">{{ $g['name'] }}</div>
                        </div>
                    </div>

                    <div class="mp-badges">
                        <span class="mp-badge {{ $g['active'] ? 'mp-on' : 'mp-off' }}" data-mp-status>
                            {{ $g['active'] ? 'Active' : 'Inactive' }}
                        </span>
                        @if (!$g['configured'] && $g['fields'] !== [])
                            <span class="mp-badge mp-warn">Credentials missing</span>
                        @endif
                    </div>

                    <div class="mp-actions">
                        <label class="mp-switch" title="Activate / deactivate">
                            <input type="checkbox" data-mp-toggle {{ $g['active'] ? 'checked' : '' }}>
                            <span class="mp-slider"></span>
                        </label>
                        <button type="button" class="mp-btn" data-mp-edit data-mp-fields='@json($g['fields'])' data-mp-label="{{ $g['label'] }}" data-mp-icon="{{ $g['icon_url_value'] }}">
                            Update
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="mp-modal-backdrop" data-mp-dismiss></div>
    <div class="mp-modal" role="dialog" aria-modal="true">
        <div class="mp-modal-card">
            <div class="mp-modal-head">
                <h3 class="mp-modal-title" data-mp-modal-title>Update credentials</h3>
                <button type="button" class="mp-close" data-mp-dismiss>&times;</button>
            </div>
            <form data-mp-form>
                <div class="mp-modal-body" data-mp-modal-fields></div>
                <div class="mp-modal-foot">
                    <button type="button" class="mp-btn" data-mp-dismiss>Cancel</button>
                    <button type="submit" class="mp-btn mp-primary" data-mp-save>Save changes</button>
                </div>
            </form>
        </div>
    </div>

    <div class="mp-toast" data-mp-toast></div>
</div>

<script>
(function () {
    var root = document.getElementById('mp-admin');
    if (!root || root.dataset.mpBound) return;
    root.dataset.mpBound = '1';

    var csrf = @json(csrf_token());
    var toggleUrl = @json(route($mpAs . 'admin.gateways.toggle', ['gateway' => '__NAME__']));
    var updateUrl = @json(route($mpAs . 'admin.gateways.update', ['gateway' => '__NAME__']));
    var toast = root.querySelector('[data-mp-toast]');
    var modalFields = root.querySelector('[data-mp-modal-fields]');
    var modalTitle = root.querySelector('[data-mp-modal-title]');
    var form = root.querySelector('[data-mp-form]');
    var saveBtn = root.querySelector('[data-mp-save]');
    var currentGateway = null;
    var toastTimer = null;

    function notify(message, ok) {
        toast.textContent = message;
        toast.className = 'mp-toast mp-show ' + (ok ? 'mp-ok' : 'mp-err');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toast.classList.remove('mp-show'); }, 3200);
    }

    function post(url, payload) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); });
    }

    function closeModal() { root.classList.remove('mp-modal-open'); currentGateway = null; }

    root.addEventListener('click', function (e) {
        if (e.target.closest('[data-mp-dismiss]')) closeModal();

        var editBtn = e.target.closest('[data-mp-edit]');
        if (editBtn) {
            var card = editBtn.closest('[data-mp-gateway]');
            currentGateway = card.dataset.mpGateway;
            modalTitle.textContent = 'Update ' + editBtn.dataset.mpLabel + ' credentials';
            var fields = JSON.parse(editBtn.dataset.mpFields || '[]');
            modalFields.innerHTML = '';

            if (!fields.length) {
                modalFields.innerHTML = '<p class="mp-empty-fields">This gateway needs no credentials.</p>';
            }

            fields.forEach(function (f) {
                var wrap = document.createElement('div');
                wrap.className = 'mp-field';
                var label = document.createElement('label');
                label.textContent = f.package_key.replace(/_/g, ' ');
                var hint = document.createElement('small');
                hint.textContent = ' (' + f.json_key + ')';
                label.appendChild(hint);
                var input = document.createElement('input');
                input.type = 'text';
                input.name = f.json_key;
                input.value = f.value || '';
                input.autocomplete = 'off';
                input.spellcheck = false;
                wrap.appendChild(label);
                wrap.appendChild(input);
                modalFields.appendChild(wrap);
            });

            // Logo field — always available; overrides the packaged icon
            var logoWrap = document.createElement('div');
            logoWrap.className = 'mp-field';
            var logoLabel = document.createElement('label');
            logoLabel.textContent = 'logo URL';
            var logoHint = document.createElement('small');
            logoHint.textContent = ' (optional — leave empty to use the built-in icon)';
            logoLabel.appendChild(logoHint);
            var logoInput = document.createElement('input');
            logoInput.type = 'text';
            logoInput.name = 'icon';
            logoInput.value = editBtn.dataset.mpIcon || '';
            logoInput.placeholder = 'https://your-site.com/images/gateway-logo.png';
            logoInput.autocomplete = 'off';
            logoInput.spellcheck = false;
            logoWrap.appendChild(logoLabel);
            logoWrap.appendChild(logoInput);
            modalFields.appendChild(logoWrap);

            root.classList.add('mp-modal-open');
        }
    });

    root.addEventListener('change', function (e) {
        var toggle = e.target.closest('[data-mp-toggle]');
        if (!toggle) return;

        var card = toggle.closest('[data-mp-gateway]');
        var name = card.dataset.mpGateway;
        var wanted = toggle.checked;
        toggle.disabled = true;

        post(toggleUrl.replace('__NAME__', encodeURIComponent(name)), { active: wanted })
            .then(function (res) {
                if (res.body.ok) {
                    var badge = card.querySelector('[data-mp-status]');
                    badge.textContent = wanted ? 'Active' : 'Inactive';
                    badge.className = 'mp-badge ' + (wanted ? 'mp-on' : 'mp-off');
                    notify(res.body.message, true);
                } else {
                    toggle.checked = !wanted;
                    notify(res.body.message || 'Could not update gateway.', false);
                }
            })
            .catch(function () { toggle.checked = !wanted; notify('Network error — try again.', false); })
            .finally(function () { toggle.disabled = false; });
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!currentGateway) return;

        var fields = {};
        modalFields.querySelectorAll('input').forEach(function (i) { fields[i.name] = i.value; });
        saveBtn.disabled = true;

        post(updateUrl.replace('__NAME__', encodeURIComponent(currentGateway)), { fields: fields })
            .then(function (res) {
                if (res.body.ok) {
                    notify(res.body.message, true);
                    closeModal();
                    setTimeout(function () { window.location.reload(); }, 600);
                } else {
                    notify(res.body.message || 'Could not save credentials.', false);
                }
            })
            .catch(function () { notify('Network error — try again.', false); })
            .finally(function () { saveBtn.disabled = false; });
    });
})();
</script>
