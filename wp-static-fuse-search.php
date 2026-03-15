<?php
/**
 * Plugin Name:       Static Fuse.js Search
 * Plugin URI:        https://github.com/currentjot/wp-static-fuse-search
 * Description:       Ricerca statica multilingua con indice Fuse.js, traduzione batch e rilevamento automatico degli URL tramite hreflang.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Dev
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-static-fuse-search
 */

if (!defined('ABSPATH')) exit;

/**
 * Costanti configurabili in wp-config.php
 * ─────────────────────────────────────────
 * SFS_TRANSLATE_API  URL dell'endpoint di traduzione compatibile LibreTranslate.
 *                    ⚠ Obbligatoria: senza questa costante la traduzione è disabilitata.
 *
 * SFS_SOURCE_LANG    Codice lingua sorgente (es. 'it', 'en').
 *                    Usato solo se il plugin di traduzione non è attivo.
 *                    Default: 'it'
 *
 * SFS_TARGET_LANGS   Lingue target separate da virgola (es. 'en,fr,de').
 *                    Usato solo se il plugin di traduzione non è attivo.
 *                    Default: '' (nessuna traduzione)
 *
 * Esempio:
 *   define( 'SFS_TRANSLATE_API', 'https://translate.tuosito.com/translate' );
 *   define( 'SFS_SOURCE_LANG',   'it' );
 *   define( 'SFS_TARGET_LANGS',  'en,fr,de' );
 */

class Minimal_Static_Fuse_Search {

    private string $dir;
    private string $url;
    private array  $hreflang_cache = [];

    // ── Helpers configurazione ────────────────────────────────────────

    private function source_lang(): string {
        if (function_exists('wplng_get_language_website_id')) return wplng_get_language_website_id();
        return defined('SFS_SOURCE_LANG') ? SFS_SOURCE_LANG : 'it';
    }

    private function target_langs(): array {
        if (function_exists('wplng_get_languages_target_ids')) return wplng_get_languages_target_ids();
        if (defined('SFS_TARGET_LANGS') && SFS_TARGET_LANGS !== '')
            return array_map('trim', explode(',', SFS_TARGET_LANGS));
        return [];
    }

    private function translate_api(): ?string {
        return defined('SFS_TRANSLATE_API') ? SFS_TRANSLATE_API : null;
    }

    private function is_frontend_enabled(): bool {
        return get_option('sfs_frontend_enabled', '1') !== '0';
    }

    // ── Bootstrap ─────────────────────────────────────────────────────

    public function __construct() {
        $upload    = wp_upload_dir();
        $this->dir = $upload['basedir'] . '/static-search';
        $this->url = $upload['baseurl']  . '/static-search';

        add_action('admin_menu',         [$this, 'menu']);
        add_action('admin_notices',      [$this, 'admin_notice']);
        add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);

        // Endpoint background (server-side, browser può essere chiuso)
        add_action('wp_ajax_sfs_bg_start',  [$this, 'ajax_bg_start']);
        add_action('wp_ajax_sfs_bg_status', [$this, 'ajax_bg_status']);

        // Endpoint amministrativi
        add_action('wp_ajax_sfs_delete_indexes', [$this, 'ajax_delete_indexes']);
        add_action('wp_ajax_sfs_toggle',         [$this, 'ajax_toggle']);
    }

    // ── Admin ─────────────────────────────────────────────────────────

    public function admin_notice(): void {
        if ($this->translate_api() !== null) return;
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['tools_page_sfs-search', 'dashboard'], true)) return;
        echo '<div class="notice notice-warning"><p>'
            . '<strong>Static Fuse.js Search:</strong> la traduzione è disabilitata. '
            . 'Aggiungi <code>define( \'SFS_TRANSLATE_API\', \'https://…/translate\' );</code> in <code>wp-config.php</code> per attivarla.'
            . '</p></div>';
    }

    public function menu(): void {
        add_management_page('Indice Ricerca', 'Indice Ricerca', 'manage_options',
            'sfs-search', [$this, 'admin_page']);
    }

    public function admin_page(): void {
        $nonce        = wp_create_nonce('sfs_nonce');
        $index_files  = glob($this->dir . '/index-*.json') ?: [];
        $post_count   = wp_count_posts('post')->publish + wp_count_posts('page')->publish;
        $last_update  = $index_files ? date_i18n('d/m/Y H:i', filemtime(end($index_files))) : '—';
        $fe_enabled   = $this->is_frontend_enabled();
        $has_api      = $this->translate_api() !== null;
        $meta         = $this->read_meta();
        $indexed_count = count($meta['entries'] ?? []);
        $bg_status    = get_option('sfs_bg_status', 'idle');
        $is_running   = $bg_status === 'running';
        ?>
        <style>
        #sfs *{box-sizing:border-box}
        #sfs{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:860px;margin:24px 0;color:#1d2327}
        .sfs-head{display:flex;align-items:center;gap:14px;margin-bottom:24px}
        .sfs-head-icon{width:44px;height:44px;background:#2271b1;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .sfs-head-icon .dashicons{color:#fff;font-size:22px;width:22px;height:22px}
        .sfs-head h1{font-size:21px;font-weight:600;margin:0;padding:0}
        .sfs-head p{font-size:13px;color:#646970;margin:2px 0 0}
        .sfs-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
        .sfs-stat{background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:16px 18px;display:flex;align-items:center;gap:12px}
        .sfs-stat-ico{width:38px;height:38px;border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .sfs-stat-ico.b{background:#dbeafe}.sfs-stat-ico.g{background:#dcfce7}.sfs-stat-ico.a{background:#fef3c7}.sfs-stat-ico.v{background:#f3e8ff}
        .sfs-stat-ico .dashicons{font-size:19px;width:19px;height:19px}
        .sfs-stat-ico.b .dashicons{color:#2563eb}.sfs-stat-ico.g .dashicons{color:#16a34a}.sfs-stat-ico.a .dashicons{color:#d97706}.sfs-stat-ico.v .dashicons{color:#7c3aed}
        .sfs-stat-val{font-size:24px;font-weight:700;line-height:1}
        .sfs-stat-lbl{font-size:12px;color:#646970;margin-top:3px}
        .sfs-card{background:#fff;border:1px solid #c3c4c7;border-radius:8px;overflow:hidden;margin-bottom:16px}
        .sfs-card-hd{padding:16px 20px;border-bottom:1px solid #f0f0f1;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
        .sfs-card-hd h2{font-size:14px;font-weight:600;margin:0}
        .sfs-card-bd{padding:20px}
        .sfs-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
        .sfs-btn{display:inline-flex;align-items:center;gap:7px;border:none;border-radius:6px;padding:9px 16px;font-size:13px;font-weight:500;cursor:pointer;transition:background .2s,opacity .2s}
        .sfs-btn:disabled{opacity:.5;cursor:not-allowed}
        .sfs-btn.primary{background:#2271b1;color:#fff}.sfs-btn.primary:hover:not(:disabled){background:#135e96}
        .sfs-btn.secondary{background:#f6f7f7;color:#1d2327;border:1px solid #c3c4c7}.sfs-btn.secondary:hover:not(:disabled){background:#dcdcde}
        .sfs-btn.danger{background:#fff;color:#b32d2e;border:1px solid #d63638}.sfs-btn.danger:hover:not(:disabled){background:#fce8e8}
        .sfs-btn .dashicons{font-size:15px;width:15px;height:15px}
        .sfs-btn.primary .dashicons{color:#fff}
        @keyframes spin{to{transform:rotate(360deg)}}
        .spin{animation:spin .7s linear infinite}
        .sfs-toggle-row{display:flex;align-items:center;justify-content:space-between;gap:16px}
        .sfs-toggle-row p{font-size:13px;color:#646970;margin:4px 0 0}
        .sfs-switch{position:relative;display:inline-block;width:40px;height:22px;flex-shrink:0}
        .sfs-switch input{opacity:0;width:0;height:0}
        .sfs-slider{position:absolute;inset:0;background:#c3c4c7;border-radius:99px;cursor:pointer;transition:background .2s}
        .sfs-slider::before{content:'';position:absolute;width:16px;height:16px;left:3px;top:3px;background:#fff;border-radius:50%;transition:transform .2s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
        .sfs-switch input:checked + .sfs-slider{background:#2271b1}
        .sfs-switch input:checked + .sfs-slider::before{transform:translateX(18px)}
        .sfs-toggle-label{font-size:14px;font-weight:500}
        #sfs-prog{margin-top:18px;display:none}
        .sfs-prog-hd{display:flex;justify-content:space-between;font-size:12px;color:#646970;margin-bottom:5px}
        .sfs-prog-bg{height:7px;background:#f0f0f1;border-radius:99px;overflow:hidden}
        #sfs-bar{height:100%;background:linear-gradient(90deg,#2271b1,#72aee6);border-radius:99px;width:0;transition:width .4s ease}
        #sfs-log-wrap{margin-top:18px;display:none}
        #sfs-log-wrap label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#646970;display:block;margin-bottom:6px}
        #sfs-log{background:#1d2327;color:#a7aaad;border-radius:6px;padding:12px 14px;font-family:monospace;font-size:12px;line-height:1.7;max-height:200px;overflow-y:auto;white-space:pre-wrap}
        #sfs-log .ok{color:#72aee6}#sfs-log .done{color:#68de7c}#sfs-log .err{color:#f86368}
        #sfs-status{margin-top:16px;padding:10px 13px;border-radius:6px;font-size:13px;display:none;align-items:center;gap:7px}
        #sfs-status .dashicons{font-size:15px;width:15px;height:15px;flex-shrink:0}
        #sfs-status.ok{background:#edfaef;color:#0a4c0e;border:1px solid #68de7c}
        #sfs-status.ok .dashicons{color:#16a34a}
        #sfs-status.err{background:#fce8e8;color:#5b0e0e;border:1px solid #f86368}
        #sfs-status.err .dashicons{color:#c02b2b}
        #sfs-status.info{background:#eff6ff;color:#1e3a5f;border:1px solid #93c5fd}
        #sfs-status.info .dashicons{color:#2271b1}
        .sfs-langs{margin-top:18px;padding-top:16px;border-top:1px solid #f0f0f1}
        .sfs-langs h3{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#646970;margin:0 0 8px}
        .sfs-pills{display:flex;flex-wrap:wrap;gap:7px}
        .sfs-pill{background:#f6f7f7;border:1px solid #dcdcde;border-radius:99px;padding:3px 11px;font-size:12px;color:#3c434a;display:flex;align-items:center;gap:5px}
        .sfs-pill::before{content:'';width:7px;height:7px;background:#68de7c;border-radius:50%;display:inline-block}
        .sfs-no-api{font-size:12px;color:#b32d2e;display:flex;align-items:center;gap:5px}
        .sfs-no-api .dashicons{font-size:14px;width:14px;height:14px;color:#b32d2e}
        </style>

        <div id="sfs">
            <div class="sfs-head">
                <div class="sfs-head-icon"><span class="dashicons dashicons-search"></span></div>
                <div>
                    <h1>Indice Ricerca Statica</h1>
                    <p>Genera e aggiorna l'indice Fuse.js per la ricerca multilingua</p>
                </div>
            </div>

            <div class="sfs-stats">
                <div class="sfs-stat">
                    <div class="sfs-stat-ico b"><span class="dashicons dashicons-admin-page"></span></div>
                    <div><div class="sfs-stat-val"><?= $post_count ?></div><div class="sfs-stat-lbl">Contenuti pubblicati</div></div>
                </div>
                <div class="sfs-stat">
                    <div class="sfs-stat-ico v"><span class="dashicons dashicons-list-view"></span></div>
                    <div><div class="sfs-stat-val" id="sfs-indexed-count"><?= $indexed_count ?></div><div class="sfs-stat-lbl">Nell'indice corrente</div></div>
                </div>
                <div class="sfs-stat">
                    <div class="sfs-stat-ico g"><span class="dashicons dashicons-yes-alt"></span></div>
                    <div><div class="sfs-stat-val" id="sfs-index-count"><?= count($index_files) ?></div><div class="sfs-stat-lbl">File indice generati</div></div>
                </div>
                <div class="sfs-stat">
                    <div class="sfs-stat-ico a"><span class="dashicons dashicons-clock"></span></div>
                    <div><div class="sfs-stat-val" style="font-size:15px;line-height:1.5" id="sfs-last-update"><?= $last_update ?></div><div class="sfs-stat-lbl">Ultimo aggiornamento</div></div>
                </div>
            </div>

            <div class="sfs-card">
                <div class="sfs-card-hd"><h2>Impostazioni</h2></div>
                <div class="sfs-card-bd">
                    <div class="sfs-toggle-row">
                        <div>
                            <span class="sfs-toggle-label">Ricerca frontend attiva</span>
                            <p>Abilita o disabilita il dropdown di ricerca su tutto il sito.</p>
                        </div>
                        <label class="sfs-switch">
                            <input type="checkbox" id="sfs-fe-toggle" <?= $fe_enabled ? 'checked' : '' ?>>
                            <span class="sfs-slider"></span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="sfs-card">
                <div class="sfs-card-hd">
                    <h2>Gestione Indice</h2>
                    <div class="sfs-actions">
                        <?php if (!$has_api): ?>
                        <span class="sfs-no-api"><span class="dashicons dashicons-warning"></span>API non configurata — solo lingua sorgente</span>
                        <?php endif; ?>
                        <button id="sfs-btn-update" class="sfs-btn secondary"
                            <?= (!$index_files || $is_running) ? 'disabled' : '' ?>>
                            <span class="dashicons dashicons-update" id="ico-update"></span> Aggiorna
                        </button>
                        <button id="sfs-btn-rebuild" class="sfs-btn primary"
                            <?= $is_running ? 'disabled' : '' ?>>
                            <span class="dashicons dashicons-database-add" id="ico-rebuild"></span> Ricostruzione Completa
                        </button>
                        <button id="sfs-btn-delete" class="sfs-btn danger"
                            <?= (!$index_files || $is_running) ? 'disabled' : '' ?>>
                            <span class="dashicons dashicons-trash"></span> Elimina Indici
                        </button>
                    </div>
                </div>
                <div class="sfs-card-bd">
                    <div id="sfs-prog">
                        <div class="sfs-prog-hd"><span id="sfs-prog-lbl">Avvio…</span><span id="sfs-prog-pct">0%</span></div>
                        <div class="sfs-prog-bg"><div id="sfs-bar"></div></div>
                    </div>
                    <div id="sfs-log-wrap"><label>Log</label><div id="sfs-log"></div></div>
                    <div id="sfs-status"></div>
                    <div class="sfs-langs">
                        <h3>Indici presenti</h3>
                        <div class="sfs-pills" id="sfs-pills">
                            <?php if ($index_files): ?>
                                <?php foreach ($index_files as $f): preg_match('/index-([^.]+)\.json$/', $f, $m); ?>
                                    <div class="sfs-pill"><?= esc_html(strtoupper($m[1] ?? '')) ?></div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span style="font-size:13px;color:#646970;font-style:italic">Nessun indice generato ancora.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (()=>{
            const $ = id => document.getElementById(id);
            const log = $('sfs-log'), logWrap = $('sfs-log-wrap'),
                  prog = $('sfs-prog'), bar = $('sfs-bar'),
                  progLbl = $('sfs-prog-lbl'), progPct = $('sfs-prog-pct'),
                  status = $('sfs-status'), pills = $('sfs-pills'),
                  btnRebuild = $('sfs-btn-rebuild'),
                  btnUpdate  = $('sfs-btn-update'),
                  btnDelete  = $('sfs-btn-delete'),
                  feToggle   = $('sfs-fe-toggle');

            const nonce = '<?= esc_js($nonce) ?>';
            let pollTimer = null;
            let lastLogCount = 0;

            // ── Utilities ─────────────────────────────────────────────

            const setP = (p, l) => {
                bar.style.width = p + '%';
                progPct.textContent = p + '%';
                progLbl.textContent = l;
            };

            const addLog = (m, t = '') => {
                const d = document.createElement('div');
                d.className = t; d.textContent = '› ' + m;
                log.appendChild(d); log.scrollTop = log.scrollHeight;
            };

            const setStatus = (t, m, ico = '') => {
                const icons = { ok: 'dashicons-yes-alt', err: 'dashicons-no-alt', info: 'dashicons-info' };
                status.className = t;
                status.innerHTML = `<span class="dashicons ${icons[t] || ico}"></span>${m}`;
                status.style.display = 'flex';
            };

            const setBtns = disabled => {
                btnRebuild.disabled = disabled;
                btnUpdate.disabled  = disabled;
                btnDelete.disabled  = disabled;
            };

            const resetUI = () => {
                log.innerHTML = ''; lastLogCount = 0;
                logWrap.style.display = prog.style.display = 'block';
                status.style.display = 'none';
            };

            const req = async (action, data = {}) => {
                const fd = new FormData();
                fd.append('action', action);
                fd.append('_ajax_nonce', nonce);
                Object.entries(data).forEach(([k, v]) =>
                    fd.append(k, typeof v === 'object' ? JSON.stringify(v) : v));
                return (await fetch(ajaxurl, { method: 'POST', body: fd })).json();
            };

            const updatePills = langs => {
                pills.innerHTML = langs.map(l =>
                    `<div class="sfs-pill">${l.toUpperCase()}</div>`).join('');
            };

            // ── Polling ───────────────────────────────────────────────

            const startPolling = () => {
                if (pollTimer) return;
                pollTimer = setInterval(poll, 2000);
                poll(); // prima chiamata immediata
            };

            const stopPolling = () => {
                clearInterval(pollTimer);
                pollTimer = null;
            };

            const poll = async () => {
                let res;
                try { res = await req('sfs_bg_status'); }
                catch { return; }
                if (!res.success) return;

                const d = res.data;

                // Aggiorna progress bar
                setP(d.progress, d.progress_label || '');

                // Aggiungi solo le nuove righe di log
                if (d.log && d.log.length > lastLogCount) {
                    d.log.slice(lastLogCount).forEach(e => addLog(e.msg, e.type));
                    lastLogCount = d.log.length;
                }

                if (d.status === 'running') return; // continua a fare polling

                // Job terminato
                stopPolling();
                setBtns(false);
                $('ico-rebuild').classList.remove('spin');
                $('ico-update').classList.remove('spin');

                if (d.status === 'done') {
                    setStatus('ok', d.result_msg || 'Operazione completata.');
                    if (d.pills)          updatePills(d.pills);
                    if (d.indexed_count != null) $('sfs-indexed-count').textContent = d.indexed_count;
                    if (d.index_count != null)   $('sfs-index-count').textContent   = d.index_count;
                    if (d.last_update)           $('sfs-last-update').textContent   = d.last_update;
                    btnDelete.disabled = false;
                    btnUpdate.disabled = false;
                } else if (d.status === 'error') {
                    setStatus('err', d.error || 'Si è verificato un errore.');
                }
            };

            // ── Avvio job ─────────────────────────────────────────────

            const startJob = async type => {
                resetUI();
                setBtns(true);
                setP(2, 'Avvio sul server…');

                const ico = type === 'rebuild' ? $('ico-rebuild') : $('ico-update');
                ico.classList.add('spin');

                setStatus('info',
                    'Generazione avviata sul server. <strong>Puoi chiudere questa pagina</strong> — riaprila per vedere il risultato.',
                    'dashicons-info');

                const res = await req('sfs_bg_start', { type });
                if (!res.success) {
                    setStatus('err', res.data || 'Errore avvio.');
                    setBtns(false); ico.classList.remove('spin'); return;
                }
                startPolling();
            };

            btnRebuild.addEventListener('click', () => startJob('rebuild'));
            btnUpdate.addEventListener('click',  () => startJob('update'));

            // ── Elimina Indici ─────────────────────────────────────────

            btnDelete.addEventListener('click', async () => {
                if (!confirm('Eliminare tutti gli indici di ricerca? L\'operazione non è reversibile.')) return;
                setBtns(true);
                resetUI(); setP(50, 'Eliminazione…');
                try {
                    const r = await req('sfs_delete_indexes');
                    if (!r.success) throw new Error(r.data);
                    setP(100, 'Eliminato');
                    addLog(`${r.data.deleted} file eliminati.`, 'done');
                    setStatus('ok', 'Tutti gli indici sono stati eliminati.');
                    pills.innerHTML = '<span style="font-size:13px;color:#646970;font-style:italic">Nessun indice generato ancora.</span>';
                    $('sfs-indexed-count').textContent = '0';
                    $('sfs-index-count').textContent   = '0';
                    $('sfs-last-update').textContent   = '—';
                    btnDelete.disabled = true;
                    btnUpdate.disabled = true;
                    btnRebuild.disabled = false;
                } catch (e) {
                    addLog('Errore: ' + e.message, 'err');
                    setStatus('err', 'Errore: ' + e.message);
                    setBtns(false);
                }
            });

            // ── Toggle Frontend ────────────────────────────────────────

            feToggle.addEventListener('change', () =>
                req('sfs_toggle', { enabled: feToggle.checked ? 1 : 0 }));

            // ── Auto-resume se job in corso al caricamento pagina ─────

            <?php if ($is_running): ?>
            resetUI();
            setBtns(true);
            setP(0, 'In esecuzione sul server…');
            logWrap.style.display = prog.style.display = 'block';
            $('ico-rebuild').classList.add('spin');
            setStatus('info',
                'Generazione in corso sul server. <strong>Puoi chiudere questa pagina</strong> — riaprila per vedere il risultato.');
            startPolling();
            <?php endif; ?>

        })();
        </script>
        <?php
    }

    // ── AJAX: Avvio job background ────────────────────────────────────
    // Chiude subito la connessione HTTP e continua l'esecuzione sul server.

    public function ajax_bg_start(): void {
        check_ajax_referer('sfs_nonce');

        if (get_option('sfs_bg_status') === 'running') {
            wp_send_json_error('Generazione già in corso.');
            return;
        }

        $type = sanitize_text_field($_POST['type'] ?? 'rebuild');
        if (!in_array($type, ['rebuild', 'update'], true)) {
            wp_send_json_error('Tipo non valido.');
            return;
        }

        // Inizializza stato
        update_option('sfs_bg_status',   'running');
        update_option('sfs_bg_log',      []);
        update_option('sfs_bg_progress', 0);
        update_option('sfs_bg_label',    'Avvio…');
        update_option('sfs_bg_result',   []);
        update_option('sfs_bg_type',     $type);

        // Chiude la connessione HTTP → il browser riceve la risposta
        // e il server continua a girare in background.
        $this->close_connection(['started' => true]);

        // Da qui in poi il browser non è più in ascolto.
        ignore_user_abort(true);
        @set_time_limit(0);

        if ($type === 'rebuild') {
            $this->bg_run_rebuild();
        } else {
            $this->bg_run_update();
        }
    }

    // ── AJAX: Status polling ──────────────────────────────────────────

    public function ajax_bg_status(): void {
        check_ajax_referer('sfs_nonce');

        $status   = get_option('sfs_bg_status', 'idle');
        $log      = get_option('sfs_bg_log', []);
        $progress = (int) get_option('sfs_bg_progress', 0);
        $label    = get_option('sfs_bg_label', '');
        $result   = get_option('sfs_bg_result', []);

        wp_send_json_success(array_merge(
            ['status' => $status, 'log' => $log, 'progress' => $progress, 'progress_label' => $label],
            $result
        ));
    }

    // ── Background: Ricostruzione Completa ────────────────────────────

    private function bg_run_rebuild(): void {
        try {
            wp_mkdir_p($this->dir);
            $sl = $this->source_lang();
            $tl = $this->translate_api() !== null ? $this->target_langs() : [];

            $this->bg_log("Raccolta contenuti…", 'ok');
            $this->bg_progress(5, 'Raccolta contenuti…');

            $posts = [];
            $query = new WP_Query([
                'post_type'      => ['post', 'page'],
                'posts_per_page' => 500,
                'post_status'    => 'publish',
            ]);

            $total = count($query->posts);
            foreach ($query->posts as $i => $p) {
                $src   = get_permalink($p);
                $hrefs = $this->hreflang_map($src);
                $urls  = [$sl => $src];
                foreach ($tl as $lang) {
                    $key = strtolower($lang);
                    $urls[$lang] = $hrefs[$key] ?? $hrefs[strtok($key, '-')] ?? $src;
                }
                $posts[] = [
                    'id'      => $p->ID,
                    'title'   => html_entity_decode(get_the_title($p)),
                    'url'     => $src,
                    'urls'    => $urls,
                    'excerpt' => wp_trim_words(wp_strip_all_tags($p->post_content), 15),
                ];
                if ($i % 20 === 0)
                    $this->bg_progress((int)(5 + ($i / max($total, 1)) * 10), "Raccolta {$i}/{$total}…");
            }

            $this->save_index($sl, $posts);
            $this->write_meta($posts);
            $this->bg_log("Indice {$sl} salvato — {$total} contenuti", 'ok');
            $this->bg_progress(15, "Indice {$sl} salvato");

            // Traduzioni
            $this->bg_translate_all($sl, $tl, $posts, 15, 100);

            // Risultato finale
            $index_files = glob($this->dir . '/index-*.json') ?: [];
            $langs = array_map(function($f) {
                preg_match('/index-([^.]+)\.json$/', $f, $m);
                return strtoupper($m[1] ?? '');
            }, $index_files);

            $this->bg_log('Ricostruzione completata.', 'done');
            $this->bg_finish([
                'result_msg'    => "Indice ricostruito: {$total} contenuti indicizzati.",
                'indexed_count' => $total,
                'index_count'   => count($index_files),
                'last_update'   => date_i18n('d/m/Y H:i'),
                'pills'         => array_map('strtolower', $langs),
            ]);
        } catch (\Throwable $e) {
            $this->bg_error($e->getMessage());
        }
    }

    // ── Background: Aggiornamento Incrementale ────────────────────────

    private function bg_run_update(): void {
        try {
            wp_mkdir_p($this->dir);
            $sl   = $this->source_lang();
            $tl   = $this->translate_api() !== null ? $this->target_langs() : [];
            $meta = $this->read_meta();

            $this->bg_progress(5, 'Analisi differenze…');

            $indexed = [];
            foreach ($meta['entries'] ?? [] as $e) $indexed[$e['id']] = $e['url'];

            $query = new WP_Query([
                'post_type'      => ['post', 'page'],
                'posts_per_page' => 500,
                'post_status'    => 'publish',
                'fields'         => 'ids',
            ]);
            $current_ids  = array_map('intval', $query->posts);
            $new_ids      = array_diff($current_ids, array_keys($indexed));
            $removed_ids  = array_diff(array_keys($indexed), $current_ids);
            $removed_urls = array_values(array_intersect_key($indexed, array_flip($removed_ids)));

            if (!$new_ids && !$removed_ids) {
                $this->bg_log('Nessuna modifica — indice già aggiornato.', 'done');
                $this->bg_finish(['result_msg' => 'Indice già aggiornato, nessuna modifica necessaria.']);
                return;
            }

            $this->bg_log("Nuovi: " . count($new_ids) . " · Rimossi: " . count($removed_ids), 'ok');

            // Nuovi post
            $new_posts = [];
            if ($new_ids) {
                $nq = new WP_Query([
                    'post_type'      => ['post', 'page'],
                    'post__in'       => array_values($new_ids),
                    'posts_per_page' => count($new_ids),
                    'post_status'    => 'publish',
                ]);
                foreach ($nq->posts as $p) {
                    $src   = get_permalink($p);
                    $hrefs = $this->hreflang_map($src);
                    $urls  = [$sl => $src];
                    foreach ($tl as $lang) {
                        $key = strtolower($lang);
                        $urls[$lang] = $hrefs[$key] ?? $hrefs[strtok($key, '-')] ?? $src;
                    }
                    $new_posts[] = [
                        'id'      => $p->ID,
                        'title'   => html_entity_decode(get_the_title($p)),
                        'url'     => $src,
                        'urls'    => $urls,
                        'excerpt' => wp_trim_words(wp_strip_all_tags($p->post_content), 15),
                    ];
                }
            }

            // Aggiorna indice sorgente
            $src_index = $this->load_index($sl);
            $src_index = array_values(array_filter($src_index, fn($e) => !in_array($e['url'], $removed_urls)));
            foreach ($new_posts as $np)
                $src_index[] = ['title' => $np['title'], 'url' => $np['url'], 'excerpt' => $np['excerpt']];
            file_put_contents("{$this->dir}/index-{$sl}.json", json_encode(array_values($src_index)));

            // Aggiorna meta
            $new_entries = array_values(array_filter($meta['entries'] ?? [], fn($e) => !in_array($e['id'], $removed_ids)));
            foreach ($new_posts as $np) $new_entries[] = ['id' => $np['id'], 'url' => $np['url']];
            file_put_contents("{$this->dir}/_meta.json", json_encode(['entries' => $new_entries, 'ts' => time()]));

            $this->bg_log("Indice {$sl} aggiornato", 'ok');
            $this->bg_progress(15, "Indice {$sl} aggiornato");

            // Aggiorna indici lingue target (rimuovi vecchi, aggiungi nuovi tradotti)
            if ($new_posts) {
                $this->bg_translate_all($sl, $tl, $new_posts, 15, 95, 'update');
            }

            // Solo rimozioni senza aggiunte
            if (!$new_posts && $removed_urls) {
                $src_urls = array_column($this->load_index($sl), 'url');
                foreach ($tl as $lang) {
                    $existing = $this->load_index($lang);
                    $existing = array_values(array_filter($existing, fn($e) => in_array($e['url'], $src_urls)));
                    file_put_contents("{$this->dir}/index-{$lang}.json", json_encode($existing));
                    $this->bg_log("Rimossi da {$lang}", 'ok');
                }
            }

            $total = count($src_index);
            $this->bg_log('Aggiornamento incrementale completato.', 'done');
            $this->bg_finish([
                'result_msg'    => "Aggiornato: +" . count($new_posts) . " aggiunti, −" . count($removed_ids) . " rimossi. Totale: {$total}.",
                'indexed_count' => $total,
                'last_update'   => date_i18n('d/m/Y H:i'),
            ]);
        } catch (\Throwable $e) {
            $this->bg_error($e->getMessage());
        }
    }

    // ── Background: traduzione tutti i chunk per tutte le lingue ──────

    private function bg_translate_all(string $sl, array $tl, array $posts, int $p_from, int $p_to, string $mode = 'rebuild'): void {
        if (!$tl) { $this->bg_progress($p_to, 'Completato'); return; }
        $range = $p_to - $p_from;

        foreach ($tl as $li => $lang) {
            $this->bg_log("Traduzione → {$lang}…");
            $chunks = array_chunk($posts, 10);
            $out    = [];

            foreach ($chunks as $ci => $chunk) {
                $translated = $this->translate_chunk($chunk, $sl, $lang);
                $out = array_merge($out, $translated);

                $pct = (int)($p_from + ($li / count($tl) + ($ci / count($chunks)) / count($tl)) * $range);
                $this->bg_progress($pct, "{$lang} — blocco " . ($ci + 1) . "/" . count($chunks));
            }

            // Salva (merge con esistente in modalità update)
            if ($mode === 'update') {
                $src_urls = array_column($this->load_index($sl), 'url');
                $existing = $this->load_index($lang);
                $existing = array_values(array_filter($existing, fn($e) => in_array($e['url'], $src_urls)));
                $out      = array_merge($existing, $out);
            }
            file_put_contents("{$this->dir}/index-{$lang}.json", json_encode(array_values($out)));
            $this->bg_log("Indice {$lang} salvato", 'ok');
            $this->bg_progress((int)($p_from + (($li + 1) / count($tl)) * $range), "Indice {$lang} salvato");
        }
    }

    // ── Traduzione di un singolo chunk (PHP → API) ────────────────────

    private function translate_chunk(array $posts, string $source, string $target): array {
        $q = [];
        foreach ($posts as $p) { $q[] = $p['title']; $q[] = $p['excerpt']; }

        $res = wp_remote_post($this->translate_api(), [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode(['q' => $q, 'source' => $source, 'target' => $target, 'format' => 'text']),
            'timeout' => 30,
        ]);

        if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
            $t = json_decode(wp_remote_retrieve_body($res), true)['translatedText'] ?? null;
            if ($t) {
                $out = []; $i = 0;
                $key = strtolower($target);
                foreach ($posts as $p) {
                    $out[] = [
                        'title'   => $t[$i]   ?? $p['title'],
                        'url'     => $p['urls'][$key] ?? $p['urls'][$target] ?? $p['url'],
                        'excerpt' => $t[$i+1] ?? $p['excerpt'],
                    ];
                    $i += 2;
                }
                return $out;
            }
        }
        // Fallback: restituisce i testi originali se la traduzione fallisce
        return array_map(fn($p) => ['title' => $p['title'], 'url' => $p['url'], 'excerpt' => $p['excerpt']], $posts);
    }

    // ── AJAX: Elimina Indici ──────────────────────────────────────────

    public function ajax_delete_indexes(): void {
        check_ajax_referer('sfs_nonce');
        $files   = glob($this->dir . '/index-*.json') ?: [];
        $meta    = $this->dir . '/_meta.json';
        $deleted = 0;
        foreach ($files as $f) { if (unlink($f)) $deleted++; }
        if (file_exists($meta)) unlink($meta);
        // Resetta anche lo stato background
        update_option('sfs_bg_status', 'idle');
        wp_send_json_success(['deleted' => $deleted]);
    }

    // ── AJAX: Toggle Frontend ─────────────────────────────────────────

    public function ajax_toggle(): void {
        check_ajax_referer('sfs_nonce');
        $enabled = (($_POST['enabled'] ?? '1') === '1') ? '1' : '0';
        update_option('sfs_frontend_enabled', $enabled);
        wp_send_json_success();
    }

    // ── Helper: chiudi connessione HTTP ───────────────────────────────

    private function close_connection(array $data): void {
        $json = wp_json_encode(['success' => true, 'data' => $data]);

        // Svuota tutti i buffer di output aperti
        while (ob_get_level()) ob_end_clean();

        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: ' . strlen($json));
        header('Connection: close');
        header('X-Accel-Buffering: no'); // Nginx: disabilita buffering

        echo $json;

        // FastCGI (PHP-FPM): chiude la connessione mantenendo il processo attivo
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            flush();
        }
    }

    // ── Helper: stato background ──────────────────────────────────────

    private function bg_log(string $msg, string $type = ''): void {
        $log   = get_option('sfs_bg_log', []);
        $log[] = ['msg' => $msg, 'type' => $type, 'ts' => time()];
        update_option('sfs_bg_log', $log);
    }

    private function bg_progress(int $pct, string $label = ''): void {
        update_option('sfs_bg_progress', min($pct, 100));
        if ($label) update_option('sfs_bg_label', $label);
    }

    private function bg_finish(array $result): void {
        $this->bg_progress(100, 'Completato');
        update_option('sfs_bg_status', 'done');
        update_option('sfs_bg_result', $result);
    }

    private function bg_error(string $msg): void {
        $this->bg_log("Errore: {$msg}", 'err');
        update_option('sfs_bg_status', 'error');
        update_option('sfs_bg_result', ['error' => $msg]);
    }

    // ── Helper: hreflang ─────────────────────────────────────────────

    private function hreflang_map(string $url): array {
        if (isset($this->hreflang_cache[$url])) return $this->hreflang_cache[$url];

        $res = wp_remote_get($url, ['timeout' => 8, 'sslverify' => false]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200)
            return $this->hreflang_cache[$url] = [];

        $map  = [];
        $html = substr(wp_remote_retrieve_body($res), 0, 8000);
        preg_match_all('/<link[^>]+rel=["\']alternate["\'][^>]*/i', $html, $tags);

        foreach ($tags[0] as $tag) {
            preg_match('/hreflang=["\']([^"\']+)["\']/i', $tag, $ml);
            preg_match('/href=["\']([^"\']+)["\']/i',     $tag, $mh);
            $lang = strtolower(trim($ml[1] ?? ''));
            $href = trim($mh[1] ?? '');
            if (!$lang || !$href || $lang === 'x-default') continue;
            $map[$lang] = $href;
            $short = strtok($lang, '-');
            if ($short && !isset($map[$short])) $map[$short] = $href;
        }

        return $this->hreflang_cache[$url] = $map;
    }

    // ── Helper: indice e meta ─────────────────────────────────────────

    private function save_index(string $lang, array $posts): void {
        file_put_contents(
            "{$this->dir}/index-{$lang}.json",
            json_encode(array_values(array_map(
                fn($p) => ['title' => $p['title'], 'url' => $p['url'], 'excerpt' => $p['excerpt']],
                $posts
            )))
        );
    }

    private function load_index(string $lang): array {
        $file = "{$this->dir}/index-{$lang}.json";
        if (!file_exists($file)) return [];
        return json_decode(file_get_contents($file), true) ?? [];
    }

    private function write_meta(array $posts): void {
        file_put_contents("{$this->dir}/_meta.json", json_encode([
            'entries' => array_values(array_map(fn($p) => ['id' => $p['id'], 'url' => $p['url']], $posts)),
            'ts'      => time(),
        ]));
    }

    private function read_meta(): array {
        $file = "{$this->dir}/_meta.json";
        if (!file_exists($file)) return ['entries' => [], 'ts' => 0];
        return json_decode(file_get_contents($file), true) ?? ['entries' => [], 'ts' => 0];
    }

    // ── Frontend ──────────────────────────────────────────────────────

    public function frontend_assets(): void {
        if (!$this->is_frontend_enabled()) return;

        wp_enqueue_script('fuse-js', 'https://cdn.jsdelivr.net/npm/fuse.js@6.6.2', [], null, true);

        $sl  = $this->source_lang();
        $url = $this->url;

        wp_add_inline_style('wp-block-library', <<<'CSS'
            .sfs-wrap{position:relative}
            .sfs-drop{
                position:absolute;top:calc(100% + 6px);left:0;right:0;
                background:#fff;border:1px solid #dcdcde;border-radius:8px;
                z-index:99999;display:none;max-height:340px;overflow-y:auto;
                -webkit-overflow-scrolling:touch;
                box-shadow:0 8px 24px rgba(0,0,0,.12);animation:sfsFade .15s ease}
            @keyframes sfsFade{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:translateY(0)}}
            .sfs-drop::-webkit-scrollbar{width:4px}
            .sfs-drop::-webkit-scrollbar-thumb{background:#dcdcde;border-radius:99px}
            .sfs-row{border-bottom:1px solid #f0f0f1;transition:background .1s}
            .sfs-row:last-of-type{border-bottom:none}
            .sfs-row.on,.sfs-row:hover{background:#f6f7f7}
            .sfs-row a{
                display:flex;align-items:center;gap:9px;
                padding:11px 13px;min-height:48px;
                text-decoration:none;color:#1d2327;
                -webkit-tap-highlight-color:transparent}
            .sfs-ico{width:30px;height:30px;background:#f0f0f1;border-radius:5px;
                display:flex;align-items:center;justify-content:center;flex-shrink:0}
            .sfs-ico svg{width:13px;height:13px;fill:none;stroke:#646970;stroke-width:2;stroke-linecap:round}
            .sfs-row strong{display:block;font-size:13px;font-weight:600;line-height:1.3}
            .sfs-row span{display:block;font-size:12px;color:#646970;line-height:1.4;margin-top:1px}
            .sfs-msg{padding:13px 14px;font-size:13px;color:#646970;text-align:center}
            .sfs-foot{padding:6px 13px;border-top:1px solid #f0f0f1;background:#fafafa;
                font-size:11px;color:#a7aaad;border-radius:0 0 8px 8px}
            @keyframes sfsSpin{to{transform:rotate(360deg)}}
            .sfs-spin{width:13px;height:13px;border:2px solid #dcdcde;border-top-color:#2271b1;
                border-radius:50%;animation:sfsSpin .6s linear infinite;
                display:inline-block;margin-right:6px;vertical-align:middle}
            @media(max-width:600px){
                input[type="search"],input[name="s"]{font-size:16px !important}
                .sfs-drop{max-height:55vh}
                .sfs-foot{display:none}
            }
CSS);

        wp_add_inline_script('fuse-js', <<<JS
        document.addEventListener('DOMContentLoaded', () => {
            const inputs = document.querySelectorAll('input[type="search"], input[name="s"]');
            if (!inputs.length) return;

            let fuse = null, active = -1;
            const lang = (document.documentElement.lang || '$sl').split('-')[0];
            const ico  = '<svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="6"/><path d="M19 19l-3.5-3.5"/></svg>';

            fetch('$url/index-' + lang + '.json')
                .then(r => r.ok ? r.json() : fetch('$url/index-$sl.json').then(r => r.json()))
                .then(d => fuse = new Fuse(d, { keys: ['title', 'excerpt'], threshold: 0.35 }))
                .catch(() => {});

            inputs.forEach(input => {
                input.setAttribute('autocomplete', 'off');
                const wrap = input.parentNode;
                if (wrap && getComputedStyle(wrap).position === 'static') wrap.style.position = 'relative';
                wrap?.classList.add('sfs-wrap');

                const box = document.createElement('div');
                box.className = 'sfs-drop';
                box.setAttribute('role', 'listbox');
                wrap?.appendChild(box);

                const hide = () => { box.style.display = 'none'; active = -1; };

                const render = q => {
                    if (q.length < 2) return hide();
                    if (!fuse) {
                        box.innerHTML = '<div class="sfs-msg"><span class="sfs-spin"></span>Caricamento…</div>';
                        box.style.display = 'block'; return;
                    }
                    const res = fuse.search(q).slice(0, 6);
                    box.innerHTML = res.length
                        ? res.map(r =>
                            '<div class="sfs-row" role="option">' +
                            '<a href="' + r.item.url + '">' +
                            '<div class="sfs-ico">' + ico + '</div>' +
                            '<div><strong>' + r.item.title + '</strong><span>' + r.item.excerpt + '</span></div>' +
                            '</a></div>'
                          ).join('') + '<div class="sfs-foot">↑↓ naviga &middot; ↵ apri &middot; Esc chiudi</div>'
                        : '<div class="sfs-msg">Nessun risultato per <strong>"' + q + '"</strong></div>';
                    box.style.display = 'block';
                    active = -1;
                };

                input.closest('form')?.addEventListener('submit', e => {
                    if (box.style.display === 'block' || input.value.trim().length >= 2) e.preventDefault();
                });

                input.addEventListener('input',  e => render(e.target.value.trim()));
                input.addEventListener('focus',  e => { if (e.target.value.trim().length >= 2) render(e.target.value.trim()); });
                input.addEventListener('keydown', e => {
                    const rows = [...box.querySelectorAll('.sfs-row')];
                    if (!rows.length) return;
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        rows[active]?.classList.remove('on');
                        active = (active + 1) % rows.length;
                        rows[active].classList.add('on');
                        rows[active].scrollIntoView({ block: 'nearest' });
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        rows[active]?.classList.remove('on');
                        active = (active - 1 + rows.length) % rows.length;
                        rows[active].classList.add('on');
                        rows[active].scrollIntoView({ block: 'nearest' });
                    } else if (e.key === 'Enter' && active >= 0) {
                        e.preventDefault();
                        const a = rows[active].querySelector('a');
                        if (a) location.href = a.href;
                    } else if (e.key === 'Escape') { hide(); input.blur(); }
                });
            });

            document.addEventListener('click', e => {
                document.querySelectorAll('.sfs-drop').forEach(b => {
                    if (!b.contains(e.target) && !b.previousElementSibling?.contains(e.target))
                        b.style.display = 'none';
                });
            });
        });
JS);
    }
}

new Minimal_Static_Fuse_Search();