# bitCONTROL

Dynamischer Variablen-Controller für IP-Symcon. Steuert Ausgangsvariablen basierend auf konfigurierbaren Triggern und drei Steuerungsmodi.

## Steuerungsmodi

### Regel-Modus

Priorisierte Regeln mit Bedingungen und Aktionen. Nutzt die Symcon-nativen SelectCondition/SelectAction-Dialoge. Die erste zutreffende Regel gewinnt (Reihenfolge per Drag & Drop konfigurierbar).

### Formel-Modus

Mathematische Ausdrücke mit Variablen-Platzhaltern — kein Programmieren nötig.

Verfügbare Funktionen: `min()`, `max()`, `clamp()`, `abs()`, `round()`, `floor()`, `ceil()`, `avg()`, `sum()`

### Expert-Modus

Vollständiger PHP-Code für komplexe Szenarien. Trigger-Aliase stehen als Variablen zur Verfügung, Ausgangsvariablen werden automatisch geschrieben.

## Trigger

- **Ereignis-Trigger:** Bei Änderung, Bei Aktualisierung, Bei Grenzüberschreitung/-unterschreitung, Bei bestimmtem Wert
- **Zyklische Trigger:** Tages-/Wochen-/Monatsintervall mit Zeitmuster (Symcon-Standard)
- Mehrere Trigger in einer sortierbaren Liste (Drag & Drop)

## Features

- Freie Aliase entkoppeln Logik von Symcon-IDs
- Vorlaufzeit: Aktion erst nach stabiler Bedingung
- Nachlaufzeit: Aktion bleibt nach Ende der Bedingung aktiv
- Drag & Drop Priorisierung
- Live-Status (aktive Regel, letzte Auswertung)
- Validierung von Formeln und Scripts vor dem Speichern

## Öffentliche API

Prefix: `BIT`

| Funktion | Beschreibung |
|----------|-------------|
| `BIT_Evaluate($InstanceID)` | Löst eine sofortige Auswertung aller Regeln/Formeln aus |
| `BIT_GetActiveRule($InstanceID)` | Gibt den Namen der aktuell aktiven Regel zurück |
| `BIT_SetMode($InstanceID, $Mode)` | Setzt den Steuerungsmodus (0 = Regel, 1 = Formel, 2 = Expert) |
| `BIT_ValidateFormula($InstanceID, $Formula)` | Validiert eine Formel und gibt Fehler oder Leerstring zurück |
| `BIT_ValidateScript($InstanceID, $Script)` | Validiert ein PHP-Script und gibt Fehler oder Leerstring zurück |

## Voraussetzungen

- IP-Symcon 7.0+
- PHP 8.1+
