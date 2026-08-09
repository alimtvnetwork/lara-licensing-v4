#!/usr/bin/env node
/**
 * Plan 11 SS-02 (v0.405.0). Closed-set parity gate between the backend
 * canonical `error_codes` list at `backend/config/lara.php` and the frontend
 * `ApiErrorCodeType` enum at `src/lib/lara-api-error.ts`.
 *
 * Two-way diff:
 *   - Codes present on FE but missing from BE `error_codes` array.
 *   - Codes present in BE `error_codes` but missing from FE enum.
 *
 * Exit 0 iff both sets are identical. Exit 1 with a readable delta table
 * otherwise. No dependency on PHP; both files are parsed as text so this
 * runs anywhere Node 18+ is available (dev, CI, pre-commit).
 *
 * Related:
 *   spec/03-error-manage/98-audit-input.md §3 (baseline delta).
 *   spec/03-error-manage/12-error-taxonomy.md (canonical source of truth).
 */

import { readFileSync } from "node:fs";
import { resolve, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const BE_CONFIG = resolve(ROOT, "backend/config/lara.php");
const FE_ENUM = resolve(ROOT, "src/lib/lara-api-error.ts");

/**
 * Extract the closed-set `'error_codes' => [ ... ]` array literal from the
 * Laravel config file. We match only single-quoted PascalCase identifiers
 * inside the array body so commented lines cannot leak in.
 */
function readBackendCodes(text) {
  const start = text.indexOf("'error_codes' => [");
  if (start === -1) throw new Error("BE: 'error_codes' array not found in backend/config/lara.php");
  const end = text.indexOf("]", start);
  if (end === -1) throw new Error("BE: unterminated 'error_codes' array");
  const body = text.slice(start, end);
  const codes = new Set();
  for (const match of body.matchAll(/'([A-Z][A-Za-z0-9]+)'/g)) codes.add(match[1]);
  codes.delete("error_codes");
  return codes;
}

/**
 * Extract enum members from `export enum ApiErrorCodeType { ... }`. Members
 * are `Name = "Name",` lines; we require the string literal matches the
 * identifier (a spec rule enforced elsewhere) and skip any that do not.
 */
function readFrontendCodes(rawText) {
  // Strip block and line comments first so JSDoc examples containing `}`
  // (e.g. `{HttpStatus, ErrorCode}`) cannot terminate the enum body early.
  const text = rawText.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\/\/.*$/gm, "");
  const start = text.indexOf("export enum ApiErrorCodeType");
  if (start === -1) throw new Error("FE: ApiErrorCodeType enum not found in src/lib/lara-api-error.ts");
  const open = text.indexOf("{", start);
  const close = text.indexOf("}", open);
  if (open === -1 || close === -1) throw new Error("FE: ApiErrorCodeType body braces not found");
  const body = text.slice(open + 1, close);
  const codes = new Set();
  for (const match of body.matchAll(/^\s*([A-Z][A-Za-z0-9]+)\s*=\s*"([A-Z][A-Za-z0-9]+)"/gm)) {
    if (match[1] !== match[2]) {
      console.error(`FE: enum member name '${match[1]}' does not match string value '${match[2]}'`);
      process.exit(2);
    }
    codes.add(match[1]);
  }
  return codes;
}

function diff(a, b) {
  return [...a].filter((x) => !b.has(x)).sort();
}

const be = readBackendCodes(readFileSync(BE_CONFIG, "utf8"));
const fe = readFrontendCodes(readFileSync(FE_ENUM, "utf8"));

const feOnly = diff(fe, be);
const beOnly = diff(be, fe);

console.log(`Backend codes:  ${be.size}`);
console.log(`Frontend codes: ${fe.size}`);

if (feOnly.length === 0 && beOnly.length === 0) {
  console.log("OK: backend and frontend error-code closed sets are identical.");
  process.exit(0);
}

console.error("");
console.error("Error-code parity FAILED. Fix by editing the side listed as missing.");
if (feOnly.length) {
  console.error("");
  console.error(`Missing in BE (backend/config/lara.php 'error_codes'):  ${feOnly.length}`);
  for (const code of feOnly) console.error(`  - ${code}`);
}
if (beOnly.length) {
  console.error("");
  console.error(`Missing in FE (src/lib/lara-api-error.ts ApiErrorCodeType):  ${beOnly.length}`);
  for (const code of beOnly) console.error(`  - ${code}`);
}
process.exit(1);
