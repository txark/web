function usersPage() {
  const MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
  const STATUS = {
    active: { label: 'ใช้งาน', cls: 'badge badge-normal' },
    pending: { label: 'รอเข้าระบบครั้งแรก', cls: 'badge badge-blacklist' },
    disabled: { label: 'ถูกระงับ', cls: 'badge badge-sanction' },
  };

  return {
    ready: false,
    me: null,

    // รายการ
    rows: [],
    meta: null,
    assignable: [],
    q: '',
    status: '',
    role: '',
    page: 1,
    loading: true,
    error: '',

    // ฟอร์มเพิ่ม
    form: { discord_id: '', character_name: '', role: '' },
    formErrors: {},
    saving: false,
    flash: '',

    statuses: [
      { value: '', label: 'ทั้งหมด' },
      { value: 'active', label: 'ใช้งาน' },
      { value: 'pending', label: 'รอเข้าระบบ' },
      { value: 'disabled', label: 'ถูกระงับ' },
    ],

    async init() {
      const auth = authState();
      await auth.require('vice_president');
      this.me = auth.user;
      this.ready = true;
      await this.load();
      for (const k of ['q', 'status', 'role']) this.$watch(k, () => this.reload());
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
      if (this.q.trim()) p.set('q', this.q.trim());
      if (this.status) p.set('status', this.status);
      if (this.role) p.set('role', this.role);

      try {
        const res = await API.get(`/api/users?${p}`);
        this.rows = res.data;
        this.meta = res.meta;
        this.assignable = res.assignable_roles;
        if (!this.form.role && this.assignable.length) {
          // ค่าเริ่มต้นของฟอร์ม: บทบาทต่ำสุดที่กำหนดได้ (ปลอดภัยที่สุด)
          this.form.role = this.assignable[this.assignable.length - 1].value;
        }
      } catch (e) {
        this.rows = [];
        this.error = e.status === 403 ? 'คุณไม่มีสิทธิ์จัดการผู้ใช้' : API.describe(e, 'โหลดรายชื่อผู้ใช้ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
      } finally {
        this.loading = false;
      }
    },

    // ── เพิ่มผู้ใช้ ──
    async add() {
      this.saving = true;
      this.formErrors = {};
      this.flash = '';
      try {
        const res = await API.post('/api/users', {
          discord_id: this.form.discord_id.trim(),
          character_name: this.form.character_name.trim(),
          role: this.form.role,
        });
        this.flash = `เพิ่ม ${res.data.character_name} เป็น ${res.data.role_label} แล้ว — เข้าสู่ระบบด้วย Discord ได้ทันที`;
        this.form.discord_id = '';
        this.form.character_name = '';
        await this.load();
      } catch (e) {
        if (e.status === 422 && e.errors) {
          this.formErrors = Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]]));
        } else {
          this.formErrors = { _: e.message || 'เพิ่มผู้ใช้ไม่สำเร็จ' };
        }
      } finally {
        this.saving = false;
      }
    },

    // ── แก้ไขบทบาท / ระงับ / ลบ ──
    async changeRole(u, value, el) {
      const target = this.assignable.find((r) => r.value === value);
      if (!target || value === u.role) return;
      if (!(await UI.ask(`เปลี่ยนบทบาทของ ${u.character_name || u.name} จาก ${u.role_label} เป็น ${target.label} (${target.name}) ?`, { confirmText: 'เปลี่ยนบทบาท' }))) {
        el.value = u.role; // ยกเลิก: คืนค่าเดิมในช่องเลือก
        return;
      }
      await this.patch(u, { role: value }, el);
    },

    async toggleDisabled(u) {
      const off = u.status !== 'disabled';
      const msg = off
        ? `ระงับสิทธิ์ ${u.character_name || u.name} ?\nจะถูกเตะออกจากระบบทันทีและเข้าสู่ระบบไม่ได้จนกว่าจะเปิดใช้งานอีกครั้ง`
        : `เปิดใช้งาน ${u.character_name || u.name} อีกครั้ง ?`;
      if ((await UI.ask(msg, { kind: off ? 'danger' : 'warn', confirmText: off ? 'ระงับ' : 'เปิดใช้งาน' }))) await this.patch(u, { disabled: off });
    },

    async patch(u, body, el) {
      this.error = '';
      try {
        const res = await API.patch(`/api/users/${u.id}`, body);
        Object.assign(u, res.data);
      } catch (e) {
        if (el) el.value = u.role;
        this.error = (e.errors && Object.values(e.errors)[0][0]) || e.message || 'บันทึกไม่สำเร็จ';
        window.scrollTo({ top: 0, behavior: 'smooth' });
      }
    },

    async remove(u) {
      if (!(await UI.ask(`ลบ ${u.character_name || u.name} ออกจากรายชื่อ ?\n(ลบได้เฉพาะคนที่ยังไม่เคยเข้าระบบ)`, { kind: 'danger', confirmText: 'ลบ' }))) return;
      try {
        await API.del(`/api/users/${u.id}`);
        await this.load();
      } catch (e) {
        this.error = e.message || 'ลบไม่สำเร็จ';
        window.scrollTo({ top: 0, behavior: 'smooth' });
      }
    },

    async copy(text, ev) {
      try {
        await navigator.clipboard.writeText(text);
        const old = ev.target.textContent;
        ev.target.textContent = 'คัดลอกแล้ว ✓';
        setTimeout(() => (ev.target.textContent = old), 1200);
      } catch (e) {}
    },

    // ── แสดงผล ──
    statusInfo(s) {
      return STATUS[s] || { label: s, cls: 'badge badge-muted' };
    },

    when(iso) {
      const d = new Date(iso);
      if (!iso || isNaN(d)) return '';
      const hh = String(d.getHours()).padStart(2, '0');
      const mm = String(d.getMinutes()).padStart(2, '0');
      return `${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear() + 543} ${hh}:${mm}`;
    },

    initial(u) {
      return (u.character_name || u.name || '?').trim().charAt(0).toUpperCase();
    },
  };
}
