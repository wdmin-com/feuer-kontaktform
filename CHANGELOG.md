# Changelog

## [3.6.1] - 2026-11-02

- Filter und Massenaktionen im Posteingang kompakter angeordnet.
- Leerfläche und graue Aktionsleiste unter jeder Anfrage entfernt.
- Ticketliste nutzt jetzt die verfügbare Admin-Breite besser aus.

## [3.6.0] - 2026-11-02

- Administrationsbereich mit einheitlicher Typografie, Abständen, Formularen, Karten, Tabs und Statusfarben überarbeitet.
- Lesbarkeit und responsive Darstellung für Anfragen, Abteilungen, Einstellungen, Telegram und Protokolle verbessert.
- Ungezieltes umbrechen beliebiger Wörter entfernt, damit Beschriftungen und Hinweise sauber lesbar bleiben.

## [3.5.1] - 2026-11-01

- Antworten und erneutes Senden für archivierte Anfragen serverseitig gesperrt.
- Interne Notizen werden im WordPress-Datenschutzexport mit ausgegeben und bei einer Datenlöschung anonymisiert.

## [3.5.0] - 2026-11-01

- Anfragen lassen sich vor dem Öffnen direkt als Spam markieren, archivieren oder in den Papierkorb verschieben.
- Massenaktionen für Spam, Papierkorb, Archiv und Wiederherstellung ergänzt.
- Filter nach Abteilung, Thema, Zuständigkeit und Zeitraum sowie Sortierung nach Eingangsdatum ergänzt.
- Anfragen können Teammitgliedern zugewiesen und mit privaten internen Notizen versehen werden.
- Archivansicht mit Wiederherstellung ergänzt.
- E-Mail-Benachrichtigungen für neue Anfragen mit frei wählbaren WordPress-Empfängern ergänzt; Nachrichtentexte werden nicht mitgesendet.
- Sicherungen enthalten Zuständigkeiten und interne Notizen.


## [3.4.5] - 2026-11-01

- Release-Version synchronisiert.

## [3.4.2] - 2026-10-30

- Startseite der Verwaltung kompakter und klarer gegliedert.
- Hero-Bereich und Schnellzugriffskarten nehmen weniger vertikalen Raum ein.
- Der Menüpunkt „Anfragen“ verwendet jetzt einen eigenen Slug, damit die Startseite nicht durch die Ticketliste ersetzt wird.
- Interne Such-, Aktions- und Dashboard-Links für Anfragen auf den neuen Slug aktualisiert.

## [3.4.1] - 2026-10-30

- Verwaltungsbereich auf eine ruhige Schwarz-Weiß-Graupalette umgestellt.
- Farbe wird nur noch für Warnungen, Fehler, Erfolge und Statusmeldungen verwendet.
- Dekorative Farbakzente der Startkarten und des Hero-Bereichs entfernt.
- Nicht vorhandene CSS-Variable `--feuer-dark` durch den vorhandenen neutralen Texttoken ersetzt.

## [3.4.0] - 2026-10-30

- Öffentliche Ansprechpartner werden automatisch als übersichtliche Kontaktkarten auf der Kontaktstrecke angezeigt.
- Abteilungen können Name, Telefon, Bereich und eine ausführliche Beschreibung für den Ansprechpartner hinterlegen.
- Kontaktkarten bieten direkte Aktionen für Formular, E-Mail und Telefon.
- Kontaktdaten lassen sich pro Abteilung öffentlich oder geschützt veröffentlichen.
- Geschützte Kontaktdaten werden erst nach einer Sicherheitsprüfung für echte Besucher freigegeben und bleiben für Crawler verborgen.
- Sicherungen und Wiederherstellungen berücksichtigen die neuen Ansprechpartner-Felder.

## [3.3.0] - 2026-09-20

- HTML-Editor für Autoantworten mit Umschaltung zwischen HTML-Code und sicherer Live-Vorschau ergänzt.
- Shortcodes können per Schaltfläche direkt an der Cursorposition eingefügt werden.
- Vorschau verwendet Beispieldaten und ein sandboxed iframe; Skripte werden nicht ausgeführt.
- Zwei deutsche Textkorrekturen im Verwaltungsbereich übernommen.

## [3.2.0] - 2026-09-08

- Neue Darstellungsvariante „Kontaktkarten mit Dialog“: Bereich auswählen, Thema im Popup wählen und direkt zum passenden Eingabefenster wechseln.
- Neue Darstellungsvariante „FAQ mit Kontaktübergang“: Antworten als zugängliche Akkordeons mit direktem Übergang zur Anfrage.
- Bestehende Designvarianten und Shortcode-Auswahl bleiben unverändert nutzbar.

## [3.1.1] - 2026-09-06

- Telegram-Arbeitsbereich um eine deutschsprachige BotFather-Anleitung und eine geschützte Live-Prüfung des Bot-Tokens ergänzt.
- Unvollständige oder ungültige Telegram-Daten werden direkt am Eingabefeld erklärt; der eingegebene Token wird vor dem Speichern nicht abgelegt oder zurückgegeben.
- Webhook-Registrierung gegen ungültige Provider-Antworten und unerwartete Remote-Fehler abgesichert, damit eine Telegram-Störung WordPress nicht blockieren kann.

## [3.1.0] - 2026-09-04

- Mindestversion auf PHP 8.1 angehoben und Kompatibilitätsmetadaten für WordPress 7.1 aktualisiert.
- Grundlage für den geschützten Telegram-Arbeitsbereich ergänzt: verschlüsselte Bot-Konfiguration, Webhook-Endpoint, einmalige Empfängerkopplung und berechtigte Ticketaktionen.
- Neue Telegram-Datenbanktabellen für Empfänger, Abteilungsrouting und kurzlebige Einmalaktionen ergänzt.
- Ticketbenachrichtigungen können Tickets übernehmen, als gelesen markieren oder als Spam kennzeichnen; jede Aktion wird im Plugin-Protokoll dokumentiert.
- Direkte E-Mails werden zunächst ohne Weiterleitung und ohne Autoantwort in die Quarantäne gelegt. Erst nach „In Bearbeitung übernehmen“ erhält der Absender eine Bearbeitungsbestätigung.
- Telegram-Empfänger können über den privaten Bot-Chat Vorname, Nachname und persönliche Antwortsignatur hinterlegen.
- Die Plugin-Startseite wurde als animierter Verwaltungs-Hub mit berechtigungsabhängigen Schnellzugriffen überarbeitet.
- Technische Spezifikation für Browseransicht, Dateivorschau, Spam-Quarantäne und die folgenden Ausbaustufen ergänzt.
















## [3.0.50] - 2026-07-02

- Release-Version synchronisiert.

## [3.0.49] - 2026-06-30

- Beschädigte Textkodierung im gesamten Plugin bereinigt, insbesondere in `index.php`, `README.md` und historischen Changelog-Einträgen.
- Deutsche Umlaute und Sonderzeichen werden wieder als echtes UTF-8 gespeichert.
- Release-Paket neu gebaut und auf BOM-freie PHP-Dateien sowie sichere ZIP-Pfade geprüft.

## [3.0.48] - 2026-06-30

- Anfragen-Menue und Kopfbereich auf die funktionierende Struktur aus Version 3.0.40 zurueckgefuehrt.
- Der sichtbare Unterpunkt heisst wieder `Anfragen`, die Seite zeigt wieder `Anfragen` und den Container `Letzte Anfragen`.
- Zusatzschutz gegen doppelte Hook-Registrierung und doppelte Seitenausgabe bleibt erhalten.
- Beschädigte UTF-8-Zeichen in Runtime-Texten der Plugin-Metadaten und IMAP-Protokollmeldungen bereinigt.

## [3.0.47] - 2026-06-29

- Fehler in der WordPress-Menue-Registrierung behoben: der Hauptmenue-Slug rendert die Anfragen-Seite jetzt direkt, der erste Unterpunkt heisst sichtbar `Anfragen`.
- Unsichere Redirects aus dem Admin-Page-Callback entfernt; die passende Admin-Seite wird direkt gerendert.
- Suche, Dashboard-Links und Formularaktionen nutzen wieder denselben stabilen WordPress-Slug `feuer-kontaktform`.

## [3.0.46] - 2026-06-29

- Anfragen-Seite wieder als eigenes Untermenue `Anfragen` registriert, damit WordPress die Seite nicht mit dem Hauptmenue `Kontaktform` verwechselt.
- Der sichtbare Kopfbereich mit `Kontaktform Anfragen`, `Letzte Anfragen` und Beschreibung wurde wiederhergestellt.
- Der Release wurde mit neuer Patch-Version gebaut, damit WordPress das Update tatsaechlich erkennt und installiert.

## [3.0.45] - 2026-06-29

- Ursache fuer die doppelte Anfragen-Ausgabe behoben: Hauptmenue und Anfragen-Untermenue verwendeten denselben WordPress-Slug.
- Das Kontaktform-Hauptmenue rendert die Anfragen-Seite jetzt direkt, wenn die Rolle Anfragen sehen darf; das doppelte Untermenue mit identischem Slug wurde entfernt.
## [3.0.44] - 2026-06-29

- Der obere Bereich der Anfragen-Seite wurde vollstaendig reduziert: kein Seitentitel, kein erklaerender Text, kein innerer Card-Container.
- Die Anfragen-Seite beginnt jetzt direkt mit Suche und Anfragenliste.
## [3.0.43] - 2026-06-29

- Auf der Anfragen-Seite wurde der innere `feuer-admin-card` Container vollstaendig entfernt.
- Suche und Anfragenliste liegen jetzt direkt im einzigen Seitencontainer.
## [3.0.42] - 2026-06-29

- Auf der Anfragen-Seite wurde der zusaetzliche innere Titel `Letzte Anfragen` entfernt.
- Die Seite zeigt jetzt nur noch einen sichtbaren Titel und darunter direkt den Container mit Suche und Anfragenliste.
## [3.0.41] - 2026-06-29

- Online-Ticketansicht fuer Abteilungen bereinigt: erstes Anfragefeld und Verlauf werden nicht mehr als zwei getrennte, doppelte Bereiche angezeigt.
- Die Kommunikation wird jetzt als ein Dialog dargestellt; eingehende Nachrichten stehen links, Antworten der Abteilung rechts.
- Doppelte identische Nachrichten werden im Dialogauszug nur einmal angezeigt.
## [3.0.40] - 2026-06-29

- Doppelte sichtbare Bezeichnung `Kontaktform Anfragen` bereinigt.
- Die Anfragen-Seite heisst jetzt kurz `Anfragen`; das WordPress-Dashboard-Widget heisst `Letzte Kontaktform-Anfragen`.
## [3.0.39] - 2026-06-29

- Separate JSON-Sicherung fuer alle Anfragen hinzugefuegt: Abteilungen, Themen, Tickets und Nachrichtenverlauf ohne Systemeinstellungen und Protokolle.
- Neue Einstellung fuer den Abteilungs-Zugriff: Empfangsbestaetigung kann vor dem Anzeigen der Anfrage erzwungen oder deaktiviert werden.
- Bei deaktivierter Pflichtbestaetigung markiert der sichere Ticket-Link den Empfang automatisch und oeffnet die Anfrage direkt.
- Versions-Sync liest und schreibt Dateien jetzt explizit als UTF-8 ohne BOM, damit Umlaute bei neuen Versionen nicht erneut beschaedigt werden.
## [3.0.38] - 2026-06-29

- Kritischer PHP-Startfehler behoben: UTF-8-BOM vor `<?php` wurde aus PHP-Dateien entfernt.
- Versions-Sync schreibt Dateien jetzt explizit als UTF-8 ohne BOM, damit `declare(strict_types=1)` nicht blockiert wird.
- `uninstall.php` laedt den Inbound-Support jetzt vollstaendig, bevor die Plugin-Uninstall-Routine gestartet wird.
- Production-ZIP neu gebaut und auf sichere Pfade, Server-Entpackbarkeit und BOM-freie PHP-Dateien geprueft.

## [3.0.37] - 2026-06-29

- Hotfix gegen kritische Fehler nach 3.0.36.
- Riskante typed callbacks in den neuen IMAP-Postfach-Einstellungen entfernt.
- Fallback-Ketten in Empfaenger-Ausgabe und Dashboard-Widget vereinfacht.
- Sichere Fallbacks fuer String-Pruefungen und IV-Erzeugung bei Secret-Verschluesselung ergaenzt.
- Production-ZIP mit Strukturpruefung neu gebaut.
## [3.0.36] - 2026-06-29

- Mehrere zusätzliche IMAP-Postfächer können für den Ticket-Mailabruf konfiguriert werden.
- SMTP-, IMAP- und externe Antispam-Secrets werden verschlüsselt in den WordPress-Optionen gespeichert.
- Frontend-Spamschutz erweitert: Honeypot, optional Cloudflare Turnstile und optional Google reCAPTCHA v2.
- WordPress-Dashboard-Widget für berechtigte Benutzer mit den letzten Anfragen.
- Privacy-Erase entfernt jetzt zusätzlich hochgeladene Dateien aus Ticket-Feldern.
- Release-Build prüft ZIP-Einträge auf serverfreundliche Pfade und saubere Attribute.
- Composer-Konfiguration für PHPCS/WPCS und PHPStan ergänzt.

## 3.0.35 - 2026-06-22

- Die öffentliche `index.php` wurde als professionelle Plugin-Präsentationsseite neu aufgebaut.
- Angaben zu Dateianzahl, Codezeilen und Zeichen wurden entfernt.
- Die Seite beschreibt jetzt Zweck, Kernfunktionen, Vorteile für freiwillige Feuerwehren, Datenschutzanforderungen in Deutschland, E-Mail-Betrieb, WordPress-Einbindung, Autor und Projektseite.
- Der Statistik-Scanner in `index.php` wurde entfernt, damit die öffentliche Seite statisch, schneller und einfacher zu betreiben ist.

## 3.0.34 - 2026-06-21

- WP-Cron für das Ticket-Postfach wird bei Aktivierung, Update und nach dem Speichern der Einstellungen zuverlässig geplant.
- Im Tab `Ticket-Postfach` wird angezeigt, ob die nächste automatische Postfachprüfung geplant ist.
- Bei aktivem `DISABLE_WP_CRON` zeigt die Administration einen Hinweis, dass der Server WordPress-Cron extern aufrufen muss.
- Geschützte Anfrage-Links nutzen jetzt kurze nicht erratbare Zugriffscodes statt langer Token.
- Der Zugriffscode wird zusätzlich als Cookie-Sitzung gesetzt, aber nicht mehr aus der URL entfernt, damit berechtigte Personen den Link weitergeben und öffnen können.
- Das Ändern der Anfrage-ID in der URL reicht weiterhin nicht aus, weil jede Anfrage einen eigenen geheimen Code benötigt.
- Alte bereits versendete Links mit langem `token` bleiben bis zum Ablauf kompatibel.
- Wenn ein angemeldeter WordPress-Benutzer die geschützte Anfrage öffnet, wird sein vollständiger Name im Antwortformular automatisch eingetragen.

## 3.0.33 - 2026-06-14

- Neue E-Mails ohne Anfrage Nr. können direkt am Ticket-Postfach als eigenständige Anfrage angenommen werden.
- Jede direkte E-Mail erhält automatisch eine 8-stellige Anfrage Nr. und wird vollständig im Anfrageverlauf gespeichert.
- Direkte E-Mail-Anfragen werden an einen frei konfigurierbaren Standard-Empfänger weitergeleitet.
- Der Standard-Empfänger erhält den geschützten Browserdialog mit Anfrageansicht, Verlauf, Empfangsbestätigung und Antwortformular.
- Optional erhält der ursprüngliche Absender eine automatische Empfangsbestätigung mit Anfrage Nr.
- Antworten werden auch dann über die sichere Plus-Adresse zugeordnet, wenn die Anfrage Nr. aus dem Betreff entfernt wurde.
- Automatische Antworten, Zustellberichte und interne Absenderadressen werden zum Schutz vor E-Mail-Schleifen abgewiesen.
- Direkte E-Mail-Anfragen werden in der Administration, im Datenschutzexport und in der JSON-Sicherung als eigener Eingangsweg geführt.

## 3.0.32 - 2026-06-14

- Abteilungen können eine Anfrage über einen zeitlich begrenzten, signierten Zugriff sicher öffnen.
- Die geschützte Seite zeigt Anfrage, Felder und vollständigen Verlauf und erlaubt Empfangsbestätigung sowie direkte Antworten.
- Zugriffstoken werden nur als Hash gespeichert, nach dem ersten Öffnen aus der URL entfernt und in eine `HttpOnly`-Cookie übernommen.
- Administratoren sehen Gültigkeit und letzte Nutzung und können einen Zugriff sofort widerrufen.
- Neues bidirektionales Ticket-Postfach, zum Beispiel `anfrage@domain.de`, mit IMAP-Import alle fünf Minuten.
- Antworten von Besuchern werden gespeichert und lösen eine neue Benachrichtigung mit frischem Zugriffslink an die Abteilung aus.
- Antworten der Abteilung aus dem E-Mail-Programm werden gespeichert und über den Plugin-Mailer an den Besucher weitergeleitet.
- Sichere Plus-Adressen wie `anfrage+fkt-12345678-securecode@domain.de` schützen den Import zusätzlich zu Anfrage Nr. und Absenderprüfung.
- Benutzerdefinierte Autoantwort-Betreffzeilen erhalten automatisch die Anfrage Nr., wenn sie dort fehlt.
- Der WordPress-Datenschutzexport enthält jetzt auch den vollständigen Nachrichtenverlauf.
- Voraussetzung für den E-Mail-Import: PHP-IMAP und Plus-Adressierung oder Catch-all beim Mailanbieter.

## 3.0.31 - 2026-06-14

- Abteilungs-E-Mails enthalten jetzt eine signierte Empfangsbestätigung.
- Die Bestätigung erfolgt erst nach einem bewussten Klick auf der Bestätigungsseite, damit automatische Link-Prüfungen von Mailservern keinen falschen Empfang melden.
- Anfragen speichern und zeigen Versandstatus, Empfänger, Anzahl der Versuche, Versandzeit, Bestätigungszeit und den letzten technischen Fehler.
- Fehlgeschlagene oder unbestätigte Abteilungs-E-Mails können direkt aus der Anfrage erneut gesendet werden, ohne die Autoantwort an den Besucher zu wiederholen.
- Fehler aus `wp_mail()` und PHPMailer werden jetzt für Testmails, Abteilungs-E-Mails, Autoantworten und Antworten aus dem Plugin protokolliert.
- Eine aktivierte, aber unvollständige SMTP-Konfiguration wird nicht mehr still über den Standardversand umgangen.

## 3.0.30 - 2026-05-14

- Version 3.0.29 wurde als stabiler Zugriffs-Release dokumentiert.
- README, Präsentationsseite und Update-Metadaten beschreiben die Rollen- und Rechteverwaltung jetzt explizit als eigenständigen stabilen Release-Schritt.

## 3.0.29 - 2026-05-14

- Neue Zugriffssteuerung für das Plugin nach WordPress-Rollen.
- Administratoren behalten immer Vollzugriff.
- Für Redakteur und andere Rollen kann jetzt pro Bereich festgelegt werden, ob sie Anfragen sehen, antworten, Abteilungen verwalten, Einstellungen öffnen oder Protokolle sehen dürfen.
- Die Zugriffs-Matrix selbst kann nur vom Administrator geändert werden.

## 3.0.28 - 2026-05-04

- Interne Benachrichtigungen zeigen bei `Direkte Antwort an` jetzt nur noch die reine E-Mail-Adresse.
- Der echte `Reply-To` Header für interne Benachrichtigungen wird jetzt ebenfalls nur noch als reine E-Mail-Adresse gesetzt, um Probleme mit einzelnen Mailprogrammen und gespeicherten Antwortadressen zu vermeiden.

## 3.0.27 - 2026-05-04

- Bitte Texte in der Adminoberfläche wurden auf korrekte UTF-8-Umlaute bereinigt.
- Sichtbare Begriffe wie `Empfänger`, `Prüfung`, `Datenschutzerklärung`, `zurück`, `über`, `Größe`, `Aktivitäten` und die neue Sicherungsoberfläche zeigen jetzt wieder normale deutsche Zeichen.

## 3.0.26 - 2026-05-04

- Neue Sicherungsfunktion im Adminbereich für Export und Wiederherstellung aller Plugin-Daten als JSON-Datei.
- Gesichert werden jetzt Einstellungen, Empfänger-E-Mails, Abteilungen, Themen, Anfragen, Nachrichtenverläufe und Protokolle.
- Wiederherstellungen ersetzen den kompletten aktuellen Plugin-Bestand und protokollieren den Import im Plugin-Log.

## 3.0.25 - 2026-05-01

- Der Updater liest nach der Installation die tatsächlich installierte Plugin-Version direkt aus der Plugin-Datei.
- WordPress- und Plugin-Update-Caches werden nach der Installation konsequenter geleert.
- Wenn nach einem Update weiterhin die alte Version installiert ist, meldet der Updater jetzt einen klaren Fehler statt erneut still eine neue Version anzuzeigen.

## 3.0.24 - 2026-05-01

- Der Plugin-Name in der WordPress-Pluginliste und in den Plugin-Infos lautet wieder `Feuer-Kontaktform`.
- Das Admin-Menü bleibt weiterhin verkürzt bei `Kontaktform`.

## 3.0.23 - 2026-05-01

- Interne Benachrichtigungen setzen den `Reply-To` jetzt mit Name und E-Mail des eigentlichen Antwortziels.
- Benachrichtigungs-E-Mails zeigen sichtbar an, an wen eine direkte Antwort aus dem Mailprogramm gesendet wird.
- Die Einstellung `Reply-To für interne Benachrichtigung` markiert den Absender der Anfrage jetzt als empfohlene Option.

## 3.0.22 - 2026-04-30

- Sichtbarer Plugin-Name in der WordPress-Adminoberfläche wurde auf `Kontaktform` verkürzt.
- Menüeinträge, Überschriften, Plugin-Infos und Privacy-Labels verwenden jetzt ebenfalls `Kontaktform`.

## 3.0.21 - 2026-04-29

- Standard-Autoantworten verwenden wieder durchgehend `Anfrage Nr.`.
- Der alte Hinweistext zum Fortführen des Dialogs im Betreff wurde aus Text- und HTML-Autoantwort entfernt.
- Bestehende Themen werden beim Update auf die neue Standard-Eingangsbestätigung migriert.

## 3.0.20 - 2026-04-23

- Direkte Beispiel-Links werden jetzt auch direkt in der Abteilungs-Konfiguration angezeigt.
- Direkte Beispiel-Links werden jetzt auch direkt in der Themen-Konfiguration angezeigt.
- Der `Shortcodes`-Tab jeder Abteilung zeigt jetzt zusätzlich direkte URL-Beispiele für Abteilung und Thema.

## 3.0.19 - 2026-04-23

- Seiten mit eingebautem Formular unterstützen jetzt direkte Sprung-Links über URL-Parameter.
- Unterstützt werden `?feuer_department=slug` und `?feuer_department=slug&feuer_topic=slug`.
- Zusätzlich werden die deutschen Aliase `?abteilung=slug` und `?thema=slug` verstanden.
- Im Bereich `Einstellungen -> Shortcodes` werden jetzt konkrete Beispiel-Links angezeigt.

## 3.0.18 - 2026-04-23

- Admin-Oberfläche erzeugt den Slug für Abteilungen und Themen jetzt live aus `Name` oder `Thema`.
- Umlaute wie `Ä`, `Ö`, `Ü`, `ä`, `ö`, `ü` und `ß` werden für den Slug sauber in `ae`, `oe`, `ue` und `ss` umgesetzt.
- Manuell geänderte Slugs bleiben erhalten und werden nicht automatisch überschrieben.
- Asset-Version erhöht, damit WordPress den neuen Admin-JavaScript-Stand direkt lädt.

## 3.0.17 - 2026-04-23

- Speichern von Abteilungen zeigt jetzt konkrete Fehlermeldungen bei leerem Namen, ungültiger Empfänger-E-Mail, leerem Slug, doppeltem Slug und Datenbankfehlern.
- Leerer Abteilungs-Slug wird aus dem Namen erzeugt und leere Sortierung automatisch sauber fortgeführt.
- Themen speichern jetzt dieselbe Validierungslogik: klare Meldungen bei ungültiger Abteilung, leerem Thema, ungültiger abweichender E-Mail, leerem oder doppeltem Slug und Datenbankfehlern.
- Frontend-AJAX lehnt deaktivierte Abteilungen und Themen jetzt serverseitig ab.
- Select-Felder validieren eingereichte Werte jetzt auch serverseitig gegen die erlaubten Optionen.
- Eine Anfrage wird nur noch als erfolgreich bestätigt, wenn der Ticket-Datensatz wirklich in der Datenbank gespeichert wurde; bei INSERT-Fehlern wird kein falscher Erfolg mehr ausgegeben.
- Protokolle enthalten jetzt auch Validierungs- und Ticket-Speicherfehler für die technische Nachverfolgung.

## 3.0.16 - 2026-04-22

- Datenschutz-Auswahl im Frontend neutral gestaltet: keine grünen oder roten Buttonflächen mehr.
- Ausgewählte Datenschutz-Option zeigt jetzt eine Checkmark-Markierung direkt in der Schaltfläche.
- Datenschutz-Schaltflächen sind kompakter.
- Abstände zwischen den Eingabefeldern im Schritt `Welche Informationen brauchen wir?` reduziert.
- Asset-Version erhöht, damit WordPress und Browser den neuen Frontend-CSS/JS-Stand laden.

## 3.0.15 - 2026-04-22

- Erste finale stabile Version des neuen Feuer-Kontaktform Plugins.
- Sichtbare deutsche Texte in Adminbereich, Frontend, Autoantworten, E-Mail-Vorlagen, Update-Dialogen und Plugin-Seite verwenden jetzt konsequent Umlaute wie `ä`, `ö`, `ü` und `ß`.
- Technische Slugs, Shortcode-Attribute, Dateinamen und interne Identifikatoren bleiben unverändert, damit bestehende Einbindungen weiter funktionieren.

## 3.0.14 - 2026-04-22

- Datenschutz-Auswahl im Frontend führt bei `Ja, ich bin einverstanden` automatisch zum nächsten Schritt.
- Bei `Nein` bleibt der Nutzer im Datenschutz-Schritt und sieht die Meldung, dass die Anfrage ohne Einwilligung nicht verarbeitet werden kann.
- Wiederholter Klick auf die bereits gewählte Datenschutz-Option funktioniert auch nach `Zurück`.

## 3.0.13 - 2026-04-22

- Einstellungen enthalten jetzt neben `Update prüfen` auch `Update jetzt installieren`.
- Die manuelle Update-Prüfung aktualisiert den WordPress-Update-Transient, damit WordPress das Entwicklerpaket sofort als Update erkennt.
- Update-Installation nutzt den WordPress `Plugin_Upgrader` und installiert das Paket von `wdmin.com/plugins/feuer-kontaktform/`.
- Erfolgreiche und fehlgeschlagene Update-Installationen werden in den Protokollen gespeichert.

## 3.0.12 - 2026-04-22

- Abteilungen können in der Admin-Oberfläche per `Hoch` und `Runter` sortiert werden.
- Die neue Reihenfolge wird dauerhaft als `sort_order` gespeichert und gilt dadurch auch für die Frontend-Auswahl.
- Protokolle zeigen das Verschieben einer Abteilung als eigenes Ereignis.

## 3.0.11 - 2026-04-22

- Verlauf in Anfragen zeigt jetzt den Namen des Absenders und den vollen Namen des antwortenden Administrators statt `inbound`/`outbound`.
- Protokolle zeigen den vollständigen Namen des Administrators, wenn Vorname/Nachname gesetzt sind.
- Standard-Feldreihenfolge für neue Themen geändert: zuerst `Titel / Betreff`, dann Name und Kontaktdaten.
- Themenfelder können in der Themenkonfiguration per `Hoch` und `Runter` sortiert werden.
- Antwortformular in Anfragen wird mit Begrüßung `{{name}}` und Signatur mit Abteilung, Website-Name und Website-Link vorbelegt.
- Antwortmakros enthalten jetzt auch `{{site_url}}`.

## 3.0.10 - 2026-04-22

- Mindestanforderung für WordPress auf `6.9` gesetzt.
- Update-Metadaten melden `requires` jetzt ebenfalls als `6.9`.
- Plugin-Info liefert auch bei temporär nicht erreichbarem Update-Server eine lokale Fallback-Antwort mit korrekter Kompatibilität.

## 3.0.9 - 2026-04-22

- Plugin-Info setzt `tested` mindestens auf die aktuelle WordPress-Version, wenn die Server-Metadaten niedriger sind.
- Warnung `Dieses Plugin wurde nicht mit deiner aktuellen WordPress-Version getestet` im WordPress-Detailfenster wird dadurch vermieden.
- Update-Prüfung in den Einstellungen zeigt jetzt installierte Version, Server-Version, getestete WordPress-Version, Download-URL, letzte Prüfung und klaren Status.
- Manuelle Update-Prüfung unterscheidet zwischen `aktuell`, `Update verfügbar` und `fehlgeschlagen`.

## 3.0.8 - 2026-04-22

- Datenschutz-Schritt im Frontend verschoben: zuerst Abteilung, dann Thema, danach Datenschutz.
- Bei direkten Shortcodes auf Abteilung/Thema bleibt Datenschutz der erste sichtbare Schritt, wenn die vorherigen Schritte übersprungen werden.

## 3.0.7 - 2026-04-22

- Antworten aus dem Anfrage-Bereich verwenden jetzt `Name der Website` aus den Einstellungen im HTML-Kopf und in der Signatur.
- Logo aus den Einstellungen wird in Antworten aus dem Plugin angezeigt, wenn gesetzt.
- Platzhalter im Antworttext werden ersetzt: `{{site_name}}`, `{{ticket_id}}`, `{{name}}`, `{{topic}}`, `{{subject}}`, `{{email}}`, `{{vorname}}`, `{{nachname}}`, `{{telefon}}`.
- Hinweis auf verfügbare Platzhalter im Antwortformular ergänzt.

## 3.0.6 - 2026-04-22

- Datenschutz-Schritt im Frontend kann in den Einstellungen aktiviert oder deaktiviert werden.
- Anfragen speichern, ob der Datenschutz-Schritt aktiv war oder deaktiviert wurde.
- WordPress Privacy Export unterscheidet zwischen bestätigter Zustimmung und nicht abgefragtem Datenschutz-Schritt.
- Admin-Tabs merken sich die zuletzt geöffnete Ansicht nach dem Speichern und Redirect.

## 3.0.5 - 2026-04-22

- Datenschutz-Auswahl im Frontend als zwei klare Buttons gestaltet.
- Zustimmung wird als grüner Button hervorgehoben.
- Ablehnung wird als weisser Button mit roter Umrandung dargestellt.

## 3.0.4 - 2026-04-22

- Abteilungen & Themen neu geordnet: erster Tab `Neue Abteilung`, danach Abteilungs-Tabs mit `Abteilung`, `Shortcodes` und Themen.
- Frontend-Kontaktstrecke um Designvarianten und Feldstile erweitert.
- Überfluessige Rahmen um einzelne Frontend-Felder entfernt.
- Anfragen als aufklappbare Karten mit Kopfzeile Anfrage Nr., Abteilung, Thema, Absender und Datum neu gestaltet.
- Antworten können direkt aus dem Plugin per E-Mail gesendet und im Anfrage-Verlauf gespeichert werden.
- Betreff für Autoantwort und Abteilungsbenachrichtigung nutzt Anfrage Nr., Thema und den vom Besucher eingegebenen Betreff.
- Abteilungsbenachrichtigung als HTML-Mail ohne Datenschutz/IP/User-Agent-Daten neu gestaltet.
- WordPress Privacy Export/Erase für gespeicherte Anfrage-Daten ergänzt.
- Erlaubte Dateiformate und maximale Upload-Größe in den Einstellungen konfigurierbar gemacht.
- Update-Cache wird gegen die aktuelle Plugin-Version neu bewertet, damit Updates nach der Installation nicht dauerhaft erneut angezeigt werden.

## 3.0.3 - 2026-04-21

- Neuer Einstellungen-Tab `Zustellbarkeit` mit Prüfung und Hinweisen zu From-Domain, SPF, DMARC, DKIM und SMTP.
- SMTP-Versand direkt im Plugin ergänzt, inklusive Host, Port, Verschlüsselung, Benutzername und Passwort.
- Reply-To-Strategien für interne Benachrichtigungen und Autoantworten hinzugefügt.
- Autoantworten setzen optional `Auto-Submitted` und `X-Auto-Response-Suppress`, damit Mailserver Antwortschleifen besser erkennen.
- HTML-Autoantworten werden vor dem Speichern strenger bereinigt.
- E-Mail-Einstellungen bewahren gespeicherte Werte, wenn andere Einstellungs-Tabs gespeichert werden.

## 3.0.2 - 2026-04-21

- Abteilungen & Themen als Tab-System aufgebaut: erster Tab für Abteilungsname/E-Mail, weitere Tabs für Themen.
- Einstellungen als Tab-System gegliedert: Website & Logo, E-Mail, Datenschutz, System und Shortcodes.
- Logo-Auswahl über die WordPress-Mediathek ergänzt.
- Protokolle in eine verständlichere Aktivitätsansicht übersetzt.
- Begriff `Anfrage Nr.` in der Oberfläche eingeführt und Hinweis für E-Mail-Betreff ergänzt.
- Neue Abteilungen erhalten keine vorinstallierten Themen mehr.
- Autoantworten können je Thema aktiviert/deaktiviert und als Text oder HTML versendet werden.

## 3.0.1 - 2026-04-21

- Standard-Autoantwort für neue Themen erweitert: Begrüßung, Prüfhinweis, Anfrage Nr. und formeller deutscher Abschluss.
- Themen besitzen jetzt zwei Autoantwort-Vorlagen: Plain-Text und HTML.
- HTML-Autoantwort mit Logo-Block, Website-Name, Anfrage Nr., Datenschutzlink und sauberem E-Mail-Layout.
- Makro-Übersicht direkt unter den Autoantwort-Editoren ergänzt.
- Mailer sendet die HTML-Autoantwort mit `text/html`, wenn eine HTML-Vorlage gesetzt ist; sonst Plain-Text.

## 3.0.0 - 2026-04-21

- Vollständiger Rewrite als Support- und Ticket-System.
- Neue Datenstruktur für Abteilungen, Themen, Anfragen, Anfrage-Nachrichten und Admin-Protokolle.
- AJAX-Wizard mit Datenschutz-Schritt, Abteilungswahl, Themenwahl, dynamischen Themenfeldern, Captcha und Abschlussmeldung.
- 8-stellige zufällige Anfrage Nr. für jede Anfrage.
- Shortcodes für kompletten Wizard und direkte Schnellformulare auf Abteilung/Thema.
- Neue Adminbereiche für Anfragen, Abteilungen & Themen, Einstellungen, E-Mail-Test, Update-Prüfung und Protokolle.
- Sauberer Release-Builder ohne Windows-Archiv-Metadaten, directory entries, ACL/Owner und verschachtelte ZIP-Dateien.

## 2.5.0 - 2026-04-21

- Plugin-Metadaten auf Walter Färber und `wdmin.com/plugins/feuer-kontaktform/` aktualisiert.
- Self-hosted Update-Check mit Button in den Einstellungen hinzugefügt.
- Frontend auf einen einheitlichen Ticket-Wizard mit Datenschutz-Schritt und Ladeanzeige umgestellt.
- Anfrage Nr., Cookie-ID, Gerätetyp und Datenschutz-Kontext für Anfragen eingeführt.
- Themen pro Formular hinzugefügt; Themen können Empfänger und Autoantwort überschreiben.
- Aktivitätsprotokoll für zentrale Plugin-Aktionen hinzugefügt.
- DB-Schema auf `dbDelta()` umgestellt und neue Spalten für Anfrage-Protokolle vorbereitet.
- CSV-Export gegen Spreadsheet-Formula-Injection abgesichert.
- Frontend-Designwerte werden jetzt als scoped CSS ausgegeben.
- GitHub-Basisdateien, Update-Metadaten und Präsentationsseite ergänzt.

## 2.4.0

- Vorherige interne Version mit Formularverwaltung, SMTP, Autoantworten, Webhooks, JSON-Dateien und mehreren Layoutvarianten.
