// จัดการกฎ (rules-admin.html): ประธาน/รองประธานแก้ เพิ่ม ซ่อนกฎ · เปิด/ปิดซิงก์อัตโนมัติจากเว็บทางการ
function rulesAdminPage() {
  const blank = () => ({ show: false, id: null, code: '', is_local: false, section: 'visit', title: '', body: '', note: '', penalty: '', err: '' });

  return {
    ready: false,
    loading: false,
    busy: false,
    error: '',
    sections: [],
    meta: null,
    q: '',
    sec: '',
    mark: '',
    form: blank(),

    async init() {
      await authState().require('vice_president');
      this.ready = true;
      await this.load();
    },

    async load() {
      this.loading = true;
      this.error = '';
      try {
        const res = await API.get('/api/council/rules');
        this.sections = res.data;
        this.meta = res.meta;
      } catch (e) {
        this.error = API.describe(e, 'โหลดกฎไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
      } finally {
        this.loading = false;
      }
    },

    // รายการที่ผ่านตัวกรอง จัดตามหมวด (ซ่อนหมวดที่ไม่มีรายการ)
    get shown() {
      const q = this.q.trim().toLowerCase();
      return this.sections
        .filter((s) => !this.sec || s.key === this.sec)
        .map((s) => ({
          ...s,
          rules: s.rules.filter((r) => {
            if (this.mark === 'edited' && !r.edited) return false;
            if (this.mark === 'local' && !r.is_local) return false;
            if (this.mark === 'hidden' && !r.hidden) return false;
            if (!q) return true;
            return [r.code, r.title, r.body, r.note, r.penalty].some((v) => (v || '').toLowerCase().includes(q));
          }),
        }))
        .filter((s) => s.rules.length > 0);
    },

    get shownCount() {
      return this.shown.reduce((n, s) => n + s.rules.length, 0);
    },

    openNew() {
      this.form = { ...blank(), show: true, section: this.sec && this.sec !== 'fees' ? this.sec : 'visit' };
    },

    openEdit(r) {
      this.form = { show: true, id: r.id, code: r.code, is_local: r.is_local, section: r.section, title: r.title || '', body: r.body || '', note: r.note || '', penalty: r.penalty || '', err: '' };
    },

    async save() {
      if (this.busy) return;
      const f = this.form;
      f.err = '';
      this.busy = true;
      const body = { title: f.title.trim(), body: f.body.trim() || null, note: f.note.trim() || null, penalty: f.penalty.trim() || null };
      try {
        if (f.id) await API.put('/api/council/rules/' + f.id, body);
        else await API.post('/api/council/rules', { section: f.section, ...body });
        f.show = false;
        await this.load();
      } catch (e) {
        f.err = (e.errors && Object.values(e.errors)[0][0]) || e.message || 'บันทึกไม่สำเร็จ';
      } finally {
        this.busy = false;
      }
    },

    async remove(r) {
      const local = r.is_local;
      const ok = await UI.confirm({
        title: local ? 'ลบข้อกฎนี้?' : 'ซ่อนข้อกฎนี้?',
        message: local ? `${r.code} จะถูกลบถาวร` : `${r.code} จะไม่แสดงในหน้ากฎ และไม่กลับมาตอนซิงก์ (กด "คืนค่าตามเว็บทางการ" เพื่อกู้คืน)`,
        confirmText: local ? 'ลบ' : 'ซ่อน',
      });
      if (!ok) return;
      try {
        await API.del('/api/council/rules/' + r.id);
        await this.load();
      } catch (e) {
        await UI.alert({ kind: 'warn', title: 'ทำไม่สำเร็จ', message: e.message || 'กรุณาลองใหม่อีกครั้ง' });
      }
    },

    async restore(r) {
      const ok = await UI.confirm({ title: 'คืนค่าตามเว็บทางการ?', message: `${r.code} จะกลับเป็นข้อความตามเว็บทางการ การแก้ไขของคุณจะหายไป`, confirmText: 'คืนค่า' });
      if (!ok) return;
      try {
        await API.post('/api/council/rules/' + r.id + '/restore');
        await this.load();
      } catch (e) {
        await UI.alert({ kind: 'warn', title: 'ทำไม่สำเร็จ', message: e.message || 'กรุณาลองใหม่อีกครั้ง' });
      }
    },

    async toggleSync(on) {
      this.busy = true;
      try {
        const res = await API.put('/api/council/rules/settings', { auto_sync: !!on });
        this.meta = { ...this.meta, auto_sync: res.data.auto_sync };
      } catch (e) {
        await UI.alert({ kind: 'warn', title: 'ตั้งค่าไม่สำเร็จ', message: e.message || 'กรุณาลองใหม่อีกครั้ง' });
        await this.load();
      } finally {
        this.busy = false;
      }
    },
  };
}
