// หน้า "กลุ่มของฉัน" (my-group.html): แก๊ง/ครอบครัวยื่นเรื่องให้สภาอนุมัติ — ขึ้นทะเบียน · เปลี่ยนหัว/รอง · ลงชุด/แก้ไขชุด · แก้ชื่อ/สี/โลโก้
// ระบบเปิดเฉพาะช่วงเวลาที่ backend กำหนด (18:30–03:00 เวลาไทย); ข้อมูลทั้งหมดตรวจที่ backend เป็นหลัก
function myGroupPage() {
  const OUTFIT_LABELS = { suit: 'Suit', hoodie: 'Hoodie', tshirt: 'T-Shirt', polo: 'Polo', armor: 'Armor', gun: 'Gun', mod1: 'MOD 1', mod2: 'MOD 2', accessories: 'Accessories' };
  const BKK = 'Asia/Bangkok';
  const REPLACE_KEYS = ['mod1', 'mod2', 'accessories'];   // ลงชุดแทนได้เฉพาะ MOD / Accessories (ชุดอื่นใช้ "แก้ไขชุด")

  const blankForm = () => ({
    // ขึ้นทะเบียน
    name: '', short_name: '', is_lady: false, color_hex: '', color_name: '',
    leader: { name: '', phone: '', discord_id: '' }, co_leaders: [],
    // เปลี่ยนหัว/รอง
    position: 'leader', replace_name: '', phone: '', discord_id: '',
    // ลงชุด
    add: {}, replace: { types: [], counts: {}, swaps: {} }, edit: { types: [] }, outfit_zip: null, note: '',
    // ชื่อ/โลโก้
    logo_url: '',
  });

  /** ใส่ค่าลง FormData แบบซ้อน: payload[a][b][]… (ข้ามค่าว่าง) */
  const put = (fd, key, v) => {
    if (v === null || v === undefined || v === '') return;
    if (v instanceof File) fd.append(key, v);
    else if (Array.isArray(v)) v.forEach((x, i) => put(fd, `${key}[${i}]`, x));
    else if (typeof v === 'object') Object.entries(v).forEach(([k, x]) => put(fd, `${key}[${k}]`, x));
    else fd.append(key, typeof v === 'boolean' ? (v ? '1' : '0') : String(v));
  };

  return {
    ready: false,
    portal: { open: true, label: '18:30 - 03:00 น.' },
    type: 'gang',
    group: null,
    myPosition: null,   // ตำแหน่งของบัญชีนี้ในกลุ่ม: leader | co_leader
    requests: [],
    fees: {},
    activity: { open: null, next: null, reported: false, min_characters: 6 },   // รอบรายงานความเคลื่อนไหวจาก backend
    act: { leader: { name: '', phone: '' }, co_leaders: [], member_name: '', photo: null, preview: '', confirm: false, note: '' },

    open: false,
    kind: '',
    form: blankForm(),
    fee: null,
    errs: {},
    msg: '',
    busy: false,
    quoteTimer: null,

    /** คำเรียกตามประเภทของผู้ใช้: แก๊ง / ครอบครัว (ใช้ในปุ่มและหัวฟอร์ม) */
    get noun() {
      return this.type === 'family' ? 'ครอบครัว' : 'แก๊ง';
    },

    get titles() {
      const n = this.noun;
      return {
        register: `ยื่นขอขึ้นทะเบียน${n}`,
        activity_report: 'รายงานความเคลื่อนไหว',
        change_leader: 'เปลี่ยน Leader / Co-Leader',
        outfit: `ลงชุด / แก้ชุด${n}`,
        rename: `เปลี่ยนชื่อและโลโก้${n}`,
        recolor: `เปลี่ยนสี${n}`,
      };
    },

    async init() {
      const auth = authState();
      await auth.require('gang');
      const role = auth.user && auth.user.role;
      if (role !== 'gang' && role !== 'family') {
        location.href = '/index.html'; // ส่วนนี้สำหรับแก๊ง/ครอบครัวเท่านั้น
        return;
      }
      this.ready = true;
      await this.load();
    },

    async load() {
      this.errs = {};
      try {
        const d = (await API.get('/api/my-group')).data;
        this.portal = d.portal;
        if (d.portal.open) {
          this.type = d.type;
          this.group = d.group;
          this.myPosition = d.my_position;
          this.requests = d.requests;
          this.fees = d.fees;
          this.activity = d.activity || this.activity;
        }
      } catch (e) {
        this.errs = { _: API.describe(e, 'โหลดข้อมูลไม่สำเร็จ') };
      }
    },

    get pendingRegister() {
      return this.requests.find((r) => r.kind === 'register' && r.status === 'pending') || null;
    },

    get coLeaders() {
      return this.group ? this.group.leadership.filter((m) => m.position === 'co_leader') : [];
    },

    get outfitList() {
      const limits = this.group ? this.group.outfit_limits : {};
      return Object.keys(OUTFIT_LABELS).filter((k) => k in limits).map((k) => ({ key: k, label: OUTFIT_LABELS[k], limit: limits[k] }));
    },

    // ── ฟอร์ม ──
    openForm(kind) {
      this.kind = kind;
      this.form = blankForm();
      this.errs = {};
      this.msg = '';
      this.fee = null;
      if (kind === 'activity_report') this.resetAct();
      this.open = true;
      if (kind !== 'outfit' && kind !== 'activity_report') this.quote();
    },

    /** เริ่มฟอร์มรายงานความเคลื่อนไหว: เติมชื่อหัว/รองที่มีในทะเบียนให้ (แก้ได้) จำนวนช่องรองตรงกับที่กลุ่มมี */
    resetAct() {
      const lead = this.group ? this.group.leadership : [];
      this.act = {
        leader: { name: (lead.find((m) => m.position === 'leader') || {}).name || '', phone: '' },
        co_leaders: lead.filter((m) => m.position === 'co_leader').map((m) => ({ name: m.name, phone: '' })),
        member_name: '', photo: null, preview: '', confirm: false, note: '',
      };
    },

    setPhoto(ev) {
      const f = ev.target.files[0] || null;
      if (this.act.preview) URL.revokeObjectURL(this.act.preview);
      this.act.photo = f;
      this.act.preview = f ? URL.createObjectURL(f) : '';
    },

    closeForm() {
      this.open = false;
    },

    err(k) {
      return this.errs[k] || '';
    },

    /** จำนวนชุดชนิดนี้ที่ลงทะเบียนไว้แล้ว */
    outfitHave(key) {
      return this.group && this.group.outfit_counts ? Number(this.group.outfit_counts[key] || 0) : 0;
    },

    /** ชนิดชุดที่ลงชุดแทนได้: เฉพาะ MOD 1 / MOD 2 / Accessories ที่ครบลิมิตแล้ว */
    get fullTypes() {
      return this.outfitList.filter((o) => REPLACE_KEYS.includes(o.key) && this.outfitHave(o.key) >= o.limit);
    },

    /** ชนิดชุดที่ลงทะเบียนไว้แล้ว (แก้ไขชุดได้เฉพาะชนิดเหล่านี้) */
    get editableTypes() {
      return this.outfitList.filter((o) => this.outfitHave(o.key) > 0);
    },

    /** กรอกจำนวนลงเพิ่มของชนิดนี้ไว้แล้วหรือยัง (ชนิดเดียวกันอยู่ได้ส่วนเดียว) */
    addFilled(key) {
      return Number(this.form.add[key] || 0) > 0;
    },

    addCount(key) {
      return Number(this.form.add[key] || 0);
    },

    /** ช่องลงเพิ่มของชนิดนี้ปิดอยู่ไหม: ชุดครบแล้ว หรือชนิดนี้ถูกเลือกในส่วนลงแทน/แก้ไข */
    addLocked(o) {
      return this.outfitHave(o.key) >= o.limit || this.form.replace.types.includes(o.key) || this.form.edit.types.includes(o.key);
    },

    stepAdd(o, delta) {
      const next = Math.min(o.limit - this.outfitHave(o.key), Math.max(0, this.addCount(o.key) + delta));
      this.form.add = { ...this.form.add, [o.key]: next || '' };
      this.quote();
    },

    toggleEdit(key) {
      const t = this.form.edit.types;
      this.form.edit.types = t.includes(key) ? t.filter((k) => k !== key) : [...t, key];
      this.quote();
    },

    toggleReplace(key) {
      const r = this.form.replace;
      if (r.types.includes(key)) {
        r.types = r.types.filter((k) => k !== key);
        delete r.counts[key];
      } else {
        r.types.push(key);
        r.counts[key] = this.outfitHave(key);   // เริ่มที่จำนวนปัจจุบัน (ชุดครบ ลงแทนแล้วจำนวนเท่าเดิม) แก้ได้
      }
      this.quote();
    },

    /** ประเมินค่าบริการจากฟอร์มปัจจุบัน (หน่วงเล็กน้อยกันยิงถี่) */
    quote() {
      clearTimeout(this.quoteTimer);
      this.quoteTimer = setTimeout(async () => {
        try {
          const payload = this.kind === 'outfit' ? { add: this.form.add, replace: this.form.replace, edit: this.form.edit } : {};
          this.fee = (await API.post('/api/my-group/quote', { kind: this.kind, payload })).data;
        } catch (e) {
          this.fee = null;
        }
      }, 250);
    },

    payload() {
      const f = this.form;
      switch (this.kind) {
        case 'register':
          return { name: f.name.trim(), short_name: f.short_name.trim(), is_lady: this.type === 'family' ? false : f.is_lady, color_hex: f.color_hex.trim(), color_name: f.color_name.trim(), leader: f.leader, co_leaders: f.co_leaders };
        case 'activity_report':
          return {
            round_id: this.activity.open ? this.activity.open.id : '',
            leader: { name: this.act.leader.name.trim(), phone: this.act.leader.phone.trim() },
            co_leaders: this.act.co_leaders.map((c) => ({ name: c.name.trim(), phone: c.phone.trim() })),
            member_name: this.act.member_name.trim(),
            photo: this.act.photo,
            confirm_characters: this.act.confirm,
            note: this.act.note.trim(),
          };
        case 'change_leader':
          return { position: f.position, replace_name: f.position === 'co_leader' ? f.replace_name : '', name: f.name.trim(), phone: f.phone.trim(), discord_id: f.discord_id.trim() };
        case 'outfit':
          return { add: f.add, replace: f.replace.types.length ? f.replace : null, edit: f.edit.types.length ? f.edit : null, outfit_zip: f.outfit_zip, note: f.note.trim() };
        case 'rename':
          return { name: f.name.trim(), short_name: f.short_name.trim(), logo_url: f.logo_url.trim() };
        case 'recolor':
          return { color_hex: f.color_hex.trim(), color_name: f.color_name.trim(), outfit_zip: f.outfit_zip };
      }
      return {};
    },

    async submit() {
      this.busy = true;
      this.errs = {};
      try {
        const fd = new FormData();
        fd.append('kind', this.kind);
        put(fd, 'payload', this.payload());
        const res = await API.postForm('/api/my-group/requests', fd);
        this.open = false;
        this.msg = `ยื่นคำขอ ${res.data.number} แล้ว รอสภาอนุมัติ`;
        await this.load();
      } catch (e) {
        if (e.status === 422 && e.errors) {
          this.errs = Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k.replace(/^payload\./, ''), v[0]]));
          if (!Object.keys(this.errs).some((k) => k !== 'kind')) this.errs.form = this.errs.kind;
          else if (this.errs.kind) this.errs.form = this.errs.kind;
        } else {
          this.errs = { form: API.describe(e, 'ยื่นคำขอไม่สำเร็จ') };
        }
      } finally {
        this.busy = false;
      }
    },

    async cancel(r) {
      if (!(await UI.ask(`ยกเลิกคำขอ ${r.number} ?`, { kind: 'danger', confirmText: 'ยกเลิกคำขอ' }))) return;
      try {
        await API.post(`/api/my-group/requests/${r.id}/cancel`);
        this.msg = `ยกเลิกคำขอ ${r.number} แล้ว`;
        await this.load();
      } catch (e) {
        this.errs = { _: API.describe(e, 'ยกเลิกไม่สำเร็จ') };
      }
    },

    // ── แสดงผล ──
    /** วันนี้เวลาไทย (YYYY-MM-DD) */
    todayStr() {
      return new Intl.DateTimeFormat('en-CA', { timeZone: BKK }).format(new Date());
    },

    money(n) {
      return Number(n || 0).toLocaleString('en-US');
    },

    stamp(iso) {
      return iso ? new Intl.DateTimeFormat('th-TH-u-ca-buddhist', { timeZone: BKK, day: '2-digit', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date(iso)).replace(',', '') : '—';
    },
  };
}
