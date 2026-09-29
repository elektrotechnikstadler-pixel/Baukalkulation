---
name: retro
description: "Retrospektive der KI-Zusammenarbeit: Lernprotokoll und Erkenntnisse auswerten, Regeln in AGENTS.md/Instructions/Agents übernehmen, kürzen, Struktur gegen copier-astral abgleichen und Kennzahlen fortschreiben."
agent: agent
tools: [read, search, edit, execute]
---
Führe eine Retrospektive des KI-Teams durch. Halte dich an [ki-dateien.instructions.md](../instructions/ki-dateien.instructions.md).

1. Lies [lernprotokoll.md](../ki/lernprotokoll.md), [erkenntnisse.md](../ki/erkenntnisse.md) und alle abgeschlossenen Arbeitspakete in `.github/ki/arbeitspakete/` seit der letzten Retro.
2. **Kennzahlen** fortschreiben: Ø Review-Runden, Anteil erster Testlauf grün, Eskalationen, Zeilenzahl von `AGENTS.md` + `.github/instructions/*.md`.
3. **Vollständigkeit:** Jeder Lernprotokoll-Eintrag verweist auf eine `E-Nr` in `erkenntnisse.md`; fehlende Erkenntnisse nachtragen.
4. **Muster erkennen:** Ursachen mit Anzahl ≥ 2, deren Erkenntnis noch „nur hier“ steht → Regel auf der richtigen Ebene eintragen und „Umgesetzt in“ pflegen.
5. **Wirksamkeit prüfen:** Tritt eine Ursache trotz Regel erneut auf, Regel präziser formulieren oder auf eine höhere Ebene heben; ggf. als Hook für Stufe 3 vormerken.
6. **Aufräumen:** Doppelte oder überholte Erkenntnisse zusammenführen bzw. nach „Abgelöst“ verschieben; Regeln in Ebene 0–1 kürzen, Budgets einhalten, Inhalte aus `docs/` durch Verweise ersetzen.
7. **Struktur-Abgleich:** Stichprobe gegen `../copier-astral-main/template/`; neue Abweichungen in `struktur.instructions.md` aufnehmen oder als Arbeitspaket vorschlagen.
8. **Rollen prüfen:** Welche Rolle verursacht die meisten Runden? Passende `.agent.md` schärfen (Vorgehen, Prüfliste, Ausgabeformat).
9. Änderungen als einen Commit `docs(ki): Retro <Datum>` vorschlagen und dem Nutzer eine Zusammenfassung zeigen:
   Kennzahlen-Trend, neue/geänderte/abgelöste Erkenntnisse und Regeln, Empfehlungen für die nächste Stufe.
