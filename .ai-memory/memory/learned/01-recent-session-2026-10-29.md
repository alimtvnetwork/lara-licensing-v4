# Recent session — 2026-10-29

This note records what was created and shipped on 2026-10-29. Paths below are relative git paths.

## go-email-reader

- Specifications for warm-up sequences 08 through 13 are in `02-spec/21-app/08-ui-and-warm-up-algorithm-improvements/` through `02-spec/21-app/13-ui-and-warm-up-algorithm-improvements/`.
- Plans are in `.ai-memory/plans/pending/08-ui-and-warm-up-algorithm-improvements.md` through `.ai-memory/plans/pending/13-ui-and-warm-up-algorithm-improvements.md`.
- Sequence 08 is the connector and gate map. Sequence 09 is brakes. Sequence 10 is the send lease and cool-down. Sequence 11 is the shadow compare. Sequence 12 is the pace envelope. Sequence 13 is the shared quiet window.
- The live tick now consults `internal/warmup/gatefeed.go`. A peer can tighten the remaining-send cap and cannot raise it. An absent or stale peer does not stop local warm-up. Local quiet hours hold. A quiet skip does not send. A dry run does not spend the cap. The gate order is health, cadence, connector, theme.
- The reader UI gate map reads `GET /api/warmup/gates`.
- Commit `f3864ee` is on `main` and is pushed. The remote uses SSH.
- Sequences 08 through 13 stay pending as catalogs. Only the gate engine is implemented.
- `user.email` is `local@gitmap`. The name is `gitmap automation`.

## gitmap-v28

- Cursor-like IDE setup landed in `cli/cmdide/ide_setup.go`, `cli/cmdide/setup/`, and `cli/cmdui/ui_server_ide_setup.go`.
- The spec is `02-spec/21-app/352-cursor-like-ide-setup-from-gitmap/`.
- Pushed commits include `3229df9b4`, `864012968`, and `04690b952`.
- `cli/cov` is a coverage profile and was not committed.

## repo-cache

- Commit `f474298` stores heatmap databases, including `.heatmap/06-cursor/go-email-reader/heatmap.db`.
- SQLite `-shm` and `-wal` sidecars were not committed.

## Push denied

These local commits are still unpushed because GitHub denied `alimtvnetwork`:

- `alim.karim.profile` (19 commits)
- `kubernetes-training-v1` (19 commits)
- `common-linux-installer` (1 commit)

## Rules learned

- Do not put a personal access token in a git remote URL. Use SSH.
- Do not commit coverage profiles or SQLite sidecar files.

## Last 30 commits in this repo

```text
bdc82d7 chore(version) - bump to v0.691.60
8a4096e feat(sync) - sync canonical v6 execute suite, gitmap heatmap telemetry, skills, and prompts (29-Oct-26 06:32 pm)
43f5907 chore(version) - bump to v0.691.59
7b482aa feat(sync) - sync canonical v6 execute suite, gitmap heatmap telemetry, skills, and prompts (29-Oct-26 04:23 pm)
b049333 chore(version) - bump to v0.691.58
e48b5d9 feat(sync) - sync canonical v6 execute suite, gitmap heatmap telemetry, skills, and prompts (29-Oct-26 03:17 pm)
d2e6650 chore(version) - bump to v0.691.57
85b9efa feat(sync) - sync canonical v6 execute suite, gitmap heatmap telemetry, skills, and prompts (29-Oct-26 02:54 pm)
d8f6017 chore(version) - bump to v0.691.56
e0cc37f feat(sync) - sync canonical v6 execute suite, gitmap heatmap telemetry, skills, and prompts (28-Oct-26 10:18 am)
490c518 chore(version) - bump to v0.691.55
f0f7138 feat(sync) - sync canonical v6 execute suite, gitmap heatmap telemetry, skills, and prompts
2dc58a1 chore(version) - bump to v0.691.54
0377573 feat(sync) - sync canonical v6 execute suite, gitmap heatmap telemetry, skills, and prompts
5b062e9 chore(version): bump to v0.691.53
ebf491f feat(sync): sync canonical prompts, skills, gitmap agent standards, and purge deprecated 46 task manager
773f9d4 chore(version): bump to v0.691.52
ab82817 feat(sync): sync canonical prompts, skills, gitmap agent standards, and purge deprecated 46 task manager
f2221e1 chore(version): bump to v0.691.51
d4e2664 feat(sync): sync canonical prompts, skills, gitmap agent standards, and purge deprecated 46 task manager
a622da0 chore(version): bump to v0.691.50
a1d8a6e feat(sync): sync canonical prompts, skills, gitmap agent standards, and purge deprecated 46 task manager
d2f1888 chore(version): bump to v0.691.49
0a91fd2 feat(sync): sync canonical prompts, skills, gitmap agent standards, and purge deprecated 46 task manager
7e1379d chore(version) - bump to v0.691.48
39d3211 feat(sync) - sync canonical v6 execute suite, gitmap heatmap telemetry, skills, and prompts
a01b635 chore(version): bump to v0.691.47
f548778 feat(sync): sync canonical prompts, skills, gitmap agent standards, and purge deprecated 46 task manager
079f240 chore(version) - bump to v0.691.46
d2328d8 feat(sync) - sync canonical v6 execute suite, gitmap heatmap telemetry, skills, and prompts
```
