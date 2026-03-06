# KI-Chat-Widget in formr.org

Diese Dokumentation erklärt, wie du ein interaktives KI-Chatfenster in einen
formr-Fragebogen einbauen kannst — ohne Programmierkenntnisse, direkt über
deine Excel-Spreadsheet-Datei.

---

## Überblick

Das fai-Widget erlaubt es, einen Freitextdialog mit einer KI (Claude oder
ChatGPT) direkt in eine Fragebogen-Seite einzubetten. Die KI antwortet in
Echtzeit, der Nutzer kann mehrere Nachrichten schicken, und der „Weiter"-Button
bleibt solange gesperrt, bis eine Mindestanzahl von Nachrichten ausgetauscht
wurde.

**Funktionsweise auf einen Blick:**

```
Nutzer tippt Nachricht
       ↓
Enter-Taste oder Senden-Klick
       ↓
formr sendet die Nachricht sicher an die KI (kein API-Schlüssel nötig)
       ↓
KI antwortet → Antwort erscheint im Chat
       ↓
Nach N Nachrichten: „Weiter"-Button wird freigeschaltet
```

---

## Schnellstart

Kopiere dieses HTML in die `label`-Spalte deines Items im Excel-Sheet.
Es handelt sich um einen vollständig funktionsfähigen Starter-Code:

```html
<div style="font-family:sans-serif;max-width:600px"
     class="fai-widget"
     data-fai-min="3"
     data-fai-system-prompt="Du bist ein freundlicher Gesprächspartner. Antworte kurz auf Deutsch.">

  <p style="color:#555;font-size:13px;margin-bottom:12px">
    Führe das Gespräch frei. Schreibe mindestens 3 Nachrichten.
  </p>

  <style>
    #fai-chat{border:1px solid #ddd;border-radius:8px;height:320px;overflow-y:auto;
              padding:15px;background:white;margin-bottom:10px}
    .fai-msg{margin:8px 0;padding:10px 14px;border-radius:18px;max-width:82%;
             line-height:1.5;word-wrap:break-word;font-size:14px}
    .fai-user{background:#0084ff;color:white;margin-left:auto;text-align:right}
    .fai-bot{background:#f0f0f0;color:#222}
    .fai-row{display:flex;gap:8px;margin-top:8px}
    #fai-input{flex:1;padding:10px;border:1px solid #ccc;border-radius:20px;font-size:14px}
    #fai-btn{padding:10px 18px;background:#0084ff;color:white;border:none;
             border-radius:20px;cursor:pointer}
    #fai-btn:disabled{background:#bbb;cursor:default}
    #fai-counter{font-size:12px;color:#888;margin-top:6px}
  </style>

  <div id="fai-chat">
    <div class="fai-msg fai-bot">Hallo! Wie kann ich dir helfen?</div>
  </div>

  <div class="fai-row">
    <input id="fai-input" type="text" placeholder="Deine Nachricht…">
    <button id="fai-btn" onclick="faiSend(this)">Senden</button>
  </div>

  <div id="fai-counter">
    Nachrichten: <span id="fai-count">0</span> / 3
  </div>

</div>
```

**Wichtig:** Kein `<script>`-Block nötig — die gesamte Logik steckt bereits
im formr-Bundle.

---

## Konfigurationsoptionen

Alle Einstellungen kommen als `data-`-Attribute an den äußeren `<div class="fai-widget">`:

| Attribut | Typ | Standard | Beschreibung |
|---|---|---|---|
| `class="fai-widget"` | — | — | **Pflicht.** Markiert den Wrapper als aktives Chat-Widget. |
| `data-fai-min` | Zahl | `0` | Mindestanzahl erfolgreicher KI-Antworten, bevor der „Weiter"-Button freigeschaltet wird. `0` = keine Mindestanforderung. |
| `data-fai-system-prompt` | Text | leer | Anweisung für die KI, die vor jeder Nutzeranfrage mitgeschickt wird. Definiert Thema, Ton und Sprache der KI. |

---

## System-Prompt: Wo und wie?

Der System-Prompt ist die Hintergrundinformation für die KI — er wird dem
Nutzer nicht angezeigt, beeinflusst aber, wie die KI antwortet.

**Beispiele:**

```html
<!-- Klimapolitik-Studie -->
data-fai-system-prompt="Du bist ein sachlicher Gesprächspartner zum Thema Klimapolitik.
Antworte auf Deutsch, kurz (max. 2 Sätze), ohne zu werten."

<!-- Sprachlernen -->
data-fai-system-prompt="You are a friendly English tutor. Correct grammatical errors
gently and keep your answers short."

<!-- Psychologische Studie (neutrale Haltung) -->
data-fai-system-prompt="Du bist ein neutraler Gesprächspartner. Stelle offene Fragen,
gib keine Ratschläge und bewertet nicht."
```

Der Prompt wird sicher server-seitig verarbeitet — Studienteilnehmer sehen
ihn nicht.

---

## Admin-Einstellungen (Account → KI-Einstellungen)

Im Admin-Bereich (`Account → KI-Einstellungen`) konfigurierst du die
**technische Infrastruktur**, die alle Widgets auf dieser formr-Instanz nutzen:

| Einstellung | Beschreibung |
|---|---|
| **KI-Anbieter** | Claude (Anthropic) oder OpenAI auswählen |
| **Modell** | Welches konkrete Modell verwendet wird (z. B. `claude-sonnet-4-6`) |
| **API-Schlüssel** | Dein persönlicher API-Schlüssel für den gewählten Anbieter |
| **Min. Nachrichten (global)** | Fallback-Wert für `data-fai-min`, wenn nicht am Widget gesetzt |
| **Max. Nachrichten (global)** | Maximale Konversationslänge für den offiziellen `ai_chat`-Item-Typ |
| **Min./Max. Wörter pro Nachricht** | Validierung pro Nachricht (nur `ai_chat`-Item-Typ) |

**Priorität:** Widget-Attribut (`data-fai-min`) hat immer Vorrang vor dem
globalen Admin-Wert.

---

## Vollständiges Beispiel: ki_studie

Das folgende Beispiel ist das vollständig kommentierte Widget, wie es in der
Studie „ki_studie" verwendet wird:

```html
<div style="font-family:sans-serif;max-width:600px"
     class="fai-widget"
     data-fai-min="3"
     data-fai-system-prompt="Du bist ein sachlicher Gesprächspartner zu Klimapolitik.
Antworte auf Deutsch, kurz (max 2 Sätze). Gib keine persönlichen Empfehlungen.">

  <!-- Instruktion für den Teilnehmer -->
  <p style="color:#555;font-size:13px;margin-bottom:12px">
    Führe das Gespräch frei. Schreibe mindestens 3 Nachrichten.
  </p>

  <!-- Styling – kann beliebig angepasst werden -->
  <style>
    #fai-chat{border:1px solid #ddd;border-radius:8px;height:360px;
              overflow-y:auto;padding:15px;background:white;margin-bottom:10px}
    .fai-msg{margin:8px 0;padding:10px 14px;border-radius:18px;max-width:82%;
             line-height:1.5;word-wrap:break-word;font-size:14px}
    .fai-user{background:#0084ff;color:white;margin-left:auto;text-align:right}
    .fai-bot{background:#f0f0f0;color:#222}
    .fai-row{display:flex;gap:8px}
    #fai-input{flex:1;padding:10px;border:1px solid #ccc;border-radius:20px;font-size:14px}
    #fai-btn{padding:10px 18px;background:#0084ff;color:white;border:none;
             border-radius:20px;cursor:pointer}
    #fai-btn:disabled{background:#bbb;cursor:default}
    #fai-counter{font-size:12px;color:#888;margin-top:6px}
  </style>

  <!-- Chat-Verlauf: Startnachricht der KI -->
  <div id="fai-chat">
    <div class="fai-msg fai-bot">
      Hallo! Ich freue mich auf unser Gespräch über Klimapolitik.
      Was beschäftigt dich zu diesem Thema?
    </div>
  </div>

  <!-- Eingabezeile: Enter oder Klick auf Senden -->
  <div class="fai-row">
    <input id="fai-input" type="text" placeholder="Deine Nachricht…">
    <!-- WICHTIG: onclick="faiSend(this)" — das "this" muss mit übergeben werden -->
    <button id="fai-btn" onclick="faiSend(this)">Senden</button>
  </div>

  <!-- Zähler: zeigt an, wie viele Nachrichten bereits gesendet wurden -->
  <div id="fai-counter">
    Nachrichten: <span id="fai-count">0</span> / 3
  </div>

  <!-- KEIN <script>-Block nötig! Die Logik kommt aus dem formr-Bundle. -->

</div>
```

**Was sich im Vergleich zu einer alten Version ändert:**

| Altes HTML | Neues HTML |
|---|---|
| `<div style="...">` | `<div style="..." class="fai-widget" data-fai-min="3" data-fai-system-prompt="...">` |
| `onclick="faiSend()"` | `onclick="faiSend(this)"` |
| Langer `<script>`-Block am Ende | Kein `<script>` nötig |

---

## Authentifizierung: Kein API-Schlüssel im HTML

Die Anfragen an die KI laufen nicht direkt von deinem Browser zu OpenAI/Claude,
sondern über den formr-Server. Das hat mehrere Vorteile:

- Dein API-Schlüssel bleibt geheim (nur auf dem Server gespeichert)
- Teilnehmer-Anfragen werden über die formr-Session authentifiziert
- Rate-Limiting und Logging funktionieren automatisch

**Technisch:** Der Button löst `faiSend(this)` aus → formr schickt eine
POST-Anfrage an `/run/{run-name}/ajax_ai_complete` → der formr-Server prüft
die Session → leitet die Anfrage an die KI weiter → gibt die Antwort zurück.

---

## Fehlersuche

### „Senden"-Klick tut nichts

- Prüfe, ob `class="fai-widget"` am äußeren `<div>` vorhanden ist
- Prüfe, ob der Button `onclick="faiSend(this)"` hat (mit `this`)
- Öffne die Browser-Konsole (F12) und lade die Seite neu — erscheint
  `[AiChat] init, form found: 1`?

### Chat sendet, aber KI antwortet mit Fehler

- Ist im Admin-Bereich ein API-Schlüssel hinterlegt (`Account → KI-Einstellungen`)?
- Ist das KI-Feature in den Einstellungen aktiviert?
- Prüfe den Browser-Netzwerk-Tab: POST auf `ajax_ai_complete` — was antwortet der Server?

### „Weiter"-Button bleibt gesperrt

- Ist `data-fai-min` korrekt gesetzt? (`data-fai-min="3"` für 3 Nachrichten)
- Hat die KI tatsächlich geantwortet (kein Verbindungsfehler)?
- Prüfe, ob `<span id="fai-count">` im HTML vorhanden ist

### Enter-Taste submitted das Formular

- Prüfe, ob `class="fai-widget"` am äußeren `<div>` gesetzt ist
- Die Enter-Behandlung wird von formr erst initialisiert, wenn `.fai-widget`
  erkannt wird

### Älterer `<script>`-Block im HTML erzeugt Fehler

Wenn dein Label noch einen `<script>`-Block mit `function faiSend() {...}`
enthält, entferne diesen — er wird nicht mehr benötigt und kann Konflikte
verursachen, da formr die Funktion bereits bereitstellt.
