import fs from "node:fs/promises";
import path from "node:path";

const dir = path.dirname(new URL(import.meta.url).pathname);

for (let i = 1; i <= 22; i += 1) {
  const n = String(i).padStart(2, "0");
  const body = `import { addDeckSlide } from "./deck-render.mjs";\n\nexport async function slide${n}(presentation, ctx) {\n  return addDeckSlide(presentation, ctx);\n}\n`;
  await fs.writeFile(path.join(dir, `slide-${n}.mjs`), body, "utf8");
}

console.log("Generated 22 slide modules.");
