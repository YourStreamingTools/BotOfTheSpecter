---
name: feedback-no-coauthor-trailer
description: "When explicitly asked to commit, never add a Co-Authored-By/Claude trailer to the commit message"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 17e51bc4-0b69-46e1-a656-4e0bdfb3b862
  modified: 2026-07-28T09:14:53.511Z
---

Never append `Co-Authored-By: Claude ...` (or any AI co-author trailer) to commit messages, even though the default git-commit workflow template suggests one.

**Why:** Told directly, twice in the same session (2026-07-28) - "don't tag yourself as a co-creator" and later "please don't co-author yourself, it looks ugly and bad." This is a standing preference, not a one-off for that session.

**How to apply:** Applies whenever the user explicitly asks for a commit (see [[feedback_no_commits]] for the baseline - I still never commit unprompted). Write the commit message body normally, just omit the trailer line entirely - no substitute attribution, no placeholder.
