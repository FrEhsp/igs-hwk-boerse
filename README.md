# HWK-Börse für die Homepage der IGS Guxhagen

Der Server der Schulhomepage (Strato) erreicht die Ausbildungsbörse der Handwerkskammer Kassel
(hwk-kassel.odav.de) nicht. Deshalb holt GitHub die öffentliche Suche zweimal am Tag ab
(Ausbildung, duales Studium, Schul-, FOS- und Ferienpraktika im Umkreis von 25 km um 34302 Guxhagen)
und legt das Ergebnis in `hwk.json` ab. Das Plugin `igs-ausbildungsplaetze` (ab 1.2.1) liest diese Datei.

- `abruf.php` holt die Liste und liest neue Anzeigen einmal einzeln (Schulabschluss, Vergütung im 1. Jahr, Praktikum möglich).
- `lesen.php` enthält dieselben Leseregeln wie das Plugin.
- `.github/workflows/abruf.yml` startet den Abruf um 5:23 und 15:23 Uhr (Winterzeit). Von Hand: Actions → „HWK-Börse abholen“ → Run workflow.

Übernommen werden nur Beruf, Betrieb, Ort, Beginn, Schulabschluss und Vergütung. Jede Zeile auf der Homepage verlinkt zur Anzeige bei der HWK.
Wenn die Handwerkskammer die Adresse des Schulservers freigibt, fragt das Plugin wieder direkt, und dieses Repository wird nicht mehr gebraucht.
