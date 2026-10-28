function logsPage() {
  const MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
  const ROLE = { gang: 'Gang', family: 'Family', member: 'Council', vice_president: 'Sub-CC', president: 'Head-CC', admin: 'Admin' };
  const FIELD = { name: 'ชื่อ', character_name: 'ชื่อตัวละคร', role: 'บทบาท', status: 'สถานะ', type: 'ประเภท', description: 'คำอธิบาย', body: 'เนื้อหา', note: 'หมายเหตุ', penalty: 'โทษ', is_published: 'เผยแพร่', slots_locked: 'ล็อก Slot', position: 'ตำแหน่ง', section: 'หมวด', sort_order: 'ลำดับ', kind: 'ชนิด', title: 'หัวข้อ', note_label: 'ป้ายหมายเหตุ', status_reason: 'เหตุผลสถานะ', logo_url: 'โลโก้' };
  const EVENT_BADGE = {
    login: 'badge badge-normal', logout: 'badge badge-muted', login_failed: 'badge badge-sanction',
    view: 'badge badge-info', denied: 'badge badge-sanction',
    created: 'badge badge-normal', updated: 'badge badge-blacklist', deleted: 'badge badge-sanction',
  };

  return {
    ready: false,
    types: [
      { value: '', label: 'ทั้งหมด' },
      { value: 'auth', label: 'เข้า/ออกระบบ' },
      { value: 'access', label: 'การเข้าดู' },
      { value: 'changes', label: 'แก้ไขข้อมูล' },
    ],
    type: '',
    q: '',
    from: '',
    to: '',
    user: null, // { id, name } ผู้กระทำที่กำลังกรอง
    page: 1,

    rows: [],
    meta: null,
    open: {}, // id → กางรายละเอียด
    loading: true,
    error: '',

    async init() {
      // ให้เข้าได้เฉพาะรองประธานขึ้นไป (ที่ฝั่ง server ตรวจซ้ำอีกชั้นเสมอ)
      await authState().require('vice_president');
      this.ready = true;
      await this.load();
      for (const k of ['q', 'from', 'to']) this.$watch(k, () => this.reload());
    },

    reload() {
      this.page = 1;
      this.load();
    },

    setType(v) {
      this.type = v;
      this.reload();
    },

    filterUser(causer) {
      this.user = { id: causer.id, name: causer.name };
      this.reload();
    },

    clearUser() {
      this.user = null;
      this.reload();
    },

    clearAll() {
      this.type = '';
      this.user = null;
      this.from = this.to = '';
      this.q = '';
      this.reload();
    },

    get hasFilter() {
      return !!(this.type || this.user || this.from || this.to || this.q);
    },

    go(p) {
      this.page = p;
      this.load();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    },

    async load() {
      this.loading = true;
      this.error = '';
      const p = new URLSearchParams({ page: this.page });
      if (this.type) p.set('type', this.type);
      if (this.user) p.set('user_id', this.user.id);
      if (this.q.trim()) p.set('q', this.q.trim());
      if (this.from) p.set('from', this.from);
      if (this.to) p.set('to', this.to);

      try {
        const res = await API.get(`/api/logs?${p}`);
        this.rows = res.data;
        this.meta = res.meta;
      } catch (e) {
        this.rows = [];
        this.error = e.status === 403
          ? 'คุณไม่มีสิทธิ์ดูบันทึกกิจกรรม'
          : API.describe(e, 'โหลดบันทึกกิจกรรมไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
      } finally {
        this.loading = false;
      }
    },

    // ไม่มีผู้กระทำ: แยกให้ชัดว่าเป็นใคร
    anon(row) {
      const p = row.properties || {};
      if (p.via === 'console') return 'ระบบ (คำสั่งบนเซิร์ฟเวอร์)';
      if (row.event === 'login_failed') return 'ผู้ที่ยังไม่ได้เข้าสู่ระบบ';
      return 'ไม่ระบุตัวตน';
    },

    toggle(id) {
      this.open = { ...this.open, [id]: !this.open[id] };
    },

    badge(event) {
      return EVENT_BADGE[event] || 'badge badge-muted';
    },

    // "2026-10-28T07:05:00+00:00" → "28 ต.ค. 2569 14:05" (เวลาของเครื่องผู้ดู)
    when(iso) {
      const d = new Date(iso);
      if (isNaN(d)) return '';
      const hh = String(d.getHours()).padStart(2, '0');
      const mm = String(d.getMinutes()).padStart(2, '0');
      return `${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear() + 543} ${hh}:${mm}`;
    },

    // สรุปอุปกรณ์จาก User-Agent แบบสั้นๆ: "Chrome · Windows"
    device(ua) {
      if (!ua) return '-';
      const browser = /Edg\//.test(ua) ? 'Edge' : /OPR\//.test(ua) ? 'Opera' : /Firefox\//.test(ua) ? 'Firefox'
        : /Chrome\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : 'เบราว์เซอร์อื่น';
      const os = /Windows/.test(ua) ? 'Windows' : /Android/.test(ua) ? 'Android' : /iPhone|iPad/.test(ua) ? 'iOS'
        : /Mac OS/.test(ua) ? 'macOS' : /Linux/.test(ua) ? 'Linux' : '';
      return os ? `${browser} · ${os}` : browser;
    },

    // รายการที่เปลี่ยน: [{ field, from, to }]  (from = null เมื่อไม่มีค่าเดิม เช่น สร้างใหม่)
    diff(row) {
      const ch = row.changes;
      if (!ch || !ch.attributes) return [];
      const created = row.event === 'created';
      return Object.keys(ch.attributes)
        // สร้างใหม่: โชว์เฉพาะช่องที่มีค่าจริง ไม่เอาช่องว่างมาเป็นเสียงรบกวน
        .filter((k) => !created || !(ch.attributes[k] === null || ch.attributes[k] === ''))
        .map((k) => ({
          field: FIELD[k] || k,
          from: ch.old && k in ch.old ? this.val(k, ch.old[k]) : null,
          to: this.val(k, ch.attributes[k]),
        }));
    },

    val(key, v) {
      if (v === undefined || v === null || v === '') return '—';
      if (key === 'role') return ROLE[v] || v;
      if (v === true) return 'ใช่';
      if (v === false) return 'ไม่';
      const s = String(v);
      return s.length > 80 ? s.slice(0, 80) + '…' : s;
    },

    // ข้อมูลเสริมที่แสดงในรายละเอียด (ตัด key ที่แสดงแยกแล้ว)
    extras(row) {
      const p = row.properties || {};
      const out = [];
      if (p.ip) out.push(['IP', p.ip]);
      if (p.user_agent) out.push(['อุปกรณ์', this.device(p.user_agent)]);
      if (p.via === 'console') out.push(['ที่มา', 'คำสั่งบนเซิร์ฟเวอร์ (artisan)']);
      if (p.path) out.push(['หน้า', p.path]);
      if (p.discord_id) out.push(['Discord ID', p.discord_id]);
      if (p.required_role) out.push(['สิทธิ์ที่ต้องมี', ROLE[p.required_role] || p.required_role]);
      if (p.reason) out.push(['สาเหตุ', { discord_failed: 'Discord ตอบกลับผิดพลาด', not_in_guild: 'ไม่ได้อยู่ในเซิร์ฟเวอร์ Discord ของสภา' }[p.reason] || p.reason]);
      if (p.first_login) out.push(['หมายเหตุ', 'เข้าสู่ระบบครั้งแรก (สร้างบัญชีใหม่)']);
      return out;
    },

    hasDetail(row) {
      return this.diff(row).length > 0 || this.extras(row).length > 0;
    },
  };
}
