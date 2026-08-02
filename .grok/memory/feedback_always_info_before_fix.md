---
name: always-info-before-fix
description: "When working through a list of tasks/fixes, always present \"info\" (where, why, what changes, risk) before any code edit — even for trivial one-line changes."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: dee89d11-1795-4625-883b-5d8bdad8a2ca
---

When walking through a task list with the user, **always present info before applying any fix** — never "just send it" for trivial-looking changes.

**Why:** The user explicitly said "i always want the info" after I offered a shortcut on a one-line fix. They want the same review cadence for every task regardless of size: where the code is, why it's a bug, what the change is, the risk assessment. They prefer one extra read pass over surprises in the diff.

**How to apply:** When presenting "Up next: Task #N — ...", **don't** ask "want info or just send it?" Just present the info section directly. Wait for explicit go-ahead before editing. Format the info compactly per [[compact-info-format]] (if not yet written, follow the pattern: "Where / Why / Fix / Risk" sections, tables for comparisons, minimal prose).
