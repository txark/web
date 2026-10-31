// คัดลอกไฟล์ที่ต้องใช้ตอนรันจาก node_modules มาไว้ในโปรเจกต์ (เว็บเสิร์ฟแบบ static ไม่พึ่ง CDN)
//  - Alpine.js  → js/vendor/alpine.min.js
//  - ฟอนต์      → fonts/*.woff2 + css/fonts.css (@font-face เฉพาะชุดอักษรไทยและละติน)
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const nm = path.join(root, 'node_modules');

// Alpine
fs.mkdirSync(path.join(root, 'js', 'vendor'), { recursive: true });
fs.copyFileSync(path.join(nm, 'alpinejs', 'dist', 'cdn.min.js'), path.join(root, 'js', 'vendor', 'alpine.min.js'));
console.log('copied alpine.min.js');

// ฟอนต์: เนื้อหา = Noto Sans Thai Looped, หัวข้อ = Prompt, เอกสารทางการ = Noto Serif Thai
const fonts = [
  { pkg: 'noto-sans-thai-looped', weights: [400, 500, 600, 700] },
  { pkg: 'prompt', weights: [600, 700] },
  { pkg: 'noto-serif-thai', weights: [400, 600, 700] }, // หนังสือสัญญา (แบบเอกสารทางการ)
  { pkg: 'dancing-script', weights: [600] }, // ลายเซ็นผู้รับรอง (ตัวอักษรละติน)
  { pkg: 'charmonman', weights: [400] }, // ลายเซ็นผู้รับรอง (ตัวอักษรไทย)
];
const subsets = ['thai', 'latin'];

const outFonts = path.join(root, 'fonts');
fs.mkdirSync(outFonts, { recursive: true });

let css = '/* สร้างโดย scripts/copy-vendor.js — อย่าแก้ไฟล์นี้โดยตรง */\n';
let count = 0;
for (const { pkg, weights } of fonts) {
  const pkgDir = path.join(nm, '@fontsource', pkg);
  for (const w of weights) {
    const src = fs.readFileSync(path.join(pkgDir, `${w}.css`), 'utf8');
    for (const block of src.match(/\/\*[^*]*\*\/\s*@font-face\s*{[^}]*}/g) || []) {
      const subset = subsets.find((s) => block.includes(`-${s}-${w}-normal`));
      if (!subset) continue;
      const file = `${pkg}-${subset}-${w}-normal.woff2`;
      fs.copyFileSync(path.join(pkgDir, 'files', file), path.join(outFonts, file));
      // ใช้เฉพาะ woff2 (รองรับทุกเบราว์เซอร์ปัจจุบัน) และชี้ไปที่ fonts/ ของโปรเจกต์
      css += block.replace(/src:[^;]*;/, `src: url(../fonts/${file}) format('woff2');`) + '\n';
      count++;
    }
  }
}
fs.writeFileSync(path.join(root, 'css', 'fonts.css'), css);
console.log(`copied ${count} font files → fonts/, css/fonts.css`);
