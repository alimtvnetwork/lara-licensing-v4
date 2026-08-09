import js from "@eslint/js";
import eslintPluginPrettier from "eslint-plugin-prettier/recommended";
import globals from "globals";
import reactHooks from "eslint-plugin-react-hooks";
import reactRefresh from "eslint-plugin-react-refresh";
import tseslint from "typescript-eslint";

/**
 * Frontend static-analysis gate. Mirrors the backend PHPStan gate in intent:
 * every enabled rule is `error`, pre-existing violations are captured in
 * `eslint-suppressions.json` (ESLint 9 bulk-suppressions file), and CI fails
 * on any NEW violation and on any suppression entry that no longer matches
 * (baseline rot prevention).
 *
 * Regenerate the suppressions file after fixing findings:
 *   bun run lint:strict:suppress
 */
export default tseslint.config(
  { ignores: ["dist", ".output", ".vinxi", "src/routeTree.gen.ts"] },
  {
    extends: [js.configs.recommended, ...tseslint.configs.recommended],
    files: ["**/*.{ts,tsx}"],
    languageOptions: {
      ecmaVersion: 2020,
      globals: globals.browser,
    },
    plugins: {
      "react-hooks": reactHooks,
      "react-refresh": reactRefresh,
    },
    rules: {
      ...reactHooks.configs.recommended.rules,
      "no-restricted-imports": [
        "error",
        {
          paths: [
            {
              name: "server-only",
              message:
                "TanStack Start does not use the Next.js `server-only` package. Rename the module to `*.server.ts` or mark it with `@tanstack/react-start/server-only`.",
            },
          ],
        },
      ],
      "react-refresh/only-export-components": ["warn", { allowConstantExport: true }],
      "@typescript-eslint/no-unused-vars": "off",
    },
  },
  // Strict, type-aware gate. Scoped to first-party source (src/) and excludes
  // shadcn-generated `src/components/ui/**`, generated route tree, test files,
  // and any *.d.ts. Rules here are all `error`; bulk suppressions absorb
  // pre-existing findings so we can turn the gate on without a big-bang cleanup.
  {
    files: ["src/**/*.{ts,tsx}"],
    ignores: [
      "src/components/ui/**",
      "src/routeTree.gen.ts",
      "src/**/*.d.ts",
      "src/**/*.test.{ts,tsx}",
      "src/**/*.spec.{ts,tsx}",
    ],
    languageOptions: {
      parserOptions: {
        projectService: true,
        tsconfigRootDir: import.meta.dirname,
      },
    },
    rules: {
      "@typescript-eslint/consistent-type-imports": [
        "error",
        { prefer: "type-imports", fixStyle: "inline-type-imports" },
      ],
      "@typescript-eslint/no-floating-promises": "error",
      "@typescript-eslint/no-misused-promises": "error",
      "@typescript-eslint/no-unnecessary-condition": "error",
      "@typescript-eslint/no-unused-vars": [
        "error",
        { argsIgnorePattern: "^_", varsIgnorePattern: "^_", caughtErrorsIgnorePattern: "^_" },
      ],
      // 15-line function-body cap per project memory. Blank lines and comments
      // do not count; nested IIFEs count separately.
      "max-lines-per-function": [
        "error",
        { max: 15, skipBlankLines: true, skipComments: true, IIFEs: true },
      ],
      // Plan 11 step 25: every Lara API call must go through `laraFetch`
      // (src/lib/lara-fetch.ts). Raw `fetch` bypasses envelope parsing,
      // `X-Request-Id` generation, `LaraApiError` wrapping, and the Global
      // Error Modal seam. Legitimate non-envelope callers (signed-URL
      // uploads, binary asset downloads, the low-level transport inside
      // `lara-api-client.ts`) opt out with a documented
      // `eslint-disable-next-line no-restricted-globals` comment.
      "no-restricted-globals": [
        "error",
        {
          name: "fetch",
          message:
            "Use `laraFetch` from `@/lib/lara-fetch` instead of raw `fetch`. Non-envelope callers (binary uploads/downloads) must add an inline `eslint-disable-next-line no-restricted-globals` with justification.",
        },
      ],
      // Plan 11 step 39: ban raw `throw new Error(...)` (and sibling builtin
      // Error subclasses) in `src/`. Domain failures must go through
      // `LaraApiError` (client) or bubble as typed exceptions; ad-hoc
      // `throw new Error` bypasses the error store, Global Error Modal,
      // and structured logging. Legitimate invariant guards inside
      // low-level primitives (context providers, transport shims) opt out
      // with a documented `eslint-disable-next-line no-restricted-syntax`.
      "no-restricted-syntax": [
        "error",
        {
          selector:
            "ThrowStatement > NewExpression[callee.name=/^(Error|TypeError|RangeError|SyntaxError)$/]",
          message:
            "Do not `throw new Error(...)` in src/. Use `LaraApiError` (client seams) or a typed domain error; see docs/contributing/error-management-cheatsheet.md.",
        },
      ],
      "padding-line-between-statements": [
        "error",
        { "blankLine": "always", "prev": "*", "next": "return" }
      ],

    },
  },
  eslintPluginPrettier,
);
