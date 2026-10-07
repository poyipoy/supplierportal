# Checking Git Change Counts

Use these commands from the repository root to review code changes before committing:

- `git diff --stat` summarizes changed files and added or removed lines in the working tree.
- `git diff --numstat` prints added and removed line counts for each file.
- `git diff --cached --stat` summarizes changes staged for commit.
- `git log --oneline --stat -5` shows file-level change counts from the five latest commits.

Uncommitted edits appear in `git diff`; Git records them in commit history only after a commit is created.
