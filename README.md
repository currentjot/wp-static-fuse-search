# wp-static-fuse-search

Ricerca statica multilingua per WordPress con indice [Fuse.js](https://fusejs.io/), traduzione batch tramite API compatibile LibreTranslate e rilevamento automatico degli URL tradotti via tag `hreflang`.

**Repository:** [github.com/currentjot/wp-static-fuse-search](https://github.com/currentjot/wp-static-fuse-search)

---

## Funzionalità

- **Ricerca client-side** — nessuna query al database, zero latenza
- **Multilingua** — genera un indice JSON separato per ogni lingua
- **Traduzione batch** — titoli ed excerpt vengono tradotti in blocchi di 10 tramite qualsiasi endpoint compatibile LibreTranslate
- **URL tradotti automatici** — legge i tag `<link rel="alternate" hreflang>` direttamente dall'HTML di ogni post; funziona con qualsiasi plugin di traduzione (WPLNG, WPML, Polylang, ecc.)
- **Aggiornamento incrementale** — aggiunge solo i nuovi post e rimuove quelli eliminati senza ricostruire l'indice da zero
- **Toggle frontend** — abilita o disabilita il dropdown di ricerca direttamente dall'admin, senza disattivare il plugin
- **Elimina indici** — rimuove tutti i file JSON generati in un click
- **Interfaccia admin** — pannello con statistiche, barra di avanzamento e log in tempo reale
- **Dropdown ricerca** — si aggancia a qualsiasi `input[type="search"]` o `input[name="s"]` già presente nel tema
- **Mobile-friendly** — target di tocco generosi, nessun zoom indesiderato su iOS, footer tastiera nascosto su touch

---

## Requisiti

| Requisito | Versione minima |
|---|---|
| WordPress | 6.0 |
| PHP | 8.0 |
| Fuse.js (CDN) | 6.6.2 |

---

## Installazione

1. Copia `wp-static-fuse-search.php` nella cartella `/wp-content/plugins/wp-static-fuse-search/`
2. Attiva il plugin da **Plugin → Plugin installati**
3. Configura le costanti in `wp-config.php` (vedi sezione [Configurazione](#configurazione))
4. Vai in **Strumenti → Indice Ricerca** e clicca **Ricostruzione Completa**

---

## Configurazione

Aggiungi le seguenti righe in `wp-config.php` **prima** della riga `/* That's all, stop editing! */`:

```php
// Obbligatoria — URL del tuo endpoint LibreTranslate
define( 'SFS_TRANSLATE_API', 'https://translate.tuosito.com/translate' );

// Usate solo se non hai un plugin di traduzione attivo (WPLNG, WPML, Polylang)
define( 'SFS_SOURCE_LANG',  'it' );       // lingua sorgente
define( 'SFS_TARGET_LANGS', 'en,fr,de' ); // lingue target separate da virgola
```

### Priorità di risoluzione

| Valore | 1° | 2° | 3° |
|---|---|---|---|
| Lingua sorgente | Plugin attivo (WPLNG) | `SFS_SOURCE_LANG` | `'it'` |
| Lingue target | Plugin attivo (WPLNG) | `SFS_TARGET_LANGS` | nessuna |
| API traduzione | `SFS_TRANSLATE_API` | — | traduzione disabilitata |

> ⚠️ Se `SFS_TRANSLATE_API` non è definita, la traduzione è completamente disabilitata. Viene generato solo l'indice nella lingua sorgente e un avviso appare nella dashboard WordPress.

---

## Come funziona

### Ricostruzione Completa

1. Il plugin raccoglie tutti i post/pagine pubblicati (max 500)
2. Per ogni post effettua una richiesta HTTP alla sua URL e legge i tag `hreflang` nel `<head>` per ottenere i permalink nelle lingue target
3. Salva `index-{lingua}.json` nella cartella `wp-content/uploads/static-search/`
4. Per ogni lingua target, traduce titoli ed excerpt in blocchi da 10 e salva il file JSON
5. Genera `_meta.json` con l'elenco degli ID e URL indicizzati (usato dagli aggiornamenti incrementali)

### Aggiornamento Incrementale

Esegue una diff tra l'indice esistente e i post pubblicati correnti:

1. Legge `_meta.json` per sapere quali post sono già nell'indice
2. Confronta con i post pubblicati → individua nuovi e rimossi
3. Traduce e aggiunge solo i nuovi post a tutti i file lingua
4. Rimuove dalle versioni tradotte i post che non esistono più
5. Se non ci sono differenze, notifica che l'indice è già aggiornato

> 💡 Usa **Aggiorna** per le operazioni quotidiane. Usa **Ricostruzione Completa** dopo modifiche importanti al contenuto o alla struttura del sito.

### Toggle Frontend

L'interruttore in **Impostazioni** abilita o disabilita il dropdown di ricerca su tutto il sito senza dover disattivare il plugin. La preferenza è salvata nelle opzioni di WordPress.

### Ricerca frontend

Il plugin si aggancia automaticamente a tutti gli `input[type="search"]` e `input[name="s"]` della pagina. All'avvio carica `index-{lang}.json` corrispondente alla lingua corrente (rilevata da `document.documentElement.lang`), con fallback alla lingua sorgente.

Il dropdown mostra fino a 6 risultati con titolo ed excerpt, navigabile da tastiera (`↑↓` per spostarsi, `↵` per aprire, `Esc` per chiudere).

Il submit nativo del form di ricerca di WordPress viene bloccato automaticamente quando il dropdown è attivo.

---

## Struttura dei file generati

```
wp-content/uploads/static-search/
├── index-it.json
├── index-en.json
├── index-fr.json
├── _meta.json        ← usato per gli aggiornamenti incrementali
└── ...
```

Ogni file indice ha questa struttura:

```json
[
  {
    "title": "Titolo del post",
    "url": "https://tuosito.com/it/titolo-del-post/",
    "excerpt": "Le prime quindici parole del contenuto del post…"
  }
]
```

Il file `_meta.json` tiene traccia degli ID e degli URL indicizzati:

```json
{
  "entries": [
    { "id": 42, "url": "https://tuosito.com/it/titolo-del-post/" }
  ],
  "ts": 1741996800
}
```

---

## Compatibilità plugin di traduzione

Il plugin rileva automaticamente WPLNG, WPML e Polylang per ottenere la lingua corrente e le lingue target. In assenza di questi plugin usa le costanti `SFS_SOURCE_LANG` e `SFS_TARGET_LANGS`.

Gli URL tradotti vengono sempre letti dai tag `hreflang` presenti nell'HTML, quindi sono compatibili con qualsiasi plugin che li emetta correttamente.

---

## Licenza

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)