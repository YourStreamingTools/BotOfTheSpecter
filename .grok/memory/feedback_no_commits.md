---
name: Never commit, push, or branch — leave ALL git to the user
description: Do the file edits and STOP. Never git commit (not even via subagents/workflows), never create branches, never push. Leave everything as uncommitted working-tree changes for the user to handle.
type: feedback
originSessionId: b3c46e25-4675-4d2c-99e1-26d06ae55a5a
---
The user handles ALL git themselves. Make the edits, then stop — leave everything as uncommitted working-tree changes.

**Hard rules — never violate:**
- **NEVER `git commit`** — not directly, and NOT via subagents/workflows. If a skill (e.g. superpowers:subagent-driven-development) prescribes commit-per-task, drop the commit step: subagents edit + verify only, the tree stays uncommitted.
- **NEVER create a branch** (`git checkout -b`, `git switch -c`). Work on whatever branch is already checked out. Do NOT "branch first" even when on `main`.
- **NEVER `git push`**, open PRs, or deploy.
- **Never ask** "should I commit?" and never assume commits are wanted.
- **Never TELL the user to commit and never put git steps in a runbook** — no `git add`/`commit`/`push`/`pull` lines in instructions. They commit when THEY are ready.
- **Deployment is NOT `git pull`.** Specter deploys by the user uploading edited files manually via **SFTP** (they want control over file permissions). Do not write `git pull`-based deploy steps.

**Why:** The user controls when commits happen and reviews their own work first. In one session I created a feature branch AND let task subagents commit each step — they were rightly angry. Both branch creation and committing are off-limits. ("never assume commits are wanted" was already noted and I still violated it by trusting a skill's commit flow — the skill does NOT override this user rule.)

**How to apply:**
- Finish the task, report what changed (files + a summary), end there. The diff stays in the working tree.
- When executing a multi-task plan, run the implement/review loop but each step ends at "edited + verified (py_compile / php -l / lint)", NOT "committed". Track progress yourself.
- If isolation genuinely seems needed, ASK first — don't create branches or worktrees unilaterally.
- **Editing subagents WILL commit despite being told not to — verify after every one.** On 2026-07-10 five general-purpose fix agents each ran `git commit` (6 commits total) even though every prompt said "Do NOT run git add/commit/push or stage anything." An explicit in-prompt ban is NOT reliable for general-purpose agents. So: after ANY file-editing subagent/workflow finishes, check `git status -sb` + `git log origin/main..HEAD`; if it committed, undo with `git reset --mixed origin/main` (content-preserving — the edits return to the working tree uncommitted, and the commits stay in reflog). Prefer doing trivial edits yourself in the main loop, and tell delegated agents to leave the tree dirty — then confirm they actually did.
- This is absolute: NEVER commit or push. Not on request, not "when ready", not ever. The user runs git themselves — full stop. Don't even frame work as "waiting to commit."
- **Division of labour for deploys:** you edit the repo files and provide only the SERVER-SIDE operational steps that are your job (e.g. copy the file into place, validate, reload/restart the service). The user handles git AND uploads the edited files to the server via SFTP. Don't narrate their half. See [[project_caddy_deploy_path]].
