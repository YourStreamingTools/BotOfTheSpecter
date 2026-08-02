---
name: feedback_answer_questions_dont_edit
description: "When the user asks a question, answer it — do NOT make unsolicited code/comment edits"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 0d52c458-9e3e-4049-97ca-a1cf4e23670e
---

When the user asks a question (e.g. "is this URL right?", "why does X work this way?"), just answer it. Do NOT jump to editing files, comments, or code off the back of a question.

**Why:** The user asked whether a MEGA S4 endpoint URL was correct; I went and rewrote comments in `config/megas4.php` that were fine. They had to revert it — unwanted churn, and it overwrote their own wording.

**How to apply:** Distinguish a *question* from an *action request*. A question gets a text answer only. Only edit code when explicitly asked, or when executing an already-agreed task/plan. When unsure which it is, answer first and offer to make a change — don't make it pre-emptively. Relates to [[feedback_always_info_before_fix]].
