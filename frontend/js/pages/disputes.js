// ระบบบันทึกข้อพิพาท (disputes.html): สภาทุกยศดู · เข้าเวรจึงบันทึก/แก้ไข · ประธานลบ
function disputesPage() {
  const MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

  // ค่าเริ่มต้นของช่องวันเวลา = ตอนนี้ (เวลาไทย) รูปแบบ YYYY-MM-DDTHH:mm
  const nowTh = () => {
    const p = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Bangkok', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date());
    return p.replace(' ', 'T');
  };
  const toTh = (iso) => {
    if (!iso) return '';
    const p = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Bangkok', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date(iso));
    return p.replace(' ', 'T');
  };
  const blank = () => ({ show: false, id: null, title: '', occurred_at: nowTh(), details: '', status: 'open', group_ids: [], council_ids: [], images: [], videos: [], err: '' });

  return {
    auth: null,
    ready: false,
    loading: false,
    busy: false,
    error: '',
    rows: [],
    meta: null,
    statuses: [],
    page: 1,
    q: '',
    status: '',
    groups: [],
    council: [],
    gq: '',
    cq: '',
    form: blank(),

    async init() {
      this.auth = authState();
      await this.auth.require('trainee', { also: ['admin'], offDutyOk: true });
      this.ready = true;
      await Promise.all([this.load(), this.loadOptions()]);
    },

    get canWrite() {
      const a = this.auth;
      return !!a && a.can('trainee') && !a.isAdmin && a.dutyOk;
    },

    // สภาที่ยังไม่เข้าเวร: บอกว่าต้องเข้าเวรก่อนจึงจะบันทึกได้ (Admin เป็นผู้ดูอย่างเดียว)
    get canWriteHint() {
      const a = this.auth;
      return !!a && a.can('trainee') && !a.isAdmin;
    },

    get isPresident() {
      return !!this.auth && this.auth.can('president') && !this.auth.isAdmin;
    },

    async load() {
      this.loading = true;
      this.error = '';
      try {
        const params = new URLSearchParams({ page: this.page, per_page: 10 });
        if (this.q.trim()) params.set('q', this.q.trim());
        if (this.status) params.set('status', this.status);
        const res = await API.get('/api/council/disputes?' + params);
        this.rows = res.data;
        this.meta = res.meta;
        this.statuses = res.statuses;
      } catch (e) {
        this.error = API.describe(e, 'โหลดข้อพิพาทไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
      } finally {
        this.loading = false;
      }
    },

    async loadOptions() {
      try {
        const res = await API.get('/api/council/disputes/options');
        this.groups = res.groups;
        this.council = res.council;
      } catch (e) {}
    },

    search() {
      this.page = 1;
      this.load();
    },

    go(p) {
      if (p === '…') return;
      this.page = p;
      this.load();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    },

    stamp(iso) {
      if (!iso) return '—';
      const d = new Date(new Date(iso).toLocaleString('en-US', { timeZone: 'Asia/Bangkok' }));
      const hm = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
      return `${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear() + 543} ${hm} น.`;
    },

    fileUrl(f) {
      return (window.APP_CONFIG.API_URL || '') + f.url;
    },

    get shownGroups() {
      const q = this.gq.trim().toLowerCase();
      return this.groups.filter((g) => !q || (g.name + ' ' + (g.short_name || '')).toLowerCase().includes(q));
    },

    get shownCouncil() {
      const q = this.cq.trim().toLowerCase();
      return this.council.filter((c) => !q || (c.name + ' ' + c.role_label).toLowerCase().includes(q));
    },

    toggle(list, id) {
      const i = list.indexOf(id);
      if (i >= 0) list.splice(i, 1);
      else list.push(id);
    },

    openNew() {
      this.gq = this.cq = '';
      this.form = { ...blank(), show: true };
    },

    openEdit(r) {
      this.gq = this.cq = '';
      this.form = { show: true, id: r.id, title: r.title, occurred_at: toTh(r.occurred_at), details: r.details, status: r.status, group_ids: r.groups.map((g) => g.id), council_ids: r.council.map((c) => c.id), images: [], videos: [], err: '' };
    },

    async save() {
      if (this.busy) return;
      const f = this.form;
      f.err = '';
      if (!f.title.trim()) return (f.err = 'กรุณาระบุหัวข้อ');
      if (!f.occurred_at) return (f.err = 'กรุณาระบุวันเวลาที่เกิดขึ้น');
      if (!f.details.trim()) return (f.err = 'กรุณาบันทึกรายละเอียดเหตุการณ์');
      if (f.group_ids.length === 0) return (f.err = 'กรุณาเลือกผู้มีข้อพิพาทอย่างน้อย 1 ราย');
      if (f.council_ids.length === 0) return (f.err = 'กรุณาเลือกสภาผู้อยู่ในเหตุการณ์อย่างน้อย 1 คน');

      const fd = new FormData();
      fd.append('title', f.title.trim());
      fd.append('occurred_at', f.occurred_at);
      fd.append('details', f.details.trim());
      fd.append('status', f.status);
      f.group_ids.forEach((id) => fd.append('group_ids[]', id));
      f.council_ids.forEach((id) => fd.append('council_ids[]', id));
      f.images.forEach((file) => fd.append('images[]', file));
      f.videos.forEach((file) => fd.append('videos[]', file));

      this.busy = true;
      try {
        if (f.id) await API.post('/api/council/disputes/' + f.id, fd);
        else await API.post('/api/council/disputes', fd);
        f.show = false;
        await this.load();
      } catch (e) {
        f.err = (e.errors && Object.values(e.errors)[0][0]) || API.describe(e, 'บันทึกไม่สำเร็จ');
      } finally {
        this.busy = false;
      }
    },

    async toggleStatus(r) {
      try {
        await API.put('/api/council/disputes/' + r.id, { status: r.status === 'closed' ? 'open' : 'closed' });
        await this.load();
      } catch (e) {
        await UI.alert({ kind: 'warn', title: 'ทำไม่สำเร็จ', message: API.describe(e, 'กรุณาลองใหม่อีกครั้ง') });
      }
    },

    async remove(r) {
      const ok = await UI.confirm({ title: 'ลบข้อพิพาทนี้?', message: `${r.number} พร้อมหลักฐานทั้งหมดจะถูกลบ`, confirmText: 'ลบ' });
      if (!ok) return;
      try {
        await API.del('/api/council/disputes/' + r.id);
        await this.load();
      } catch (e) {
        await UI.alert({ kind: 'warn', title: 'ทำไม่สำเร็จ', message: API.describe(e, 'กรุณาลองใหม่อีกครั้ง') });
      }
    },

    async removeFile(r, f) {
      const ok = await UI.confirm({ title: 'ลบไฟล์หลักฐานนี้?', message: f.name, confirmText: 'ลบ' });
      if (!ok) return;
      try {
        await API.del(`/api/council/disputes/${r.id}/files/${f.id}`);
        await this.load();
      } catch (e) {
        await UI.alert({ kind: 'warn', title: 'ทำไม่สำเร็จ', message: API.describe(e, 'กรุณาลองใหม่อีกครั้ง') });
      }
    },
  };
}
