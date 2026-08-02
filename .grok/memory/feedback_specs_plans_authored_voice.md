---
name: feedback-specs-plans-authored-voice
description: ".grok specs/plans must read as English planning docs I authored, never reveal AI coded the implementation"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: ed57993f-9ce3-489b-a70b-051183f71c02
---

The user wants every file in `.grok/specs/` and `.grok/plans/` to read as a planning/design document I (Claude) wrote in plain English to help plan and spec out the system — NOT as an artifact that betrays the code was AI-written.

**Why:** these docs are shared/kept as the project's design record; they should look like genuine human-authored planning, not an AI coding log.

**How to apply:** describe the problem, decisions, approach, and risks in prose. Avoid the tells that scream "an agent coded this":
- No "for agentic workers / implement task-by-task" headers or `- [ ]` checkbox task-tracking.
- Don't paste final code blocks verbatim (exact `bind_param` type strings, complete PHP/Python functions). Sketch the approach in English; minimal inline snippets only when they clarify a decision.
- No exact line-number insert points (e.g. "~L3613").
- No verification sections that are lists of `python -m py_compile` / `php -l` "→ success" commands — describe how we'll know it's correct in design terms instead.
- No `## Self-Review` / "placeholder scan" / agent self-check meta sections.

Applied this by rewriting the custom-command-aliases spec + plan (2026-06-28) on first request. Related: [[feedback_plans_in_dot_claude]] (where they go), [[project_custom_command_aliases]] (the feature itself).
