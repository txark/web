// หน้า "ประกาศปรับ" (fines.html): บันทึกค่าปรับ/โทษของทะเบียนก่อน แล้วรายการที่บันทึกจะมีประกาศให้คัดลอก · ดูย้อนหลังรายวัน
function finesPage() {
  const BKK = 'Asia/Bangkok';
  const todayBkk = () => new Intl.DateTimeFormat('en-CA', { timeZone: BKK }).format(new Date());   // YYYY-MM-DD เวลาไทย
  const blank = () => ({ kind: '', group_id: '', category: '', rule_id: '', charge: '', amount: '', units: '', note: '' });
  const DISCORD_EMOJI = '<:6060bluemegaphone:1470478774217998479>';   // อิโมจิ Discord นำหน้าบรรทัดแท็กท้ายข้อความ
  const DISCORD_ROLE_ID = '1561416124157919274';                      // บทบาทใน Discord ที่แท็กท้ายประกาศ
  const digits = (v) => String(v ?? '').replace(/[,\s]/g, '');

  return {
    ready: false,
    loading: false,
    loadingGroups: false,
    busy: false,
    msg: '',
    errs: {},
    me: null,
    items: [],
    kinds: [
      { value: 'gang', label: 'Gang' },
      { value: 'lady', label: 'Gang Lady' },
      { value: 'family', label: 'Family' },
    ],
    fineSections: [],   // หมวดที่มีข้อกฎเป็นเหตุปรับได้
    fineRules: [],      // ข้อกฎที่มีบทลงโทษ (ข้อความ/ค่าปรับ/ไม้ที่อ่านได้จากกฎ)
    groups: [],
    day: todayBkk(),
    today: todayBkk(),
    showForm: false,
    f: blank(),
    ruleQuery: '',
    ruleOpen: false,
    ruleActive: 0,
    copiedId: null,
    newId: null,

    async init() {
      const auth = authState();
      await auth.require('member');
      this.me = auth.user;
      this.ready = true;
      await this.load();
    },

    get meName() {
      return this.me ? this.me.character_name || this.me.name : '';
    },

    async load() {
      this.loading = true;
      try {
        const res = await API.get(`/api/council/fines?date=${this.day}&per_page=200`);
        this.items = res.data;
        if (res.meta) {
          this.fineSections = res.meta.fine_sections || [];
          this.fineRules = res.meta.fine_rules || [];
        }
      } catch (e) {
        this.errs = { ...this.errs, _: e.status === 403 ? 'หน้านี้เปิดได้เฉพาะสภาที่เข้าเวรอยู่' : API.describe(e, 'โหลดรายการไม่สำเร็จ') };
      } finally {
        this.loading = false;
      }
    },

    /** เปลี่ยนวันที่ดู (ย้อนหลังได้ ไปข้างหน้าไม่เกินวันนี้) */
    async goDay(ymd) {
      if (!/^\d{4}-\d{2}-\d{2}$/.test(ymd || '')) return;
      this.day = ymd > this.today ? this.today : ymd;
      await this.load();
    },

    async shiftDay(delta) {
      const d = new Date(this.day + 'T00:00:00Z');
      d.setUTCDate(d.getUTCDate() + delta);
      await this.goDay(d.toISOString().slice(0, 10));
    },

    get dayLabel() {
      const t = new Intl.DateTimeFormat('th-TH-u-ca-buddhist', { timeZone: 'UTC', weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(this.day + 'T00:00:00Z'));
      return this.day === this.today ? 'วันนี้ · ' + t : t;
    },

    // ── ฟอร์มบันทึกค่าปรับ/โทษ: ประเภท → ทะเบียน · หมวดหมู่ → ข้อหา (จากกฎ หรือพิมพ์เอง) → ค่าปรับ + ไม้บำเพ็ญ ──
    openForm() {
      this.f = blank();
      this.groups = [];
      this.ruleQuery = '';
      this.ruleOpen = false;
      this.errs = {};
      this.showForm = true;
      document.documentElement.classList.add('ui-locked');
    },

    closeForm() {
      if (this.busy) return;
      this.showForm = false;
      document.documentElement.classList.remove('ui-locked');
    },

    /** ประเภทเปลี่ยน: โหลดทะเบียนของประเภทนั้นให้เลือก */
    async setKind(kind) {
      this.f.kind = kind;
      this.f.group_id = '';
      this.groups = [];
      delete this.errs.kind;
      delete this.errs.group_id;
      if (!kind) return;
      this.loadingGroups = true;
      try {
        this.groups = (await API.get(`/api/council/groups?kind=${kind}&per_page=100`)).data.filter((g) => !g.dissolved_at);
      } catch (e) {
        this.errs = { ...this.errs, _form: 'โหลดรายชื่อทะเบียนไม่สำเร็จ ลองเลือกประเภทอีกครั้ง' };
      } finally {
        this.loadingGroups = false;
      }
    },

    label(g) {
      return g.name + (g.short_name ? ' [' + g.short_name + ']' : '');
    },

    shorten(text, n) {
      const t = String(text || '').replace(/\s+/g, ' ').trim();
      return t.length > n ? t.slice(0, n - 1) + '…' : t;
    },

    get selectedRule() {
      return this.fineRules.find((r) => String(r.id) === String(this.f.rule_id)) || null;
    },

    /** ตัวเลือกข้อหา: ข้อกฎของหมวดที่เลือก (พิมพ์ = กรอง) · ถ้าพิมพ์ข้อความที่ไม่ตรงข้อกฎใดก็ใช้เป็นข้อหาที่พิมพ์เองได้ */
    get ruleOptions() {
      const q = this.ruleQuery.trim().toLowerCase();
      const chosen = this.selectedRule && this.ruleQuery === this.shorten(this.selectedRule.text, 120);
      let pool = this.fineRules.filter((r) => !this.f.category || r.section === this.f.category);
      if (q && !chosen) pool = pool.filter((r) => `${r.text} ${r.penalty}`.toLowerCase().includes(q));
      return pool.slice(0, 80).map((r) => ({ key: 'r' + r.id, label: this.shorten(r.text, 140), rule: r }));
    },

    onCategory() {
      this.f.rule_id = '';
      this.f.charge = '';
      this.ruleQuery = '';
      this.f.amount = '';
      this.f.units = '';
      delete this.errs.category;
    },

    /** พิมพ์ในช่องข้อหา = ข้อหาที่พิมพ์เอง (ยกเลิกข้อกฎที่เลือกไว้) */
    onRuleInput() {
      this.ruleOpen = true;
      this.ruleActive = 0;
      if (this.f.rule_id) {
        this.f.rule_id = '';
        this.f.amount = '';
        this.f.units = '';
      }
      this.f.charge = this.ruleQuery.trim();
      delete this.errs.charge;
    },

    /** เลือกข้อกฎ: เติมข้อหา ค่าปรับ และจำนวนไม้จากกฎให้ (แก้ได้) */
    pickRule(o) {
      const r = o.rule;
      this.f.rule_id = String(r.id);
      this.f.charge = r.text;
      this.ruleQuery = this.shorten(r.text, 120);
      this.f.amount = r.amount ? String(r.amount) : '';
      this.f.units = r.penance ? String(r.penance) : '';
      this.ruleOpen = false;
      delete this.errs.charge;
    },

    ruleMove(step) {
      this.ruleOpen = true;
      const n = this.ruleOptions.length;
      if (n) this.ruleActive = (this.ruleActive + step + n) % n;
    },

    ruleEnter(ev) {
      if (!this.ruleOpen) return;
      const o = this.ruleOptions[this.ruleActive];
      if (o) {
        ev.preventDefault();
        this.pickRule(o);
      }
    },

    /** MAX-002: แลกค่าปรับเป็นบำเพ็ญ 500,000 WVDL = 1,000 ไม้ (ใช้กับโทษของสภาเท่านั้น) */
    get canConvert() {
      const a = digits(this.f.amount);
      return /^\d+$/.test(a) && Number(a) >= 500;
    },

    convertToPenance() {
      if (!this.canConvert) return;
      const extra = Math.round(Number(digits(this.f.amount)) / 500);
      this.f.units = String((Number(digits(this.f.units)) || 0) + extra);
      this.f.amount = '';
      delete this.errs.amount;
      delete this.errs.units;
    },

    get amountHint() {
      const r = this.selectedRule;
      if (!r) return '';
      if (r.amount_min === null) return 'กฎข้อนี้ไม่ได้ระบุค่าปรับ (กรอกเองได้ หรือเว้นว่าง)';
      if (r.amount_min !== r.amount_max) return `กฎระบุ ${Number(r.amount_min).toLocaleString('th-TH')} – ${Number(r.amount_max).toLocaleString('th-TH')} WVDL (ตามดุลยพินิจ)`;
      return '';
    },

    get penanceHint() {
      const r = this.selectedRule;
      if (!r) return '';
      if (r.penance === null && r.penance_min === null) return 'กฎข้อนี้ไม่ได้ระบุให้ส่งบำเพ็ญ (กรอกเองได้ หรือเว้นว่าง)';
      if (r.penance_min !== r.penance_max) return `กฎระบุ ${Number(r.penance_min).toLocaleString('th-TH')} – ${Number(r.penance_max).toLocaleString('th-TH')} ไม้`;
      return '';
    },

    validate() {
      const e = {};
      const amount = digits(this.f.amount);
      const units = digits(this.f.units);
      if (!this.f.kind) e.kind = 'กรุณาเลือกประเภท';
      if (!this.f.group_id) e.group_id = this.f.kind ? 'กรุณาเลือกทะเบียน' : 'กรุณาเลือกประเภทก่อน';
      if (!this.f.category) e.category = 'กรุณาเลือกหมวดหมู่';
      if (!this.f.charge.trim()) e.charge = 'กรุณาเลือกข้อหาจากกฎ หรือพิมพ์ข้อหาเอง';
      if (amount && (!/^\d+$/.test(amount) || Number(amount) < 1)) e.amount = 'ค่าปรับต้องเป็นตัวเลขจำนวนเต็มมากกว่า 0';
      if (units && (!/^\d+$/.test(units) || Number(units) < 1)) e.units = 'จำนวนไม้ต้องเป็นตัวเลขจำนวนเต็มมากกว่า 0';
      if (!e.amount && !e.units && !amount && !units) e.amount = 'กรุณากรอกค่าปรับหรือจำนวนไม้ที่ต้องบำเพ็ญอย่างน้อยหนึ่งอย่าง';
      this.errs = e;
      return Object.keys(e).length === 0;
    },

    async save() {
      this.msg = '';
      if (this.busy || !this.validate()) return;
      this.busy = true;
      try {
        const amount = digits(this.f.amount);
        const units = digits(this.f.units);
        const body = { kind: 'fine', penalty_type: 'both', note: this.f.note.trim() || null };
        if (this.f.rule_id) body.rule_id = Number(this.f.rule_id);
        else Object.assign(body, { category: this.f.category, charge: this.f.charge.trim() });
        if (amount) body.amount = Number(amount);
        if (units) body.penance_units = Number(units);
        const res = await API.post(`/api/council/groups/${this.f.group_id}/records`, body);
        this.showForm = false;
        document.documentElement.classList.remove('ui-locked');
        this.day = this.today;   // กลับมาวันนี้เพื่อให้เห็นรายการที่เพิ่งบันทึก
        this.msg = 'บันทึกค่าปรับ/โทษแล้ว · คัดลอกประกาศได้จากรายการด้านล่าง';
        this.newId = res.data.id;
        this.copiedId = null;
        this.errs = {};
        await this.load();
      } catch (err) {
        const map = { rule_id: 'charge', penance_units: 'units' };
        this.errs = err.errors ? Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [map[k] || k, v[0]])) : { _form: API.describe(err, 'บันทึกไม่สำเร็จ') };
      } finally {
        this.busy = false;
      }
    },

    // ── ประกาศให้คัดลอก ──
    money(n) {
      return Number(n).toLocaleString('en-US');
    },

    /** สรุปบทลงโทษของรายการ: ค่าปรับ และ/หรือ ส่งบำเพ็ญ */
    penaltyText(r) {
      const parts = [];
      if (r.amount) parts.push(`${this.money(r.amount)} WVDL`);
      if (r.penance_units) parts.push(`ส่งบำเพ็ญ ${this.money(r.penance_units)} ไม้`);
      return parts.join(' + ');
    },

    /** ประกาศปรับที่คัดลอก (รูปแบบ Discord) — เฉพาะรายการที่มีค่าปรับ (โทษบำเพ็ญใช้ประกาศโทษ) */
    announceText(r) {
      const g = r.group;
      const kind = { gang: 'GANG', lady: 'LADY GANG', family: 'FAMILY' }[g && g.kind] || 'Gang/Family';
      const who = g ? g.name + (g.short_name ? ' [' + g.short_name + ']' : '') : '—';
      return [
        '# [ประกาศปรับจากสภา]',
        '```',
        `${kind} : ${who}`,
        '',
        `ข้อหา : ${r.charge}`,
        '```',
        '```',
        `ค่าปรับ : ${this.money(r.amount)} WVDL`,
        '```',
        `${DISCORD_EMOJI}  : <@&${DISCORD_ROLE_ID}>`,
      ].join('\n');
    },

    async copyAnnounce(r) {
      const text = this.announceText(r);
      try {
        await navigator.clipboard.writeText(text);
      } catch (e) {
        const t = document.createElement('textarea');
        t.value = text;
        document.body.appendChild(t);
        t.select();
        document.execCommand('copy');
        t.remove();
      }
      this.copiedId = r.id;
    },

    /** สองฝั่ง: ซ้าย = แก๊ง (รวมแก๊งหญิง) · ขวา = ครอบครัว */
    get columns() {
      const of = (...kinds) => this.items.filter((r) => r.group && kinds.includes(r.group.kind));
      return [
        { key: 'gang', title: 'แก๊ง', sections: [{ value: 'gang', rows: of('gang') }, { value: 'lady', rows: of('lady') }].filter((g) => g.rows.length) },
        { key: 'family', title: 'ครอบครัว', sections: [{ value: 'family', rows: of('family') }].filter((g) => g.rows.length) },
      ];
    },

    stamp(iso) {
      return iso ? new Intl.DateTimeFormat('th-TH-u-ca-buddhist', { timeZone: BKK, day: '2-digit', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date(iso)).replace(',', '') : '—';
    },
  };
}
