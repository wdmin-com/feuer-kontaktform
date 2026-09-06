# Technische Spezifikation: Telegram-Arbeitsbereich fuer Anfragen

**Zielversion:** 3.1.0
**Status:** Freigegeben zur Implementierung nach fachlicher Abnahme
**Sprache der Benutzeroberflaeche:** Deutsch

## 1. Ziel und Abgrenzung

Das Plugin erhaelt einen optionalen, geschuetzten Telegram-Arbeitsbereich fuer neue Anfragen. Telegram ist ein Benachrichtigungs- und Bedienkanal; die WordPress-Datenbank bleibt die einzige fachliche Quelle fuer Tickets, Nachrichten, Dateien, Status und Protokolle.

Die Erweiterung umfasst:

- Benachrichtigungen je Abteilung und berechtigter Person bzw. Telegram-Chat.
- Lesen einer Anfrage, Uebernahme, Kennzeichnung als gelesen und Antwort aus Telegram.
- Spam- und Quarantaene-Workflow fuer direkte E-Mails und Formularanfragen.
- Eine browserbasierte, geschuetzte Vollansicht mit Nachricht, Verlauf und Dateivorschau.
- Vollstaendige deutschsprachige Administration, Protokollierung und Release-Paketierung.

Nicht Teil der ersten Ausbaustufe sind Gruppen ohne Zugriffskontrolle, ein oeffentlicher Bot, automatische Spam-Loeschung oder die Ablage von Dateikopien bei Telegram.

## 2. Nutzerrollen und Berechtigungen

| Rolle | Berechtigung |
| --- | --- |
| Plugin-Administrator | Telegram konfigurieren, Benutzer koppeln, Rechte und Routing verwalten, jede Anfrage einsehen. |
| Abteilungsadministrator | Benachrichtigungen der zugeordneten Abteilung sehen, Tickets uebernehmen, beantworten, lesen und als Spam markieren. |
| Benachrichtigungs-Empfaenger | Ausschliesslich die explizit freigegebenen Aktionen ausfuehren. |
| Besucher | Keine Telegram- oder Browserrechte durch diese Erweiterung. |

Jede Telegram-Aktion wird gegen eine lokale Zuordnung aus `telegram_user_id`, Chat-ID, Abteilung und Aktionsrechten geprueft. Der Anzeigename eines Telegram-Kontos ist niemals ein Berechtigungsmerkmal.

## 3. Bedienkonzept

### 3.1 Benachrichtigung

Bei einer neuen, nicht als Spam bewerteten Anfrage erhaelt der passende Empfaenger eine kompakte Karte:

```text
Neue Anfrage #12345678
Abteilung: Jugendfeuerwehr
Von: max@example.org
Betreff: Anfrage zur Mitgliedschaft
Eingang: heute, 14:32 Uhr
```

Konfigurierbare Schaltflaechen:

- `Anzeigen` – zeigt eine kurze, datensparsame Zusammenfassung.
- `Im Browser oeffnen` – erzeugt eine kurzlebige, einmalig nutzbare Zugriffs-URL.
- `Uebernehmen` – setzt Bearbeiter und Status `in Bearbeitung`.
- `Als gelesen` – speichert Benutzer und Zeitstempel und aktualisiert die Telegram-Karte.
- `Antworten` – fordert den Antworttext an; die Antwort wird vor Versand bestaetigt.
- `Spam` – verschiebt in die Quarantaene; eine Begruendung ist optional.

Welche Schaltflaechen sichtbar sind, wird je Telegram-Empfaenger in der Plugin-Administration eingestellt. `Antworten` und `Spam` sind standardmaessig deaktiviert, bis sie explizit freigegeben werden.

### 3.2 Antwortdialog

Nach `Antworten` wechselt der Bot in einen zeitlich begrenzten Entwurfsmodus. Der Mitarbeiter sendet den Text, sieht Empfaenger und eine Vorschau und bestaetigt anschliessend mit `Senden` oder verwirft mit `Abbrechen`.

Der Versand erfolgt ausschliesslich ueber den bestehenden Mailer. Die Nachricht wird mit Quelle `telegram` im Ticket-Verlauf, einschliesslich Telegram-Benutzer-ID, Zeit und Versandresultat, gespeichert.

### 3.3 Spam und Quarantaene

Neue direkte E-Mails erhalten vor der Weiterleitung eine Spam-Bewertung. Anfragen mit hohem Risiko landen in `Quarantaene`; dort wird weder ein Auto-Reply noch eine Abteilungsbenachrichtigung versendet.

Aktionen:

- `Als Spam markieren` – Status `spam`, keine weiteren Benachrichtigungen, optional Adresse oder Domain sperren.
- `Kein Spam` – Status `offen`, Ticket zustellen und berechtigte Empfaenger informieren.
- `Absender erlauben` bzw. `Domain sperren` – verwaltete Regeln mit Begruendung und Audit-Log.

## 4. Browseransicht und Dateivorschau

Die Schaltflaeche `Im Browser oeffnen` fuehrt auf eine eigene, nicht indexierbare Ticketansicht. Sie zeigt vollstaendigen Betreff, Absender, Nachricht, Verlauf, Status und Anhangsliste.

- Der Link ist fuer genau einen Ticket- und Telegram-Empfaenger ausgestellt, mindestens 128 Bit stark, kurzlebig (Vorgabe: 15 Minuten) und nach Gebrauch ungueltig.
- Die Seite setzt `no-store`, `Referrer-Policy: no-referrer`, CSP und `X-Frame-Options: DENY`.
- Bilder werden als begrenzte Vorschau gerendert; ein Download erfolgt nur ueber einen signierten, kurzlebigen Endpunkt.
- PDF-Dateien werden im Browser eingebettet, sofern der Browser dies unterstuetzt; alternativ wird ein geschuetzter Download angeboten.
- Andere Dateien werden mit Name, MIME-Typ, Groesse und geschuetztem Download angezeigt.
- Roh-URLs aus `wp-content/uploads` werden nicht in Telegram oder der Ticketansicht ausgegeben.

Anhaenge werden serverseitig auf Dateigroesse, Inhaltstyp und Dateisignatur validiert. Ausfuehrbare Dateien, SVG und nicht erlaubte Typen sind ausgeschlossen. Die vorhandene Upload-Whitelist wird entsprechend geschaerft.

## 5. Telegram-Integration

### 5.1 Einrichtung im Plugin

Neue Admin-Seite `Kontaktform -> Telegram` mit Bereichen:

1. **Bot-Verbindung:** Bot-Token, Verbindungstest, aktiv/inaktiv, letzter API-Fehler.
2. **Webhook:** automatisch generierte, geheime Webhook-URL; Registrierung, Test und erneute Registrierung.
3. **Empfaenger:** Kopplungscode erzeugen, Telegram-Benutzer verifizieren, Chat-ID, Anzeigename, Status und letzte Aktivitaet.
4. **Routing:** Abteilung -> Empfaenger, Benachrichtigungsart und sichtbare Aktionen.
5. **Datenschutz:** Schalter fuer Kurztext, E-Mail-Adresse maskieren und Browseransicht erzwingen.
6. **Protokoll:** Zustellungen, fehlgeschlagene API-Aufrufe und ausgefuehrte Aktionen.

Ein Mitarbeiter startet den Bot privat und sendet den einmaligen Kopplungscode. Erst nach erfolgreicher Zuordnung wird die Telegram-ID gespeichert und freigeschaltet. Gruppen werden in Version 3.1.0 nicht unterstuetzt; so bleiben personenbezogene Daten auf private, kontrollierbare Chats begrenzt.

### 5.2 Webhook und API-Sicherheit

- Eingehende Updates werden ausschliesslich an einen REST-Endpunkt mit zufaelligem, geheimem Pfad und `X-Telegram-Bot-Api-Secret-Token` akzeptiert.
- `update_id` wird dauerhaft dedupliziert; Callback-Daten enthalten keine personenbezogenen Daten und keine Zugangstoken.
- Callback-Daten referenzieren nur einen kurzlebigen, serverseitig gespeicherten Aktionsschluessel.
- Alle Bot-Aufrufe verwenden WordPress HTTP API, kurze Timeouts und Retry mit exponentiellem Backoff.
- Bot-Token und Webhook-Secret werden verschluesselt gespeichert. AES-256-GCM mit zufaelligem Nonce und Authentifizierungstag ersetzt die bisherige CBC-Verschluesselung fuer neue Geheimnisse.
- Die Kommunikation mit Telegram erfolgt nur per HTTPS; Token werden nie geloggt.
- Pro Telegram-Nutzer gelten Rate-Limits fuer Callback- und Antwortaktionen.

## 6. Datenmodell

Neue Tabellen mit dem WordPress-Praefix:

| Tabelle | Zweck |
| --- | --- |
| `feuer_support_telegram_users` | Gekoppelte Telegram-Nutzer, Chat-ID, Status, Rechte und Zeitstempel. |
| `feuer_support_telegram_routes` | Abteilungs- und optional Themenrouting zu berechtigten Nutzern. |
| `feuer_support_telegram_actions` | Kurzlebige Einmal-Aktionen, Antwortentwuerfe und Deduplizierung. |
| `feuer_support_ticket_files` | Metadaten sicher gespeicherter Anhaenge, MIME-Typ, Groesse, Pruefergebnis und Speicherpfad. |
| `feuer_support_spam_rules` | Adress-, Domain- und Regelentscheidungen mit Quelle und Ablaufdatum. |

Tickets erhalten mindestens die Felder `status`, `assigned_user_id`, `assigned_at`, `read_at`, `read_by`, `spam_score`, `spam_reason`, `quarantined_at` und `telegram_notified_at`. Bestehende Tickets werden durch eine migrationssichere Standardbelegung unveraendert nutzbar gehalten.

## 7. Spam-Bewertung fuer direkte E-Mails

Vor der Ticketerstellung werden mindestens folgende Kriterien ausgewertet:

- `Authentication-Results` fuer SPF, DKIM und DMARC, sofern der Mailserver die Header liefert.
- Auto-Reply-, Bounce-, Mailinglisten- und Bulk-Header.
- Lokale Sperr- und Erlaubnislisten fuer Adresse und Domain.
- Rate-Limits pro Absender, Domain und Postfach.
- Groessenlimit, MIME-Tiefe, Headergroesse, Zeichensatz- und Inhaltstyp-Pruefung vor vollstaendiger Dekodierung.

Die Bewertung ist transparent: Pro Ticket werden Score, ausloesende Regeln und Entscheidung im Audit-Log gespeichert. Eine fehlende SPF/DKIM/DMARC-Information allein macht eine echte Besucheranfrage nicht automatisch zu Spam.

## 8. Fehlerverhalten

- Telegram nicht erreichbar: Ticket bleibt vollstaendig in WordPress erhalten; die Zustellung wird protokolliert und erneut versucht.
- Unberechtigter Callback: keine Ticketinformation wird ausgegeben; Ereignis wird ohne Token geloggt.
- Mailversand einer Telegram-Antwort fehlgeschlagen: Entwurf und Fehler bleiben sichtbar, es wird kein falscher Erfolgsstatus angezeigt.
- Fehlerhafte oder nicht darstellbare Datei: Ticket bleibt lesbar, die Datei wird als gesperrt markiert.

## 9. Abnahmekriterien

1. Ein Administrator kann Bot, Webhook und privaten Empfaenger ohne Codeaenderung einrichten und testen.
2. Eine neue Anfrage wird nur an berechtigte Empfaenger der zugeordneten Abteilung gesendet.
3. Jede sichtbare Telegram-Aktion wird serverseitig autorisiert, dedupliziert und im Audit-Log festgehalten.
4. Eine Antwort aus Telegram erscheint im Ticket-Verlauf und wird erst nach Bestaetigung per E-Mail versendet.
5. Ein Spam-Status verhindert Auto-Reply und Abteilungsbenachrichtigungen.
6. Die Browseransicht zeigt Nachricht, Verlauf und sichere Bild-/PDF-Vorschau, ohne permanente Datei-URLs preiszugeben.
7. Token, Passwoerter und personenbezogene Daten erscheinen weder in Plugin-Logs noch in Telegram-Callback-Daten.
8. Bestehende Formulare, IMAP-Importe und Ticketlinks funktionieren nach dem Datenbank-Upgrade weiter.
9. Alle sichtbaren Texte der Erweiterung sind deutsch und UTF-8-kodiert.

## 10. Versionierung, Tests und Release

Die Erweiterung wird als funktionales Minor-Release **3.1.0** ausgeliefert.

- `feuer-kontaktform.php`, `update.json` und `CHANGELOG.md` erhalten dieselbe Version.
- Das Changelog beschreibt Funktionen, Sicherheitsmassnahmen, Migration und bekannte Voraussetzungen auf Deutsch.
- Vor dem Release: PHP-Syntaxpruefung, PHPCS, PHPStan, manuelle WordPress-Pruefung, Webhook- und Berechtigungstests, Spam-/Quarantaene-Tests sowie Bild- und PDF-Vorschau testen.
- Das vorhandene Skript `tools/build-release.ps1 -Version 3.1.0` erzeugt anschliessend `release/3.1.0/feuer-kontaktform.zip` und aktualisiert `release/latest/`.
- Das Release darf erst erstellt werden, wenn Migration, Rollback-Verhalten und die Abnahmekriterien dokumentiert getestet sind.

## 11. Implementierungsreihenfolge

1. Datenbankmigration, Statusmodell, Audit-Log und Spam-Quarantaene.
2. Sichere Attachment-Verwaltung, geschuetzte Browseransicht und Vorschau.
3. Telegram-Konfiguration, Empfaengerkopplung, Webhook und Benachrichtigungen.
4. Callback-Aktionen, Uebernahme, gelesen, Spam und Antwortentwuerfe.
5. Routing- und Rechteoberflaeche, Tests, deutsche Texte und Release 3.1.0.
