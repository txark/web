function rulesPage() {
  return {
    sections: [],
    current: '',
    tocOpen: false,
    closed: {}, // ชื่อกลุ่มที่ถูกพับ
    activeCat: '', // anchor ของหมวดย่อยที่กำลังอ่าน (ติดตามตามการเลื่อน)
    spyRetries: 0,
    spyLock: false, // ระหว่างเลื่อนอัตโนมัติ ไม่อัปเดตตามการเลื่อน กันตัวเน้นกระพริบผ่านหมวดย่อยระหว่างทาง
    q: '',
    loading: true,
    error: '',

    async init() {
      try {
        const saved = parseInt(localStorage.getItem('rulesTextScale'), 10);
        if (saved >= 0 && saved < this.scaleSteps.length) this.scaleIdx = saved;
      } catch (e) {}
      this.applyScale();

      let ticking = false;
      const onScroll = () => {
        if (ticking) return;
        ticking = true;
        requestAnimationFrame(() => {
          ticking = false;
          this.spy();
        });
      };
      window.addEventListener('scroll', onScroll, { passive: true });
      window.addEventListener('resize', onScroll);
      // กดลิงก์ในเมนูบนที่ชี้หมวดอื่นของหน้านี้ (เช่น ประกาศเรียก) ขณะอยู่ในหน้ากฎอยู่แล้ว
      window.addEventListener('hashchange', () => {
        const key = location.hash.slice(1);
        if (key && key !== this.current && this.sections.some((s) => s.key === key)) this.choose(key);
      });

      try {
        const res = await API.get('/api/public/rules');
        // เลขหมวด (no) นับใหม่ทุกกลุ่มเริ่มที่ 1
        // เลขข้อ (n) ใส่เฉพาะข้อกฎ และ "นับใหม่ทุกครั้งที่เจอหัวข้อหมวดย่อย" (heading)
        const perGroup = {};
        this.sections = res.data.map((s) => {
          perGroup[s.group] = (perGroup[s.group] || 0) + 1;
          return { ...s, no: perGroup[s.group], ...this.numberRules(s) };
        });
        const fromHash = location.hash.slice(1);
        this.current = this.sections.some((s) => s.key === fromHash) ? fromHash : (this.sections[0]?.key ?? '');

        let saved = null;
        try {
          saved = localStorage.getItem('rulesTocClosed');
        } catch (e) {}

        if (saved !== null) {
          // ผู้ใช้เคยย่อ/ขยายเอง: ใช้ค่าที่จำไว้
          try {
            this.closed = JSON.parse(saved) || {};
          } catch (e) {
            this.closed = {};
          }
        } else {
          // ครั้งแรก: ขยายเฉพาะกลุ่มที่กำลังอ่าน เพื่อให้สารบัญพอดีจอ (ยังไม่บันทึกจนกว่าผู้ใช้จะกดเอง)
          this.closed = Object.fromEntries(
            this.groups.filter((g) => !g.items.some((s) => s.key === this.current)).map((g) => [g.label, true]),
          );
        }
        this.reveal(this.current);
        this.$nextTick(() => this.spy());
      } catch (e) {
        this.error = API.describe(e, 'โหลดกฎไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
      } finally {
        this.loading = false;
        // เนื้อหาเพิ่งแสดงจริง: คำนวณหมวดย่อยที่อ่านอยู่อีกครั้งเมื่อมีตำแหน่งจริงแล้ว
        this.$nextTick(() => this.spy());
      }
    },

    // จัด Typography ในเนื้อหา แล้วคืนเป็น HTML ที่ปลอดภัย (escape ก่อนเสมอ) ใช้กับ x-html
    //  - "…" → “…”  และช่วงตัวเลข 1 - 2 → 1 – 2
    //  - เน้นจำนวนเงิน/ไม้, ระยะเวลา/จำนวนคน, ใบเหลือง/ใบแดง, คำสำคัญ และคำที่กฎใช้บ่อย (ห้าม/เด็ดขาด/ทุกกรณี/ยกเว้น)
    fmt(text) {
      if (!text) return '';
      const typo = String(text)
        .replace(/"([^"\n]+)"/g, '“$1”')
        .replace(/(\d)\s+-\s+(\d)/g, '$1 – $2');
      const esc = typo.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

      // ลำดับกลุ่ม: 1 จำนวนเงิน/ไม้  2 ระยะเวลา/จำนวนคน  3 ใบเหลือง  4 ใบแดง  5 คำสำคัญ  6 ห้าม  7 เด็ดขาด/ทุกกรณี  8 ยกเว้น
      // "ห้าม" ต้องไม่ตามด้วยสระ/วรรณยุกต์ เพื่อไม่ไปจับคำอื่น เช่น "ห้ามิให้"
      const re = /(\d[\d,]*(?:\s*[-–]\s*\d[\d,]*)?\s*(?:WVDL|ไม้))|(\d+(?:\s*[-–]\s*\d+)?\s*(?:วัน|นาที|ชม\.|คน))|(ใบเหลือง)|(ใบแดง)|(BK|Blacklist|คว่ำบาตร|Break Roleplay)|(ห้าม(?![ัิ-ฺ็-๎]))|(เด็ดขาด|ทุกกรณี)|(ยกเว้น)/g;
      return esc.replace(re, (m, amt, num, yellow, red, kw, no, strong, except) => {
        if (amt || num) {
          // ช่วงตัวเลขใช้ขีดช่วง (–): "2-3" → "2–3", "500 - 900" → "500 – 900"
          const shown = m.replace(/(\d)(\s*)-(\s*)(\d)/, (_, x, sp1, sp2, y) => (sp1 || sp2 ? `${x} – ${y}` : `${x}–${y}`));
          return `<span class="amt">${shown}</span>`;
        }
        if (yellow) return `<span class="kw-yellow">${m}</span>`;
        if (red) return `<span class="kw-red">${m}</span>`;
        if (no) return `<span class="kw-no">${m}</span>`;
        if (strong) return `<span class="kw-strong">${m}</span>`;
        if (except) return `<span class="kw-except">${m}</span>`;
        return `<span class="kw">${m}</span>`;
      });
    },

    // ── ขนาดตัวอักษร (ก− / ก+) ──
    scaleSteps: [0.9, 1, 1.12, 1.25, 1.4],
    scaleIdx: 1,

    get scaleLabel() {
      return Math.round(this.scaleSteps[this.scaleIdx] * 100) + '%';
    },

    setScale(i) {
      this.scaleIdx = Math.max(0, Math.min(this.scaleSteps.length - 1, i));
      this.applyScale();
      try {
        localStorage.setItem('rulesTextScale', String(this.scaleIdx));
      } catch (e) {}
    },

    applyScale() {
      document.documentElement.style.setProperty('--text-scale', this.scaleSteps[this.scaleIdx]);
    },

    // วันที่แบบไทย: "2026-10-28" → "28 ต.ค. 2569" (วว ด.ด พ.ศ.) ตัดเอง ไม่พึ่ง timezone ของเครื่อง
    thaiDate(iso) {
      const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || '');
      if (!m) return '';
      const months = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
      return `${Number(m[3])} ${months[Number(m[2]) - 1]} ${Number(m[1]) + 543}`;
    },

    // ใส่เลขข้อ/ข้อมูลหมวดย่อยให้รายการของหมวดหนึ่ง
    //  - หัวข้อ (heading) เริ่มหมวดย่อยใหม่: เลขข้อกลับไปเริ่มที่ 1, ได้เลขหมวดย่อย (cat), anchor และจำนวนรายการ (count)
    //  - cats = รายการหมวดย่อยทั้งหมด ใช้ทำแถบกระโดด
    numberRules(section) {
      let n = 0;
      let cat = 0;
      const rules = section.rules.map((r) => {
        if (r.kind === 'heading') {
          n = 0;
          cat += 1;
          return { ...r, cat, anchor: `${section.key}-cat-${r.id}`, count: 0 };
        }
        return { ...r, n: r.kind === 'rule' ? ++n : null };
      });

      // นับรายการในแต่ละหมวดย่อย (ข้อกฎ/กล่องเนื้อหา/ราคา) จนถึงหัวข้อถัดไป
      let current = null;
      for (const r of rules) {
        if (r.kind === 'heading') current = r;
        else if (current && ['rule', 'highlight', 'price'].includes(r.kind)) current.count += 1;
      }

      return { rules, cats: rules.filter((r) => r.kind === 'heading') };
    },

    // ความสูงของส่วนที่ตรึงอยู่บนจอ: เมนูบน (จอ ≥640px) + แถบค้นหา/หมวด (แท็บเล็ต/มือถือ)
    pinned() {
      const header = document.querySelector('.site-header');
      const sticky = header && getComputedStyle(header).position === 'sticky' ? header.offsetHeight : 0;
      const narrow = window.matchMedia?.('(max-width: 1023px)')?.matches;
      const bar = narrow ? document.getElementById('rules-bar')?.offsetHeight || 0 : 0;
      return sticky + bar;
    },

    // เลื่อนไปที่องค์ประกอบ โดยหักความสูงของส่วนที่ตรึงอยู่
    scrollToEl(el) {
      if (!el) return;
      this.spyLock = true;
      window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - this.pinned() - 16, behavior: 'smooth' });
      // ปล่อยล็อกเมื่อเลื่อนเสร็จ (scrollend ถ้ารองรับ ไม่งั้นรอเวลาคงที่) แล้วคำนวณหมวดย่อยที่อ่านอยู่ใหม่
      const release = () => {
        this.spyLock = false;
        this.spy();
      };
      if ('onscrollend' in window) window.addEventListener('scrollend', release, { once: true });
      setTimeout(() => this.spyLock && release(), 1200);
    },

    jump(anchor) {
      this.scrollToEl(document.getElementById(anchor));
    },

    // กดหมวดย่อยในสารบัญ: ไฮไลต์ทันที แล้วเลื่อนไป (มือถือ: ปิดเมนูสารบัญ)
    jumpSub(cat) {
      this.activeCat = cat.anchor;
      this.tocOpen = false;
      this.jump(cat.anchor);
    },

    // หมวดย่อยที่กำลังอ่าน = หัวข้อหมวดย่อยสุดท้ายที่เลื่อนผ่านขอบล่างของส่วนที่ตรึงอยู่
    spy() {
      const cats = this.currentSection?.cats || [];
      if (this.searching || cats.length < 2) {
        this.activeCat = '';
        return;
      }
      if (this.spyLock) return;

      // หัวข้อที่ยังไม่แสดง (อยู่ในส่วนที่ซ่อนอยู่ตอนโหลด) ไม่มีตำแหน่งจริง จึงต้องข้าม ไม่งั้นจะนับว่าเลื่อนผ่านไปแล้วทั้งหมด
      const shown = cats.map((k) => ({ k, el: document.getElementById(k.anchor) })).filter((x) => x.el && x.el.getClientRects().length > 0);
      if (shown.length === 0) {
        // ยังไม่แสดงจริง (เพิ่งโหลด/เพิ่งเปลี่ยนหมวด): ลองใหม่ทุกเฟรม ไม่เกิน ~0.3 วินาที
        if (this.spyRetries++ < 20) requestAnimationFrame(() => this.spy());
        return;
      }
      this.spyRetries = 0;

      const line = this.pinned() + 32;
      let active = shown[0].k.anchor;
      for (const { k, el } of shown) {
        if (el.getBoundingClientRect().top <= line) active = k.anchor;
        else break;
      }

      // เลื่อนถึงท้ายหน้าแล้ว: หมวดสุดท้ายมักสั้นเกินกว่าจะเลื่อนขึ้นถึงเส้นอ่านได้ จึงถือว่าอ่านถึงหมวดสุดท้าย
      if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4) {
        active = shown[shown.length - 1].k.anchor;
      }

      if (active !== this.activeCat) {
        this.activeCat = active;
        this.$nextTick(() => this.keepActiveInView());
      }
    },

    // สารบัญยาวจนต้องเลื่อน: เลื่อนกล่องสารบัญให้เห็นหมวดย่อยที่อ่านอยู่เสมอ (ไม่ขยับหน้าหลัก)
    keepActiveInView() {
      const nav = document.getElementById('toc-nav');
      const btn = nav?.querySelector(`[data-cat="${this.activeCat}"]`);
      if (!nav || !btn) return;
      const box = [nav, nav.parentElement].find((e) => e && e.scrollHeight > e.clientHeight + 1);
      if (!box) return;
      const b = btn.getBoundingClientRect();
      const r = box.getBoundingClientRect();
      if (b.top < r.top + 8) box.scrollTop += b.top - r.top - 8;
      else if (b.bottom > r.bottom - 8) box.scrollTop += b.bottom - r.bottom + 8;
    },

    get activeCatTitle() {
      return this.currentSection?.cats.find((k) => k.anchor === this.activeCat)?.title || '';
    },

    pad(n) {
      return String(n).padStart(2, '0');
    },

    // จำนวนรายการของหมวด: นับข้อกฎ/รายการราคา ถ้าไม่มี (เช่น มาตรการสูงสุด) ให้นับกล่องเนื้อหา
    count(s) {
      const items = s.rules.filter((r) => r.kind === 'rule' || r.kind === 'price').length;
      return items || s.rules.filter((r) => r.kind !== 'heading').length;
    },

    // จัดหมวดเป็นกลุ่มตามที่ API ส่งมา (หมวดในกลุ่มเดียวกันอยู่ติดกัน)
    get groups() {
      const out = [];
      for (const s of this.sections) {
        let g = out.find((x) => x.label === s.group);
        if (!g) out.push((g = { label: s.group, items: [] }));
        g.items.push(s);
      }
      return out;
    },

    // ── ย่อ/ขยายกลุ่มในสารบัญ ──
    // ตอนค้นหาแสดงทุกกลุ่มเสมอ เพื่อให้เห็นจำนวนที่พบในแต่ละหมวด
    isOpen(g) {
      return this.searching || !this.closed[g.label];
    },

    toggleGroup(g) {
      if (this.isOpen(g)) {
        this.closed = { ...this.closed, [g.label]: true };
      } else {
        // กางกลุ่มนี้ → กลุ่มอื่นย่อลงทั้งหมด (เหลือกลุ่มเดียวที่เปิด)
        this.closed = Object.fromEntries(this.groups.map((x) => [x.label, x.label !== g.label]));
      }
      this.saveClosed();
    },

    get allOpen() {
      return this.groups.every((g) => !this.closed[g.label]);
    },

    toggleAll() {
      const close = this.allOpen;
      this.closed = Object.fromEntries(this.groups.map((g) => [g.label, close]));
      this.saveClosed();
    },

    // กลุ่มที่พับอยู่แต่มีหมวดที่กำลังอ่าน: ใช้แสดงจุดบอกตำแหน่งที่หัวกลุ่ม
    hasCurrent(g) {
      return !this.searching && g.items.some((s) => s.key === this.current);
    },

    // เลือกหมวดที่อยู่ในกลุ่มที่พับ ให้กลุ่มนั้นขยายออก
    reveal(key) {
      const g = this.groups.find((x) => x.items.some((s) => s.key === key));
      if (g && this.closed[g.label]) {
        this.closed = { ...this.closed, [g.label]: false };
        this.saveClosed();
      }
    },

    saveClosed() {
      try {
        localStorage.setItem('rulesTocClosed', JSON.stringify(this.closed));
      } catch (e) {}
    },

    get currentSection() {
      return this.sections.find((s) => s.key === this.current);
    },

    matches(r, q) {
      return [r.title, r.body, r.note, r.penalty].some((t) => t && t.toLowerCase().includes(q));
    },

    // จำนวนข้อในหมวดที่ตรงกับคำค้น (แสดงในสารบัญตอนค้นหา)
    matchCount(s) {
      const q = this.q.trim().toLowerCase();
      return q ? s.rules.filter((r) => this.matches(r, q)).length : 0;
    },

    get searching() {
      return this.q.trim() !== '';
    },

    // ค้นหา = แสดงทุกหมวดที่มีข้อตรงคำค้น, ไม่ค้นหา = แสดงหมวดที่เลือก
    get chapters() {
      if (!this.searching) {
        return this.sections.filter((s) => s.key === this.current);
      }
      const q = this.q.trim().toLowerCase();
      return this.sections
        .map((s) => ({
          ...s,
          rules: s.rules.filter((r) => this.matches(r, q)),
        }))
        .filter((s) => s.rules.length > 0);
    },

    choose(key) {
      this.q = '';
      this.tocOpen = false;
      // เลือกหมวดในกลุ่มอื่น: กลุ่มเดิมที่เปิดอยู่ย่อลงอัตโนมัติ
      const prev = this.groups.find((x) => x.items.some((s) => s.key === this.current));
      const next = this.groups.find((x) => x.items.some((s) => s.key === key));
      if (prev && next && prev.label !== next.label) {
        this.closed = { ...this.closed, [prev.label]: true };
        this.saveClosed();
      }
      this.current = key;
      this.reveal(key);
      history.replaceState(null, '', '#' + key);
      this.activeCat = '';
      this.$nextTick(() => this.spy());
      // แท็บเล็ต/มือถือ: เลื่อนไปที่หัวเนื้อหา โดยหักความสูงของเมนูและแถบค้นหาที่ตรึงอยู่
      if (window.matchMedia?.('(max-width: 1023px)')?.matches) {
        this.$nextTick(() => this.scrollToEl(document.getElementById('rules-panel')));
      }
    },
  };
}
