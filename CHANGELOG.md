# Changelog

Tutte le modifiche rilevanti al plugin sono documentate in questo file.

## [4.5.3] - 2026-03-31

### Modifiche
- Test: rinominato in 'Test', fix warning stock/backorders condizionali
- Log arricchiti: JSON request/response, ID remoto, link admin, tassonomie

## [4.5.2] - 2026-03-31

### Modifiche
- 404 su lookup da ERROR a WARNING, ERROR solo per fallimenti reali

## [4.5.1] - 2026-03-31

### Modifiche
- Aggiorna Plugin Update Checker da 5.5 a 5.6

## [4.5.0] - 2026-03-31

### Modifiche
- Filtro store multi-select, descrizione comportamento destinazione
- UX sync filtrati: label 'in Background', store opzionale, descrizione

## [4.4.2] - 2026-03-31

### Modifiche
- Fix MariaDB 5.5 compat, descrizione plugin, changelog HTML

## [4.4.1] - 2026-03-31

### Modifiche
- Fix: tabella log non creata dopo aggiornamento plugin (auto-create al primo uso)

## [4.4.0] - 2026-03-31

### Modifiche
- Test suite: aggiunto brand, featured, date_on_sale, ISBN, bookshop_link
- Test suite potenziata: prodotti privati, più campi, tag, pulizia tassonomie remote
- Fix UX migrazione URL: timeout 120s, messaggio attesa, gestione errori

## [4.3.0] - 2026-03-30

### Modifiche
- Force sync split, UI migliorata, sync tutti filtrati, URL migration

## [4.2.1] - 2026-03-30

### Modifiche
- Riepilogo con link al Log al completamento batch, pre-filtro errori
- Force Sync integrato in Bulk Sync, rimossa pagina separata, Log rinominato
- Rimozione stock sync su ordini, fix immagini variazioni, pulizia

## [4.2.0] - 2026-03-30

### Modifiche
- Fix YAML workflow: riscritto per evitare errori parser, usa jq per JSON
- CHANGELOG.md storico + Force Sync per store

## [4.1.0] - 2026-03-30

### Aggiunto
- Retry con exponential backoff sulle chiamate API (max 3 tentativi su timeout/500/502/503)
- Cache transient per tassonomie remote (categorie, tag, brand, attributi) - evita chiamate API duplicate in bulk sync
- Dashboard widget nella home admin con stato sync, errori 24h, batch attivo
- Notifica email opzionale al completamento del bulk sync (configurabile in Settings)

### Fixato
- Bug minori: null check su image in taxonomy sync, before_delete_post filtrato per post type, formato exclude_meta_on_duplicate, posizione immagini sequenziale

## [4.0.1] - 2026-03-30

### Fixato
- Fix bug critici: variazioni non sincronizzate in full sync mode (scope issue nel refactoring)
- Fix mapping immagini rotto (images e variations ora passati via $extra reference)
- Fix date_created: gestione corretta di WC_DateTime invece di strtotime su oggetto
- Fix crash su wc_get_order() che tornava false
- Fix race condition nei contatori batch con Action Scheduler concorrente
- Fix cancel batch che cancellava tutti i batch invece di solo quello target
- Fix XSS nel log viewer (escape di message, context, store_url)
- Fix CSRF: aggiunto nonce su tutti i form admin (stores, settings) e AJAX (auto sync, manual sync, stock update)
- Rimosso wp_ajax_nopriv su stock update (endpoint exploitable senza autenticazione)
- Rimossa chiamata wc_api_mps_add_site_to_synch(0) che sporcava il DB

### Sicurezza
- Sanitizzazione URL store con esc_url_raw()
- Conferma JavaScript prima di eliminare uno store

## [4.0.0] - 2026-03-30

### Aggiunto
- Aggiornamento automatico da GitHub tramite Plugin Update Checker
- GitHub Actions workflow per release automatiche (build zip + update metadata JSON)
- Sistema di logging unificato su database (tabella rps_sync_log) con:
  - Log viewer admin con filtri (livello, store, prodotto, data, testo)
  - Toggle espandibile per request/response JSON completi
  - Esportazione CSV
  - Pulizia automatica (MAX_ENTRIES = 500)
  - Mascheramento dati sensibili (consumer_key/secret)
- Bulk sync asincrono tramite WooCommerce Action Scheduler
  - Progress bar in tempo reale con polling AJAX
  - Possibilità di annullare un batch in corso
  - Nessun timeout anche con 300-400 prodotti
- Metabox "Link Siti" per vedere i link ai prodotti remoti
- Admin Columns Pro integration per colonna mpsrel con link cliccabili (v6 e v7)
- Bulk action "Forza Sincronizzazione" nella lista prodotti
- Bulk action "Elimina dati sincronizzazione" (tutti o per singolo store)

### Cambiato
- Plugin rinominato: da "WooCommerce API Product Sync" a "Raffaello Product Sync"
- File principale rinominato: da woocommerce-api-product-sync.php a raffaello-product-sync.php
- Architettura completamente riscritta: da funzioni procedurali (~5800 righe in 4 file) a 14 classi OOP
- API client refactored: da 1510 righe con codice duplicato a ~300 righe con metodo request() centralizzato
- Logica di sync spezzata in classi dedicate: ProductSync, TaxonomySync, VariationSync, ImageSync, PriceAdjuster
- Price adjustment: da codice duplicato 4 volte a singolo metodo PriceAdjuster::apply()
- Admin pages organizzate in classi: AdminPages, Metabox, BulkActions, AdminColumns

### Rimosso
- Doppio sistema di logging (WC logger + file debug.log) sostituito da logging unificato su DB
- File debug.log
- Cartella Licensing (residuo plugin originale)
- Sistema di licenza Obtain Infotech
- Pagina "API Error Logs" (sostituita da "Sync Log" molto più completo)

### Backward Compatibility
- Funzione globale wc_api_mps_integration() mantenuta per compatibilità
- Tutte le option keys (wc_api_mps_*) preservate
- Tutte le meta keys (mpsrel, sites_to_synch) preservate
- Classe WC_API_MPS mantenuta con stessa interfaccia pubblica
- Costante WC_API_MPS_PLUGIN_PATH mantenuta come alias

## [3.2.3] - Pre-fork

### Note
- Ultima versione del fork manuale basata su WooCommerce API Product Sync v3.2.0 di Obtain Infotech
- Personalizzazioni: logging potenziato (WC logger), sync personalizzato per bookshop_link, Admin Columns integration, gestione ACF sites_to_synch, bulk actions per cancellazione dati sync
