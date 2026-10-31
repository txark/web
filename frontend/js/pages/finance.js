// หน้า "รายรับ-รายจ่าย / ค่าบริการ" (รองประธานขึ้นไป)
//   รายรับ = ค่าบริการของธุรกรรมที่อนุมัติแล้ว (ดึงอัตโนมัติ) · รายจ่าย = กรอกเอง
function financePage() {
  const BKK = 'Asia/Bangkok';
  const iso = (d) => new Intl.DateTimeFormat('en-CA', { timeZone: BKK, year: 'numeric', month: '2-digit', day: '2-digit' }).format(d); // YYYY-MM-DD
  const todayIso = () => iso(new Date());
  const addDays = (s, n) => {
    const [y, m, d] = s.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d + n)).toISOString().slice(0, 10);
  };
  const monthStart = (s) => s.slice(0, 8) + '01';
  const blankForm = () => ({ id: null, spent_at: todayIso(), amount: '', category: '', title: '', note: '' });

  return {
    ready: false,
    loading: false,
    error: '',
    msg: '',

    presets: [
      { key: 'today', label: 'วันนี้' },
      { key: 'week', label: '7 วันล่าสุด' },
      { key: 'month', label: 'เดือนนี้' },
      { key: 'lastmonth', label: 'เดือนก่อน' },
    ],
    preset: 'today',
    from: '',
    to: '',
    type: '', // '' | income | expense
    category: '',
    categoryLabel: '',
    q: '',

    summary: null,
    rows: [],
    meta: { total: 0, page: 1, last_page: 1, per_page: 20, income: 0, expense: 0 },
    page: 1,

    dlg: null, // form | delete
    f: blankForm(),
    errs: {},
    busy: false,
    target: null, // รายการที่กำลังจะลบ

    async init() {
      const auth = authState();
      await auth.require('vice_president');
      this.ready = true;
      this.setPreset('today', false);
      this.$watch('type', () => this.reload());
      this.$watch('q', () => this.reload());
      this.$watch('from', () => this.onDate());
      this.$watch('to', () => this.onDate());
      await this.load();
    },

    // ── ช่วงวันที่ ──
    setPreset(key, load = true) {
      const t = todayIso();
      this.preset = key;
      const set = (a, b) => { this._silent = true; this.from = a; this.to = b; this.$nextTick(() => { this._silent = false; }); };
      if (key === 'today') set(t, t);
      else if (key === 'week') set(addDays(t, -6), t);
      else if (key === 'month') set(monthStart(t), t);
      else if (key === 'lastmonth') {
        const end = addDays(monthStart(t), -1);
        set(monthStart(end), end);
      }
      if (load) this.reload();
    },

    onDate() {
      if (this._silent) return;
      this.preset = '';
      if (this.from && this.to && this.from > this.to) return;
      this.reload();
    },

    // ── โหลดข้อมูล ──
    query(extra = {}) {
      const p = new URLSearchParams();
      if (this.from) p.set('from', this.from);
      if (this.to) p.set('to', this.to);
      if (this.type) p.set('type', this.type);
      if (this.category) p.set('category', this.category);
      if (this.q.trim()) p.set('q', this.q.trim());
      for (const [k, v] of Object.entries(extra)) p.set(k, v);
      return p.toString();
    },

    reload() {
      this.page = 1;
      return this.load();
    },

    async load() {
      this.loading = true;
      this.error = '';
      try {
        const [s, e] = await Promise.all([
          API.get(`/api/council/finance/summary?${this.query()}`),
          API.get(`/api/council/finance/entries?${this.query({ page: this.page })}`),
        ]);
        this.summary = s.data;
        this.rows = e.data;
        this.meta = e.meta;
      } catch (err) {
        this.error = err.status === 403 ? 'หน้านี้เปิดได้เฉพาะรองประธานสภาขึ้นไปที่เข้าเวรอยู่' : API.describe(err, err.errors ? Object.values(err.errors)[0][0] : 'โหลดข้อมูลไม่สำเร็จ');
      } finally {
        this.loading = false;
      }
    },

    async go(p) {
      if (p < 1 || p > this.meta.last_page) return;
      this.page = p;
      await this.load();
    },

    pickCategory(key, label) {
      this.category = this.category === key ? '' : key;
      this.categoryLabel = this.category ? label : '';
      this.reload();
    },

    get hasFilter() {
      return !!(this.type || this.category || this.q.trim());
    },

    clearFilters() {
      this.type = '';
      this.category = '';
      this.categoryLabel = '';
      this.q = '';
    },

    // ── แสดงผล ──
    money(n) {
      return Number(n || 0).toLocaleString('th-TH');
    },

    /** สัดส่วนแท่งของรายการในกลุ่ม (เทียบกับยอดสูงสุด) */
    pct(list, item) {
      const max = Math.max(...list.map((x) => x.amount), 1);
      return Math.max(3, Math.round((item.amount / max) * 100));
    },

    when(r) {
      const d = new Date(r.date);
      if (r.type === 'expense') return new Intl.DateTimeFormat('th-TH-u-ca-buddhist', { timeZone: BKK, day: 'numeric', month: 'short', year: '2-digit' }).format(d);
      return new Intl.DateTimeFormat('th-TH-u-ca-buddhist', { timeZone: BKK, day: 'numeric', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(d).replace(',', '');
    },

    get rangeLabel() {
      if (!this.summary) return '';
      const f = (s) => new Intl.DateTimeFormat('th-TH-u-ca-buddhist', { timeZone: 'UTC', day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(s + 'T00:00:00Z'));
      return this.summary.from === this.summary.to ? f(this.summary.from) : `${f(this.summary.from)} – ${f(this.summary.to)}`;
    },

    // ── รายจ่าย (เพิ่ม/แก้ไข/ลบ) ──
    openCreate() {
      this.f = blankForm();
      this.errs = {};
      this.dlg = 'form';
    },

    openEdit(r) {
      this.f = { id: r.expense_id, spent_at: r.spent_at, amount: String(r.amount), category: r.category, title: r.title, note: r.note || '' };
      this.errs = {};
      this.dlg = 'form';
    },

    openDelete(r) {
      this.target = r;
      this.errs = {};
      this.dlg = 'delete';
    },

    closeDlg() {
      if (this.busy) return;
      this.dlg = null;
      this.target = null;
    },

    e(k) {
      return this.errs[k] || '';
    },

    validate() {
      const er = {};
      if (!this.f.spent_at) er.spent_at = 'กรุณาเลือกวันที่จ่าย';
      else if (this.f.spent_at > todayIso()) er.spent_at = 'วันที่จ่ายต้องไม่เกินวันนี้';
      const amt = String(this.f.amount).replace(/[,\s]/g, '');
      if (!amt) er.amount = 'กรุณากรอกจำนวนเงิน';
      else if (!/^\d{1,12}$/.test(amt) || Number(amt) < 1) er.amount = 'จำนวนเงินต้องเป็นตัวเลขมากกว่า 0';
      if (!this.f.category) er.category = 'กรุณาเลือกหมวด';
      if (!this.f.title.trim()) er.title = 'กรุณากรอกรายการ';
      this.errs = er;
      return !Object.keys(er).length;
    },

    async save() {
      if (this.busy || !this.validate()) return;
      this.busy = true;
      const body = { spent_at: this.f.spent_at, amount: Number(String(this.f.amount).replace(/[,\s]/g, '')), category: this.f.category, title: this.f.title.trim(), note: this.f.note.trim() || null };
      try {
        if (this.f.id) await API.put(`/api/council/finance/expenses/${this.f.id}`, body);
        else await API.post('/api/council/finance/expenses', body);
        this.msg = this.f.id ? 'แก้ไขรายจ่ายแล้ว' : 'บันทึกรายจ่ายแล้ว';
        this.dlg = null;
        await this.load();
      } catch (err) {
        if (err.errors) this.errs = Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]]));
        else this.errs = { _: API.describe(err, err.message || 'บันทึกไม่สำเร็จ') };
      } finally {
        this.busy = false;
      }
    },

    async remove() {
      if (this.busy || !this.target) return;
      this.busy = true;
      try {
        await API.del(`/api/council/finance/expenses/${this.target.expense_id}`);
        this.msg = 'ลบรายจ่ายแล้ว';
        this.dlg = null;
        this.target = null;
        await this.load();
      } catch (err) {
        this.errs = { _: API.describe(err, err.message || 'ลบไม่สำเร็จ') };
      } finally {
        this.busy = false;
      }
    },

    // ── ส่งออก CSV (ตามช่วงวันและตัวกรองที่เลือกอยู่) ──
    async exportCsv() {
      this.error = '';
      try {
        const res = await fetch(`${window.APP_CONFIG.API_URL}/api/council/finance/export?${this.query()}`, { credentials: 'include' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const blob = await res.blob();
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `finance-${this.from || 'all'}_${this.to || 'all'}.csv`;
        a.click();
        setTimeout(() => URL.revokeObjectURL(a.href), 1000);
      } catch (e) {
        this.error = 'ส่งออกไฟล์ไม่สำเร็จ';
      }
    },
  };
}
