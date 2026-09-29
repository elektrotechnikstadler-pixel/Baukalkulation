---
description: "Use when creating or updating AI customization files: AGENTS.md, agents, instructions, skills, prompts, lessons-learned log, retrospective."
applyTo: "**/AGENTS.md, **/.github/agents/**, **/.github/instructions/**, **/.github/skills/**, **/.github/prompts/**, **/.github/ki/**"
---
# Pflege der KI-Dateien

- Jeder gelernte Punkt steht vollständig in `.github/ki/erkenntnisse.md` (Nr., Regel, Warum, Quelle, Umgesetzt in).
- In `AGENTS.md`, Instructions, Agents oder Skills wird eine Erkenntnis erst nach dem 2. Auftreten übernommen
  (Lernprotokoll) oder auf ausdrücklichen Wunsch – nie „auf Vorrat“; dann Spalte „Umgesetzt in“ pflegen.
- Eine Regel = eine Zeile, positiv und prüfbar formuliert; bei Bedarf Verweis `(AP-…)`.
- Richtige Ebene: alle Aufgaben → `AGENTS.md`; bestimmte Dateien → `*.instructions.md`;
  eine Rolle → `*.agent.md`; mehrschrittiger Ablauf → Skill.
- Budgets: `AGENTS.md` ≤ 50, Instructions ≤ 40, Agents ≤ 80, Skills ≤ 120 Zeilen.
- Verweisen statt kopieren: Was in `docs/` steht, nur verlinken.
- `description` enthält Auslösewörter („Use when …“); Werte mit `:` in Anführungszeichen.
- Überholte oder doppelte Regeln löschen statt ergänzen; in `erkenntnisse.md` nach „Abgelöst“ verschieben.
- Änderungen an KI-Dateien als eigener Commit `docs(ki): …`.
