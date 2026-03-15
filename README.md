# wp-static-fuse-search

Ricerca statica multilingua per WordPress con indice [Fuse.js](https://fusejs.io/), traduzione batch tramite API compatibile LibreTranslate e rilevamento automatico degli URL tradotti via tag `hreflang`.

**Repository:** [github.com/currentjot/wp-static-fuse-search](https://github.com/currentjot/wp-static-fuse-search)

---

## Funzionalità

- **Ricerca client-side** — nessuna query al database, zero latenza
- **Multilingua** — genera un indice JSON separato per ogni lingua
- **Traduzione batch** — titoli ed excerpt vengono tradotti in blocchi di 10 tramite qualsiasi endpoint compatibile LibreTranslate
- **URL tradotti automatici** — legge i tag `<link rel="alternate" hreflang>` direttamente dall'HTML di ogni post; funziona con qualsiasi plugin di traduzione (WPLNG, WPML, Polylang, ecc.)
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
4. Vai in **Strumenti → Indice Ricerca** e clicca **Avvia Generazione**

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

### Generazione dell'indice

1. L'admin clicca **Avvia Generazione**
2. Il plugin raccoglie tutti i post/pagine pubblicati (max 500)
3. Per ogni post effettua una richiesta HTTP alla sua URL e legge i tag `hreflang` nel `<head>` per ottenere i permalink nelle lingue target
4. Salva `index-{lingua}.json` nella cartella `wp-content/uploads/static-search/`
5. Per ogni lingua target, invia i testi (titolo + excerpt) all'API di traduzione in blocchi da 10 e salva il file JSON tradotto

### Ricerca frontend

Il plugin si aggancia automaticamente a tutti gli `input[type="search"]` e `input[name="s"]` della pagina. All'avvio carica il file `index-{lang}.json` corrispondente alla lingua corrente (rilevata da `document.documentElement.lang`), con fallback alla lingua sorgente.

Il dropdown mostra fino a 6 risultati con titolo ed excerpt. Navigabile da tastiera (`↑↓` per spostarsi, `↵` per aprire, `Esc` per chiudere).

Il submit nativo del form di ricerca di WordPress viene bloccato automaticamente quando il dropdown è attivo.

---

## Struttura dei file generati

```
wp-content/uploads/static-search/
├── index-it.json
├── index-en.json
├── index-fr.json
└── ...
```

Ogni file ha questa struttura:

```json
[
  {
    "title": "Titolo del post",
    "url": "https://tuosito.com/it/titolo-del-post/",
    "excerpt": "Le prime quindici parole del contenuto del post…"
  }
]
```

---

## Compatibilità plugin di traduzione

Il plugin rileva automaticamente WPLNG, WPML e Polylang per ottenere la lingua corrente e le lingue target. In assenza di questi plugin usa le costanti `SFS_SOURCE_LANG` e `SFS_TARGET_LANGS`.

Gli URL tradotti vengono sempre letti dai tag `hreflang` presenti nell'HTML, quindi sono compatibili con qualsiasi plugin che li emetta correttamente.

---

## Licenza

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)