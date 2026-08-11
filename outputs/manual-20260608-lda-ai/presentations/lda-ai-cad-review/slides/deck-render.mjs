import { colors, footer, slides } from "./deck-data.mjs";

const W = 1280;
const H = 720;
const M = 54;

function slide(presentation) {
  return presentation.slides.add();
}

function bg(s, ctx, fill = colors.pale) {
  ctx.addShape(s, { x: 0, y: 0, w: W, h: H, fill });
  ctx.addShape(s, { x: 0, y: 0, w: W, h: 18, fill: colors.green });
}

function titleBlock(s, ctx, d) {
  ctx.addText(s, { x: M, y: 40, w: 790, h: 44, text: d.title, fontSize: 25, bold: true, color: colors.navy, typeface: ctx.fonts.title });
  if (d.claim) ctx.addText(s, { x: M, y: 88, w: 900, h: 42, text: d.claim, fontSize: 16, color: colors.muted });
}

function footerBar(s, ctx, n) {
  ctx.addShape(s, { x: 0, y: 682, w: W, h: 38, fill: colors.navy });
  ctx.addText(s, { x: M, y: 695, w: 500, h: 18, text: footer, fontSize: 9.5, color: colors.white });
  ctx.addText(s, { x: 1188, y: 695, w: 40, h: 18, text: String(n).padStart(2, "0"), fontSize: 10, color: colors.white, align: "right" });
}

function pill(s, ctx, x, y, w, text, fill = colors.lightGreen, color = colors.green) {
  ctx.addShape(s, { x, y, w, h: 30, geometry: "roundRect", fill, line: ctx.line(colors.green, 1) });
  ctx.addText(s, { x: x + 10, y: y + 8, w: w - 20, h: 14, text, fontSize: 10.5, bold: true, color, align: "center" });
}

function card(s, ctx, x, y, w, h, title, body, accent = colors.green) {
  ctx.addShape(s, { x, y, w, h, geometry: "roundRect", fill: colors.white, line: ctx.line(colors.line, 1) });
  ctx.addShape(s, { x, y, w: 8, h, fill: accent });
  ctx.addText(s, { x: x + 22, y: y + 18, w: w - 40, h: 24, text: title, fontSize: 16, bold: true, color: colors.navy });
  ctx.addText(s, { x: x + 22, y: y + 48, w: w - 40, h: h - 58, text: body, fontSize: 12.2, color: colors.ink });
}

function bullets(s, ctx, items, x, y, w, gap = 40, size = 14) {
  items.forEach((item, i) => {
    const yy = y + i * gap;
    ctx.addShape(s, { x, y: yy + 6, w: 9, h: 9, geometry: "ellipse", fill: colors.green });
    ctx.addText(s, { x: x + 22, y: yy, w, h: gap - 4, text: item, fontSize: size, color: colors.ink });
  });
}

function drawTable(s, ctx, d, x, y, widths, rowH, headerH = 34) {
  let xx = x;
  d.columns.forEach((c, i) => {
    ctx.addShape(s, { x: xx, y, w: widths[i], h: headerH, fill: i === 0 ? colors.navy : colors.green });
    ctx.addText(s, { x: xx + 8, y: y + 9, w: widths[i] - 16, h: 14, text: c, fontSize: 10.5, bold: true, color: colors.white });
    xx += widths[i];
  });
  d.rows.forEach((r, ri) => {
    xx = x;
    r.forEach((c, ci) => {
      ctx.addShape(s, { x: xx, y: y + headerH + ri * rowH, w: widths[ci], h: rowH, fill: ri % 2 ? "#f9fbfd" : colors.white, line: ctx.line(colors.line, 0.7) });
      ctx.addText(s, { x: xx + 8, y: y + headerH + ri * rowH + 7, w: widths[ci] - 16, h: rowH - 8, text: c, fontSize: 8.6, color: ci === 0 ? colors.navy : colors.ink, bold: ci === 0 });
      xx += widths[ci];
    });
  });
}

function renderTitle(p, ctx, d) {
  const s = slide(p); bg(s, ctx, colors.navy);
  ctx.addShape(s, { x: 0, y: 0, w: W, h: H, fill: colors.navy });
  ctx.addShape(s, { x: 0, y: 0, w: 460, h: H, fill: colors.navy2 });
  ctx.addShape(s, { x: 54, y: 76, w: 70, h: 70, geometry: "roundRect", fill: colors.green });
  ctx.addText(s, { x: 76, y: 95, w: 32, h: 32, text: "LDA", fontSize: 15, bold: true, color: colors.white, align: "center" });
  ctx.addText(s, { x: 54, y: 185, w: 720, h: 110, text: d.title, fontSize: 39, bold: true, color: colors.white, typeface: ctx.fonts.title });
  ctx.addText(s, { x: 58, y: 318, w: 560, h: 34, text: d.subtitle, fontSize: 20, color: "#dcecff" });
  ctx.addShape(s, { x: 58, y: 395, w: 560, h: 78, geometry: "roundRect", fill: "#ffffff12", line: ctx.line("#ffffff55", 1) });
  ctx.addText(s, { x: 80, y: 415, w: 510, h: 38, text: d.callout, fontSize: 18, bold: true, color: colors.white });
  ctx.addText(s, { x: 820, y: 155, w: 240, h: 30, text: "CAD-based", fontSize: 25, bold: true, color: colors.green2, align: "center" });
  ctx.addShape(s, { x: 745, y: 220, w: 390, h: 265, geometry: "roundRect", fill: colors.white, line: ctx.line("#ffffff", 1) });
  ["DWG", "DXF", "PDF", "OCR", "Rules", "GIS", "Report"].forEach((t, i) => pill(s, ctx, 790 + (i % 2) * 160, 260 + Math.floor(i / 2) * 44, 130, t, i < 3 ? "#eaf2ff" : colors.lightGreen, i < 3 ? colors.blue : colors.green));
  ctx.addText(s, { x: 54, y: 640, w: 420, h: 18, text: d.kicker, fontSize: 12, color: "#cfe3ff" });
  footerBar(s, ctx, ctx.slideNumber);
  return s;
}

function renderGeneric(p, ctx, d) {
  const s = slide(p); bg(s, ctx); titleBlock(s, ctx, d);
  const layout = d.layout;
  if (layout === "summary") {
    card(s, ctx, 825, 155, 350, 255, d.sideTitle, d.sideText, colors.gold);
    bullets(s, ctx, d.points, 92, 168, 650, 95, 19);
  } else if (layout === "positioning") {
    card(s, ctx, 76, 168, 355, 230, d.leftTitle, d.left.join("\n\n"), colors.blue);
    card(s, ctx, 470, 168, 355, 230, d.rightTitle, d.right.join("\n\n"), colors.green);
    ctx.addShape(s, { x: 145, y: 455, w: 900, h: 95, geometry: "roundRect", fill: colors.navy });
    ctx.addText(s, { x: 178, y: 480, w: 830, h: 40, text: d.quote, fontSize: 17, bold: true, color: colors.white, align: "center" });
  } else if (layout === "challenges" || layout === "challenges2") {
    const items = d.items.map((it) => Array.isArray(it) ? it : [it, ""]);
    items.forEach((it, i) => card(s, ctx, 80 + (i % 3) * 372, 158 + Math.floor(i / 3) * 168, 315, 126, it[0], it[1] || "Requires standards, training and feedback loop.", i % 2 ? colors.blue : colors.green));
  } else if (layout === "workflow") {
    d.steps.forEach((step, i) => {
      const x = 64 + i * 195;
      ctx.addShape(s, { x, y: 245, w: 150, h: 92, geometry: "roundRect", fill: i < 2 ? "#eaf2ff" : i < 4 ? colors.lightGreen : colors.white, line: ctx.line(i < 4 ? colors.green : colors.blue, 1.5) });
      ctx.addText(s, { x: x + 16, y: 274, w: 118, h: 38, text: step, fontSize: 15, bold: true, color: colors.navy, align: "center" });
      if (i < d.steps.length - 1) ctx.addText(s, { x: x + 160, y: 278, w: 40, h: 28, text: "→", fontSize: 30, color: colors.green, bold: true });
    });
    ctx.addText(s, { x: 170, y: 430, w: 880, h: 42, text: "Output: a structured scrutiny package for officer review, not an automated final approval.", fontSize: 22, bold: true, color: colors.green, align: "center" });
  } else if (layout === "technology") {
    d.groups.forEach((g, i) => card(s, ctx, 88 + i * 372, 180, 315, 260, g[0], g.slice(1).join("\n\n"), [colors.blue, colors.green, colors.gold][i]));
  } else if (layout === "ml") {
    d.steps.forEach((step, i) => {
      const x = 90 + (i % 4) * 285, y = 170 + Math.floor(i / 4) * 180;
      ctx.addShape(s, { x, y, w: 210, h: 85, geometry: "roundRect", fill: colors.white, line: ctx.line(colors.green, 1.2) });
      ctx.addText(s, { x: x + 16, y: y + 22, w: 178, h: 30, text: step, fontSize: 15, bold: true, color: colors.navy, align: "center" });
      if (i !== 3 && i !== 7) ctx.addText(s, { x: x + 218, y: y + 24, w: 28, h: 24, text: "→", fontSize: 24, color: colors.green });
    });
  } else if (layout === "comparison-table") {
    drawTable(s, ctx, d, 56, 138, [170, 455, 540], 49, 34);
  } else if (layout === "feature-compare") {
    drawTable(s, ctx, { columns: ["Capability", "Manual System", "AI-Assisted System"], rows: d.rows }, 78, 148, [260, 390, 420], 50, 38);
  } else if (layout === "pakistan-fit" || layout === "strategic-advantage" || layout === "way-forward") {
    d.items.forEach((item, i) => pill(s, ctx, 90 + (i % 2) * 540, 164 + Math.floor(i / 2) * 80, 460, item, i % 2 ? "#eaf2ff" : colors.lightGreen, i % 2 ? colors.blue : colors.green));
  } else if (layout === "benefits") {
    d.items.forEach((it, i) => card(s, ctx, 74 + (i % 3) * 380, 158 + Math.floor(i / 3) * 160, 330, 118, it[0], it[1], i % 2 ? colors.blue : colors.green));
  } else if (layout === "resource") {
    d.cards.forEach((it, i) => card(s, ctx, 84 + (i % 2) * 560, 165 + Math.floor(i / 2) * 170, 485, 125, it[0], it[1], i % 2 ? colors.blue : colors.green));
  } else if (layout === "where-stand") {
    d.items.forEach((item, i) => pill(s, ctx, 104 + (i % 3) * 350, 172 + Math.floor(i / 3) * 96, 285, item, i % 3 === 0 ? "#eaf2ff" : colors.lightGreen, i % 3 === 0 ? colors.blue : colors.green));
  } else if (layout === "screenshots") {
    d.placeholders.forEach((item, i) => {
      const x = 70 + (i % 3) * 382, y = 158 + Math.floor(i / 3) * 190;
      ctx.addShape(s, { x, y, w: 318, h: 132, geometry: "roundRect", fill: colors.white, line: ctx.line(colors.line, 1.5) });
      ctx.addShape(s, { x: x + 18, y: y + 18, w: 282, h: 76, fill: "#eef4f8", line: ctx.line("#cbd8e5", 1) });
      ctx.addText(s, { x: x + 26, y: y + 47, w: 266, h: 20, text: "SCREENSHOT PLACEHOLDER", fontSize: 10, color: colors.muted, align: "center" });
      ctx.addText(s, { x: x + 18, y: y + 106, w: 282, h: 18, text: item, fontSize: 13, bold: true, color: colors.navy, align: "center" });
    });
  } else if (layout === "report") {
    d.metrics.forEach((m, i) => card(s, ctx, 80 + (i % 3) * 380, 165 + Math.floor(i / 3) * 155, 320, 105, m, "Shown as structured report evidence for officer review.", i % 2 ? colors.blue : colors.green));
  } else if (layout === "roadmap") {
    d.phases.forEach((p, i) => {
      const x = 82 + i * 185;
      ctx.addShape(s, { x, y: 200, w: 140, h: 140, geometry: "ellipse", fill: i < 2 ? colors.green : i < 4 ? colors.blue : colors.navy });
      ctx.addText(s, { x: x + 20, y: 238, w: 100, h: 20, text: p[0], fontSize: 13, bold: true, color: colors.white, align: "center" });
      ctx.addText(s, { x: x + 16, y: 268, w: 108, h: 40, text: p[1], fontSize: 11.5, color: colors.white, align: "center" });
    });
  } else if (layout === "governance") {
    d.rules.forEach((r, i) => card(s, ctx, 120 + (i % 2) * 500, 170 + Math.floor(i / 2) * 165, 420, 115, r, i === 1 ? "Statutory accountability remains with the reviewing authority." : "Designed for transparent decision support.", i % 2 ? colors.green : colors.blue));
  } else if (layout === "impact") {
    d.items.forEach((it, i) => card(s, ctx, 94 + (i % 3) * 365, 160 + Math.floor(i / 3) * 160, 315, 116, it[0], it[1], i % 2 ? colors.blue : colors.green));
  } else if (layout === "closing") {
    ctx.addShape(s, { x: 0, y: 0, w: W, h: H, fill: colors.navy });
    ctx.addText(s, { x: 120, y: 185, w: 1040, h: 92, text: d.title, fontSize: 39, bold: true, color: colors.white, align: "center", typeface: ctx.fonts.title });
    ctx.addText(s, { x: 260, y: 310, w: 760, h: 32, text: d.callout, fontSize: 20, color: "#dcecff", align: "center" });
    ctx.addText(s, { x: 500, y: 440, w: 280, h: 44, text: d.subtitle, fontSize: 30, bold: true, color: colors.green2, align: "center" });
  }
  footerBar(s, ctx, ctx.slideNumber);
  return s;
}

export async function addDeckSlide(presentation, ctx) {
  const d = slides[ctx.slideNumber - 1];
  if (d.layout === "title") return renderTitle(presentation, ctx, d);
  return renderGeneric(presentation, ctx, d);
}
