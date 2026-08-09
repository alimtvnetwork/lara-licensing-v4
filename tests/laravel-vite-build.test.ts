import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const read = (p: string) => readFileSync(resolve(process.cwd(), p), 'utf8');

describe('Plan 06 step 79: hashed console assets under public/build', () => {
  const config = read('backend/vite.config.ts');

  it('pins the build directory to public/build with a manifest', () => {
    expect(config).toContain("outDir: 'public/build'");
    expect(config).toContain("manifest: 'manifest.json'");
    expect(config).toContain("buildDirectory: 'build'");
    expect(config).toContain('emptyOutDir: true');
  });

  it('emits content-hashed filenames for entries, chunks and assets', () => {
    expect(config).toContain("entryFileNames: 'assets/[name]-[hash].js'");
    expect(config).toContain("chunkFileNames: 'assets/[name]-[hash].js'");
    expect(config).toContain("assetFileNames: 'assets/[name]-[hash][extname]'");
    expect(config).toContain('sourcemap: false');
  });

  it('declares both the stylesheet and the app entry', () => {
    expect(config).toContain("input: ['resources/css/app.css', 'resources/js/app.tsx']");
  });

  it('resolves the @ alias to an absolute path instead of a root-relative string', () => {
    expect(config).toContain("fileURLToPath(new URL('./resources/js', import.meta.url))");
    expect(config).not.toContain("'@': '/resources/js'");
  });

  it('build artefacts stay out of version control', () => {
    expect(read('backend/.gitignore')).toContain('/public/build');
  });

  it('the Blade root resolves entries through ViteEntries, not a raw page path', () => {
    const blade = read('backend/resources/views/app.blade.php');
    expect(blade).toContain('ViteEntries::forPage');
    expect(blade).not.toContain('resources/js/Pages/{$page');
    // Step 78 CSP nonce plumbing must survive.
    expect(blade).toContain('Vite::useCspNonce');
  });

  it('ViteEntries guards against manifest keys that do not exist', () => {
    const php = read('backend/app/Support/ViteEntries.php');
    expect(php).toContain('public const BASE');
    expect(php).toContain('manifestHas');
    expect(php).toContain("public_path('build/manifest.json')");
    expect(php).toContain("is_file(public_path('hot'))");
  });
});
