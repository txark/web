// หน้า "ประกาศโทษ" (penalties.html): รายการโทษบำเพ็ญที่บันทึกไว้ที่หน้าประกาศปรับ ให้คัดลอกเป็นประกาศ · ดูย้อนหลังรายวัน
function penaltiesPage() {
  const BKK = 'Asia/Bangkok';
  const DISCORD_EMOJI = '<:6060bluemegaphone:1470478774217998479>';   // อิโมจิ Discord นำหน้าบรรทัดแท็กท้ายข้อความ
  const DISCORD_ROLE_ID = '1561416124157919274';                      // บทบาทใน Discord ที่แท็กท้ายประกาศ
  const todayBkk = () => new Intl.DateTimeFormat('en-CA', { timeZone: BKK }).format(new Date());   // YYYY-MM-DD เวลาไทย

  return {
    ready: false,
    loading: false,
    errs: {},
    items: [],
    day: todayBkk(),
    today: todayBkk(),
    copiedId: null,
    busyId: null,
    mode: 'day',      // day = รายวัน · pending = ค้างรับโทษทุกวัน
    status: '',       // '' | pending | served (กรองในรายการที่โหลด)
    modes: [
      { value: 'day', label: 'รายวัน' },
      { value: 'pending', label: 'ค้างรับโทษทุกวัน' },
    ],
    statuses: [
      { value: '', label: 'ทั้งหมด' },
      { value: 'pending', label: 'รอรับโทษ' },
      { value: 'served', label: 'รับโทษแล้ว' },
    ],

    async init() {
      const auth = authState();
      await auth.require('member');
      this.ready = true;
      await this.load();
    },

    async load() {
      this.loading = true;
      this.errs = {};
      try {
        const url = this.mode === 'pending' ? '/api/council/penances?scope=pending' : `/api/council/penances?date=${this.day}`;
        this.items = (await API.get(url)).data;
      } catch (e) {
        this.errs = { _: e.status === 403 ? 'หน้านี้เปิดได้เฉพาะสภาที่เข้าเวรอยู่' : API.describe(e, 'โหลดรายการไม่สำเร็จ') };
      } finally {
        this.loading = false;
      }
    },

    async setMode(m) {
      this.mode = m;
      this.status = '';
      await this.load();
    },

    /** รายการที่แสดง: กรองตามสถานะรับโทษ */
    get shownItems() {
      return this.status ? this.items.filter((r) => (this.status === 'served') === !!r.served) : this.items;
    },

    countStatus(v) {
      return v ? this.items.filter((r) => (v === 'served') === !!r.served).length : this.items.length;
    },

    async serve(r) {
      if (!(await UI.ask(`ทำเครื่องหมายว่า ${r.group ? r.group.name : ''} รับโทษบำเพ็ญ ${this.money(r.penance_units)} ไม้ เรียบร้อยแล้ว ?`, { confirmText: 'รับโทษแล้ว' }))) return;
      await this.mark(r, 'post');
    },

    async unserve(r) {
      if (!(await UI.ask('ยกเลิกเครื่องหมาย "รับโทษเรียบร้อย" ของรายการนี้ ?', { kind: 'danger', confirmText: 'ยกเลิกเครื่องหมาย' }))) return;
      await this.mark(r, 'del');
    },

    async mark(r, how) {
      this.busyId = r.id;
      this.errs = {};
      try {
        const res = await API[how](`/api/council/penances/${r.id}/serve`);
        Object.assign(r, res.data);
        if (this.mode === 'pending' && r.served) this.items = this.items.filter((x) => x.id !== r.id);
      } catch (e) {
        this.errs = { _: API.describe(e, 'บันทึกไม่สำเร็จ') };
      } finally {
        this.busyId = null;
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

    money(n) {
      return Number(n).toLocaleString('en-US');
    },

    /** ประกาศโทษที่คัดลอก (รูปแบบ Discord) — ใช้เมื่อกลุ่มรับโทษบำเพ็ญเรียบร้อยแล้ว */
    announceText(r) {
      const g = r.group;
      const kind = { gang: 'GANG', lady: 'LADY GANG', family: 'FAMILY' }[g && g.kind] || 'Gang/Family';
      const who = g ? g.name + (g.short_name ? ' [' + g.short_name + ']' : '') : '—';
      return [
        '# [ประกาศโทษจากสภา]',
        '```',
        `${kind} : ${who}`,
        '',
        `ข้อหา : ${r.charge}`,
        '```',
        '```',
        'สถานะ : รับโทษบำเพ็ญเรียบร้อย',
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
      const of = (...kinds) => this.shownItems.filter((r) => r.group && kinds.includes(r.group.kind));
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
