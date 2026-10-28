function dutyReportPage() {
  const TZ = 'Asia/Bangkok'; // ให้ตรงกับ council.report_timezone ของ backend
  const MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
  const pad = (n) => String(n).padStart(2, '0');

  // YYYY-MM-DD ของ "วันนี้" ตามเวลาไทย (ไม่ขึ้นกับเขตเวลาของเครื่องผู้ใช้)
  const todayStr = () => new Intl.DateTimeFormat('en-CA', { timeZone: TZ }).format(new Date());
  const addDays = (str, n) => {
    const d = new Date(str + 'T00:00:00Z');
    d.setUTCDate(d.getUTCDate() + n);
    return d.toISOString().slice(0, 10);
  };
  const monthStart = (str) => str.slice(0, 8) + '01';
  const prevMonth = (str) => {
    const d = new Date(monthStart(str) + 'T00:00:00Z');
    d.setUTCDate(0); // วันสุดท้ายของเดือนก่อน
    const last = d.toISOString().slice(0, 10);
    return [monthStart(last), last];
  };

  const PRESETS = {
    today: () => [todayStr(), todayStr()],
    d7: () => [addDays(todayStr(), -6), todayStr()],
    d30: () => [addDays(todayStr(), -29), todayStr()],
    month: () => [monthStart(todayStr()), todayStr()],
    lastmonth: () => prevMonth(todayStr()),
  };

  return {
    ready: false,
    me: null,

    presets: [
      { key: 'today', label: 'วันนี้' },
      { key: 'd7', label: '7 วันล่าสุด' },
      { key: 'd30', label: '30 วันล่าสุด' },
      { key: 'month', label: 'เดือนนี้' },
      { key: 'lastmonth', label: 'เดือนที่แล้ว' },
      { key: 'custom', label: 'กำหนดเอง' },
    ],
    preset: 'd30',
    from: '',
    to: '',

    rows: [],
    meta: null,
    q: '',
    sort: 'hours',
    loading: true,
    error: '',

    open: null, // user id ที่กางรายละเอียดอยู่
    detail: null,
    detailLoading: false,
    detailError: '',

    async init() {
      const auth = authState();
      await auth.require('vice_president');
      this.me = auth.user;
      this.ready = true;
      this.setPreset('d30');
    },

    setPreset(key) {
      this.preset = key;
      if (key !== 'custom') {
        [this.from, this.to] = PRESETS[key]();
        this.load();
      }
    },

    applyCustom() {
      if (this.from && this.to) this.load();
    },

    async load() {
      this.loading = true;
      this.error = '';
      this.open = null;
      try {
        const res = await API.get(`/api/council/duty-report?from=${this.from}&to=${this.to}`);
        this.rows = res.data;
        this.meta = res.meta;
      } catch (e) {
        this.rows = [];
        this.meta = null;
        this.error = (e.errors && Object.values(e.errors)[0][0]) || (e.status === 403 ? 'คุณไม่มีสิทธิ์ดูสรุปชั่วโมงเวร' : API.describe(e, 'โหลดสรุปไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'));
      } finally {
        this.loading = false;
      }
    },

    async toggle(r) {
      if (this.open === r.user.id) {
        this.open = null;
        return;
      }
      this.open = r.user.id;
      this.detail = null;
      this.detailError = '';
      this.detailLoading = true;
      try {
        this.detail = (await API.get(`/api/council/duty-report/${r.user.id}?from=${this.from}&to=${this.to}`)).data.shifts;
      } catch (e) {
        this.detailError = API.describe(e, 'โหลดรายละเอียดไม่สำเร็จ');
      } finally {
        this.detailLoading = false;
      }
    },

    // ── รายการที่แสดง (ค้นหา + เรียง) ──
    get shown() {
      const q = this.q.trim().toLowerCase();
      const list = q ? this.rows.filter((r) => `${r.user.display_name} ${r.user.discord_name}`.toLowerCase().includes(q)) : [...this.rows];
      if (this.sort === 'name') list.sort((a, b) => a.user.display_name.localeCompare(b.user.display_name, 'th'));
      else if (this.sort === 'shifts') list.sort((a, b) => b.shifts - a.shifts || b.total_seconds - a.total_seconds);
      return list;
    },

    get maxSeconds() {
      return Math.max(1, ...this.rows.map((r) => r.total_seconds));
    },

    // อันดับตามชั่วโมงรวม (คงที่แม้ค้นหา/เปลี่ยนการเรียง); ยังไม่มีเวร = ไม่มีอันดับ
    rank(r) {
      return r.total_seconds > 0 ? this.rows.indexOf(r) + 1 : 0;
    },

    bar(r) {
      return Math.round((r.total_seconds / this.maxSeconds) * 100) + '%';
    },

    // ── แสดงผล ──
    hm(seconds) {
      const s = Math.max(0, Math.round(seconds));
      const h = Math.floor(s / 3600);
      const m = Math.floor((s % 3600) / 60);
      if (h === 0 && m === 0) return s > 0 ? '< 1 นาที' : '0';
      return h > 0 ? `${h} ชม. ${pad(m)} นาที` : `${m} นาที`;
    },

    hms(seconds) {
      const s = Math.max(0, Math.round(seconds));
      return `${pad(Math.floor(s / 3600))}:${pad(Math.floor((s % 3600) / 60))}:${pad(s % 60)}`;
    },

    // วันที่/เวลาตามเวลาไทย
    dt(iso, withDate = true) {
      if (!iso) return '';
      const p = Object.fromEntries(
        new Intl.DateTimeFormat('en-GB', { timeZone: TZ, day: 'numeric', month: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false })
          .formatToParts(new Date(iso))
          .map((x) => [x.type, x.value]),
      );
      const time = `${p.hour}:${p.minute}`;
      return withDate ? `${p.day} ${MONTHS[+p.month - 1]} ${String(+p.year + 543).slice(-2)} ${time}` : time;
    },

    rangeLabel() {
      if (!this.meta) return '';
      const f = (s) => {
        const [y, m, d] = s.split('-').map(Number);
        return `${d} ${MONTHS[m - 1]} ${y + 543}`;
      };
      return this.meta.from === this.meta.to ? f(this.meta.from) : `${f(this.meta.from)} – ${f(this.meta.to)}`;
    },

    shiftStatus(s) {
      if (s.open) return { label: 'กำลังอยู่ในเวร', cls: 'badge badge-normal' };
      if (s.forced) return { label: 'ถูกบังคับออกเวร', cls: 'badge badge-sanction' };
      if (s.short) return { label: 'ออกก่อนเวลา', cls: 'badge badge-blacklist' };
      return { label: 'ปกติ', cls: 'badge badge-muted' };
    },

    // ── ดาวน์โหลดเป็น CSV (เปิดใน Excel ได้ ภาษาไทยไม่เพี้ยนเพราะใส่ BOM) ──
    downloadCsv() {
      const esc = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
      const head = ['ชื่อ', 'บทบาท', 'ชั่วโมงรวม', 'จำนวนเวร', 'เฉลี่ยต่อเวร (ชม.)', 'ออกก่อนเวลา', 'ถูกบังคับออกเวร'];
      const lines = this.shown.map((r) => [
        r.user.display_name,
        r.user.role_label,
        (r.total_seconds / 3600).toFixed(2),
        r.shifts,
        (r.avg_seconds / 3600).toFixed(2),
        r.short_count,
        r.forced_count,
      ].map(esc).join(','));
      const blob = new Blob(['﻿' + [head.map(esc).join(','), ...lines].join('\r\n')], { type: 'text/csv;charset=utf-8' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = `duty-report_${this.meta ? this.meta.from : ''}_${this.meta ? this.meta.to : ''}.csv`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(() => URL.revokeObjectURL(a.href), 1000);
    },
  };
}
