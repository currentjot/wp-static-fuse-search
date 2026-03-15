<?php
/**
 * Plugin Name:       Static Fuse.js Search
 * Plugin URI:        https://github.com/currentjot/wp-static-fuse-search
 * Description:       Ricerca statica multilingua con indice Fuse.js, traduzione batch e rilevamento automatico degli URL tramite hreflang.
 * Version:           1.0.0
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

    // Risolve la lingua sorgente: plugin → costante → default 'it'
    private function source_lang(): string {
        if (function_exists('wplng_get_language_website_id')) return wplng_get_language_website_id();
        return defined('SFS_SOURCE_LANG') ? SFS_SOURCE_LANG : 'it';
    }

    // Risolve le lingue target: plugin → costante → array vuoto
    private function target_langs(): array {
        if (function_exists('wplng_get_languages_target_ids')) return wplng_get_languages_target_ids();
        if (defined('SFS_TARGET_LANGS') && SFS_TARGET_LANGS !== '')
            return array_map('trim', explode(',', SFS_TARGET_LANGS));
        return [];
    }

    // URL dell'API di traduzione — null se SFS_TRANSLATE_API non è definita in wp-config.php
    private function translate_api(): ?string {
        return defined('SFS_TRANSLATE_API') ? SFS_TRANSLATE_API : null;
    }

    public function __construct() {
        $upload    = wp_upload_dir();
        $this->dir = $upload['basedir'] . '/static-search';
        $this->url = $upload['baseurl']  . '/static-search';

        add_action('admin_menu',         [$this, 'menu']);
        add_action('admin_notices',      [$this, 'admin_notice']);
        add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);
        add_action('wp_ajax_sfs_init',      [$this, 'ajax_init']);
        add_action('wp_ajax_sfs_translate', [$this, 'ajax_translate']);
        add_action('wp_ajax_sfs_save',      [$this, 'ajax_save']);
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
        $nonce       = wp_create_nonce('sfs_nonce');
        $index_files = glob($this->dir . '/index-*.json') ?: [];
        $post_count  = wp_count_posts('post')->publish + wp_count_posts('page')->publish;
        $last_update = $index_files ? date_i18n('d/m/Y H:i', filemtime(end($index_files))) : '—';
        ?>
        <style>
        #sfs *{box-sizing:border-box}
        #sfs{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:860px;margin:24px 0;color:#1d2327}
        .sfs-head{display:flex;align-items:center;gap:14px;margin-bottom:24px}
        .sfs-head-icon{width:44px;height:44px;background:#2271b1;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .sfs-head-icon .dashicons{color:#fff;font-size:22px;width:22px;height:22px}
        .sfs-head h1{font-size:21px;font-weight:600;margin:0;padding:0}
        .sfs-head p{font-size:13px;color:#646970;margin:2px 0 0}
        .sfs-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px}
        .sfs-stat{background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:16px 18px;display:flex;align-items:center;gap:12px}
        .sfs-stat-ico{width:38px;height:38px;border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .sfs-stat-ico.b{background:#dbeafe}.sfs-stat-ico.g{background:#dcfce7}.sfs-stat-ico.a{background:#fef3c7}
        .sfs-stat-ico .dashicons{font-size:19px;width:19px;height:19px}
        .sfs-stat-ico.b .dashicons{color:#2563eb}.sfs-stat-ico.g .dashicons{color:#16a34a}.sfs-stat-ico.a .dashicons{color:#d97706}
        .sfs-stat-val{font-size:24px;font-weight:700;line-height:1}
        .sfs-stat-lbl{font-size:12px;color:#646970;margin-top:3px}
        .sfs-card{background:#fff;border:1px solid #c3c4c7;border-radius:8px;overflow:hidden}
        .sfs-card-hd{padding:16px 20px;border-bottom:1px solid #f0f0f1;display:flex;align-items:center;justify-content:space-between}
        .sfs-card-hd h2{font-size:14px;font-weight:600;margin:0}
        .sfs-card-bd{padding:20px}
        #sfs-btn{display:inline-flex;align-items:center;gap:7px;background:#2271b1;color:#fff;border:none;border-radius:6px;padding:9px 18px;font-size:13px;font-weight:500;cursor:pointer;transition:background .2s}
        #sfs-btn:hover:not(:disabled){background:#135e96}
        #sfs-btn:disabled{background:#a7aaad;cursor:not-allowed}
        #sfs-btn .dashicons{font-size:15px;width:15px;height:15px;color:#fff}
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
        .sfs-langs{margin-top:18px;padding-top:16px;border-top:1px solid #f0f0f1}
        .sfs-langs h3{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#646970;margin:0 0 8px}
        .sfs-pills{display:flex;flex-wrap:wrap;gap:7px}
        .sfs-pill{background:#f6f7f7;border:1px solid #dcdcde;border-radius:99px;padding:3px 11px;font-size:12px;color:#3c434a;display:flex;align-items:center;gap:5px}
        .sfs-pill::before{content:'';width:7px;height:7px;background:#68de7c;border-radius:50%;display:inline-block}
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
                    <div class="sfs-stat-ico g"><span class="dashicons dashicons-yes-alt"></span></div>
                    <div><div class="sfs-stat-val"><?= count($index_files) ?></div><div class="sfs-stat-lbl">Indici generati</div></div>
                </div>
                <div class="sfs-stat">
                    <div class="sfs-stat-ico a"><span class="dashicons dashicons-clock"></span></div>
                    <div><div class="sfs-stat-val" style="font-size:15px;line-height:1.5"><?= $last_update ?></div><div class="sfs-stat-lbl">Ultimo aggiornamento</div></div>
                </div>
            </div>

            <div class="sfs-card">
                <div class="sfs-card-hd">
                    <h2>Generazione Indice</h2>
                    <button id="sfs-btn">
                        <span class="dashicons dashicons-update"></span> Avvia Generazione
                    </button>
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
            const $=id=>document.getElementById(id);
            const btn=$('sfs-btn'), log=$('sfs-log'), logWrap=$('sfs-log-wrap'),
                  prog=$('sfs-prog'), bar=$('sfs-bar'), progLbl=$('sfs-prog-lbl'),
                  progPct=$('sfs-prog-pct'), status=$('sfs-status'), pills=$('sfs-pills');

            const setP=(p,l)=>{bar.style.width=p+'%';progPct.textContent=p+'%';progLbl.textContent=l};
            const addLog=(m,t='')=>{const d=document.createElement('div');d.className=t;d.textContent='› '+m;log.appendChild(d);log.scrollTop=log.scrollHeight};
            const setStatus=(t,m)=>{const ico={ok:'dashicons-yes-alt',err:'dashicons-no-alt'};status.className=t;status.innerHTML=`<span class="dashicons ${ico[t]}"></span>${m}`;status.style.display='flex'};

            const req=async(action,data={})=>{
                const fd=new FormData();
                fd.append('action',action);
                fd.append('_ajax_nonce','<?= esc_js($nonce) ?>');
                Object.entries(data).forEach(([k,v])=>fd.append(k,typeof v==='object'?JSON.stringify(v):v));
                return (await fetch(ajaxurl,{method:'POST',body:fd})).json();
            };

            btn.addEventListener('click',async()=>{
                btn.disabled=true;
                log.innerHTML='';
                logWrap.style.display=prog.style.display='block';
                status.style.display='none';
                setP(5,'Inizializzazione…');

                try{
                    const init=await req('sfs_init');
                    if(!init.success) throw new Error(init.data);
                    const {source_lang:sl,target_langs:tl,posts}=init.data;
                    const chunks=[];
                    for(let i=0;i<posts.length;i+=10) chunks.push(posts.slice(i,i+10));

                    addLog(`Lingua sorgente: ${sl.toUpperCase()} — ${posts.length} contenuti`,'ok');
                    addLog(`Lingue target: ${tl.join(', ')||'nessuna'}`);
                    setP(15,`Indice ${sl.toUpperCase()} salvato`);

                    for(let li=0;li<tl.length;li++){
                        const lang=tl[li];
                        let out=[];
                        addLog(`Traduzione → ${lang.toUpperCase()}…`);
                        for(let ci=0;ci<chunks.length;ci++){
                            const r=await req('sfs_translate',{source_lang:sl,target_lang:lang,posts:chunks[ci]});
                            out=out.concat(r.success?r.data:chunks[ci]);
                            setP(Math.round(15+(li/tl.length+(ci/chunks.length)/tl.length)*80),
                                `${lang.toUpperCase()} — blocco ${ci+1}/${chunks.length}`);
                        }
                        await req('sfs_save',{lang,index_data:out});
                        addLog(`Indice ${lang.toUpperCase()} salvato`,'ok');
                        setP(Math.round(15+((li+1)/tl.length)*80),`Indice ${lang.toUpperCase()} salvato`);
                    }

                    setP(100,'Completato!');
                    addLog('Tutti gli indici generati.','done');
                    setStatus('ok','Indice generato con successo!');
                    pills.innerHTML=[sl,...tl].map(l=>`<div class="sfs-pill">${l.toUpperCase()}</div>`).join('');
                }catch(e){
                    addLog('Errore: '+e.message,'err');
                    setStatus('err','Errore: '+e.message);
                }
                btn.disabled=false;
            });
        })();
        </script>
        <?php
    }

    // ── AJAX ──────────────────────────────────────────────────────────

    public function ajax_init(): void {
        check_ajax_referer('sfs_nonce');
        @set_time_limit(300);
        wp_mkdir_p($this->dir);

        $sl = $this->source_lang();
        // Se l'API non è configurata ignoriamo le lingue target: solo indice sorgente
        $tl = $this->translate_api() !== null ? $this->target_langs() : [];

        $posts = [];
        $query = new WP_Query([
            'post_type'      => ['post', 'page'],
            'posts_per_page' => 500,
            'post_status'    => 'publish',
        ]);

        foreach ($query->posts as $p) {
            $src   = get_permalink($p);
            $hrefs = $this->hreflang_map($src);
            $urls  = [$sl => $src];
            foreach ($tl as $lang) {
                $key = strtolower($lang);
                $urls[$lang] = $hrefs[$key] ?? $hrefs[strtok($key, '-')] ?? $src;
            }
            $posts[] = [
                'title'   => html_entity_decode(get_the_title($p)),
                'url'     => $src,
                'urls'    => $urls,
                'excerpt' => wp_trim_words(wp_strip_all_tags($p->post_content), 15),
            ];
        }

        file_put_contents(
            "{$this->dir}/index-{$sl}.json",
            json_encode(array_map(fn($p) => ['title' => $p['title'], 'url' => $p['url'], 'excerpt' => $p['excerpt']], $posts))
        );

        wp_send_json_success(['source_lang' => $sl, 'target_langs' => $tl, 'posts' => $posts]);
    }

    public function ajax_translate(): void {
        check_ajax_referer('sfs_nonce');
        if ($this->translate_api() === null) wp_send_json_error('SFS_TRANSLATE_API non definita in wp-config.php');
        $posts  = json_decode(stripslashes($_POST['posts']  ?? '[]'), true);
        $source = sanitize_text_field($_POST['source_lang'] ?? '');
        $target = sanitize_text_field($_POST['target_lang'] ?? '');
        if (!$posts) wp_send_json_error('Blocco vuoto');

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
                wp_send_json_success($out);
            }
        }
        wp_send_json_error('Errore API traduzione');
    }

    public function ajax_save(): void {
        check_ajax_referer('sfs_nonce');
        $lang = sanitize_text_field($_POST['lang'] ?? '');
        $data = stripslashes($_POST['index_data'] ?? '');
        if ($lang && json_decode($data)) {
            file_put_contents("{$this->dir}/index-{$lang}.json", $data);
            wp_send_json_success();
        }
        wp_send_json_error('Dati non validi');
    }

    // ── Helper: legge hreflang dal <head> della pagina ────────────────

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

    // ── Frontend ──────────────────────────────────────────────────────

    public function frontend_assets(): void {
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