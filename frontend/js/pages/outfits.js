// หน้า "ลงชุด/แก้ไขชุด": เลือกแก๊ง/ครอบครัว แล้วทำธุรกรรมชุด
//   outfit        = ลงเพิ่ม (add) และ/หรือ เปลี่ยน-แทนชุดเดิม (replace) ในธุรกรรมเดียว ใช้ไฟล์ .ZIP ไฟล์เดียว
//   outfit_counts = แก้จำนวนชุดที่ลงทะเบียน (กำหนดใหม่ทั้งหมด)
const OUTFIT_TYPES = [
  { key: 'suit', label: 'Suit (สูท)' },
  { key: 'hoodie', label: 'Hoodie (ฮูด)' },
  { key: 'tshirt', label: 'T-Shirt (เสื้อยืด)' },
  { key: 'polo', label: 'Polo (โปโล)' },
  { key: 'armor', label: 'Armor (เกราะ)' },
  { key: 'gun', label: 'Gun (ซองปืน)' },
  { key: 'mod1', label: 'MOD 1' },
  { key: 'mod2', label: 'MOD 2' },
  { key: 'accessories', label: 'Accessories' },
];
const FAMILY_OUTFIT_KEYS = ['hoodie', 'tshirt', 'polo', 'accessories']; // ครอบครัวลงได้เฉพาะ 4 ชนิดนี้
const SPECIAL_KEYS = ['mod1', 'mod2', 'accessories']; // ราคาเพิ่มชุด MOD / Accessories สูงกว่าชุดทั่วไป
const MODES = [
  { value: 'outfit', label: 'ลงชุด / แก้ไขชุด', hint: 'ลงชุดเพิ่ม และ/หรือ เปลี่ยน-แทนชุดเดิม ในธุรกรรมเดียว แนบไฟล์ .ZIP ไฟล์เดียว' },
  // โหมด "แก้จำนวนชุด" (outfit_counts) ถูกซ่อนจากหน้านี้ แก้จำนวนได้ที่หน้าจัดการแก๊ง/ครอบครัว
];
const ZIP_LIMIT_MB = 50;

function outfitsPage() {
  const firstError = (e, fallback) => (e.errors && Object.values(e.errors)[0][0]) || e.message || fallback;

  return {
    ready: false,
    modes: MODES,
    mode: 'outfit',

    q: '',
    kind: '', // '' ทั้งหมด | gang | lady | family
    kinds: [{ value: '', label: 'ทั้งหมด' }, { value: 'gang', label: 'GANG · แก๊ง' }, { value: 'lady', label: 'LADY-GANG · แก๊งหญิงล้วน' }, { value: 'family', label: 'FAMILY · ครอบครัว' }],
    results: [],
    searching: false,
    g: null,

    form: {}, // ลงเพิ่ม (outfit) / จำนวนใหม่ (outfit_counts)
    rform: {}, // จำนวนหลังเปลี่ยนของชนิดที่ถูกแทน
    zip: null,
    note: '',
    types: [],
    swaps: {},

    fees: { currency: 'WVDL', outfit_add: 500000, outfit_add_special: 1000000, outfit_edit: 500000, outfit_free_hours: 24 },
    busy: false,
    errors: {},
    msg: '',

    async init() {
      const auth = authState();
      await auth.require('member');
      this.ready = true;
      this.$watch('q', () => this.search());
      this.$watch('kind', () => this.search());
      this.$watch('mode', () => this.resetForm());
      await Promise.all([this.search(), this.loadFees()]);
    },

    async loadFees() {
      try {
        const res = await API.get('/api/council/transactions?per_page=1');
        if (res.fees) this.fees = { ...this.fees, ...res.fees };
      } catch (e) {
        /* ใช้ค่าเริ่มต้นไปก่อน เซิร์ฟเวอร์คำนวณจริงเสมอ */
      }
    },

    async search() {
      this.searching = true;
      const p = new URLSearchParams({ per_page: 24 });
      if (this.q.trim()) p.set('q', this.q.trim());
      if (this.kind) p.set('kind', this.kind);
      try {
        this.results = (await API.get(`/api/council/groups?${p}`)).data;
      } catch (e) {
        this.results = [];
        this.errors = { _: API.describe(e, 'โหลดรายการไม่สำเร็จ') };
      } finally {
        this.searching = false;
      }
    },

    pick(r) {
      this.g = r;
      this.msg = '';
      this.resetForm();
    },

    back() {
      this.g = null;
      this.msg = '';
      this.errors = {};
      this.search();
    },

    kindLabel(r) {
      if (r.type === 'family') return 'FAMILY';
      return r.is_lady ? 'LADY' : 'GANG';
    },

    labelOf(key) {
      return (OUTFIT_TYPES.find((o) => o.key === key) || { label: key }).label;
    },

    numLabel(n) {
      return n === null || n === undefined ? 'ยังไม่ลงทะเบียน' : n.toLocaleString('th-TH') + ' ชิ้น';
    },

    money(n) {
      return Number(n || 0).toLocaleString('th-TH') + ' ' + (this.fees.currency || 'WVDL');
    },

    // ── ข้อมูลชุดของกลุ่ม ──
    /** เฉพาะชนิดที่ประเภทนี้ลงได้ตามลิมิตจากเซิร์ฟเวอร์ */
    get outfitTypes() {
      const lim = this.g && this.g.outfit_limits;
      if (lim) return OUTFIT_TYPES.filter((o) => o.key in lim);
      return this.g && this.g.type === 'family' ? OUTFIT_TYPES.filter((o) => FAMILY_OUTFIT_KEYS.includes(o.key)) : OUTFIT_TYPES;
    },

    limit(key) {
      const v = this.g && this.g.outfit_limits ? this.g.outfit_limits[key] : undefined;
      return v === undefined ? null : Number(v);
    },

    current(key) {
      const v = this.g && this.g.outfit_counts ? this.g.outfit_counts[key] : undefined;
      return v === undefined || v === null ? null : Number(v);
    },

    /** แก้ไข/แทนชุดได้เฉพาะชนิดที่ลงทะเบียนไว้แล้ว (หรือเลือกไว้แล้ว/เป็นปลายทางการสลับ) */
    canEdit(key) {
      return (this.current(key) || 0) > 0 || this.types.includes(key);
    },

    /** ลงเพิ่มได้อีกกี่ชิ้น (ลิมิต − ที่ลงไว้แล้ว) ถ้าลงเกินลิมิตมาก่อนแล้วจะเป็น 0 */
    room(key) {
      return Math.max(0, (this.limit(key) ?? 0) - (this.current(key) || 0));
    },

    // ── ฟอร์ม ──
    resetForm() {
      this.errors = {};
      this.zip = null;
      this.note = '';
      this.types = [];
      this.swaps = {};
      this.rform = {};
      if (this.$refs.zipInput) this.$refs.zipInput.value = '';
      if (!this.g) return;
      this.form = Object.fromEntries(this.outfitTypes.map((o) => [o.key, this.mode === 'outfit_counts' && this.current(o.key) !== null ? String(this.current(o.key)) : '']));
    },

    /** ค่าในช่อง (ตัดช่องว่าง/คอมมา): ว่าง = undefined, ไม่ใช่ตัวเลข = null */
    num(v) {
      const s = String(v ?? '').replace(/[,\s]/g, '');
      if (s === '') return undefined;
      return /^\d{1,4}$/.test(s) ? Number(s) : null;
    },

    parsed(key) {
      return this.num(this.form[key]);
    },

    parsedR(key) {
      return this.num(this.rform[key]);
    },

    /** ตัดตัวอักษรที่ไม่ใช่ตัวเลข และไม่ให้กรอกเกินที่ลงได้ */
    clampTo(obj, key, max) {
      const digits = String(obj[key] ?? '').replace(/\D/g, '');
      obj[key] = digits === '' ? '' : String(Math.min(Number(digits), max));
    },

    clamp(key) {
      this.clampTo(this.form, key, this.mode === 'outfit' ? this.room(key) : this.limit(key) ?? 0);
      delete this.errors[(this.mode === 'outfit' ? 'add.' : 'counts.') + key];
      delete this.errors.add;
    },

    clampR(key) {
      this.clampTo(this.rform, key, this.limit(key) ?? 0);
      delete this.errors['replace.counts.' + key];
    },

    get addTotal() {
      return this.outfitTypes.reduce((s, o) => s + (this.types.includes(o.key) ? 0 : this.parsed(o.key) || 0), 0);
    },

    /** จำนวนหลังบันทึกของชนิดนี้ (ใช้แสดงให้เห็นก่อนกดบันทึก) */
    after(key) {
      if (this.mode === 'outfit_counts') {
        const v = this.parsed(key);
        return v === undefined ? null : v;
      }
      if (this.types.includes(key)) {
        const v = this.parsedR(key);
        return v === undefined || v === null ? this.current(key) : v;
      }
      const v = this.parsed(key);
      return v ? (this.current(key) || 0) + v : this.current(key);
    },

    // ── ส่วนเปลี่ยน/แทน ──
    toggleType(key) {
      if (this.types.includes(key)) {
        this.types = this.types.filter((t) => t !== key);
        delete this.rform[key];
        // ถอดชนิดนี้ออก = ยกเลิกการสลับที่เกี่ยวข้อง
        for (const [from, to] of Object.entries(this.swaps)) if (from === key || to === key) delete this.swaps[from];
        this.recalcSwaps();
      } else {
        this.types = [...this.types, key];
        this.form[key] = ''; // ชนิดเดียวกันอยู่ได้ส่วนเดียว: ล้างค่าในส่วนลงเพิ่ม
        // เริ่มจากจำนวนที่ลงไว้ (ยังไม่ลงทะเบียน = 1 ชิ้น) แล้วแก้ได้ภายในลิมิต
        this.rform[key] = String(this.current(key) ?? Math.min(1, this.limit(key) ?? 1));
      }
      delete this.errors['replace.types'];
      delete this.errors.add;
    },

    /** สลับข้ามชนิด: from → to (กดซ้ำที่ปลายทางเดิม = ยกเลิก) ต้นทางเหลือ 0 ส่วนปลายทางได้ชิ้นที่ย้ายมา */
    setSwap(from, to) {
      // ย้ายต่อเป็นทอดไม่ได้: ปลายทางห้ามเป็นต้นทาง และต้นทางห้ามเป็นปลายทางของชนิดอื่น
      if (this.swaps[to] || this.isSwapTarget(from)) return;
      if (this.swaps[from] === to) {
        delete this.swaps[from];
        this.rform[from] = String(this.current(from) ?? Math.min(1, this.limit(from) ?? 1));
      } else {
        this.swaps[from] = to;
        if (!this.types.includes(to)) {
          this.types = [...this.types, to];
          this.form[to] = '';
        }
      }
      this.recalcSwaps();
    },

    isSwapTarget(key) {
      return Object.values(this.swaps).includes(key);
    },

    /** ชนิดที่ถูกย้ายมารวมที่ชนิดนี้ */
    swapSources(key) {
      return Object.entries(this.swaps).filter(([, to]) => to === key).map(([from]) => from);
    },

    /** ตั้งจำนวนอัตโนมัติจากการสลับ (แก้ช่องปลายทางต่อได้ แต่จะคำนวณใหม่เมื่อเปลี่ยนการสลับ) */
    recalcSwaps() {
      for (const from of Object.keys(this.swaps)) this.rform[from] = '0';
      for (const to of new Set(Object.values(this.swaps))) {
        const moved = this.swapSources(to).reduce((s, f) => s + Math.max(1, this.current(f) || 0), 0);
        this.rform[to] = String(Math.min((this.current(to) || 0) + moved, this.limit(to) ?? 0));
        delete this.errors['replace.counts.' + to];
      }
    },

    /** การ์ดเรียงให้ต้นทางตามด้วยปลายทางเสมอ ที่เหลือเรียงตามปกติ */
    get orderedTypes() {
      const picked = this.outfitTypes.filter((o) => this.types.includes(o.key)).map((o) => o.key);
      const out = [];
      const push = (k) => {
        if (picked.includes(k) && !out.includes(k)) out.push(k);
      };
      for (const k of picked) {
        if (this.swaps[k]) {
          push(k);
          push(this.swaps[k]);
        }
      }
      picked.forEach(push);
      return out.map((k) => ({ key: k, label: this.labelOf(k) }));
    },

    /** สรุปทุกอย่างที่ธุรกรรมนี้จะเปลี่ยน ให้อ่านง่าย: ลงเพิ่ม / เปลี่ยนชนิด (A → B) / แก้จำนวน */
    get changeRows() {
      const side = (key) => ({ key, label: this.labelOf(key), before: this.current(key), after: this.after(key) });
      const rows = [];
      for (const o of this.outfitTypes) {
        if (!this.types.includes(o.key) && this.parsed(o.key) > 0) rows.push({ id: 'a' + o.key, kind: 'add', from: side(o.key), n: this.parsed(o.key) });
      }
      for (const k of this.types) {
        if (this.swaps[k]) rows.push({ id: 'r' + k, kind: 'swap', from: side(k), to: side(this.swaps[k]) });
        else if (!this.isSwapTarget(k)) rows.push({ id: 'r' + k, kind: 'count', from: side(k) });
      }
      return rows;
    },

    // ── ค่าบริการตามกฎสภา (เซิร์ฟเวอร์คำนวณจริงอีกครั้ง) ──
    /** ขึ้นทะเบียนใหม่ ลงชุดเพิ่มฟรีภายใน N ชั่วโมง และใช้ได้ครั้งเดียว (ส่งไฟล์ชุดแล้ว 1 ครั้ง สิทธิ์หมด) — เซิร์ฟเวอร์เป็นผู้ตัดสิน */
    get addIsFree() {
      return !!(this.g && this.g.outfit_free_available);
    },

    get feeLines() {
      if (this.mode !== 'outfit') return [];
      const f = this.fees;
      const free = this.addIsFree;
      const suffix = free ? ` (ฟรี ครั้งแรกภายใน ${f.outfit_free_hours} ชม. หลังขึ้นทะเบียน)` : '';
      const lines = [];
      let normal = 0;
      let special = 0;
      for (const o of this.outfitTypes) {
        if (this.types.includes(o.key)) continue;
        const n = this.parsed(o.key) || 0;
        if (SPECIAL_KEYS.includes(o.key)) special += n;
        else normal += n;
      }
      if (normal) lines.push({ label: `ลงชุดเพิ่ม ${normal} ชิ้น${suffix}`, amount: free ? 0 : Number(f.outfit_add || 0) * normal });
      if (special) lines.push({ label: `ลงชุด MOD / Accessories เพิ่ม ${special} ชิ้น${suffix}`, amount: free ? 0 : Number(f.outfit_add_special || 0) * special });
      const units = this.types.length - new Set(Object.values(this.swaps)).size;
      if (units > 0) lines.push({ label: `แก้ไขชุด ${units} ชนิด`, amount: Number(f.outfit_edit || 0) * units });
      return lines;
    },

    get feeTotal() {
      return this.mode === 'outfit' ? this.feeLines.reduce((s, l) => s + l.amount, 0) : Number(this.fees.outfit_counts || 0);
    },

    // ── ไฟล์ ──
    onZip(ev) {
      const file = ev.target.files[0];
      this.zip = null;
      delete this.errors.outfit_zip;
      if (!file) return;
      if (!file.name.toLowerCase().endsWith('.zip')) {
        this.errors.outfit_zip = 'ต้องเป็นไฟล์ .ZIP เท่านั้น';
        ev.target.value = '';
      } else if (file.size > ZIP_LIMIT_MB * 1024 * 1024) {
        this.errors.outfit_zip = `ไฟล์ใหญ่เกิน ${ZIP_LIMIT_MB} MB`;
        ev.target.value = '';
      } else {
        this.zip = file;
      }
    },

    size(file) {
      return file.size >= 1048576 ? (file.size / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(file.size / 1024)) + ' KB';
    },

    // ── ส่ง ──
    precheck() {
      const er = {};
      if (this.mode === 'outfit_counts') {
        for (const o of this.outfitTypes) {
          const v = this.parsed(o.key);
          if (v === null) er['counts.' + o.key] = 'ต้องเป็นตัวเลข 0-9999';
          else if (v !== undefined && v > this.limit(o.key)) er['counts.' + o.key] = `ลงได้สูงสุด ${this.limit(o.key)} ชิ้น`;
        }
        if (!Object.keys(er).length && !this.outfitTypes.some((o) => this.parsed(o.key) !== undefined)) er.counts = 'กรุณากรอกจำนวนชุดอย่างน้อย 1 ชนิด';
        return er;
      }

      if (!this.zip) er.outfit_zip = 'กรุณาแนบไฟล์ชุด (.ZIP)';
      if (!this.addTotal && !this.types.length) er.add = 'กรุณากรอกจำนวนชุดที่จะลงเพิ่ม หรือเลือกชุดที่จะเปลี่ยน/แทนอย่างน้อย 1 อย่าง';
      for (const o of this.outfitTypes) {
        if (this.types.includes(o.key)) continue;
        const v = this.parsed(o.key);
        if (v === null) er['add.' + o.key] = 'ต้องเป็นตัวเลข 0-9999';
        else if (v > this.room(o.key)) er['add.' + o.key] = this.room(o.key) ? `ลงเพิ่มได้อีกสูงสุด ${this.room(o.key)} ชิ้น` : 'ลงครบลิมิตแล้ว';
      }
      for (const key of this.types) {
        if (this.parsedR(key) === undefined || this.parsedR(key) === null) er['replace.counts.' + key] = 'กรุณากรอกจำนวนหลังเปลี่ยน (ใส่ 0 ถ้าไม่มีแล้ว)';
      }
      return er;
    },

    async submit() {
      this.msg = '';
      this.errors = this.precheck();
      if (Object.keys(this.errors).length) return;

      const fd = new FormData();
      fd.append('kind', this.mode);
      fd.append('group_id', this.g.id);
      if (this.mode === 'outfit_counts') {
        for (const o of this.outfitTypes) {
          const v = this.parsed(o.key);
          if (v !== undefined) fd.append(`payload[counts][${o.key}]`, v);
        }
      } else {
        fd.append('payload[outfit_zip]', this.zip);
        for (const o of this.outfitTypes) {
          const v = this.types.includes(o.key) ? undefined : this.parsed(o.key);
          if (v) fd.append(`payload[add][${o.key}]`, v);
        }
        for (const t of this.types) {
          fd.append('payload[replace][types][]', t);
          fd.append(`payload[replace][counts][${t}]`, this.parsedR(t));
          if (this.swaps[t]) fd.append(`payload[replace][swaps][${t}]`, this.swaps[t]);
        }
        if (this.note.trim()) fd.append('payload[note]', this.note.trim());
      }

      this.busy = true;
      try {
        const res = await API.postForm('/api/council/transactions', fd);
        const fresh = await API.get(`/api/council/groups/${this.g.id}`);
        this.g = fresh.data;
        this.resetForm();
        this.msg = `บันทึกธุรกรรม ${res.data.number} เรียบร้อย`;
      } catch (e) {
        if (e.status === 422 && e.errors) {
          this.errors = Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k.replace(/^payload\./, ''), v[0]]));
        } else {
          this.errors = { _: e.status === 403 && /เวร/.test(e.message || '') ? 'ต้องเข้าเวรก่อนทำธุรกรรม' : firstError(e, 'บันทึกธุรกรรมไม่สำเร็จ') };
        }
      } finally {
        this.busy = false;
      }
    },
  };
}
