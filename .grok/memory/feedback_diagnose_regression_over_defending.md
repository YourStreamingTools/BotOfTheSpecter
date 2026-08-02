---
name: feedback_diagnose_regression_over_defending
description: "When the user reports a regression, diagnose the visible symptom (get a screenshot early) and fix it — don't defend my diff's innocence or suggest steps they've already tried"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: d4535b42-54e1-402f-9d82-0ce83a3c4802
---

When the user says "X is broken now," go straight to diagnosing the **actual visible symptom** — ask for a screenshot early, inspect the rendered behaviour — and fix the root cause. Do **not** spend turns arguing my own change is provably isolated/innocent, and do **not** suggest obvious steps (e.g. "hard-refresh") they've very likely already done. (This user pushed back hard: "I'm not a fucking idiot, don't treat me like one.")

**Why:** Even when my diff is genuinely isolated, the real cause can be latent elsewhere (here: a global `color-scheme: dark` poisoning white panels — [[project_yourchat_color_scheme_gotcha]]). The user wants the problem solved, not an attribution defence; litigating blame + suggesting basic steps reads as condescending and wastes their time.

**How to apply:** On a reported regression, first request/look at the visual evidence, then trace the symptom to its cause in the broader codebase — not just my last change. Mention attribution at most once, briefly, after it's fixed. Skip "did you try refreshing" unless there's a specific reason to think cache is involved.
