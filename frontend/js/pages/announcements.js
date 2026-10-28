function announcementsPage() {
  const MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
  const TYPE_BADGE = { general: 'badge badge-info', summon: 'badge badge-sanction', event: 'badge badge-normal' };
  const pad = (n) => String(n).padStart(2, '0');
  const firstError = (e, fallback) => (e.errors && Object.values(e.errors)[0][0]) || e.message || fallback;

  // ISO → ค่าของ <input type="datetime-local"> (เวลาท้องถิ่น)
  const toLocalInput = (iso) => {
    const d = new Date(iso);
    if (!iso || isNaN(d)) return '';
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  };

  const blank = () => ({ id: null, type: 'general', title: '', body: '', group_id: '', due_at: '', is_published: true });

  return {
    ready: false,
    isCouncil: false,
    me: null,

    items: [],
    meta: null,
    page: 1,
    type: '',
    status: '',
    loading: true,
    error: '',
    msg: '',

    types: [
      { value: '', label: 'ทั้งหมด' },
      { value: 'general', label: 'ประกาศทั่วไป' },
      { value: 'summon', label: 'ประกาศเรียก' },
      { value: 'event', label: 'กิจกรรม' },
    ],
    statuses: [
      { value: '', label: 'ทุกสถานะ' },
      { value: 'published', label: 'เผยแพร่แล้ว' },
      { value: 'draft', label: 'ฉบับร่าง' },
    ],

    // ฟอร์ม (สภาเท่านั้น)
    showForm: false,
    form: blank(),
    formErrors: {},
    saving: false,
    groups: [],

    async init() {
      const auth = authState();
      await auth.require('member'); // เฉพาะสภา และต้องเข้าเวร (ไม่ใช่ → ถูกส่งกลับหน้าแรก)
      this.me = auth.user;
      this.isCouncil = true;
      this.ready = true;
      await this.load();
      this.loadGroups();
      for (const k of ['type', 'status']) this.$watch(k, () => this.reload());
    },

    reload() {
      this.page = 1;
      this.load();
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
      if (this.status) p.set('status', this.status);

      try {
        const res = await API.get(`/api/council/announcements?${p}`);
        this.items = res.data;
        this.meta = res.meta;
      } catch (e) {
        this.items = [];
        this.error = API.describe(e, 'โหลดประกาศไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
      } finally {
        this.loading = false;
      }
    },

    async loadGroups() {
      try {
        this.groups = (await API.get('/api/council/groups?per_page=100')).data;
      } catch (e) {}
    },

    // ── สร้าง / แก้ไข ──
    newForm() {
      this.form = blank();
      this.formErrors = {};
      this.showForm = true;
      this.msg = '';
    },

    edit(a) {
      this.form = {
        id: a.id,
        type: a.type,
        title: a.title,
        body: a.body,
        group_id: a.group ? a.group.id : '',
        due_at: toLocalInput(a.due_at),
        is_published: a.is_published,
      };
      this.formErrors = {};
      this.showForm = true;
      this.msg = '';
      window.scrollTo({ top: 0, behavior: 'smooth' });
    },

    async save(publish) {
      this.saving = true;
      this.formErrors = {};
      const f = this.form;
      const body = {
        type: f.type,
        title: f.title.trim(),
        body: f.body.trim(),
        group_id: f.group_id || null,
        due_at: f.due_at ? new Date(f.due_at).toISOString() : null,
        is_published: publish,
      };
      try {
        if (f.id) await API.put(`/api/council/announcements/${f.id}`, body);
        else await API.post('/api/council/announcements', body);
        this.msg = publish ? 'เผยแพร่ประกาศแล้ว' : 'บันทึกเป็นฉบับร่างแล้ว';
        this.showForm = false;
        this.page = 1;
        await this.load();
      } catch (e) {
        if (e.status === 422 && e.errors) {
          this.formErrors = Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]]));
        } else {
          this.formErrors = { _: e.message || 'บันทึกไม่สำเร็จ' };
        }
      } finally {
        this.saving = false;
      }
    },

    async toggleStatus(a) {
      const publish = !a.is_published;
      try {
        await API.put(`/api/council/announcements/${a.id}`, {
          type: a.type,
          title: a.title,
          body: a.body,
          group_id: a.group ? a.group.id : null,
          due_at: a.due_at,
          is_published: publish,
        });
        this.msg = publish ? 'เผยแพร่แล้ว' : 'ถอนกลับเป็นฉบับร่างแล้ว';
        await this.load();
      } catch (e) {
        this.error = firstError(e, 'บันทึกไม่สำเร็จ');
      }
    },

    async remove(a) {
      if (!(await UI.ask(`ลบประกาศ "${a.title}" ?`, { kind: 'danger', confirmText: 'ลบประกาศ' }))) return;
      try {
        await API.del(`/api/council/announcements/${a.id}`);
        this.msg = 'ลบประกาศแล้ว';
        await this.load();
      } catch (e) {
        this.error = firstError(e, 'ลบไม่สำเร็จ');
      }
    },

    canDelete(a) {
      return !!this.me && (a.created_by === this.me.id || ['vice_president', 'president', 'admin'].includes(this.me.role));
    },

    // ── แสดงผล ──
    badge(t) {
      return TYPE_BADGE[t] || 'badge badge-muted';
    },

    when(iso, withTime = false) {
      const d = new Date(iso);
      if (!iso || isNaN(d)) return '';
      const date = `${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear() + 543}`;
      return withTime ? `${date} ${pad(d.getHours())}:${pad(d.getMinutes())} น.` : date;
    },
  };
}
