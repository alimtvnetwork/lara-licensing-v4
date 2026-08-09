import asyncio, json, os
from pathlib import Path
from playwright.async_api import async_playwright

OUT = Path("docs/ui-baselines"); OUT.mkdir(parents=True, exist_ok=True)
ROUTES = [("landing", "/")]
BASE = "http://localhost:8080"

async def main():
    async with async_playwright() as pw:
        b = await pw.chromium.launch(headless=True)
        ctx = await b.new_context(viewport={"width":1280,"height":1800})
        page = await ctx.new_page()
        manifest = {"routes": {}}
        fails = []
        for rid, path in ROUTES:
            await page.goto(BASE + path, wait_until="networkidle")
            await page.evaluate("() => document.fonts.ready")
            probe = await page.evaluate("""() => {
                const h1 = document.querySelector('h1');
                const body = document.body;
                return { h1: h1 ? getComputedStyle(h1).fontFamily : null,
                         body: body ? getComputedStyle(body).fontFamily : null };
            }""")
            manifest["routes"][rid] = probe
            await page.screenshot(path=str(OUT / f"{rid}.png"))
            if not probe["h1"] or "Ubuntu" not in probe["h1"]:
                fails.append(f"{rid}: H1 missing Ubuntu (got {probe['h1']})")
            if not probe["body"] or "Poppins" not in probe["body"]:
                fails.append(f"{rid}: body missing Poppins (got {probe['body']})")
        (OUT / "font-baseline.json").write_text(json.dumps(manifest, indent=2) + "\n")
        await b.close()
        print(json.dumps(manifest, indent=2))
        if fails:
            print("FAIL:", *fails, sep="\n  ")
            raise SystemExit(1)

asyncio.run(main())
