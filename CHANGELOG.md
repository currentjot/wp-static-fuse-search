# Changelog — wp-static-fuse-search

Tutte le modifiche rilevanti a questo progetto sono documentate in questo file.

Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.0.0/) e il progetto adotta il [Semantic Versioning](https://semver.org/lang/it/).

---

## [1.2.0] — 2025-03-15

### Aggiunto
- Generazione dell'indice completamente server-side: il browser riceve subito la conferma di avvio e la connessione HTTP si chiude; il server prosegue autonomamente tramite `ignore_user_abort(true)` e `set_time_limit(0)`
- Supporto `fastcgi_finish_request()` per PHP-FPM; fallback a `flush()` con header `Connection: close` per Apache/Nginx
- Polling del browser ogni 2 secondi tramite endpoint `sfs_bg_status` per aggiornare progresso e log in tempo reale senza bloccare l'interfaccia
- Resume automatico del polling se la pagina viene riaperta mentre un job è in corso (rilevato dal flag `sfs_bg_status = running` nelle opzioni di WordPress)
- Stato del job persistito nelle opzioni di WordPress (`sfs_bg_status`, `sfs_bg_log`, `sfs_bg_progress`, `sfs_bg_label`, `sfs_bg_result`)
- Notifica inline blu "Puoi chiudere questa pagina" durante la generazione
- Protezione contro avvii multipli simultanei: se un job è già in corso il server restituisce errore
- Fallback automatico per chunk di traduzione falliti: vengono mantenuti i testi originali invece di interrompere il processo

### Modificato
- Tutta la logica di traduzione e salvataggio spostata dal browser al server (`bg_run_rebuild`, `bg_run_update`, `bg_translate_all`, `translate_chunk`)
- Rimossi gli endpoint AJAX browser-driven precedenti (`sfs_init`, `sfs_translate`, `sfs_save`, `sfs_update_init`, `sfs_update_apply`) sostituiti da `sfs_bg_start` e `sfs_bg_status`
- I pulsanti **Ricostruzione Completa** e **Aggiorna** sono disabilitati automaticamente se un job è già in esecuzione

---

## [1.1.0] — 2025-03-15

### Aggiunto
- Aggiornamento incrementale dell'indice: aggiunge solo i nuovi post e rimuove quelli eliminati senza ricostruire tutto (`sfs_update_init`, `sfs_update_apply`)
- File `_meta.json` generato ad ogni ricostruzione completa, contenente l'elenco di ID e URL indicizzati — usato come riferimento per la diff incrementale
- Toggle on/off per il frontend: interruttore nell'admin che abilita o disabilita il dropdown di ricerca su tutto il sito senza disattivare il plugin (`sfs_frontend_enabled`, salvato nelle opzioni di WordPress)
- Pulsante **Elimina Indici**: rimuove tutti i file `index-*.json` e `_meta.json` con richiesta di conferma
- Quarta card statistica "Nell'indice corrente" che mostra il numero di voci attualmente indicizzate
- Pulsanti admin distinti per funzione: **Ricostruzione Completa**, **Aggiorna**, **Elimina Indici**
- Animazione di caricamento (spin) sui pulsanti durante l'operazione in corso
- Avviso inline nella card di gestione se `SFS_TRANSLATE_API` non è configurata

### Corretto
- Salvataggio dell'opzione booleana del toggle frontend tramite stringhe `'1'`/`'0'` per compatibilità con `update_option` di WordPress (il cast `bool` impediva il salvataggio di `false`)

---

## [1.0.0] — 2025-03-14

Primo rilascio stabile.

### Aggiunto
- Generazione dell'indice Fuse.js statico per post e pagine pubblicati (max 500)
- Salvataggio dei file JSON in `wp-content/uploads/static-search/index-{lang}.json`
- Traduzione batch di titoli ed excerpt tramite endpoint compatibile LibreTranslate, in blocchi da 10
- Rilevamento automatico degli URL tradotti tramite parsing dei tag `<link rel="alternate" hreflang>` nel `<head>` di ogni post (compatibile con tutti i plugin di traduzione)
- Cache in-memory dei risultati hreflang per evitare fetch duplicate durante la generazione
- Supporto priorità per rilevamento lingua: WPLNG → `SFS_SOURCE_LANG` → `'it'`
- Supporto priorità per lingue target: WPLNG → `SFS_TARGET_LANGS` → array vuoto
- Normalizzazione dei codici lingua (`en-US` → salvataggio come `en-us` e `en`)
- Costante `SFS_TRANSLATE_API` obbligatoria — senza di essa la traduzione è disabilitata
- Avviso admin se `SFS_TRANSLATE_API` non è definita (visibile su dashboard e pagina plugin)
- Interfaccia admin con intestazione, tre card statistiche (contenuti, indici generati, ultimo aggiornamento), barra di avanzamento animata, log a tema scuro con colori per tipo di messaggio, badge di stato finale e pill delle lingue indicizzate
- Icone tramite Dashicons native di WordPress (nessuna dipendenza esterna)
- Dropdown di ricerca frontend agganciato a `input[type="search"]` e `input[name="s"]`
- Animazione di apertura dropdown (`fadeIn` + slide)
- Navigazione da tastiera: `↑↓` per spostarsi tra i risultati, `↵` per aprire, `Esc` per chiudere
- Blocco automatico del submit del form di ricerca nativo di WordPress
- Fallback automatico all'indice della lingua sorgente se quello della lingua corrente non esiste
- Ottimizzazione mobile: `font-size: 16px` per prevenire zoom automatico su iOS/Safari, altezza minima righe 48px, footer tastiera nascosto su schermi piccoli, `max-height: 55vh` sul dropdown
- Stato di caricamento con spinner animato mentre l'indice JSON è in fase di fetch
- Supporto `set_time_limit(300)` per siti con molti contenuti