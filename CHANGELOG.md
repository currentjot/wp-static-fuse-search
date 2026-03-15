# Changelog — wp-static-fuse-search

Tutte le modifiche rilevanti a questo progetto sono documentate in questo file.

Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.0.0/) e il progetto adotta il [Semantic Versioning](https://semver.org/lang/it/).

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