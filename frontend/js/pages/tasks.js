function tasksPage() {
  const MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
  const SEV = {
    high: { label: 'เร่งด่วน', cls: 'badge badge-sanction' },
    medium: { label: 'ควรทำ', cls: 'badge badge-blacklist' },
    low: { label: 'ทบทวน', cls: 'badge badge-muted' },
  };

  return {
    ready: false,
    items: [],
    loading: true,
    error: '',
    filter: '',
    copiedId: '',
    cat: '', // กลุ่มหมวดที่เลือก (ดัชนีใน groups; '' = ทั้งหมด)
    kind: '', // หมวดย่อยที่เลือก ('' = ทั้งกลุ่ม)
    // sidebar: หมวดของคำขอ/ธุรกรรมที่รออนุมัติ + งานตรวจสอบ
    sections: [
      { title: '', items: [{ value: '', icon: 'list-checks', label: 'ทั้งหมด' }] },
      {
        title: 'คำขอและธุรกรรมรออนุมัติ',
        items: [
          { value: 'register', icon: 'user-plus', label: 'ขึ้นทะเบียน' },
          { value: 'leader', icon: 'crown', label: 'เปลี่ยน Leader / Co-Leader' },
          { value: 'outfit', icon: 'shirt', label: 'ลงชุด / แก้ไขชุด' },
          { value: 'rename', icon: 'image', label: 'เปลี่ยนชื่อ / โลโก้' },
          { value: 'recolor', icon: 'palette', label: 'เปลี่ยนสี' },
          { value: 'txn_other', icon: 'receipt', label: 'ธุรกรรมอื่น ๆ' },
        ],
      },
      {
        title: 'บันทึก',
        items: [{ value: 'reminder', icon: 'sticky-note', label: 'เตือนความจำ' }],
      },
      {
        title: 'ตรวจสอบ',
        items: [
          { value: 'group', icon: 'users', label: 'แก๊ง/ครอบครัว' },
          { value: 'activity', icon: 'activity', label: 'รายงานความเคลื่อนไหว' },
          { value: 'loan', icon: 'banknote', label: 'เงินกู้' },
        ],
      },
    ],
    filters: [
      { value: '', label: 'ทั้งหมด' },
      { value: 'high', label: 'เร่งด่วน' },
      { value: 'medium', label: 'ควรทำ' },
      { value: 'low', label: 'ทบทวน' },
    ],

    async init() {
      const auth = authState();
      await auth.require('member');
      this.me = auth.user;
      this.ready = true;
      await this.load();
    },

    async load() {
      this.loading = true;
      this.error = '';
      try {
        this.items = (await API.get('/api/council/alerts')).data;
      } catch (e) {
        this.items = [];
        this.error = e.status === 403 ? 'คุณไม่มีสิทธิ์ดูงานค้าง' : API.describe(e, 'โหลดงานค้างไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
      } finally {
        this.loading = false;
      }
    },

    // ── บันทึกเตือนความจำ (ใช้ร่วมกันทั้งสภา) ──
    me: null,
    showReminder: false,
    rem: { title: '', body: '', due_on: '' },
    remErr: {},
    remBusy: false,
    busyId: null,

    openReminderForm() {
      this.kind = 'reminder';
      this.showReminder = true;
      this.rem = { title: '', body: '', due_on: '' };
      this.remErr = {};
    },

    async addReminder() {
      this.remBusy = true;
      this.remErr = {};
      try {
        await API.post('/api/council/reminders', { title: this.rem.title.trim(), body: this.rem.body.trim() || null, due_on: this.rem.due_on || null });
        this.showReminder = false;
        await this.load();
      } catch (e) {
        this.remErr = e.errors ? Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])) : { title: e.message || 'บันทึกไม่สำเร็จ' };
      } finally {
        this.remBusy = false;
      }
    },

    async doneReminder(a) {
      this.busyId = a.id;
      try {
        await API.post(`/api/council/reminders/${a.reminder_id}/done`);
        await this.load();
      } catch (e) {
        this.error = API.describe(e, 'ทำเครื่องหมายไม่สำเร็จ');
      } finally {
        this.busyId = null;
      }
    },

    async deleteReminder(a) {
      if (!(await UI.ask(`ลบบันทึกเตือนความจำ "${a.title}" ?`, { kind: 'danger', confirmText: 'ลบ' }))) return;
      this.busyId = a.id;
      try {
        await API.del(`/api/council/reminders/${a.reminder_id}`);
        await this.load();
      } catch (e) {
        this.error = API.describe(e, 'ลบไม่สำเร็จ');
      } finally {
        this.busyId = null;
      }
    },

    /** ลบได้เฉพาะผู้สร้างหรือรองประธานขึ้นไป */
    canDeleteReminder(a) {
      return !!this.me && (a.created_by === this.me.id || ['vice_president', 'president'].includes(this.me.role));
    },

    isLoan(a) {
      return a.type.startsWith('loan_');
    },

    /** หมวดใน sidebar ของงานค้างนี้ */
    catOf(a) {
      if (a.type === 'reminder') return 'reminder';
      if (this.isLoan(a)) return 'loan';
      if (a.type.startsWith('txn_')) {
        return { register: 'register', activity_report: 'activity', change_leader: 'leader', rename: 'rename', recolor: 'recolor', outfit: 'outfit', outfit_add: 'outfit', outfit_counts: 'outfit', outfit_file: 'outfit' }[a.kind] || 'txn_other';
      }
      return 'group';
    },

    /** รายการตามหมวดที่เลือก */
    get byKind() {
      if (this.kind) return this.items.filter((a) => this.catOf(a) === this.kind);
      if (this.cat !== '') return this.items.filter((a) => this.inGroup(this.groups[this.cat], a));
      return this.items;
    },

    /** กลุ่มหมวด (ไม่รวม "ทั้งหมด"): คำขอและธุรกรรม · บันทึก · ตรวจสอบ */
    get groups() {
      const meta = [
        { short: 'คำขอและธุรกรรม', icon: 'inbox' },
        { short: 'บันทึก', icon: 'sticky-note' },
        { short: 'ตรวจสอบ', icon: 'shield' },
      ];
      return this.sections.slice(1).map((g, i) => ({ ...g, ...meta[i] }));
    },

    inGroup(g, a) {
      return g.items.some((k) => k.value === this.catOf(a));
    },

    countGroup(g) {
      return this.items.filter((a) => this.inGroup(g, a)).length;
    },

    setCat(i) {
      this.cat = i;
      this.kind = '';
    },

    get shown() {
      return this.filter ? this.byKind.filter((a) => a.severity === this.filter) : this.byKind;
    },

    countOf(value) {
      return value ? this.byKind.filter((a) => a.severity === value).length : this.byKind.length;
    },

    countKind(value) {
      return value ? this.items.filter((a) => this.catOf(a) === value).length : this.items.length;
    },

    /** คัดลอกข้อความทวง (สัญญากู้เงินเกินกำหนด/ใกล้ครบกำหนด) ไปวางส่งเอง ไม่ใช้ Discord bot */
    async copyText(a) {
      try {
        await navigator.clipboard.writeText(a.copy_text);
      } catch (e) {
        const t = document.createElement('textarea'); // เบราว์เซอร์ที่ไม่อนุญาต clipboard API
        t.value = a.copy_text;
        t.style.position = 'fixed';
        t.style.opacity = '0';
        document.body.appendChild(t);
        t.select();
        document.execCommand('copy');
        t.remove();
      }
      this.copiedId = a.id;
      setTimeout(() => { if (this.copiedId === a.id) this.copiedId = ''; }, 2500);
    },

    sev(s) {
      return SEV[s] || SEV.low;
    },

    when(iso) {
      const d = new Date(iso);
      if (!iso || isNaN(d)) return '-';
      return `${d.getDate()} ${MONTHS[d.getMonth()]} ${String(d.getFullYear() + 543).slice(-2)}`;
    },
  };
}
