// หน้า "ธุรกรรมการเงิน" (รองประธานขึ้นไป)
//   รายรับ = ค่าบริการของธุรกรรมที่อนุมัติแล้ว + ค่าปรับที่บันทึกในทะเบียน (ดึงอัตโนมัติ) + รายการที่กรอกเอง · รายจ่าย = กรอกเอง
//   รายการที่กรอกเอง (กองทุน / เงินกู้ / โบนัส ฯลฯ) เป็นได้ทั้งรายรับและรายจ่าย · ประธานและรองประธานเท่านั้น
//   แท็บ: สรุป · เงินกู้ (สัญญา ดอกเบี้ย ผ่อนรายสัปดาห์ ค่าปรับล่าช้า) · ซื้อ-ขายกับแก๊ง/ครอบครัว · โบนัสเวร (ตัดรอบ คำนวณจากชั่วโมงเวร)
function financePage() {
  const BKK = 'Asia/Bangkok';
  const iso = (d) => new Intl.DateTimeFormat('en-CA', { timeZone: BKK, year: 'numeric', month: '2-digit', day: '2-digit' }).format(d); // YYYY-MM-DD
  const todayIso = () => iso(new Date());
  const addDays = (s, n) => {
    const [y, m, d] = s.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d + n)).toISOString().slice(0, 10);
  };
  const monthStart = (s) => s.slice(0, 8) + '01';
  const REFRESH_MS = 30000; // รายรับดึงจากธุรกรรมอัตโนมัติ: หน้านี้รีเฟรชตัวเองเป็นระยะ ไม่ต้องกดโหลดใหม่
  const blankLoan = (st) => ({ kind: '', group: null, borrower_name: '', principal: '', reason: '', loaned_at: todayIso(), due_at: '', installment: false, installments: '3', interest_rate: st ? String(st.default_interest) : '0', late_fee_type: st ? st.late_fee_type : 'day', late_fee_value: st ? String(st.late_fee_amount) : '0', note: '' });
  const blankTrade = () => ({ id: null, group: null, direction: 'sell', item: '', quantity: '1', unit_price: '', traded_at: todayIso(), note: '' });
  const blankForm = () => ({ id: null, type: 'expense', spent_at: todayIso(), amount: '', category: '', title: '', note: '' });

  return {
    ready: false,
    tabs: [
      { key: 'summary', label: 'สรุป' },
      { key: 'loans', label: 'เงินกู้' },
      { key: 'trade', label: 'ซื้อ-ขาย' },
      { key: 'bonus', label: 'โบนัสเวร' },
    ],
    tab: 'summary',
    updatedAt: null,
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
      this.me = auth.user; // ชื่อผู้ให้กู้ในตัวอย่างสัญญา
      this.ready = true;
      this.tab = this.tabs.some((t) => !t.soon && t.key === location.hash.slice(1)) ? location.hash.slice(1) : 'summary';
      this.setPreset('today', false);
      this.$watch('type', () => this.reload());
      this.$watch('q', () => this.reload());
      this.$watch('from', () => this.onDate());
      this.$watch('to', () => this.onDate());
      this.$watch('loanStatus', () => this.loadLoans(true));
      this.$watch('loanQ', () => this.loadLoans(true));
      this.$watch('tradeDir', () => this.loadTrades(true));
      this.$watch('bn.start_from', () => this.schedulePreview());
      this.$watch('tradeQ', () => this.loadTrades(true));
      await this.load();
      if (this.tab === 'loans') await this.loadLoans(true);
      if (this.tab === 'trade') await this.loadTrades(true);
      if (this.tab === 'bonus') await this.loadBonus(true);

      // รีเฟรชอัตโนมัติ: ทุก 30 วินาทีตอนเปิดหน้านี้อยู่ และทันทีที่กลับมาที่แท็บ (ไม่รีเฟรชขณะเปิดกล่องกรอกข้อมูล)
      setInterval(() => this.refresh(), REFRESH_MS);
      document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && this.refresh());
    },

    async refresh() {
      if (document.visibilityState !== 'visible' || this.dlg || this.loading || this.loanLoading || this.tradeLoading || this.bonusLoading) return;
      if (this.tab === 'bonus' && document.activeElement && document.activeElement.matches('input, textarea')) return; // กำลังพิมพ์อยู่
      if (this.tab === 'loans') await this.loadLoans();
      else if (this.tab === 'trade') await this.loadTrades();
      else if (this.tab === 'bonus') await this.loadBonus();
      else await this.load();
    },

    setTab(key) {
      const t = this.tabs.find((x) => x.key === key);
      if (!t || t.soon) return;
      this.tab = key;
      this.msg = '';
      history.replaceState(null, '', key === 'summary' ? location.pathname : '#' + key);
      if (key === 'loans' && !this.loansLoaded) this.loadLoans(true);
      if (key === 'trade' && !this.tradesLoaded) this.loadTrades(true);
      if (key === 'bonus' && !this.bonusLoaded) this.loadBonus(true);
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
        this.updatedAt = new Date();
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

    get updatedLabel() {
      return this.updatedAt ? new Intl.DateTimeFormat('th-TH', { timeZone: BKK, hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' }).format(this.updatedAt) : '';
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

    /** หมวดที่เลือกได้ตามประเภทรายการ (รายรับ/รายจ่าย) */
    get formCategories() {
      if (!this.summary) return [];
      return this.f.type === 'income' ? this.summary.income_categories : this.summary.expense_categories;
    },

    setFormType(t) {
      this.f.type = t;
      if (!this.formCategories.some((c) => c.key === this.f.category)) this.f.category = '';
      delete this.errs.category;
    },

    openEdit(r) {
      this.f = { id: r.entry_id, type: r.type, spent_at: r.spent_at, amount: String(r.amount), category: r.category, title: r.title, note: r.note || '' };
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
      this.loanTarget = null;
      this.payTarget = null;
      this.reasonText = '';
      this.tradeTarget = null;
      this.bonusTarget = null;
      this.errs = {};
    },

    e(k) {
      return this.errs[k] || '';
    },

    validate() {
      const er = {};
      if (!this.f.spent_at) er.spent_at = 'กรุณาเลือกวันที่';
      else if (this.f.spent_at > todayIso()) er.spent_at = 'วันที่ต้องไม่เกินวันนี้';
      const amt = String(this.f.amount).replace(/[,\s]/g, '');
      if (!amt) er.amount = 'กรุณากรอกจำนวนเงิน';
      else if (!/^\d{1,12}$/.test(amt) || Number(amt) < 1) er.amount = 'จำนวนเงินต้องเป็นตัวเลขมากกว่า 0';
      if (!this.f.category || !this.formCategories.some((c) => c.key === this.f.category)) er.category = 'กรุณาเลือกหมวด';
      if (!this.f.title.trim()) er.title = 'กรุณากรอกรายการ';
      this.errs = er;
      return !Object.keys(er).length;
    },

    async save() {
      if (this.busy || !this.validate()) return;
      this.busy = true;
      const body = { type: this.f.type, spent_at: this.f.spent_at, amount: Number(String(this.f.amount).replace(/[,\s]/g, '')), category: this.f.category, title: this.f.title.trim(), note: this.f.note.trim() || null };
      try {
        if (this.f.id) await API.put(`/api/council/finance/records/${this.f.id}`, body);
        else await API.post('/api/council/finance/records', body);
        this.msg = this.f.id ? 'แก้ไขรายการแล้ว' : (this.f.type === 'income' ? 'บันทึกรายรับแล้ว' : 'บันทึกรายจ่ายแล้ว');
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
        await API.del(`/api/council/finance/records/${this.target.entry_id}`);
        this.msg = 'ลบรายการแล้ว';
        this.dlg = null;
        this.target = null;
        await this.load();
      } catch (err) {
        this.errs = { _: API.describe(err, err.message || 'ลบไม่สำเร็จ') };
      } finally {
        this.busy = false;
      }
    },

    // ───────────────────────── สัญญากู้เงิน ─────────────────────────
    // แก๊ง/ครอบครัวขอกู้ ประธานหรือรองเป็นผู้บันทึกสัญญา · บันทึกแล้วแก้/ลบไม่ได้ ผิดให้บันทึกรายการยกเลิกต่อท้าย
    loans: [],
    loanMeta: { total: 0, page: 1, last_page: 1, summary: null, central: null, settings: null },
    loanStatus: '', // '' | unpaid | overdue | partial | paid | cancelled
    loanQ: '',
    loanPage: 1,
    loansLoaded: false,
    loanLoading: false,
    openLoan: null, // id ที่ขยายดูประวัติ
    lf: blankLoan(null),
    loanFormOpen: true, // ฟอร์มสร้างสัญญาย่อ/ขยายได้ (เริ่มต้น = ขยาย)
    me: null,
    loanDraftOpen: false, // หน้าต่างตัวอย่างหนังสือสัญญาก่อนบันทึก
    loanDraftKey: 0,
    groupOptions: [], // รายชื่อแก๊ง/ครอบครัวที่ขึ้นทะเบียน ตามประเภทที่เลือก (ดึงจากข้อมูลจริง)
    groupFilter: '',
    groupsLoading: false,
    loanKinds: [
      { key: 'gang', label: 'Gang' },
      { key: 'lady', label: 'Gang Lady' },
      { key: 'family', label: 'Family' },
    ],
    contractImgBusy: false,
    contractImgMsg: '',
    contractImgErr: '',
    contractLoan: null, // สัญญาที่เปิดเอกสารเป็นป๊อปอัปอยู่
    loanTarget: null, // สัญญาที่กำลังบันทึกคืน/ยกเลิก
    payTarget: null, // รายการรับคืนที่กำลังจะยกเลิก
    rp: { mode: 'partial', amount: '', paid_at: todayIso(), note: '', checked: false }, // ฟอร์มบันทึกการคืน (popup)
    reasonText: '', // เหตุผลของการยกเลิกสัญญา/รายการรับคืน
    gq: '',
    gresults: [],
    gsearching: false,

    loanStatuses: [
      { key: '', label: 'ทั้งหมด' },
      { key: 'unpaid', label: 'ยังไม่คืนครบ' },
      { key: 'overdue', label: 'เกินกำหนด' },
      { key: 'paid', label: 'คืนครบ' },
      { key: 'cancelled', label: 'ยกเลิกแล้ว' },
    ],

    async loadLoans(reset = false) {
      if (reset) this.loanPage = 1;
      this.loanLoading = true;
      this.error = '';
      try {
        const p = new URLSearchParams({ page: this.loanPage });
        if (this.loanStatus) p.set('status', this.loanStatus);
        if (this.loanQ.trim()) p.set('q', this.loanQ.trim());
        const res = await API.get(`/api/council/finance/loans?${p}`);
        this.loans = res.data;
        this.loanMeta = res.meta;
        if (!this.loansLoaded) this.lf = blankLoan(res.meta.settings); // ค่าเริ่มต้นของฟอร์มดึงจากตั้งค่ากลาง
        this.loansLoaded = true;
        this.updatedAt = new Date();
      } catch (err) {
        this.error = err.status === 403 ? 'หน้านี้เปิดได้เฉพาะประธานและรองประธานที่เข้าเวรอยู่' : API.describe(err, 'โหลดรายการเงินกู้ไม่สำเร็จ');
      } finally {
        this.loanLoading = false;
      }
    },

    async goLoans(p) {
      if (p < 1 || p > this.loanMeta.last_page) return;
      this.loanPage = p;
      await this.loadLoans();
    },

    get todayMax() {
      return todayIso();
    },

    toggleLoan(id) {
      this.openLoan = this.openLoan === id ? null : id;
    },

    kindOf(g) {
      return !g ? '' : g.type === 'family' ? 'FAMILY' : g.is_lady ? 'LADY' : 'GANG';
    },

    kindLabel(g) {
      return !g ? '' : g.type === 'family' ? 'ครอบครัว' : g.is_lady ? 'แก๊งหญิงล้วน' : 'แก๊ง';
    },

    dayLabel(s) {
      return s ? new Intl.DateTimeFormat('th-TH-u-ca-buddhist', { timeZone: 'UTC', day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(s + 'T00:00:00Z')) : '—';
    },

    /** ป้ายสถานะตามแบบร่าง: เกินกำหนด N วัน / คืนบางส่วน / กำลังกู้ / คืนครบ / ยกเลิกแล้ว */
    statusText(l) {
      return l.status === 'overdue' ? `เกินกำหนด ${l.days_overdue} วัน` : l.status_label;
    },

    statusClass(l) {
      return { overdue: 'fn-pill-overdue', partial: 'fn-pill-open', open: 'fn-pill-teal', paid: 'fn-pill-mute', cancelled: 'fn-pill-mute' }[l.status] || '';
    },

    /** บรรทัดรองใต้ยอดคงค้างตามแบบร่าง */
    leftNote(l) {
      if (l.status === 'cancelled') return 'ยกเลิกสัญญา';
      if (l.late_fee_total > 0 && l.outstanding > 0) return 'รวมค่าปรับ ' + this.money(l.late_fee_total);
      if (l.repaid > 0 && l.outstanding > 0) return 'คืนแล้ว ' + this.money(l.repaid);
      if (l.outstanding > 0 && l.next_due && l.next_due.due_at) return 'งวด' + (l.installments > 1 ? 'ที่ ' + l.next_due.no : 'เดียว') + ' ' + this.dayLabel(l.next_due.due_at);
      return '';
    },

    // ── ฟอร์มสร้างสัญญา ──
    loanInstallments() {
      if (!this.lf.installment) return 1;
      const n = parseInt(String(this.lf.installments).trim(), 10);
      return Number.isInteger(n) && n >= 1 ? n : 1;
    },

    get maxInstallments() {
      return (this.loanMeta.settings && this.loanMeta.settings.max_installments) || 6;
    },

    /** วันกำหนดคืนที่คำนวณได้เมื่อผ่อน (งวดสุดท้าย = วันที่กู้ + 7 × จำนวนงวด) */
    get autoDue() {
      return this.lf.installment && this.lf.loaned_at ? addDays(this.lf.loaned_at, 7 * this.loanInstallments()) : '';
    },

    /** ตัวอย่างยอดตามเงื่อนไขที่กรอก (ตัวเลขจริงคำนวณที่เซิร์ฟเวอร์ตอนบันทึก) */
    get loanPreview() {
      const p = this.num(this.lf.principal);
      const r = Number(this.loanMeta.settings ? this.loanMeta.settings.default_interest : this.lf.interest_rate);
      if (p === null || Number.isNaN(r) || r < 0) return null;
      const n = this.loanInstallments();
      const interest = Math.round(p * (r / 100) * n);
      const dues = Array.from({ length: n }, (_, i) => this.lf.loaned_at ? this.shortDay(addDays(this.lf.loaned_at, 7 * (i + 1))) : '');
      return { interest, total: p + interest, each: Math.round((p + interest) / n), n, dues };
    },

    shortDay(s) {
      return s ? new Intl.DateTimeFormat('th-TH-u-ca-buddhist', { timeZone: 'UTC', day: 'numeric', month: 'short' }).format(new Date(s + 'T00:00:00Z')) : '';
    },

    /** กองทุนก่อน/หลังปล่อยกู้ */
    get loanCentral() {
      const c = this.loanMeta.central;
      const p = this.num(this.lf.principal);
      return c ? { balance: c.balance, after: c.balance - (p || 0) } : null;
    },

    feeTypeLabel(t) {
      return { day: 'ต่อวัน', week: 'ต่อสัปดาห์', once: 'ครั้งเดียว' }[t] || t;
    },

    /** ผู้กู้: ค้นแก๊ง/ครอบครัวที่ขึ้นทะเบียนอยู่ */
    async searchGroups() {
      const q = this.gq.trim();
      if (!q) {
        this.gresults = [];
        return;
      }
      this.gsearching = true;
      try {
        this.gresults = (await API.get(`/api/council/groups?q=${encodeURIComponent(q)}&per_page=8`)).data;
      } catch (e) {
        this.gresults = [];
      } finally {
        this.gsearching = false;
      }
    },

    /** เลือกประเภท (Gang / Gang Lady / Family) แล้วดึงรายชื่อกลุ่มที่ขึ้นทะเบียนของประเภทนั้นมาให้เลือก */
    async setLoanKind(kind) {
      if (this.lf.kind === kind) return;
      this.lf.kind = kind;
      this.lf.group = null;
      this.groupFilter = '';
      this.groupOptions = [];
      delete this.errs.group_id;
      this.groupsLoading = true;
      try {
        this.groupOptions = (await API.get(`/api/council/groups?kind=${kind}&per_page=100`)).data;
      } catch (e) {
        this.errs = { ...this.errs, group_id: 'โหลดรายชื่อไม่สำเร็จ ลองเลือกประเภทอีกครั้ง' };
      } finally {
        this.groupsLoading = false;
      }
    },

    get shownGroups() {
      const q = this.groupFilter.trim().toLowerCase();
      return q ? this.groupOptions.filter((g) => (g.name + ' ' + (g.short_name || '')).toLowerCase().includes(q)) : this.groupOptions;
    },

    pickGroupId(id) {
      this.lf.group = this.groupOptions.find((g) => String(g.id) === String(id)) || null;
      delete this.errs.group_id;
      // ชื่อผู้กู้: ดึงชื่อหัวหน้าให้อัตโนมัติ (ถ้ายังว่าง หรือเป็นชื่อที่ดึงมาจากกลุ่มก่อนหน้า) แก้เองหรือกดเลือกรองหัวหน้าแทนได้
      const names = this.groupLeaders.map((m) => m.name);
      if (!this.lf.borrower_name || this._autoBorrower === this.lf.borrower_name) {
        this.lf.borrower_name = names[0] || '';
        this._autoBorrower = this.lf.borrower_name;
      }
    },

    /** หัวหน้าและรองหัวหน้าของกลุ่มที่เลือก (จากทะเบียน) ให้กดเลือกเป็นชื่อผู้กู้ */
    get groupLeaders() {
      return this.lf.group && this.lf.group.leadership ? this.lf.group.leadership : [];
    },

    pickBorrower(name) {
      this.lf.borrower_name = name;
      this._autoBorrower = name;
      delete this.errs.borrower_name;
    },

    pickGroup(g) {
      if (this.dlg === 'trade') this.tf.group = g;
      else this.lf.group = g;
      delete this.errs.group_id;
      this.gq = '';
      this.gresults = [];
    },

    num(v) {
      const s = String(v ?? '').replace(/[,\s]/g, '');
      return /^\d{1,12}$/.test(s) && Number(s) >= 1 ? Number(s) : null;
    },

    validateLoan() {
      const er = {};
      const L = this.lf;
      if (!L.kind) er.group_id = 'กรุณาเลือกประเภท (Gang / Gang Lady / Family)';
      else if (!L.group) er.group_id = 'กรุณาเลือกชื่อแก๊ง/ครอบครัว';
      if (!L.borrower_name.trim()) er.borrower_name = 'กรุณากรอกชื่อผู้กู้';
      if (this.num(L.principal) === null) er.principal = String(L.principal).trim() ? 'จำนวนเงินต้องเป็นตัวเลขมากกว่า 0' : 'กรุณากรอกจำนวนเงินที่ต้องการกู้';
      if (!L.reason.trim()) er.reason = 'กรุณากรอกเหตุผล';
      if (!L.loaned_at) er.loaned_at = 'กรุณาเลือกวันที่กู้';
      else if (L.loaned_at > todayIso()) er.loaned_at = 'วันที่กู้ต้องไม่เกินวันนี้';
      if (!L.installment) {
        if (!L.due_at) er.due_at = 'กรุณาเลือกวันกำหนดคืน';
        else if (L.loaned_at && L.due_at < L.loaned_at) er.due_at = 'วันกำหนดคืนต้องไม่ก่อนวันที่กู้';
      } else {
        const inst = String(L.installments).trim() === '' ? 1 : Number(L.installments);
        if (!Number.isInteger(inst) || inst < 1 || inst > this.maxInstallments) er.installments = `จำนวนงวดต้องเป็นจำนวนเต็ม 1–${this.maxInstallments} (ตามตั้งค่ากลาง)`;
      }
      const rate = String(L.interest_rate).trim() === '' ? 0 : Number(L.interest_rate);
      if (Number.isNaN(rate) || rate < 0 || rate > 100) er.interest_rate = 'ดอกเบี้ยต้องเป็นตัวเลข 0–100';
      const fee = String(L.late_fee_value).trim() === '' ? 0 : Number(L.late_fee_value);
      if (Number.isNaN(fee) || fee < 0) er.late_fee_value = 'ค่าปรับต้องเป็นตัวเลขไม่ติดลบ';
      this.errs = er;
      return !Object.keys(er).length;
    },

    /** กดบันทึกสัญญา: แสดงตัวอย่างหนังสือสัญญาให้ตรวจก่อนทุกครั้ง ยืนยันแล้วจึงบันทึกจริง */
    saveLoan() {
      const st = this.loanMeta.settings;
      if (st) Object.assign(this.lf, { interest_rate: String(st.default_interest), late_fee_type: st.late_fee_type, late_fee_value: String(st.late_fee_amount) }); // ดอกเบี้ย/ค่าปรับใช้ค่ากลางเสมอ
      if (this.busy || !this.validateLoan()) return;
      const L = this.lf;
      const n = this.loanInstallments();
      const p = this.num(L.principal);
      const r = Number(String(L.interest_rate).trim() === '' ? 0 : L.interest_rate);
      const interest = Math.round(p * (r / 100) * n);
      const pBase = Math.floor(p / n);
      const iBase = Math.floor(interest / n);
      const finalDue = n === 1 ? L.due_at : addDays(L.loaned_at, 7 * n);
      const schedule = Array.from({ length: n }, (_, i) => {
        const principal = i < n - 1 ? pBase : p - pBase * (n - 1);
        const int = i < n - 1 ? iBase : interest - iBase * (n - 1);
        return { no: i + 1, due_at: n === 1 ? L.due_at : addDays(L.loaned_at, 7 * (i + 1)), principal, interest: int, amount: principal + int, late_fee: 0, status: 'upcoming' };
      });
      const me = this.me || {};
      const draft = {
        draft: true, id: 0, status_label: 'ฉบับร่าง · ยังไม่บันทึก', group: L.group, borrower_name: L.borrower_name.trim(), reason: L.reason.trim(),
        principal: p, interest_rate: r, installments: n, interest_total: interest, total_payable: p + interest,
        late_fee_type: L.late_fee_type, late_fee_value: String(L.late_fee_value).trim() === '' ? 0 : Number(L.late_fee_value), late_fee_total: 0,
        loaned_at: L.loaned_at, due_at: finalDue, final_due: finalDue, schedule, by: me.character_name || me.name || '', by_rank: me.role_name || '', borrower_position: this.borrowerPosition(L), created_at: new Date().toISOString(),
      };
      try {
        sessionStorage.setItem('loanDraft', JSON.stringify(draft));
      } catch (e) {
        this.errs = { _: 'แสดงตัวอย่างสัญญาไม่ได้ (เบราว์เซอร์ปิดการเก็บข้อมูลชั่วคราว)' };
        return;
      }
      this.errs = {};
      this.loanDraftKey++;
      this.loanDraftOpen = true;
    },

    /** ตำแหน่งของผู้กู้ในกลุ่ม (หัวหน้า/รองหัวหน้า ถ้าชื่อตรงกับทะเบียน) ใช้ในตัวอย่างสัญญา */
    borrowerPosition(L) {
      const n = L.borrower_name.trim().toLowerCase();
      const m = ((L.group && L.group.leadership) || []).find((x) => x.name.trim().toLowerCase() === n);
      return m ? m.position_label : '';
    },

    async confirmLoan() {
      if (this.busy || !this.validateLoan()) return;
      this.busy = true;
      const L = this.lf;
      const n = this.loanInstallments();
      const body = {
        group_id: L.group.id, borrower_name: L.borrower_name.trim(), reason: L.reason.trim(), principal: this.num(L.principal), loaned_at: L.loaned_at,
        due_at: n === 1 ? L.due_at : null, installments: n,
        interest_rate: String(L.interest_rate).trim() === '' ? 0 : Number(L.interest_rate),
        late_fee_type: L.late_fee_type, late_fee_value: String(L.late_fee_value).trim() === '' ? 0 : Number(L.late_fee_value),
      };
      try {
        const res = await API.post('/api/council/finance/loans', body);
        this.msg = 'บันทึกสัญญาแล้ว · ยอดกองทุนลดลง ' + this.money(res.data.principal) + ' WVDL';
        this.lf = blankLoan(res.meta.settings);
        this.groupOptions = [];
        this.loanDraftOpen = false;
        this.errs = {};
        await Promise.all([this.loadLoans(), this.load()]);
      } catch (err) {
        this.errs = err.errors ? Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])) : { _: API.describe(err, err.message || 'บันทึกสัญญาไม่สำเร็จ') };
      } finally {
        this.busy = false;
      }
    },

    // ── ตั้งค่ากลางการกู้เงิน (popup) ──
    ss: { default_interest: '', late_fee_type: 'day', late_fee_amount: '', max_installments: '' },
    ssSaved: null,
    ssLogs: [],
    ssLoading: false,

    async openSettings() {
      this.errs = {};
      this.ssLoading = true;
      this.dlg = 'loanSettings';
      try {
        this.applySettings((await API.get('/api/council/finance/loan-settings')).data);
      } catch (e) {
        this.errs = { _: e.status === 403 ? 'แก้ตั้งค่ากลางได้เฉพาะประธานและรองประธานที่เข้าเวรอยู่' : API.describe(e, 'โหลดตั้งค่าไม่สำเร็จ') };
      } finally {
        this.ssLoading = false;
      }
    },

    applySettings(d) {
      this.ssSaved = d.settings;
      this.ssLogs = d.logs;
      this.ss = { default_interest: String(d.settings.default_interest), late_fee_type: d.settings.late_fee_type, late_fee_amount: String(d.settings.late_fee_amount), max_installments: String(d.settings.max_installments) };
      this.loanMeta.settings = d.settings; // ฟอร์มสร้างสัญญาใช้ค่ากลางล่าสุด
    },

    get ssDirty() {
      const s = this.ssSaved;
      return !!s && (String(s.default_interest) !== this.ss.default_interest.trim() || s.late_fee_type !== this.ss.late_fee_type || String(s.late_fee_amount) !== this.ss.late_fee_amount.trim() || String(s.max_installments) !== this.ss.max_installments.trim());
    },

    async saveSettings() {
      if (this.busy || !this.ssDirty) return;
      const er = {};
      const rate = this.ss.default_interest.trim() === '' ? NaN : Number(this.ss.default_interest);
      if (Number.isNaN(rate) || rate < 0 || rate > 100) er.default_interest = 'ดอกเบี้ยต้องเป็นตัวเลข 0–100';
      const fee = this.ss.late_fee_amount.trim().replace(/,/g, '');
      if (!/^\d{1,10}$/.test(fee)) er.late_fee_amount = 'จำนวนเงินค่าปรับต้องเป็นตัวเลขไม่ติดลบ';
      const n = this.ss.max_installments.trim();
      if (!/^\d{1,3}$/.test(n) || Number(n) < 1 || Number(n) > 104) er.max_installments = 'จำนวนงวดต้องเป็นจำนวนเต็ม 1–104';
      this.errs = er;
      if (Object.keys(er).length) return;

      this.busy = true;
      try {
        const res = await API.put('/api/council/finance/loan-settings', { default_interest: Number(this.ss.default_interest), late_fee_type: this.ss.late_fee_type, late_fee_amount: Number(fee), max_installments: Number(n) });
        this.applySettings(res.data);
        if (!this.lf.group && !this.lf.principal) this.lf = { ...blankLoan(res.data.settings), kind: this.lf.kind }; // ฟอร์มที่ยังไม่ได้กรอก ใช้ค่ากลางใหม่ทันที
        this.msg = 'บันทึกค่ากลางแล้ว · สัญญาใหม่ใช้ค่านี้เป็นค่าเริ่มต้น (สัญญาเก่าไม่เปลี่ยน)';
        this.errs = {};
      } catch (err) {
        this.errs = err.errors ? Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])) : { _: API.describe(err, err.message || 'บันทึกไม่สำเร็จ') };
      } finally {
        this.busy = false;
      }
    },

    ssTypeText() {
      return { day: 'ต่อวัน', week: 'ต่อสัปดาห์', once: 'ครั้งเดียว' }[this.ss.late_fee_type];
    },

    // ── popup บันทึกการคืนเงิน ──
    async openRepay(l) {
      this.errs = {};
      try {
        const res = await API.get(`/api/council/finance/loans/${l.id}`); // รายละเอียดล่าสุดพร้อมตารางงวด (ค่าปรับคำนวณ ณ วันนี้)
        this.loanTarget = res.data;
        this.loanMeta.central = res.meta.central;
      } catch (e) {
        this.error = API.describe(e, 'โหลดข้อมูลสัญญาไม่สำเร็จ');
        return;
      }
      this.rp = { mode: 'partial', amount: '', paid_at: todayIso() < l.loaned_at ? l.loaned_at : todayIso(), note: '', checked: false };
      this.dlg = 'repay';
    },

    setRepayMode(mode) {
      this.rp.mode = mode;
      if (mode === 'full' && this.loanTarget) this.rp.amount = String(this.loanTarget.outstanding);
    },

    get repayAmount() {
      return this.rp.mode === 'full' && this.loanTarget ? this.loanTarget.outstanding : this.num(this.rp.amount);
    },

    /** ยอดคงค้างหลังคืน และกองทุนก่อน → หลัง */
    get repayAfter() {
      const l = this.loanTarget;
      const a = this.repayAmount;
      if (!l || a === null) return null;
      const c = this.loanMeta.central;
      return { left: Math.max(0, l.outstanding - a), fundBefore: c ? c.balance : null, fundAfter: c ? c.balance + a : null };
    },

    /** ค่าปรับของงวดที่เลยกำหนด (วัน × อัตรา) ไว้แสดงเป็นบรรทัดในตารางสรุปของ popup */
    overdueFeeLines(l) {
      return (l.schedule || []).filter((s) => s.late_fee > 0 && !['paid', 'paid_late'].includes(s.status)).map((s) => ({
        no: s.no, days: s.days_late, fee: s.late_fee,
        text: l.late_fee_type === 'day' ? `ค่าปรับเกินกำหนด ${s.days_late} วัน × ${this.money(l.late_fee_value)}` : l.late_fee_type === 'week' ? `ค่าปรับเกินกำหนด ${Math.ceil(s.days_late / 7)} สัปดาห์ × ${this.money(l.late_fee_value)}` : 'ค่าปรับเกินกำหนด (ครั้งเดียว)',
      }));
    },

    async savePay() {
      if (this.busy || !this.loanTarget) return;
      const l = this.loanTarget;
      const er = {};
      const amt = this.repayAmount;
      if (amt === null) er.amount = String(this.rp.amount).trim() ? 'จำนวนเงินต้องเป็นตัวเลขมากกว่า 0' : 'กรุณากรอกจำนวนที่คืน';
      else if (amt > l.outstanding) er.amount = 'จำนวนที่คืนเกินยอดคงค้าง (' + this.money(l.outstanding) + ')';
      if (!this.rp.paid_at) er.paid_at = 'กรุณาเลือกวันที่รับคืน';
      else if (this.rp.paid_at > todayIso()) er.paid_at = 'วันที่รับคืนต้องไม่เกินวันนี้';
      else if (this.rp.paid_at < l.loaned_at) er.paid_at = 'วันที่รับคืนต้องไม่ก่อนวันที่กู้';
      if (!this.rp.checked) er.checked = 'กรุณาติ๊กยืนยันว่าตรวจจำนวนเงินและผู้คืนแล้ว';
      this.errs = er;
      if (Object.keys(er).length) return;

      this.busy = true;
      try {
        await API.post(`/api/council/finance/loans/${l.id}/payments`, { amount: amt, paid_at: this.rp.paid_at, note: this.rp.note.trim() || null });
        this.msg = 'บันทึกการคืน ' + this.money(amt) + ' WVDL แล้ว';
        this.dlg = null;
        this.loanTarget = null;
        await Promise.all([this.loadLoans(), this.load()]);
      } catch (err) {
        this.errs = err.errors ? Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])) : { _: API.describe(err, err.message || 'บันทึกไม่สำเร็จ') };
      } finally {
        this.busy = false;
      }
    },

    // ── รายการแก้ไขต่อท้าย: ยกเลิกสัญญา / ยกเลิกรายการรับคืน ──
    openLoanCancel(l) {
      this.loanTarget = l;
      this.payTarget = null;
      this.reasonText = '';
      this.errs = {};
      this.dlg = 'cancelLoan';
    },

    openPayReverse(l, p) {
      this.loanTarget = l;
      this.payTarget = p;
      this.reasonText = '';
      this.errs = {};
      this.dlg = 'reversePay';
    },

    async submitReason() {
      if (this.busy || !this.loanTarget) return;
      if (!this.reasonText.trim()) {
        this.errs = { reason: 'กรุณาระบุเหตุผล' };
        return;
      }
      const l = this.loanTarget;
      const isPay = this.dlg === 'reversePay' && this.payTarget;
      this.busy = true;
      try {
        await API.post(isPay ? `/api/council/finance/loans/${l.id}/payments/${this.payTarget.id}/reverse` : `/api/council/finance/loans/${l.id}/cancel`, { reason: this.reasonText.trim() });
        this.msg = isPay ? 'บันทึกรายการยกเลิกรับคืนแล้ว (รายการเดิมยังอยู่ในประวัติ)' : 'ยกเลิกสัญญาแล้ว (สัญญาเดิมยังอยู่ในประวัติ)';
        this.dlg = null;
        this.loanTarget = null;
        this.payTarget = null;
        await Promise.all([this.loadLoans(), this.load()]);
      } catch (err) {
        this.errs = err.errors ? Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])) : { _: API.describe(err, err.message || 'บันทึกไม่สำเร็จ') };
      } finally {
        this.busy = false;
      }
    },

    // ───────────────────────── ซื้อ-ขายกับแก๊ง/ครอบครัว ─────────────────────────
    trades: [],
    tradeMeta: { total: 0, page: 1, last_page: 1, summary: null },
    tradeDir: '', // '' | sell | buy
    tradeQ: '',
    tradePage: 1,
    tradesLoaded: false,
    tradeLoading: false,
    tf: blankTrade(),
    tradeTarget: null,
    tradeDirs: [
      { key: '', label: 'ทั้งหมด' },
      { key: 'sell', label: 'สภาขาย (รายรับ)' },
      { key: 'buy', label: 'สภาซื้อ (รายจ่าย)' },
    ],

    async loadTrades(reset = false) {
      if (reset) this.tradePage = 1;
      this.tradeLoading = true;
      this.error = '';
      try {
        const p = new URLSearchParams({ page: this.tradePage });
        if (this.tradeDir) p.set('direction', this.tradeDir);
        if (this.tradeQ.trim()) p.set('q', this.tradeQ.trim());
        const res = await API.get(`/api/council/finance/trades?${p}`);
        this.trades = res.data;
        this.tradeMeta = res.meta;
        this.tradesLoaded = true;
        this.updatedAt = new Date();
      } catch (err) {
        this.error = err.status === 403 ? 'หน้านี้เปิดได้เฉพาะประธานและรองประธานที่เข้าเวรอยู่' : API.describe(err, 'โหลดรายการซื้อ-ขายไม่สำเร็จ');
      } finally {
        this.tradeLoading = false;
      }
    },

    async goTrades(p) {
      if (p < 1 || p > this.tradeMeta.last_page) return;
      this.tradePage = p;
      await this.loadTrades();
    },

    get tradeTotal() {
      const q = this.num(this.tf.quantity);
      const u = this.num(this.tf.unit_price);
      return q !== null && u !== null ? q * u : null;
    },

    openTradeCreate() {
      this.tf = blankTrade();
      this.gq = '';
      this.gresults = [];
      this.errs = {};
      this.dlg = 'trade';
    },

    openTradeEdit(t) {
      this.tf = { id: t.id, group: t.group, direction: t.direction, item: t.item, quantity: String(t.quantity), unit_price: String(t.unit_price), traded_at: t.traded_at, note: t.note || '' };
      this.errs = {};
      this.dlg = 'trade';
    },

    openTradeDelete(t) {
      this.tradeTarget = t;
      this.errs = {};
      this.dlg = 'delTrade';
    },

    async saveTrade() {
      if (this.busy) return;
      const er = {};
      if (!this.tf.id && !this.tf.group) er.group_id = 'กรุณาเลือกแก๊ง/ครอบครัว';
      if (!this.tf.item.trim()) er.item = 'กรุณากรอกสินค้า/บริการ';
      if (this.num(this.tf.quantity) === null) er.quantity = 'จำนวนต้องเป็นตัวเลขมากกว่า 0';
      if (this.num(this.tf.unit_price) === null) er.unit_price = String(this.tf.unit_price).trim() ? 'ราคาต้องเป็นตัวเลขมากกว่า 0' : 'กรุณากรอกราคาต่อหน่วย';
      if (!this.tf.traded_at) er.traded_at = 'กรุณาเลือกวันที่';
      else if (this.tf.traded_at > todayIso()) er.traded_at = 'วันที่ต้องไม่เกินวันนี้';
      this.errs = er;
      if (Object.keys(er).length) return;

      this.busy = true;
      const body = { direction: this.tf.direction, item: this.tf.item.trim(), quantity: this.num(this.tf.quantity), unit_price: this.num(this.tf.unit_price), traded_at: this.tf.traded_at, note: this.tf.note.trim() || null };
      try {
        if (this.tf.id) await API.put(`/api/council/finance/trades/${this.tf.id}`, body);
        else await API.post('/api/council/finance/trades', { ...body, group_id: this.tf.group.id });
        this.msg = this.tf.id ? 'แก้ไขรายการซื้อ-ขายแล้ว' : 'บันทึกรายการซื้อ-ขายแล้ว';
        this.dlg = null;
        await Promise.all([this.loadTrades(), this.load()]);
      } catch (err) {
        this.errs = err.errors ? Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])) : { _: API.describe(err, err.message || 'บันทึกไม่สำเร็จ') };
      } finally {
        this.busy = false;
      }
    },

    async removeTrade() {
      if (this.busy || !this.tradeTarget) return;
      this.busy = true;
      try {
        await API.del(`/api/council/finance/trades/${this.tradeTarget.id}`);
        this.msg = 'ลบรายการซื้อ-ขายแล้ว';
        this.dlg = null;
        await Promise.all([this.loadTrades(), this.load()]);
      } catch (err) {
        this.errs = { _: API.describe(err, err.message || 'ลบไม่สำเร็จ') };
      } finally {
        this.busy = false;
      }
    },

    // ───────────────────────── โบนัสเวร (ตัดรอบ) ─────────────────────────
    // กรอกสูตรครั้งเดียวต่อรอบ ระบบคำนวณทุกคนด้วยสูตรเดียวกัน เช่น (เวลาเข้าเวร ÷ 1440) × paycheck + โบนัสพิเศษ
    // "เวลาเข้าเวร" = นาทีเวรของแต่ละคน (ระบบดึงให้) · ตัวแปรอื่นเป็นค่าเดียวกันทุกคน · ตัวแปรไม่บังคับเว้นว่างได้ (นับเป็น 0)
    bonuses: [],
    bonusMeta: { total: 0, page: 1, last_page: 1, total_paid: 0, open: null, defaults: null, minutes_variable: 'เวลาเข้าเวร', currency: 'WVDL' },
    bonusPage: 1,
    bonusLoaded: false,
    bonusLoading: false,
    bn: { formula: '', variables: [], start_from: '', note: '', checked: false },
    bonusPreview: null, // ผลคำนวณจากเซิร์ฟเวอร์ ณ ตอนนี้
    bonusErrs: {}, // ข้อผิดพลาดของสูตร/ตัวแปรจากเซิร์ฟเวอร์ (formula · variables.N.name · variables.N.value)
    bonusTarget: null, // รอบที่กำลังจะย้อน
    openBonus: null,
    _bnTimer: null,

    async loadBonus(reset = false) {
      if (reset) this.bonusPage = 1;
      this.bonusLoading = true;
      this.error = '';
      try {
        const res = await API.get(`/api/council/finance/bonus?page=${this.bonusPage}`);
        this.bonuses = res.data;
        this.bonusMeta = res.meta;
        if (!this.bonusLoaded) {
          // สูตรและตัวแปรเริ่มต้น = ของรอบล่าสุด (ไม่มีก็ใช้สูตรตั้งต้น)
          const d = res.meta.defaults;
          this.bn.formula = d.formula;
          const saved = d.variables.map((v) => ({ name: v.name, value: v.value === '0' ? '' : this.fmtNum(v.value), optional: !!v.optional }));
          // ตัวแปรหลักสองตัวอยู่หัวรายการเสมอ (ค่าจากรอบก่อน ถ้ามี) ตามด้วยตัวแปรที่เพิ่มเอง
          const core = [{ name: 'paycheck', optional: false }, { name: 'โบนัสพิเศษ', optional: true }].map((c) => saved.find((v) => v.name === c.name) || { ...c, value: '' });
          this.bn.variables = core.concat(saved.filter((v) => !core.some((c) => c.name === v.name)));
          this.bn.start_from = res.meta.open.earliest_shift_date || '';
        }
        this.bonusLoaded = true;
        this.updatedAt = new Date();
      } catch (err) {
        this.error = err.status === 403 ? 'หน้านี้เปิดได้เฉพาะประธานและรองประธานที่เข้าเวรอยู่' : API.describe(err, 'โหลดรายการโบนัสไม่สำเร็จ');
      } finally {
        this.bonusLoading = false;
      }
      await this.loadBonusPreview();
    },

    async goBonus(p) {
      if (p < 1 || p > this.bonusMeta.last_page) return;
      this.bonusPage = p;
      await this.loadBonus();
    },

    get bonusFirst() {
      return !!(this.bonusMeta.open && this.bonusMeta.open.first_round);
    },

    /** ใส่ลูกน้ำคั่นหลักพันให้ตัวเลขที่กรอก (เว้นว่างไว้เมื่อไม่ใช่ตัวเลข) */
    fmtNum(v) {
      const s = String(v ?? '').replace(/,/g, '').trim();
      return /^\d+(\.\d+)?$/.test(s) ? Number(s).toLocaleString('th-TH', { maximumFractionDigits: 6 }) : String(v ?? '');
    },

    bonusBody() {
      const body = { formula: this.bn.formula, variables: this.bn.variables.map((v) => ({ name: v.name.trim(), value: String(v.value ?? '').replace(/,/g, '').trim(), optional: !!v.optional })) };
      if (this.bonusFirst && this.bn.start_from) body.start_from = this.bn.start_from;
      return body;
    },

    /** คำนวณโบนัสของรอบที่เปิดอยู่ ณ ตอนนี้ (เรียกเซิร์ฟเวอร์ ไม่คำนวณเองฝั่งหน้าเว็บ) */
    async loadBonusPreview() {
      try {
        this.bonusPreview = (await API.post('/api/council/finance/bonus/preview', this.bonusBody())).data;
        this.bonusErrs = {};
      } catch (err) {
        this.bonusPreview = null;
        this.bonusErrs = err.errors ? Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])) : { formula: API.describe(err, 'คำนวณโบนัสไม่สำเร็จ') };
      }
    },

    /** แก้สูตร/ตัวแปรแล้วคำนวณใหม่ (รอให้หยุดพิมพ์สักครู่) */
    schedulePreview() {
      this.bn.checked = false;
      clearTimeout(this._bnTimer);
      this._bnTimer = setTimeout(() => this.loadBonusPreview(), 400);
    },

    be(k) {
      return this.bonusErrs[k] || '';
    },

    /** paycheck และโบนัสพิเศษเป็นตัวแปรหลัก ล็อกตำแหน่ง 2 แถวแรก เปลี่ยนชื่อ/ลบไม่ได้ (ตัวแปรที่เพิ่มเองอยู่ถัดลงไป) */
    isCoreVar(i) {
      return i < 2;
    },

    /** เวลาที่ตัดรอบเวลาเข้าเวรไว้ (null = ยังไม่ตัด ยังไม่คิดโบนัส) */
    get bonusCutAt() {
      return this.bonusPreview ? this.bonusPreview.cut_at : null;
    },

    /** ตัดรอบเวลาเข้าเวร: ล็อกนาทีเวรของทุกคนถึงตอนนี้ ให้นาทีเวรคงที่ก่อนคิดโบนัส */
    async dutyCut() {
      if (this.busy) return;
      this.busy = true;
      try {
        await API.post('/api/council/finance/bonus/duty-cut', {});
        await this.loadBonusPreview();
      } catch (err) {
        this.bonusErrs = { formula: API.describe(err, 'ตัดรอบเวลาเข้าเวรไม่สำเร็จ') };
      } finally {
        this.busy = false;
      }
    },

    async clearDutyCut() {
      if (this.busy) return;
      this.busy = true;
      try {
        await API.del('/api/council/finance/bonus/duty-cut');
        await this.loadBonusPreview();
      } catch (err) {
        this.bonusErrs = { formula: API.describe(err, 'ยกเลิกไม่สำเร็จ') };
      } finally {
        this.busy = false;
      }
    },

    /** คัดลอกรูปสัญญาในป๊อปอัป: ให้หน้าสัญญา (iframe) สร้างรูป แล้วเขียนลงคลิปบอร์ดจากหน้านี้ */
    async contractImage() {
      const frame = document.querySelector('.st-contract-frame');
      this.contractImgMsg = '';
      this.contractImgErr = '';
      this.contractImgBusy = true;
      try {
        const page = frame && frame.contentWindow && frame.contentDocument.querySelector('main');
        const d = page && frame.contentWindow.Alpine ? frame.contentWindow.Alpine.$data(page) : null;
        if (!d || !d.cur) throw new Error('not-ready');
        const blob = await d.blob();
        if (!navigator.clipboard || typeof ClipboardItem === 'undefined') throw new Error('clipboard');
        await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
        this.contractImgMsg = 'คัดลอกรูปแล้ว · ไปวางใน Discord ได้เลย';
        setTimeout(() => (this.contractImgMsg = ''), 6000);
      } catch (e) {
        this.contractImgErr = e.message === 'not-ready' ? 'สัญญายังโหลดไม่เสร็จ ลองใหม่อีกครั้ง' : 'คัดลอกรูปไม่สำเร็จ';
      } finally {
        this.contractImgBusy = false;
      }
    },

    /** สร้างลิงก์รูปหนังสือสัญญา (อัปโหลดรูปให้เซิร์ฟเวอร์) แล้วคัดลอกลิงก์ */
    async contractImageLink() {
      const frame = document.querySelector('.st-contract-frame');
      this.contractImgMsg = '';
      this.contractImgErr = '';
      this.contractImgBusy = true;
      try {
        const page = frame && frame.contentWindow && frame.contentDocument.querySelector('main');
        const d = page && frame.contentWindow.Alpine ? frame.contentWindow.Alpine.$data(page) : null;
        if (!d || !d.cur) throw new Error('not-ready');
        const url = await d.shareLink();
        try {
          await navigator.clipboard.writeText(url);
        } catch (e) {
          window.prompt('คัดลอกลิงก์รูปนี้', url); // เบราว์เซอร์ไม่อนุญาตคลิปบอร์ด
        }
        this.contractImgMsg = 'คัดลอกลิงก์รูปแล้ว · วางใน Discord ได้เลย';
        setTimeout(() => (this.contractImgMsg = ''), 6000);
      } catch (e) {
        this.contractImgErr = e.message === 'not-ready' ? 'สัญญายังโหลดไม่เสร็จ ลองใหม่อีกครั้ง' : API.describe(e, 'สร้างลิงก์รูปไม่สำเร็จ');
      } finally {
        this.contractImgBusy = false;
      }
    },

    /** % ที่คืนแล้วของสัญญา (ใช้วาดแถบความคืบหน้า) */
    repaidPct(l) {
      const total = Number(l.total_payable) || 0;
      return total > 0 ? Math.max(0, Math.min(100, Math.round((Number(l.repaid) || 0) / total * 100))) : 0;
    },

    addBonusVar() {
      if (this.bn.variables.length >= 20) return;
      this.bn.variables.push({ name: '', value: '', optional: true });
      this.schedulePreview();
    },

    removeBonusVar(i) {
      if (this.isCoreVar(i)) return;
      this.bn.variables.splice(i, 1);
      this.schedulePreview();
    },

    /** ตัวอย่างบรรทัดสรุปในกล่องสูตร: คนที่นาทีเวรมากสุด */
    get bonusSample() {
      const r = this.bonusPreview && this.bonusPreview.rows.find((x) => x.minutes > 0);
      return r ? `${r.user.display_name} ${this.minutesLabelPlain(r.minutes)} → ${this.money(r.amount)}` : '';
    },

    minutesLabelPlain(m) {
      return Number(m || 0).toLocaleString('th-TH') + ' นาที';
    },

    get bonusPayRows() {
      return this.bonusPreview ? this.bonusPreview.rows.filter((r) => r.amount > 0) : [];
    },

    get bonusPayTotal() {
      return this.bonusPreview ? this.bonusPreview.total : 0;
    },

    get bonusLatest() {
      return this.bonuses.find((b) => b.is_latest) || null;
    },

    hoursLabel(h) {
      return Number(h || 0).toLocaleString('th-TH', { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + ' ชม.';
    },

    minutesLabel(m) {
      return Number(m || 0).toLocaleString('th-TH') + ' นาที';
    },

    /** วันเวลาไทย เช่น 18 พ.ย. 69 20:00 */
    stamp(iso) {
      return iso ? new Intl.DateTimeFormat('th-TH-u-ca-buddhist', { timeZone: BKK, day: 'numeric', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date(iso)).replace(',', '') : '—';
    },

    /** สูตรแบบอ่านง่ายของรอบที่ตัดแล้ว พร้อมค่าตัวแปร เช่น paycheck = 300,000 · โบนัสพิเศษ = 50,000 */
    varsText(vs) {
      return (vs || []).map((v) => v.name + ' = ' + (v.value === '' ? '—' : this.fmtNum(v.value))).join(' · ');
    },

    async openCutoff() {
      this.errs = {};
      this.bn.checked = false;
      await this.loadBonusPreview(); // เปิดหน้าต่างยืนยันด้วยยอดล่าสุด ณ ตอนนี้ (เวลาตัดรอบ = เวลาของตัวอย่างนี้)
      if (this.bonusPreview && this.bonusPayRows.length > 0) this.dlg = 'cutoff';
      else if (this.bonusPreview) this.bonusErrs = { formula: 'ยังไม่มีสมาชิกสภาที่ได้รับโบนัสในรอบนี้ (ตรวจสูตร ตัวแปร และชั่วโมงเวร)' };
    },

    openRevert(r) {
      this.bonusTarget = r || this.bonusLatest;
      if (!this.bonusTarget) return;
      this.errs = {};
      this.dlg = 'revertBonus';
    },

    async cutoff() {
      if (this.busy || !this.bonusPreview) return;
      if (!this.bn.checked) {
        this.errs = { checked: 'กรุณาติ๊กยืนยันว่าตรวจรายชื่อและสูตรแล้ว' };
        return;
      }
      this.busy = true;
      try {
        await API.post('/api/council/finance/bonus/cutoff', { ...this.bonusBody(), note: this.bn.note.trim() || null });
        this.msg = 'ตัดรอบโบนัสเวรแล้ว · จ่าย ' + this.money(this.bonusPayTotal) + ' WVDL ให้ ' + this.bonusPayRows.length + ' คน';
        this.dlg = null;
        this.bn.note = '';
        this.bn.checked = false;
        await Promise.all([this.loadBonus(true), this.load()]);
      } catch (err) {
        this.errs = err.errors ? Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])) : { _: API.describe(err, err.message || 'ตัดรอบไม่สำเร็จ') };
        if (this.errs.cut) this.errs._ = this.errs.cut;
        if (err.errors && err.errors.cut) await this.loadBonusPreview();
      } finally {
        this.busy = false;
      }
    },

    async revertBonus() {
      if (this.busy || !this.bonusTarget) return;
      this.busy = true;
      try {
        await API.del(`/api/council/finance/bonus/rounds/${this.bonusTarget.id}`);
        this.msg = 'ย้อนรอบล่าสุดแล้ว';
        this.dlg = null;
        this.bonusTarget = null;
        this.bonusLoaded = false; // ให้สูตร/วันเริ่มนับโหลดใหม่ตามรอบที่เหลือ
        await Promise.all([this.loadBonus(true), this.load()]);
      } catch (err) {
        this.errs = err.errors ? Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])) : { _: API.describe(err, err.message || 'ย้อนรอบไม่สำเร็จ') };
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
