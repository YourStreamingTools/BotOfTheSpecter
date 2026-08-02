---
name: feedback-model-routing
description: "Default to workflows/multi-agent orchestration; route subagents by model — Haiku for reading, Sonnet for coding, Opus for thinking/decisions"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 02934e5f-cdea-4a4e-8372-ba9f86cde601
---

Prefer workflows and multi-agent orchestration for substantive tasks (exploration, design, implementation, review), not solo work. Route each spawned agent to the right model by job:

- **Haiku** (`model: 'haiku'`) — quick/cheap thinking and **file reading / searching**: Explore-style fan-out, log/excerpt reads, simple lookups, mechanical scans.
- **Sonnet 4.6** (`model: 'sonnet'`) — **any code writing/editing** an agent does.
- **Opus 4.8** (`model: 'opus'`, the default/parent model) — **high-level thinking, decisions, planning, synthesis, and adversarial review.** Keep these on the main loop or on Opus subagents; don't downgrade them.

**Why:** the user wants cost/latency optimized per task type without sacrificing quality on the parts that matter (decisions, code correctness). Reading is cheap → Haiku; coding needs more capability → Sonnet; judgment/architecture stays on Opus.

**How to apply:** when calling the Agent tool, set `model` per the above. In `Workflow` scripts, set `opts.model` per `agent()` call / pipeline stage (e.g. read/search stages `model:'haiku'`, code-edit stages `model:'sonnet'`, judge/synthesis stages omit or `model:'opus'`). Model enum values: `'haiku'`, `'sonnet'`, `'opus'`, `'fable'`. Relates to [[feedback_always_info_before_fix]] and [[feedback_no_commits]].
