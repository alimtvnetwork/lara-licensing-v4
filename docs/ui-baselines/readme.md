# UI Baselines

Plan 09 Step 10 artifacts. Prove that the mandated Ubuntu (display) and
Poppins (body) Google Fonts actually resolve at runtime in the built app.

## Regenerate

The Vite dev server must be running on `http://localhost:8080`. Then:

```bash
python3 scripts/capture-font-baseline.py
```

The script writes:

- `font-baseline.json`: computed `font-family` for `h1` and `body` per route.
  Must contain `Ubuntu` in the H1 stack and `Poppins` in the body stack.
- `<route>.png`: one screenshot per route for manual review.

Exit code is non-zero if either family is missing, which is the CI signal
we want when a future change breaks the Google Fonts `<link>` in
`src/routes/__root.tsx` or the `@theme` tokens in `src/styles.css`.

## Current baseline

Captured on the landing route (`/`):

- `h1.fontFamily`: `Ubuntu, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif`
- `body.fontFamily`: `Poppins, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif`

Screenshots are intentionally gitignored to keep the repo lean. Re-run the
script locally when reviewing UI changes.
