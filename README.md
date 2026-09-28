# Ratatoskr

## Mímir (optioneel)

Zet in `web/auth.php` (niet in git), **naast** de Business Central-credentials:

```php
$mimirApi  = 'mimir_…';
// optioneel:
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';

$baseUrl = 'https://…:7148/';
$environment = 'Production';
$auth_list = [ /* … */ ];
$auth = $auth_list[$environment];
```

Met `$mimirApi` gezet proberen OData-fetches en company-discovery eerst Mímir. Faalt die aanroep (verbinding/timeout, non-2xx, ongeldige JSON of een Mímir-foutpayload), dan haalt Ratatoskr dezelfde data op via het oude Business Central-pad (`$baseUrl`, `$auth` / `$auth_list`, `$environment`, lokale odata-filecache) en slaat Mímir voor de rest van dat PHP-proces over. Dat geldt voor live requests (`index.php`) én voor nightly/CLI (`php web/nightly.php` houdt de lange timeout; webrequests time-outen korter).

Laat die BC-credentials in `auth.php` staan naast `$mimirApi`. Ontbreken ze, dan wordt de oorspronkelijke Mímir-fout opnieuw gegooid. Zonder `$mimirApi` blijft alleen het bestaande BC-pad actief.
