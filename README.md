# Fabby — Kirby-Plugin

LLM-basierter Chatbot für Seiten auf Basis des [fab-kirby-Projekts](https://github.com/bitbetterde/fab-kirby). Das Plugin liefert das Backend (Chat, TTS, STT, RAG-Suche), das Widget für die Website und eine Panel-Seite, über die LLM-Provider, Modell, System-Prompt und Tools ohne Serverzugriff gepflegt werden.

Die Entwicklung dieses Plugins wurde von der [CSCP gGmbH](https://www.cscp.org/) beauftragt. Die Unity-Modelle in diesem Plugin [wurden von der onto[story] GmbH erstellt](https://www.ontostory.com/developing-fabby-a-character-driven-campaign-for-a-sustainable-future/).

Dieses Repository enthält den installationsfertigen Stand des Plugins. Es ist zum Ausrollen gedacht, nicht zum Entwickeln: das Repository ist der Plugin-Ordner und wird unverändert nach `site/plugins/` gelegt.

## Voraussetzungen

| | |
|---|---|
| PHP | ≥ 8.1, mit `sqlite3` und `curl` |
| Kirby | 5.x |

Optional, aber empfohlen für die RAG-Suche: die SQLite-Erweiterung [`sqlite-vec`](https://github.com/asg017/sqlite-vec). Ohne sie funktioniert die Suche weiterhin, fällt aber auf einen langsameren PHP-Pfad zurück (siehe [sqlite-vec aktivieren](#sqlite-vec-aktivieren-optional)).

## Installation

Das Repository gehört nach `site/plugins/` der Kirby-Site. Der Ordnername ist frei wählbar; die Beispiele hier verwenden `kirby-fabby`.

```bash
cd /pfad/zur/site/site/plugins
git clone https://github.com/bitbetterde/kirby-plugin-fabby.git kirby-fabby
```

Ohne Git auf dem Server: das Repository lokal klonen oder als ZIP herunterladen und den Ordner hochladen.

Danach muss `site/plugins/kirby-fabby/index.php` existieren — das ist der Einstiegspunkt, an dem Kirby das Plugin erkennt.

### Nach der Installation

Beim nächsten Seitenaufruf legt das Plugin die Seite `fabby-settings` automatisch in `content/` an. Im Panel erscheint links ein Menüpunkt **Fabby**. Das Plugin bringt keinen System-Prompt mit: Das Pflichtfeld „Persönlichkeit & Verhalten“ ist nach der Installation leer und muss dort einmal ausgefüllt werden.

Der PHP-Prozess braucht Schreibrechte auf `content/` (für die Settings-Seite) und auf `site/logs/` (für den RAG-Index).

### Update

```bash
cd /pfad/zur/site/site/plugins/kirby-fabby
git pull
```

Bestehende Einstellungen bleiben erhalten — sie liegen in `content/`, nicht im Plugin. Der RAG-Index bleibt ebenfalls bestehen; ein Neuaufbau ist nur nötig, wenn sich Modell, Basis-URL oder Chunk-Größe ändern.

## Konfiguration

Im **Panel** — Felder auf der Seite *Fabby* (Tab „Technik“).


### Wichtige Standardwerte

| Einstellung | Default |
|---|---|
| LLM-Provider | `openai` |
| Modell | `gpt-4o` |
| Basis-URL | `https://api.openai.com/v1` |
| Max. Tokens | `1000` |
| RAG-Index | `site/logs/fabby-rag/index.sqlite` |

Der Index liegt bewusst unter `site/logs`, nicht unter `site/cache`: ein Cache-Verzeichnis wird von Deploy-Skripten geleert, und ein Neuaufbau des Index kostet echte Embedding-Aufrufe.

## Widget einbinden

Nichts zu tun — das Plugin hängt das Widget über den Hook `page.render:after` automatisch vor `</body>` jeder veröffentlichten HTML-Seite ein.

Für eigene Platzierung stattdessen manuell:

```php
<?= snippet('fabby') ?>
```

Ein Marker im Snippet verhindert, dass der Hook das Widget doppelt einfügt.

## RAG-Index aufbauen

Damit Fabby Seiteninhalte zitieren kann, muss der Index einmal aufgebaut werden. Im Panel über den Fabby-Menüpunkt:

- **Aufbauen** — nur geänderte Seiten einbetten (überspringt Unverändertes)
- **Alles neu einbetten** — kompletter Neuaufbau, kostenpflichtig. Nötig nach einem Wechsel von Modell, Basis-URL oder Chunk-Größe.
- **Verarbeiten** — vorgemerkte Änderungen abarbeiten

Inhaltsänderungen im Panel landen automatisch in einer Warteschlange und werden nebenbei bei normalen Requests abgearbeitet.

### Kommandozeile

```bash
cd site/plugins/kirby-fabby
php tools/fabby-index.php --status          # Zustand anzeigen
php tools/fabby-index.php --rebuild         # geänderte Seiten einbetten
php tools/fabby-index.php --force           # alles neu (kostet)
php tools/fabby-index.php --queue           # Warteschlange abarbeiten
php tools/fabby-index.php --search "Frage"  # Suche testen
```

Für den Produktionsbetrieb möglich per Cron, alle 5 Minuten:

```cron
*/5 * * * * cd /pfad/zur/site/site/plugins/kirby-fabby && php tools/fabby-index.php --queue
```

Der Site-Root wird automatisch gesucht; abweichend über `FABBY_KIRBY_ROOT=/pfad/zur/site` setzbar.

### sqlite-vec aktivieren (optional)

PHP lädt SQLite-Erweiterungen **ausschließlich** aus dem in der `php.ini` gesetzten Verzeichnis:

```ini
sqlite3.extension_dir = /pfad/zu/sqlite-extensions
```

Die Einstellung ist `PHP_INI_SYSTEM` und lässt sich zur Laufzeit nicht setzen — ohne den Eintrag ist das Laden unmöglich. Die Erweiterungsdatei (z. B. `vec0.so`) in dieses Verzeichnis legen. Im Panel wird nur der **Dateiname** eingetragen, kein Pfad: ein absoluter Pfad außerhalb des ini-Verzeichnisses lädt ohne Fehlermeldung und stellt danach keine Funktionen bereit.

Der Status ist im Panel unter *Fabby → Technik* sichtbar.

## Panel-Struktur

Die Seite *Fabby* hat drei Tabs:

- **Inhalt** — redaktionell, in drei Gruppen: System-Prompt, Tools, RAG
- **Audio** — Sprachausgabe (Stimme, Modell, Klangparameter) und Darstellung von Sprachaufnahmen
- **Technik** — API-Keys, Endpunkte, Modelle, Limits, RAG

Den Tab **Technik sehen nur Administratoren**. Alle anderen Rollen bekommen ihn gar nicht erst angezeigt und können seine Felder auch nicht schreiben.

Wer die Technik-Einstellungen pflegen soll, braucht also die Rolle `admin` — zuweisen lässt sich die im Panel unter *Benutzer*, ohne Deployment.

Die technischen Werte werden für andere Rollen auch aus der Seiten-API und den gespeicherten sowie ungespeicherten Panel-Inhalten entfernt. Die interne Konfiguration liest weiterhin die vollständigen Werte.

Suchtreffer werden vor der Ausgabe mit dem aktuell extrahierten Seiteninhalt verglichen. Veraltete Treffer, auch mit inzwischen zurückgezogenen Inhalten verknüpfter Seiten, werden sofort ausgeblendet. Die betroffene Quellseite wird bei der Suche zur Aktualisierung vorgemerkt; bis zur Verarbeitung fehlen ihre Treffer vorübergehend. Ein vollständiger Neuaufbau ist dafür nicht nötig.

Beim Umbenennen oder Verschieben einer übergeordneten Seite werden auch alle Unterseiten zur Aktualisierung vorgemerkt. Mit UUIDs aktualisiert der Worker unveränderte Inhalte ohne erneute Embeddings; ohne UUIDs entfernt er die alten Pfad-Einträge und indexiert die neuen. Entwürfe und ausgeschlossene Unterseiten werden dabei aus dem Index entfernt. Diese Fälle lassen sich mit `php tests/lifecycle.php` ohne externe API-Aufrufe prüfen.

Die Sicherheitsregressionen lassen sich ohne externe API-Aufrufe mit `php tests/security.php` im Plugin-Verzeichnis prüfen. Bei abweichendem Installationspfad kann `FABBY_KIRBY_ROOT` auf die Kirby-Site zeigen.

