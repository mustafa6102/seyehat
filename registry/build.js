// v3.0 builder: registry -> validation -> .docx
const fs = require("fs");
const {
  Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell, HeadingLevel, AlignmentType,
  LevelFormat, WidthType, ShadingType, BorderStyle, Header, Footer, PageNumber, PageBreak,
  TableOfContents,
} = require("docx");

const M = require("./registry_master");
const STORIES = [
  ...require("./registry_stories_a"), ...require("./registry_stories_b"),
  ...require("./registry_stories_c"), ...require("./registry_stories_d"),
];
const X = require("./sections");

const pad = (n) => String(n).padStart(2, "0");
const keys = (o) => Object.keys(o || {}).map(Number).sort((a, b) => a - b);

// ------------------------------------------------------------------ registry index
const reg = new Map(); // id -> {type, status, where}
const dup = [];
function add(id, type, status = "ACTIVE", where = "") {
  if (reg.has(id)) dup.push(`${id} (${reg.get(id).where} / ${where})`);
  else reg.set(id, { type, status, where });
}
M.BUS.forEach(([id]) => add(id, "BUS", "ACTIVE", "BUS"));
M.EPIC.forEach(([id]) => add(id, "EPIC", "ACTIVE", "EPIC"));
M.FEAT.forEach(([id, , st]) => add(id, "FEAT", st, "FEAT"));
M.NFR.forEach(([id, , st]) => add(id, "NFR", st, "NFR"));
M.OD.forEach(([id]) => add(id, "OD", "ACTIVE", "OD"));
M.ADR.forEach((a) => add(a.id, "ADR", "ACTIVE", "ADR"));
add("M0-SPK-001", "SPIKE", "ACTIVE", "Spike");
// legacy / non-active ids
["VIS-001"].forEach((i) => add(i, "US", "RESERVED", "legacy"));
["TOD-001", "TOD-002", "TOD-003", "TOD-004", "TOD-005"].forEach((i) => add(i, "US", "SUPERSEDED", "legacy"));
add("RULE-VIS-002-01", "RULE", "RETIRED", "legacy");
add("RULE-VIS-002-04", "RULE", "SUPERSEDED", "legacy");

const itemId = (kind, sid, n) => `${kind}-${sid}-${pad(n)}`;
for (const s of STORIES) {
  add(s.id, "US", "ACTIVE", "story");
  for (const n of keys(s.rules)) add(itemId("RULE", s.id, n), "RULE", "ACTIVE", s.id);
  for (const n of keys(s.fr)) add(itemId("FR", s.id, n), "FR", "ACTIVE", s.id);
  for (const n of keys(s.ac)) add(itemId("AC", s.id, n), "AC", "ACTIVE", s.id);
  for (const n of keys(s.ts)) add(itemId("TS", s.id, n), "TS", "ACTIVE", s.id);
}
for (const b of X.SECTIONS) if (b[0] === "rules") b[1].forEach(([id]) => add(id, "RULE", "ACTIVE", "cross"));

// ------------------------------------------------------------------ validation
const errors = [], warnings = [];
const featMap = new Map(M.FEAT.map((f) => [f[0], f]));
const epicIds = new Set(M.EPIC.map((e) => e[0]));
const busIds = new Set(M.BUS.map((b) => b[0]));
const PHASES = ["M0", "M1A", "M1B", "M1C", "M2", "M3", "M4", "M5"];
const storyRe = /^[A-Z][A-Z0-9]{1,4}(-[A-Z]{2,7})?-\d{3}$/;

dup.forEach((d) => errors.push(`Duplicate ID: ${d}`));
for (const s of STORIES) {
  if (!storyRe.test(s.id)) errors.push(`${s.id}: story ID formatı geçersiz`);
  if (!PHASES.includes(s.phase)) errors.push(`${s.id}: bilinmeyen faz ${s.phase}`);
  if (!s.bus || !s.bus.length) errors.push(`${s.id}: BUS bağı yok`);
  (s.bus || []).forEach((b) => { if (!busIds.has(b)) errors.push(`${s.id}: bilinmeyen ${b}`); });
  s.epic.forEach((e) => { if (!epicIds.has(e)) errors.push(`${s.id}: bilinmeyen ${e}`); });
  const f = featMap.get(s.feat);
  if (!f) errors.push(`${s.id}: bilinmeyen feature ${s.feat}`);
  else {
    if (f[2] !== "ACTIVE") errors.push(`${s.id}: feature ${s.feat} ACTIVE değil (${f[2]})`);
    s.epic.forEach((e) => { if (!f[1].includes(e)) errors.push(`${s.id}: ${e} ile ${s.feat} epic'i uyuşmuyor`); });
  }
  (s.nfr || []).forEach((n) => {
    const r = reg.get(n);
    if (!r) errors.push(`${s.id}: bilinmeyen ${n}`);
    else if (r.status !== "ACTIVE") errors.push(`${s.id}: ${n} ${r.status}`);
  });
  if (!keys(s.rules).length) errors.push(`${s.id}: RULE yok`);
  if (!keys(s.ac).length) errors.push(`${s.id}: AC yok`);
  const covered = new Set();
  for (const n of keys(s.ts)) {
    const [, acs] = s.ts[n];
    if (!acs || !acs.length) errors.push(`${itemId("TS", s.id, n)}: doğrular alanı boş`);
    acs.forEach((a) => {
      if (!s.ac[a]) errors.push(`${itemId("TS", s.id, n)}: var olmayan ${itemId("AC", s.id, a)}`);
      covered.add(a);
    });
  }
  for (const a of keys(s.ac)) if (!covered.has(a)) errors.push(`${itemId("AC", s.id, a)}: TS ile doğrulanmıyor`);
}
// features & bus coverage
for (const [id, epics, st] of M.FEAT) {
  epics.forEach((e) => { if (!epicIds.has(e)) errors.push(`${id}: bilinmeyen ${e}`); });
  const n = STORIES.filter((s) => s.feat === id).length;
  if (st === "ACTIVE" && n === 0) errors.push(`${id}: ACTIVE feature'ın story'si yok`);
  if (st === "DEFERRED") warnings.push(`${id}: DEFERRED — henüz story yok (beklenen)`);
}
for (const [id] of M.BUS) if (!STORIES.some((s) => s.bus.includes(id))) errors.push(`${id}: hiçbir story'ye bağlı değil`);
for (const [id] of M.EPIC) if (!STORIES.some((s) => s.epic.includes(id)) && !M.FEAT.some((f) => f[1].includes(id) && f[2] === "DEFERRED"))
  warnings.push(`${id}: story'si olmayan epic`);
for (const [id, , st] of M.NFR) if (st === "ACTIVE" && !STORIES.some((s) => (s.nfr || []).includes(id)))
  warnings.push(`${id}: hiçbir story'de referans verilmiyor (kesişen NFR)`);

// reference scan over active content
const refRe = /(?<![-\w])(?:(?:RULE|FR|AC|TS)-[A-Z][A-Z0-9]{1,4}(?:-[A-Z]{2,7})?-\d{3}-\d{2}|RULE-M\d-\d{3}|NFR-[A-Z0-9]+(?:-[A-Z0-9]+)*-\d{3}|FEAT-[A-Z]+(?:-[A-Z]+)*|EPIC-\d{2}|BUS-\d{3}|OD-\d{3}|ADR-\d{3}|M0-SPK-\d{3}|[A-Z][A-Z0-9]{1,4}(?:-[A-Z]{2,7})?-\d{3})(?![-\w])/g;
const texts = [];
const pushT = (t, where) => { if (typeof t === "string") texts.push([t, where]); };
for (const s of STORIES) {
  ["story", "value", "scope", "out", "data", "dod"].forEach((k) => pushT(s[k], s.id));
  ["rules", "fr", "ac"].forEach((k) => keys(s[k]).forEach((n) => pushT(s[k][n], s.id)));
}
for (const b of X.SECTIONS) {
  if (["p", "h1", "h2", "h3"].includes(b[0])) pushT(b[1], "section");
  if (b[0] === "ul") b[1].forEach((t) => pushT(t, "section"));
  if (b[0] === "table") b[2].forEach((r) => r.forEach((c) => pushT(c, "section")));
  if (b[0] === "rules") b[1].forEach(([, t]) => pushT(t, "section"));
}
M.ADR.forEach((a) => ["context", "decision", "alternatives", "consequences"].forEach((k) => pushT(a[k], a.id)));
M.NFR.forEach(([id, t, st]) => st === "ACTIVE" && pushT(t, id));
M.FEAT.forEach(([id, , , t]) => pushT(t, id));
M.OD.forEach(([id, t]) => pushT(t, id));
const IGNORE = new Set(["UTF-008", "ISO-4217"]);
let refCount = 0; const supRefs = [];
for (const [t, where] of texts) {
  for (const m of t.matchAll(refRe)) {
    const id = m[0]; if (IGNORE.has(id)) continue; refCount++;
    const r = reg.get(id);
    if (!r) errors.push(`${where}: tanımsız referans ${id}`);
    else if (r.status !== "ACTIVE" && r.status !== "DEFERRED") supRefs.push(`${where} → ${id} (${r.status})`);
  }
}
supRefs.forEach((x) => warnings.push(`Aktif metinde ${x} — birleştirme/yeniden adlandırma notu olarak`));

const stats = {
  stories: STORIES.length,
  rules: [...reg.values()].filter((r) => r.type === "RULE" && r.status === "ACTIVE").length,
  fr: [...reg.values()].filter((r) => r.type === "FR").length,
  ac: [...reg.values()].filter((r) => r.type === "AC").length,
  ts: [...reg.values()].filter((r) => r.type === "TS").length,
  nfr: M.NFR.filter((n) => n[2] === "ACTIVE").length,
  feat: M.FEAT.filter((f) => f[2] === "ACTIVE").length,
  od: M.OD.length, adr: M.ADR.length, refs: refCount,
};
console.log("STATS", stats);
console.log("ERRORS", errors.length); errors.forEach((e) => console.log("  E", e));
console.log("WARNINGS", warnings.length); warnings.forEach((w) => console.log("  W", w));
if (errors.length && !process.argv.includes("--force")) { console.error("Validation failed"); process.exit(1); }

// ------------------------------------------------------------------ rendering helpers
const FONT = "Calibri";
const W = 9638; // content width (A4, 2 cm margins)
const C = { head: "1F3864", fill: "D9E2F3", meta: "EEF2F8", border: "B4C0D3", muted: "595959" };
const border = { style: BorderStyle.SINGLE, size: 4, color: C.border };
const borders = { top: border, bottom: border, left: border, right: border };

function runs(text, base = {}) {
  // **bold** support
  const out = []; const parts = String(text).split(/(\*\*[^*]+\*\*)/);
  for (const p of parts) {
    if (!p) continue;
    if (p.startsWith("**")) out.push(new TextRun({ text: p.slice(2, -2), bold: true, ...base }));
    else out.push(new TextRun({ text: p, ...base }));
  }
  return out;
}
const P = (text, opt = {}) => new Paragraph({ children: runs(text, opt.run || {}), spacing: { after: 100 }, ...opt.para });
const H = (lvl, text) => new Paragraph({ heading: lvl, children: [new TextRun(text)] });
const bullet = (children) => new Paragraph({ numbering: { reference: "bullets", level: 0 }, children, spacing: { after: 60 } });
const B = (text) => bullet(runs(text));
const IDB = (id, text, extra) => bullet([new TextRun({ text: id, bold: true }), new TextRun(": " + text), ...(extra || [])]);
const label = (t) => new Paragraph({ children: [new TextRun({ text: t, bold: true, color: C.head })], spacing: { before: 120, after: 60 } });

function cell(text, width, opts = {}) {
  const paras = String(text).split("\n").map((line) => new Paragraph({ children: runs(line, { size: 18, ...(opts.bold ? { bold: true } : {}) }) }));
  return new TableCell({
    borders, width: { size: width, type: WidthType.DXA },
    shading: opts.fill ? { fill: opts.fill, type: ShadingType.CLEAR, color: "auto" } : undefined,
    margins: { top: 60, bottom: 60, left: 100, right: 100 }, children: paras,
  });
}
function table(headers, rows, widths) {
  const sum = widths.reduce((a, b) => a + b, 0);
  if (sum !== W) widths = widths.map((w, i) => (i === widths.length - 1 ? w + (W - sum) : w));
  return new Table({
    width: { size: W, type: WidthType.DXA }, columnWidths: widths,
    rows: [
      new TableRow({ tableHeader: true, children: headers.map((h, i) => cell(h, widths[i], { fill: C.fill, bold: true })) }),
      ...rows.map((r) => new TableRow({ children: r.map((c, i) => cell(c, widths[i])) })),
    ],
  });
}
const gap = () => new Paragraph({ children: [], spacing: { after: 60 } });

// ------------------------------------------------------------------ generated blocks
const out = [];
const push = (...x) => out.push(...x);

function genStory(s) {
  push(H(HeadingLevel.HEADING_3, `${s.id} — ${s.title}`));
  const meta = [
    ["Epic / Feature", `${s.epic.join(", ")} / ${s.feat}`],
    ["BUS", s.bus.join(", ")],
    ["Faz", s.phase],
    ["User Story", s.story],
  ];
  if (s.value) meta.push(["İş değeri", s.value]);
  if (s.scope) meta.push(["Kapsam", s.scope]);
  if (s.out) meta.push(["Kapsam dışı", s.out]);
  if (s.data) meta.push(["Veri gereksinimleri", s.data]);
  if (s.nfr && s.nfr.length) meta.push(["NFR", s.nfr.join("; ")]);
  push(new Table({
    width: { size: W, type: WidthType.DXA }, columnWidths: [2200, W - 2200],
    rows: meta.map(([k, v]) => new TableRow({ children: [cell(k, 2200, { fill: C.meta, bold: true }), cell(v, W - 2200)] })),
  }));
  push(label("İş Kuralları"));
  keys(s.rules).forEach((n) => push(IDB(itemId("RULE", s.id, n), s.rules[n])));
  push(label("Fonksiyonel Gereksinimler"));
  keys(s.fr).forEach((n) => push(IDB(itemId("FR", s.id, n), s.fr[n])));
  push(label("Acceptance Criteria"));
  keys(s.ac).forEach((n) => push(IDB(itemId("AC", s.id, n), s.ac[n])));
  push(label("Test Senaryoları"));
  keys(s.ts).forEach((n) => {
    const [name, acs] = s.ts[n];
    push(bullet([new TextRun({ text: itemId("TS", s.id, n), bold: true }), new TextRun(": " + name + " "),
      new TextRun({ text: "(doğrular: " + acs.map((a) => itemId("AC", s.id, a)).join(", ") + ")", color: C.muted })]));
  });
  push(P(`**Definition of Done:** Ortak DoD (Bölüm 14.2)${s.dod ? " + " + s.dod : ""}`, { para: { spacing: { before: 100, after: 200 } } }));
}

const PHASE_DEF = [
  ["M-1A", "Varsayılan MVP iş modeli: Model A — Metasearch/Affiliate", "M0 öncesi sorumluluk sınırı ve partner yönlendirme varsayımı onaylı.", null],
  ["M0", "Provider feasibility", "M0-SPK-001 ölçümleri, capability matrix; OD-001, OD-008, OD-015 kapalı.", "M0"],
  ["M-1B", "Nihai iş modeli", "M0 sonucuna göre Model A'da kalma veya B/C'ye geçiş kararı (OD-011).", null],
  ["M1A", "Profil + talep + direkt uçuş + temel sıralama + auth/PII/resilience temeli", "Listelenen story'ler DoD'yi sağlar; OD-012 kapalı; ADR-007/009/010 kabul edilmiş.", "M1A"],
  ["M1B", "Tek hub self-transfer + true cost + compliance", "Listelenen story'ler; OD-002, 004, 005, 007, 009, 016, 017, 018 kapalı.", "M1B"],
  ["M1C", "Alternatif havalimanı + ground", "Listelenen story'ler ve ground provider PoC (OD-003).", "M1C"],
  ["M2", "Fiyat geçmişi + bildirim", "PRC-004; FEAT-NOTIFICATION refinement'ı ve OD-006.", "M2"],
  ["M3", "Trip Builder: konaklama, POI, restoran, etkinlik, günlük plan, hazırlık", "Listelenen story'ler; deterministik Domain Feasibility Gate M4'ten bağımsız çalışır.", "M3"],
  ["M4", "AI reasoning, kişiselleştirme, structured output, grounding, audit, provider/ops/privacy", "Listelenen story'ler; runtime publish gate ve CI golden-set kapısı ayrı ve geçer.", "M4"],
  ["M5", "Canlı seyahat, Bugün Modu, disruption ingestion, dinamik replan, offline", "Listelenen story'ler; offline/concurrency/safety gate geçer; OD-010 kapalı; ADR-008 kabul edilmiş.", "M5"],
];

function gen(kind) {
  if (kind === "bus") {
    push(H(HeadingLevel.HEADING_1, "3. Business Requirements"));
    push(table(["ID", "Gereksinim"], M.BUS, [1400, 8238]));
  } else if (kind === "epic") {
    push(H(HeadingLevel.HEADING_1, "4. Epic Kataloğu — 26 Epic"));
    push(P("v2.2'de farklı anlam verilmiş EPIC-01/02/04/07 kimlikleri orijinal anlamlarındadır; Epic ID yeniden kullanılmaz."));
    push(table(["Epic", "Ad", "Faz", "Story sayısı"], M.EPIC.map(([id, n, f]) => [id, n, f, String(STORIES.filter((s) => s.epic.includes(id)).length)]), [1300, 4838, 2300, 1200]));
  } else if (kind === "feat") {
    push(H(HeadingLevel.HEADING_1, "5. Feature Kataloğu"));
    push(table(["Feature", "Epic", "Durum", "Story'ler / not"], M.FEAT.map(([id, e, st, note]) => {
      const ss = STORIES.filter((s) => s.feat === id).map((s) => s.id).join(", ");
      return [id, e.join(", "), st, [ss, note].filter(Boolean).join(" — ")];
    }), [3000, 1500, 1400, 3738]));
  } else if (kind === "enum") {
    push(table(["Enum", "Değerler", "Not"], M.ENUM, [2300, 4000, 3338]));
  } else if (kind === "stories") {
    push(H(HeadingLevel.HEADING_1, "8. Canonical User Stories"));
    push(P("Her story aynı şablonu izler. Test senaryolarındaki \"doğrular\" alanı zorunludur. Story'ler faza göre gruplanmıştır; EPIC-24 platform story'leri ayrı başlıktadır."));
    const groups = [
      ["M0", "M0 — Provider yönetişimi", (s) => s.phase === "M0" && !s.epic.includes("EPIC-24")],
      ["M1A", "M1A — Profil, talep, direkt uçuş ve sıralama", (s) => s.phase === "M1A" && !s.epic.includes("EPIC-24")],
      ["M1B", "M1B — Self-transfer, transfer, uyum ve maliyet", (s) => s.phase === "M1B"],
      ["M1C", "M1C — Alternatif havalimanı ve kara bağlantısı", (s) => s.phase === "M1C"],
      ["M2", "M2 — Fiyat geçmişi", (s) => s.phase === "M2"],
      ["M3", "M3 — Trip Builder ve Destination Experience", (s) => s.phase === "M3" && !s.epic.includes("EPIC-24")],
      ["M4", "M4 — AI Reasoning ve Audit", (s) => s.phase === "M4" && !s.epic.includes("EPIC-24")],
      ["M5", "M5 — Canlı Seyahat ve Dinamik Yeniden Planlama", (s) => s.phase === "M5" && !s.epic.includes("EPIC-24")],
      ["PLT", "Platform — EPIC-24 (Güvenilirlik, Güvenlik, Veri Koruma)", (s) => s.epic.includes("EPIC-24")],
    ];
    groups.forEach(([, title, fn], i) => {
      const list = STORIES.filter(fn);
      push(H(HeadingLevel.HEADING_2, `8.${i + 1} ${title}`));
      list.forEach(genStory);
    });
  } else if (kind === "adr") {
    for (const a of M.ADR) {
      push(H(HeadingLevel.HEADING_3, `${a.id} — ${a.title}`));
      push(new Table({
        width: { size: W, type: WidthType.DXA }, columnWidths: [2000, W - 2000],
        rows: [["Durum", a.status], ["Bağlam", a.context], ["Karar", a.decision], ["Alternatifler", a.alternatives], ["Sonuçlar", a.consequences]]
          .map(([k, v]) => new TableRow({ children: [cell(k, 2000, { fill: C.meta, bold: true }), cell(v, W - 2000)] })),
      }));
      push(gap());
    }
  } else if (kind === "nfr") {
    push(table(["NFR", "Gereksinim", "Durum"], M.NFR.map(([id, t, st]) => [id, t, st]), [2100, 6238, 1300]));
  } else if (kind === "phases") {
    push(table(["Faz", "Kapsam", "Story'ler", "Çıkış kriteri"], PHASE_DEF.map(([f, k, c, key]) => [
      f, k, key ? STORIES.filter((s) => s.phase === key).map((s) => s.id).join(", ") : "—", c,
    ]), [900, 2800, 3000, 2938]));
  } else if (kind === "trace") {
    push(H(HeadingLevel.HEADING_1, "17. Traceability Matrisi"));
    push(P("Bu matris registry'den otomatik üretilir; her BUS'tan story'lere ve story'lerden AC/TS'ye iki yönlü izlenebilirlik sağlar."));
    push(H(HeadingLevel.HEADING_2, "17.1 BUS → Epic → Feature → Story"));
    push(table(["BUS", "Epic", "Feature", "Story"], M.BUS.map(([b]) => {
      const ss = STORIES.filter((s) => s.bus.includes(b));
      const ep = [...new Set(ss.flatMap((s) => s.epic))].sort();
      const fe = [...new Set(ss.map((s) => s.feat))].sort();
      return [b, ep.join(", "), fe.join(", "), ss.map((s) => s.id).join(", ")];
    }), [1100, 2000, 3300, 3238]));
    push(gap());
    push(H(HeadingLevel.HEADING_2, "17.2 Story kapsama özeti"));
    push(table(["Story", "Faz", "BUS", "RULE", "FR", "AC", "TS", "NFR"], STORIES.map((s) => [
      s.id, s.phase, s.bus.join(", "), String(keys(s.rules).length), String(keys(s.fr).length),
      String(keys(s.ac).length), String(keys(s.ts).length), (s.nfr || []).join(", "),
    ]), [1500, 600, 1700, 600, 500, 500, 500, 3738]));
  } else if (kind === "od") {
    push(table(["ID", "Karar", "Accountable (rol)", "Hedef", "Bloke ettiği", "Durum"], M.OD, [900, 3400, 1700, 1300, 1238, 1100]));
  }
}

// ------------------------------------------------------------------ document body
// Title page
push(new Paragraph({ spacing: { before: 2400, after: 200 }, alignment: AlignmentType.LEFT,
  children: [new TextRun({ text: "AI SEYAHAT PLANLAYICISI", bold: true, size: 48, color: C.head })] }));
push(new Paragraph({ spacing: { after: 120 }, children: [new TextRun({ text: "Sürüm 3.0 — Konsolide Analiz Baseline (CONDITIONAL)", size: 30, color: C.head })] }));
push(new Paragraph({ spacing: { after: 600 }, children: [new TextRun({ text: "İş Analizi • Ürün Analizi • Teknik Baseline", italics: true, size: 22, color: C.muted })] }));
push(table(["Alan", "Değer"], [
  ["Doküman durumu", "CONDITIONAL BASELINE — OD-001, OD-002, OD-011 ve OD-015 kapanmadan FINAL değildir (Bölüm 20)."],
  ["Sürüm", "3.0"],
  ["Tarih", "26.09.2026"],
  ["Üretim yöntemi", "ID registry'den otomatik üretim + tutarlılık kontrolü (Ek B)."],
  ["Kapsam özeti", `${stats.stories} story, ${stats.rules} iş kuralı, ${stats.fr} FR, ${stats.ac} AC, ${stats.ts} TS, ${stats.nfr} aktif NFR, ${stats.od} OD, ${stats.adr} ADR`],
  ["Dil", "Türkçe son kullanıcı / İngilizce teknik identifier"],
  ["Ana ilke", "AI veri kaynağı değildir; doğrulanmış domain verisi üzerinde reasoning/açıklama yapar."],
], [2600, 7038]));
push(new Paragraph({ children: [new PageBreak()] }));
push(new Paragraph({ children: [new TextRun({ text: "İçindekiler", bold: true, size: 28, color: C.head })], spacing: { after: 200 } }));
push(new TableOfContents("İçindekiler", { hyperlink: true, headingStyleRange: "1-2" }));
push(new Paragraph({ children: [new PageBreak()] }));

// Change history
push(H(HeadingLevel.HEADING_1, "Değişiklik Geçmişi"));
push(table(["Sürüm", "Özet"], X.CHANGELOG, [1000, 8638]));

for (const b of X.SECTIONS) {
  const [t] = b;
  if (t === "h1") push(H(HeadingLevel.HEADING_1, b[1]));
  else if (t === "h2") push(H(HeadingLevel.HEADING_2, b[1]));
  else if (t === "h3") push(H(HeadingLevel.HEADING_3, b[1]));
  else if (t === "p") push(P(b[1]));
  else if (t === "ul") b[1].forEach((x) => push(B(x)));
  else if (t === "table") { push(table(b[1], b[2], b[3])); push(gap()); }
  else if (t === "rules") b[1].forEach(([id, x]) => push(IDB(id, x)));
  else if (t === "gen") gen(b[1]);
}

// Glossary
push(H(HeadingLevel.HEADING_1, "21. Sözlük"));
push(table(["Terim", "Tanım"], X.GLOSSARY, [2400, 7238]));

// Appendix A
push(H(HeadingLevel.HEADING_1, "Ek A — Legacy ID Eşlemesi"));
push(H(HeadingLevel.HEADING_2, "A.1 Story, feature, kural ve enum eşlemesi"));
push(table(["Eski ID / anlam", "v3.0 karşılığı", "Durum / açıklama"], X.LEGACY, [3600, 2800, 3238]));
push(gap());
push(H(HeadingLevel.HEADING_2, "A.2 OD legacy registry"));
push(table(["Anlam", "v2.1", "v2.2", "v3.0 canonical"], X.OD_LEGACY, [3238, 1800, 2000, 2600]));

// Appendix B — consistency report
push(H(HeadingLevel.HEADING_1, "Ek B — Otomatik Tutarlılık Raporu"));
push(P("Bu rapor doküman üretimi sırasında registry üzerinde çalışan doğrulama script'inin çıktısıdır. Hata sayısı sıfır değilse doküman üretilmez."));
const chk = (name, ok, detail) => [name, ok ? "PASS" : "FAIL", detail];
push(table(["Kontrol", "Sonuç", "Ayrıntı"], [
  chk("Duplicate aktif ID", dup.length === 0, `${dup.length} duplicate`),
  chk("Story ID formatı", !errors.some((e) => e.includes("formatı")), `${stats.stories} story`),
  chk("Her story BUS/EPIC/ACTIVE Feature bağlı", !errors.some((e) => /BUS bağı|feature|epic/.test(e)), "Tümü bağlı"),
  chk("Her TS geçerli bir AC'yi doğruluyor", !errors.some((e) => e.startsWith("TS-")), `${stats.ts} TS`),
  chk("Her AC en az bir TS ile doğrulanıyor", !errors.some((e) => e.includes("TS ile doğrulanmıyor")), `${stats.ac} AC`),
  chk("Her BUS en az bir story'ye bağlı", !errors.some((e) => e.includes("hiçbir story'ye bağlı değil")), `${M.BUS.length} BUS`),
  chk("Her ACTIVE feature'ın story'si var", !errors.some((e) => e.includes("ACTIVE feature")), `${stats.feat} ACTIVE feature`),
  chk("Story'lerdeki NFR referansları ACTIVE", !errors.some((e) => /NFR-.*(SUPERSEDED|bilinmeyen)/.test(e)), ""),
  chk("Metin içi ID referansları tanımlı", !errors.some((e) => e.includes("tanımsız referans")), `${stats.refs} referans tarandı`),
], [4400, 1000, 4238]));
push(gap());
push(P(`**Uyarılar (${warnings.length}):** hata değildir; bilinçli olarak açık bırakılan noktaları gösterir.`));
warnings.forEach((w) => push(B(w)));

// Appendix C
push(H(HeadingLevel.HEADING_1, "Ek C — v2.9 → v3.0 Değişiklikleri"));
X.CHANGES_30.forEach((c) => push(B(c)));

// ------------------------------------------------------------------ document
const doc = new Document({
  creator: "AI Seyahat Planlayıcısı",
  features: { updateFields: true },
  title: "AI Seyahat Planlayıcısı v3.0 Konsolide Analiz Baseline",
  styles: {
    default: { document: { run: { font: FONT, size: 20 } } },
    paragraphStyles: [
      { id: "Heading1", name: "Heading 1", basedOn: "Normal", next: "Normal", quickFormat: true,
        run: { size: 32, bold: true, font: FONT, color: C.head }, paragraph: { spacing: { before: 360, after: 160 }, outlineLevel: 0, keepNext: true } },
      { id: "Heading2", name: "Heading 2", basedOn: "Normal", next: "Normal", quickFormat: true,
        run: { size: 26, bold: true, font: FONT, color: C.head }, paragraph: { spacing: { before: 280, after: 120 }, outlineLevel: 1, keepNext: true } },
      { id: "Heading3", name: "Heading 3", basedOn: "Normal", next: "Normal", quickFormat: true,
        run: { size: 22, bold: true, font: FONT, color: "2E5496" }, paragraph: { spacing: { before: 240, after: 100 }, outlineLevel: 2, keepNext: true } },
    ],
  },
  numbering: { config: [{ reference: "bullets", levels: [{ level: 0, format: LevelFormat.BULLET, text: "•", alignment: AlignmentType.LEFT,
    style: { paragraph: { indent: { left: 540, hanging: 270 } } } }] }] },
  sections: [{
    properties: { page: { size: { width: 11906, height: 16838 }, margin: { top: 1134, right: 1134, bottom: 1134, left: 1134 } } },
    headers: { default: new Header({ children: [new Paragraph({ alignment: AlignmentType.RIGHT,
      children: [new TextRun({ text: "AI Seyahat Planlayıcısı — v3.0 Conditional Baseline", size: 16, color: C.muted })] })] }) },
    footers: { default: new Footer({ children: [new Paragraph({ alignment: AlignmentType.CENTER,
      children: [new TextRun({ children: ["Sayfa ", PageNumber.CURRENT, " / ", PageNumber.TOTAL_PAGES], size: 16, color: C.muted })] })] }) },
    children: out,
  }],
});

const file = "AI_Seyahat_Planlayicisi_v3.0_Konsolide_Analiz_Baseline.docx";
Packer.toBuffer(doc).then((buf) => { fs.writeFileSync(file, buf); console.log("WROTE", file, buf.length); });
