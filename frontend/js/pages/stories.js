// หน้า "สตอรี่" (หมวดธุรกรรม): ภาพรวม · รายการคู่สตอรี่ · รายละเอียด · บันทึกคะแนน · หนังสือสัญญา (บันทึกเป็นภาพ PNG)
// การสร้าง/แก้ไขอยู่ที่ story-form.html · ตรวจกติกาอัตโนมัติที่เซิร์ฟเวอร์ (StoryService)
const STORY_FILTERS = [
  { value: '', label: 'ทุกสถานะ' },
  { value: 'waiting', label: 'รอเข้าสู่การเล่น' },
  { value: 'playing', label: 'กำลังเล่น' },
  { value: 'ended', label: 'สิ้นสุด' },
  { value: 'cancelled', label: 'ยกเลิก' },
];
function storiesPage() {
  const firstError = (e, fallback) => (e.errors && Object.values(e.errors)[0][0]) || e.message || fallback;

  return {
    ready: false,
    me: null,
    filters: STORY_FILTERS,
    status: '',
    q: '',
    rows: [],
    protectDays: 7,
    counts: { waiting: 0, playing: 0, ended: 0, all: 0 },
    loading: false,
    error: '',
    msg: '',
    busy: false,

    modal: null, // detail | score | end | contract
    cur: null, // สตอรี่ที่เปิดอยู่
    sf: { score_a: '', score_b: '', protect_a: null, protect_b: null }, // protect_*: true รับ / false ไม่รับ / null ยังไม่ระบุ
    ef: { end_kind: 'surrender', surrender_group_id: null, protect_a: null, protect_b: null, note: '' },
    errors: {},
    issuedAt: '',

    async init() {
      const auth = authState();
      await auth.require('member');
      this.me = auth.user || null;
      this.ready = true;
      this.initCaptureGuard();
      this.$watch('q', () => this.load());
      this.$watch('status', () => this.load());
      await this.load();
      // เปิดรายละเอียดจากลิงก์ ?id=
      const id = new URLSearchParams(location.search).get('id');
      if (id) {
        const s = this.rows.find((r) => String(r.id) === id);
        if (s) this.openDetail(s);
      }
    },

    // ── แสดงผล ──
    ...StoryFmt,
    /** ฝั่งที่ชำระเดิมพันหลังเริ่ม (DEAL-011) หรือว่างถ้าไม่มี */
    lateText(s) {
      if (s.stake_late_a && s.stake_late_b) return 'ทั้งสองฝั่งชำระหลังเริ่ม (×2)';
      if (s.stake_late_a) return 'ฝั่ง A ชำระหลังเริ่ม (×2)';
      if (s.stake_late_b) return 'ฝั่ง B ชำระหลังเริ่ม (×2)';
      return '';
    },
    colorOf: (side) => (side === 'a' ? 'สีน้ำเงิน' : 'สีแดง'),

    kindLabel(g) {
      if (g.type === 'family') return 'FAMILY';
      return g.is_lady ? 'LADY' : 'GANG';
    },

    // ── รายการ ──
    async load() {
      this.loading = true;
      this.error = '';
      const p = new URLSearchParams({ per_page: 100 });
      if (this.status) p.set('status', this.status);
      if (this.q.trim()) p.set('q', this.q.trim());
      try {
        const res = await API.get(`/api/council/stories?${p}`);
        this.rows = res.data;
        this.counts = res.counts || this.counts;
        if (res.options && res.options.protect_days) this.protectDays = res.options.protect_days;
        if (this.cur) {
          const fresh = this.rows.find((r) => r.id === this.cur.id);
          if (fresh) this.cur = fresh;
        }
      } catch (e) {
        this.rows = [];
        this.error = e.status === 403 ? 'ดูสตอรี่ได้เฉพาะตอนเข้าเวร' : API.describe(e, 'โหลดรายการสตอรี่ไม่สำเร็จ');
      } finally {
        this.loading = false;
      }
    },

    async refresh() {
      this.msg = '';
      await this.load();
    },

    // ── ป๊อปอัป ──
    openDetail(s) {
      this.cur = s;
      this.errors = {};
      this.modal = 'detail';
    },

    closeModal() {
      this.freeLogos();
      this.modal = null;
    },

    backToDetail() {
      this.freeLogos();
      this.errors = {};
      this.modal = 'detail';
    },

    get open() {
      return this.modal !== null;
    },

    // ── บันทึกคะแนน ──
    openScore() {
      this.sf = { score_a: String(this.cur.score_a ?? 0), score_b: String(this.cur.score_b ?? 0), protect_a: null, protect_b: null };
      this.errors = {};
      this.modal = 'score';
    },

    get scoreNums() {
      const n = (v) => (/^\d{1,2}$/.test(String(v)) ? Number(v) : null);
      return { a: n(this.sf.score_a), b: n(this.sf.score_b) };
    },

    /** สรุปผลจากคะแนนที่กรอก (แสดงสดก่อนยืนยัน) */
    get scoreResult() {
      const s = this.cur;
      const { a, b } = this.scoreNums;
      if (!s || a === null || b === null) return { title: 'กรอกคะแนนให้ครบทั้งสองฝั่ง', sub: '' };
      const t = s.fights;
      if (a === t && b === t) return { title: 'ถึงเป้าพร้อมกันไม่ได้', sub: `ทั้งสองฝั่งถึง ${t} คะแนนพร้อมกันไม่ได้` };
      if (a > t || b > t) return { title: 'คะแนนเกินเป้า', sub: `คะแนนต้องไม่เกิน ${t}` };
      if (a === t || b === t) return { title: `${a === t ? s.group_a.name : s.group_b.name} ชนะ ${a} : ${b}`, sub: `ถึง ${t} คะแนนแล้ว สตอรี่จะสิ้นสุดและปลดล็อกสล็อตทันที` };
      const lead = a === b ? 'เสมอ' : `${a > b ? s.group_a.name : s.group_b.name} นำ`;
      return { title: `${lead} ${a} : ${b}`, sub: `ยังไม่มีฝั่งถึง ${t} คะแนน สตอรี่ยังคง “กำลังเล่น”` };
    },

    /** คะแนนที่กรอกทำให้สตอรี่จบ = ต้องระบุ Protect (PROT-006) */
    get willEnd() {
      const { a, b } = this.scoreNums;
      const t = this.cur ? this.cur.fights : 0;
      return a !== null && b !== null && (a === t || b === t) && !(a === t && b === t);
    },

    async saveScore() {
      const { a, b } = this.scoreNums;
      const er = {};
      if (a === null) er.score_a = 'กรุณากรอกคะแนน';
      if (b === null) er.score_b = 'กรุณากรอกคะแนน';
      if (this.willEnd) {
        if (this.sf.protect_a === null) er.protect_a = 'กรุณาระบุว่าฝั่ง A รับ Protect หรือไม่';
        if (this.sf.protect_b === null) er.protect_b = 'กรุณาระบุว่าฝั่ง B รับ Protect หรือไม่';
      }
      if (Object.keys(er).length) {
        this.errors = er;
        return;
      }
      this.busy = true;
      this.errors = {};
      try {
        const body = { score_a: a, score_b: b };
        if (this.willEnd) {
          body.protect_a = this.sf.protect_a;
          body.protect_b = this.sf.protect_b;
        }
        const res = await API.post(`/api/council/stories/${this.cur.id}/score`, body);
        this.msg = res.data.status === 'ended' ? `สตอรี่ ${res.data.number} สิ้นสุดแล้ว · ปลดล็อกสล็อตแล้ว` : `บันทึกคะแนนสตอรี่ ${res.data.number} แล้ว`;
        this.cur = res.data;
        await this.load();
        await this.openSummary(); // รูปสรุปคะแนนของสตอรี่นี้ พร้อมคัดลอกไปวางใน Discord
      } catch (e) {
        if (e.status === 422 && e.errors) this.errors = Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]]));
        else this.errors = { _: firstError(e, 'บันทึกคะแนนไม่สำเร็จ') };
      } finally {
        this.busy = false;
      }
    },

    // ── จบแบบพิเศษ / ยกเลิก ──
    openEnd() {
      this.ef = { end_kind: 'surrender', surrender_group_id: null, protect_a: null, protect_b: null, note: '' };
      this.errors = {};
      this.modal = 'end';
    },

    async saveEnd() {
      const e = this.ef;
      if (e.end_kind === 'surrender') {
        const er = {};
        if (!e.surrender_group_id) er.surrender_group_id = 'กรุณาเลือกฝั่งที่ยอมแพ้';
        if (e.protect_a === null) er.protect_a = 'กรุณาระบุว่าฝั่ง A รับ Protect หรือไม่';
        if (e.protect_b === null) er.protect_b = 'กรุณาระบุว่าฝั่ง B รับ Protect หรือไม่';
        if (Object.keys(er).length) {
          this.errors = er;
          return;
        }
      }
      this.busy = true;
      this.errors = {};
      try {
        const body = { end_kind: e.end_kind, note: e.note.trim() || null };
        if (e.end_kind === 'surrender') {
          body.surrender_group_id = e.surrender_group_id;
          body.protect_a = e.protect_a;
          body.protect_b = e.protect_b;
        }
        const res = await API.post(`/api/council/stories/${this.cur.id}/end`, body);
        this.msg = `สตอรี่ ${res.data.number} สิ้นสุดแล้ว · ปลดล็อกสล็อตแล้ว`;
        this.cur = res.data;
        this.modal = 'detail';
        await this.load();
      } catch (x) {
        if (x.status === 422 && x.errors) this.errors = Object.fromEntries(Object.entries(x.errors).map(([k, v]) => [k, v[0]]));
        else this.errors = { _: firstError(x, 'บันทึกไม่สำเร็จ') };
      } finally {
        this.busy = false;
      }
    },

    async cancelStory() {
      const reason = await UI.prompt({ title: 'ยกเลิกสตอรี่', message: this.cur.number, label: 'เหตุผลที่ยกเลิก', required: true, maxLength: 2000, confirmText: 'ยกเลิกสตอรี่', kind: 'danger' });
      if (!reason) return;
      this.busy = true;
      this.errors = {};
      try {
        const res = await API.post(`/api/council/stories/${this.cur.id}/cancel`, { reason });
        this.msg = `ยกเลิกสตอรี่ ${res.data.number} แล้ว · ปลดล็อกสล็อตแล้ว`;
        this.cur = res.data;
        await this.load();
      } catch (x) {
        this.errors = { _: firstError(x, 'ยกเลิกสตอรี่ไม่สำเร็จ') };
      } finally {
        this.busy = false;
      }
    },

    // ── หนังสือสัญญา ──
    logos: { a: '', b: '' }, // โลโก้ของสองฝั่งเป็น blob URL (ดึงผ่าน API เพื่อให้วาดลงรูปได้)
    blank: false, // true = ซ่อนหนังสือสัญญาเป็นจอดำ (กันแคปหน้าจอ)
    copied: false,
    linkCopied: false,
    blankTimer: null,

    async openContract() {
      await this.openImage('contract');
    },

    /** รูปสรุปคะแนน (เปิดอัตโนมัติหลังบันทึกคะแนน หรือกดจากป๊อปอัป) */
    async openSummary() {
      await this.openImage('summary');
    },

    async openImage(kind) {
      this.issuedAt = new Date().toISOString();
      this.errors = {};
      this.copied = false;
      this.blank = !document.hasFocus() && document.visibilityState !== 'visible';
      this.modal = kind;
      await Promise.all(['a', 'b'].map((side) => this.loadLogo(side)));
    },

    /** ผู้ลงชื่อรับรองรูปสรุปคะแนน: ผู้ประกาศจบสตอรี่ ถ้ายังไม่จบใช้ผู้ที่กำลังออกเอกสาร */
    summarySigner(s) {
      if (s.ended_by) return { name: s.ended_by.name, role: s.ended_by.role_label || '' };
      if (this.me) return { name: this.me.character_name || this.me.name, role: this.me.role_name || this.me.role_label || '' };
      return { name: s.created_by ? s.created_by.name : '', role: s.created_by ? s.created_by.role_label || '' : '' };
    },

    /** Protect หลังจบสตอรี่: 'รับ Protect' / 'ไม่รับ Protect' / '' (ยังไม่จบหรือไม่ได้ระบุ) */
    protectText(s, side) {
      if (s.status !== 'ended') return '';
      if (s.end_kind === 'no_show') return 'งด Protect';
      const v = s['protect_' + side];
      if (v === null || v === undefined) return 'ไม่ได้ระบุ';
      if (!v) return 'ไม่รับ Protect';
      const until = s['protect_until_' + side];
      return until ? `รับ Protect · ถึง ${stDateLong(until)} เวลา ${stTimeHm(until)} น.` : 'รับ Protect';
    },

    /** ข้อความสรุปผลสำหรับรูปสรุปคะแนน */
    summaryLine(s) {
      const name = (id) => (s.group_a.id === id ? s.group_a.name : s.group_b.name);
      if (s.status === 'ended') {
        if (s.end_kind === 'no_show') return 'สิ้นสุด · ไม่มาเจรจาปิดเรื่อง (0 คะแนนทั้งสองฝั่ง)';
        if (s.end_kind === 'surrender') return `สิ้นสุด · ${name(s.surrender_group_id)} ยอมแพ้`;
        return `สิ้นสุด · ${name(s.winner_group_id)} ชนะ`;
      }
      if (s.status === 'cancelled') return 'ยกเลิก';
      if (s.score_a === s.score_b) return `เสมอ ${s.score_a} : ${s.score_b} · ยังไม่มีฝั่งถึง ${s.fights} คะแนน`;
      return `${s.score_a > s.score_b ? s.group_a.name : s.group_b.name} นำ · ยังไม่มีฝั่งถึง ${s.fights} คะแนน`;
    },

    async loadLogo(side) {
      const g = this.cur['group_' + side];
      try {
        const res = await fetch(`${window.APP_CONFIG.API_URL}/api/council/groups/${g.id}/files/logo`, { credentials: 'include' });
        if (!res.ok) return;
        this.logos[side] = URL.createObjectURL(await res.blob());
      } catch (e) {
        /* ไม่มีโลโก้ = แสดงชื่อย่อบนพื้นสีของกลุ่มแทน */
      }
    },

    freeLogos() {
      for (const side of ['a', 'b']) {
        if (this.logos[side]) URL.revokeObjectURL(this.logos[side]);
        this.logos[side] = '';
      }
      this.blank = false;
    },

    get isImageModal() {
      return this.modal === 'contract' || this.modal === 'summary';
    },

    // ── กันแคปหน้าจอหนังสือสัญญา/รูปสรุปคะแนน (ทำได้เท่าที่เว็บทำได้: ซ่อนเนื้อหาเมื่อมีสัญญาณการแคป) ──
    hideContract(ms = 0) {
      if (!this.isImageModal) return;
      this.blank = true;
      clearTimeout(this.blankTimer);
      if (ms) this.blankTimer = setTimeout(() => this.showContract(), ms);
    },

    showContract() {
      if (document.hasFocus() && document.visibilityState === 'visible') this.blank = false;
    },

    initCaptureGuard() {
      window.addEventListener('blur', () => this.hideContract());
      window.addEventListener('focus', () => {
        clearTimeout(this.blankTimer);
        this.blankTimer = setTimeout(() => this.showContract(), 400);
      });
      document.addEventListener('visibilitychange', () => (document.visibilityState === 'hidden' ? this.hideContract() : this.showContract()));
      document.addEventListener('keydown', (e) => {
        if (!this.isImageModal) return;
        // PrintScreen / Win+Shift+S / Cmd+Shift+3-4-5 / Ctrl+P: ซ่อนทันที
        const snip = e.key === 'PrintScreen' || e.key === 'Snapshot' || (e.shiftKey && (e.metaKey || e.key === 'Meta') && /^(s|S|3|4|5|#|\$|%)$/.test(e.key)) || (e.ctrlKey && (e.key === 'p' || e.key === 'P'));
        if (snip || e.key === 'Meta' || e.key === 'OS') {
          this.hideContract(snip ? 2500 : 1500);
          if (e.ctrlKey && /p/i.test(e.key)) e.preventDefault();
        }
      });
      document.addEventListener('keyup', (e) => {
        if (!this.isImageModal || (e.key !== 'PrintScreen' && e.key !== 'Snapshot')) return;
        // PrintScreen ใส่รูปจอลงคลิปบอร์ด: เขียนทับด้วยข้อความว่าง
        navigator.clipboard?.writeText(' ').catch(() => {});
        this.hideContract(2500);
      });
    },

    /** สร้างรูป PNG ของหนังสือสัญญา/รูปสรุปคะแนน */
    async contractBlob() {
      const el = document.getElementById(this.modal === 'summary' ? 'story-summary' : 'story-contract');
      if (!el) throw new Error('no-renderer');
      // html-to-image วาดผ่าน SVG foreignObject พร้อมฝังฟอนต์ จึงออกมาเหมือนที่เห็นบนจอ (ตัวอักษรไทย/รูปโลโก้ไม่เพี้ยน)
      if (window.htmlToImage) {
        // สำเนาที่ล้างแอตทริบิวต์ของ Alpine (:style, @click ฯลฯ) ออก เพราะชื่อเหล่านี้ไม่ใช่ XML ที่ถูกต้อง ทำให้สร้างรูปจาก SVG ไม่ได้
        const host = document.createElement('div');
        host.style.cssText = 'position:fixed;left:-20000px;top:0;pointer-events:none';
        const copy = el.cloneNode(true);
        for (const node of [copy, ...copy.querySelectorAll('*')]) {
          for (const attr of [...node.attributes]) {
            if (!/^[A-Za-z_][\w.-]*$/.test(attr.name) || attr.name.startsWith('x-')) node.removeAttribute(attr.name);
          }
        }
        copy.removeAttribute('aria-hidden');
        // ตัดส่วนที่ซ่อนอยู่ (x-show = false) ออก และแปลงรูป blob เป็น data URL เพื่อให้ฝังลงรูปได้แน่นอน
        copy.querySelectorAll('[style*="display: none"]').forEach((n) => n.remove());
        await Promise.all(
          [...copy.querySelectorAll('img')].map(async (img) => {
            const src = img.getAttribute('src');
            if (!src || src.startsWith('data:')) return;
            try {
              const blob = await (await fetch(src)).blob();
              img.src = await new Promise((res, rej) => {
                const fr = new FileReader();
                fr.onload = () => res(fr.result);
                fr.onerror = rej;
                fr.readAsDataURL(blob);
              });
            } catch (e) {
              img.remove();
            }
          }),
        );
        host.appendChild(copy);
        document.body.appendChild(host);
        try {
          const blob = await window.htmlToImage.toBlob(copy, { pixelRatio: 2, backgroundColor: '#0a1c2a', cacheBust: false });
          if (blob) return blob;
        } finally {
          host.remove();
        }
      }
      if (typeof html2canvas !== 'function') throw new Error('no-renderer');
      const canvas = await html2canvas(el, { backgroundColor: '#0a1c2a', scale: 2, useCORS: true, logging: false, onclone: (doc) => this.fixCapture(el, doc) });
      return new Promise((res) => canvas.toBlob(res, 'image/png'));
    },

    /** (ทางสำรองของ html2canvas) แก้รูปโลโก้ที่ถูกบีบเพราะไม่รองรับ object-fit */
    fixCapture(orig, doc) {
      const clone = doc.getElementById(orig.id);
      if (!clone) return;
      const origImgs = [...orig.querySelectorAll('img')];
      const cloneImgs = [...clone.querySelectorAll('img')];
      origImgs.forEach((img, i) => {
        const t = cloneImgs[i];
        if (!t || !img.naturalWidth || getComputedStyle(img).objectFit !== 'contain') return;
        const bw = img.clientWidth;
        const bh = img.clientHeight;
        if (!bw || !bh) return;
        const r = Math.min(bw / img.naturalWidth, bh / img.naturalHeight);
        t.style.objectFit = 'fill';
        t.style.width = img.naturalWidth * r + 'px';
        t.style.height = img.naturalHeight * r + 'px';
      });
    },

    /** คัดลอกลิงก์หนังสือสัญญา (เปิดอ่านได้โดยไม่ต้องล็อกอิน) */
    async copyLink() {
      this.busy = true;
      this.errors = {};
      try {
        const { token } = await API.post(`/api/council/stories/${this.cur.id}/share`);
        const url = `${location.origin}/contract.html?t=${token}`;
        try {
          await navigator.clipboard.writeText(url);
        } catch (e) {
          window.prompt('คัดลอกลิงก์นี้', url); // เบราว์เซอร์ไม่อนุญาตคลิปบอร์ด
        }
        this.linkCopied = true;
        setTimeout(() => (this.linkCopied = false), 5000);
      } catch (e) {
        this.errors = { _: (e && e.message) || 'สร้างลิงก์ไม่สำเร็จ' };
      } finally {
        this.busy = false;
      }
    },

    /** คัดลอกหนังสือสัญญาเป็นรูปลงคลิปบอร์ด (ไปวางใน Discord) */
    async copyPng() {
      this.busy = true;
      this.errors = {};
      try {
        const blob = await this.contractBlob();
        if (!navigator.clipboard || typeof ClipboardItem === 'undefined') throw new Error('clipboard');
        await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
        this.copied = true;
        setTimeout(() => (this.copied = false), 6000);
      } catch (e) {
        this.errors = { _: 'คัดลอกรูปไม่สำเร็จ (เบราว์เซอร์ต้องอนุญาตคลิปบอร์ด และต้องเปิดผ่าน https หรือ localhost)' };
      } finally {
        this.busy = false;
      }
    },

    resultText(s) {
      const name = (id) => (s.group_a.id === id ? s.group_a.name : s.group_b.name);
      if (s.end_kind === 'no_show') return 'ไม่มาเจรจาปิดเรื่อง · 0 คะแนนทั้งสองฝั่ง (PROT-009)';
      if (s.end_kind === 'surrender') return `${name(s.surrender_group_id)} ยอมแพ้ · ผู้ชนะ ${name(s.winner_group_id)}`;
      return s.winner_group_id ? `ผู้ชนะ ${name(s.winner_group_id)}` : '';
    },
  };
}
