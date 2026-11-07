// หน้า "ประกาศเรียก" (summons.html): เลือกประเภท → เลือกผู้ถูกเรียกและผู้แจ้งเรียกจากทะเบียน → เหตุผล สถานที่ มิติ → บันทึก
function summonsPage() {
  const BKK = 'Asia/Bangkok';
  const DEFAULT_PLACE = 'หน้าที่ทำการสภา';
  const DISCORD_TAG_ID = '<:6060bluemegaphone:1470478774217998479>';    // อิโมจิ Discord นำหน้าบรรทัดแท็กท้ายข้อความ
  const DISCORD_TICKET_URL = 'https://discord.com/channels/1189260716302147684/1459212495595110493';   // ห้อง TICKET ติดต่อสภา
  const DISCORD_ROLE_ID ='1561416124157919274';   // บทบาทใน Discord ที่แท็กตอนประกาศมาตรการที่ 3
  const todayBkk = () => new Intl.DateTimeFormat('en-CA', { timeZone: BKK }).format(new Date());   // YYYY-MM-DD เวลาไทย
  const COUNCIL = { id: 'council', council: true, name: 'สภา', short_name: '' };   // ผู้แจ้งเรียกเป็นสภาเอง (ไม่ใช่กลุ่มในทะเบียน)
  const blank = () => ({ reason: '', place: '', dimension: '' });

  return {
    ready: false,
    loading: false,
    loadingGroups: false,
    busy: false,
    msg: '',
    rounds: {},
    newNumber: '',
    copiedId: null,
    errs: {},
    me: null,
    kind: '',
    kinds: [
      { value: 'gang', label: 'Gang' },
      { value: 'lady', label: 'Lady Gang' },
      { value: 'family', label: 'Family' },
    ],
    dimensions: Array.from({ length: 11 }, (_, i) => i),   // 0 = มิติหลัก, 1–10 = มิติ N
    groups: [],
    q: { a: '', b: '' },
    open: { a: false, b: false },
    picked: { a: null, b: null },
    f: blank(),
    items: [],
    day: todayBkk(),
    today: todayBkk(),

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

    /** ประเภทเปลี่ยน: โหลดรายชื่อกลุ่มของประเภทนั้นให้เลือกทั้งสองช่อง (ล้างตัวที่เลือกไว้เดิม) */
    async setKind(kind) {
      this.kind = kind;
      this.picked = { a: null, b: null };
      this.q = { a: '', b: '' };
      this.groups = [];
      delete this.errs.summoned_group_id;
      delete this.errs.summoner_group_id;
      if (!kind) return;
      this.loadingGroups = true;
      try {
        this.groups = (await API.get(`/api/council/groups?kind=${kind}&per_page=100`)).data;
      } catch (e) {
        this.errs = { ...this.errs, _: 'โหลดรายชื่อไม่สำเร็จ ลองเลือกประเภทอีกครั้ง' };
      } finally {
        this.loadingGroups = false;
      }
    },

    label(g) {
      return g.name + (g.short_name ? ' [' + g.short_name + ']' : '');
    },

    /** รายชื่อที่กรองตามคำค้น (อีกช่องที่เลือกไปแล้วไม่ให้ซ้ำ) */
    options(side) {
      const other = this.picked[side === 'a' ? 'b' : 'a'];
      const term = this.q[side].trim().toLowerCase();
      const picked = this.picked[side];
      const list = side === 'b' ? [COUNCIL, ...this.groups] : this.groups;
      return list.filter((g) => (!other || g.id !== other.id) && (!term || (picked && picked.id === g.id) || this.label(g).toLowerCase().includes(term)));
    },

    choose(side, g) {
      this.picked[side] = g;
      this.q[side] = this.label(g);
      this.open[side] = false;
      delete this.errs[side === 'a' ? 'summoned_group_id' : 'summoner_group_id'];
    },

    async load() {
      this.loading = true;
      try {
        const res = await API.get(`/api/council/summons?date=${this.day}&per_page=200`);
        this.items = res.data;
      } catch (e) {
        this.errs = { ...this.errs, _: e.status === 403 ? 'หน้านี้เปิดได้เฉพาะสภาที่เข้าเวรอยู่' : API.describe(e, 'โหลดรายการไม่สำเร็จ') };
      } finally {
        this.loading = false;
      }
    },

    validate() {
      const e = {};
      if (!this.picked.a) e.summoned_group_id = this.kind ? 'กรุณาเลือกผู้ถูกเรียกจากรายชื่อ' : 'กรุณาเลือกประเภทก่อน';
      if (!this.picked.b) e.summoner_group_id = 'กรุณาเลือกผู้แจ้งเรียก (สภา หรือแก๊ง/ครอบครัวจากรายชื่อ)';
      if (!this.f.reason.trim()) e.reason = 'กรุณาระบุเหตุผล';
      if (this.f.dimension === '') e.dimension = 'กรุณาเลือกมิติ';
      this.errs = e;
      return Object.keys(e).length === 0;
    },

    async save() {
      this.msg = '';
      this.newNumber = '';
      if (this.busy || !this.validate()) return;
      this.busy = true;
      try {
        const res = await API.post('/api/council/summons', {
          summoned_group_id: this.picked.a.id,
          ...(this.picked.b.council ? { by_council: true } : { summoner_group_id: this.picked.b.id }),
          reason: this.f.reason.trim(), place: this.f.place.trim(), dimension: Number(this.f.dimension),
        });
        this.day = this.today;   // กลับมาวันนี้เพื่อให้เห็นรายการที่เพิ่งบันทึก
        this.msg = 'บันทึกประกาศเรียกแล้ว · เลขที่ ' + res.data.number;
        this.newNumber = res.data.number;
        this.copiedId = null;
        this.f = blank();
        this.picked = { a: null, b: null };
        this.q = { a: '', b: '' };
        this.errs = {};
        await this.load();
      } catch (err) {
        this.errs = err.errors ? Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])) : { _: API.describe(err, 'บันทึกไม่สำเร็จ') };
      } finally {
        this.busy = false;
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

    get columns() {
      return this.columnsOf(this.items);
    },

    /** สองฝั่ง: ซ้าย = แก๊ง (รวมแก๊งหญิง) · ขวา = ครอบครัว */
    columnsOf(items) {
      const by = Object.fromEntries(this.kinds.map((k) => [k.value, { ...k, rows: items.filter((s) => s.summoned && s.summoned.kind === k.value) }]));
      return [
        { key: 'gang', title: 'แก๊ง', sections: [by.gang, by.lady].filter((g) => g.rows.length) },
        { key: 'family', title: 'ครอบครัว', sections: [by.family].filter((g) => g.rows.length) },
      ];
    },

    kindTh(v) {
      return { gang: 'แก๊ง', lady: 'แก๊งหญิง', family: 'ครอบครัว' }[v] || '';
    },

    /** ข้อความเชิญให้คัดลอกไปส่งต่อ ตามมาตรการที่เลือกของการ์ดนั้น */
    inviteText(s) {
      const g = s.summoned;
      const kind = { gang: 'GANG', lady: 'LADY GANG', family: 'FAMILY' }[g.kind] || '';
      const place = s.place === DEFAULT_PLACE ? 'หน้าทำการสภา' : s.place;
      const base = `ขอเชิญตัวแทน ${kind} : ${g.name}${g.short_name ? ' [' + g.short_name + ']' : ''} ติดต่อสภา${place} ${this.dimLabel(s.dimension)}`;
      switch (this.roundOf(s)) {
        case '2': return `/cc ${base}  สภาจะทำการล็อกธุรกรรมและระงับสิทธิ์กิจกรรมทั้งหมด จนกว่าจะมาติดต่อ`;
        case '3': return `## [ ประกาศจากสภา WAVEVY CITY ]

## ขอเชิญตัวแทน ${kind} : ${g.name}${g.short_name ? ' [' + g.short_name + ']' : ''} [กด TICKET ติดต่อสภา](${DISCORD_TICKET_URL}) ภายใน 24 ชม. ด้วยครับ/ค่ะ

${DISCORD_TAG_ID}  : <@&${DISCORD_ROLE_ID}>`;
        default: return `/cc ${base} ในเวลานี้ด้วยครับ`;
      }
    },

    /** มาตรการของการ์ดนั้น: '1' ห่างกันรอบละ 5 นาที · '2' ห่างกัน 10 นาที ล็อกธุรกรรม · '3' ประกาศลง Discord */
    roundOf(s) {
      return this.rounds[s.id] || '1';
    },

    async copyInvite(s) {
      const text = this.inviteText(s);
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
      this.copiedId = s.id;
    },

    dimLabel(n) {
      return n === 0 ? 'มิติหลัก' : 'มิติ ' + n;
    },

    stamp(iso) {
      return iso ? new Intl.DateTimeFormat('th-TH-u-ca-buddhist', { timeZone: BKK, day: '2-digit', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date(iso)).replace(',', '') : '—';
    },
  };
}
