const KINDS = {
  gang: {
    type: 'gang', lady: false, min: 10, max: 25, noun: 'แก๊ง', noun_full: 'แก๊ง',
    allowed: ['สูท', 'เกราะ', 'เสื้อโปโล', 'ฮูด', 'เสื้อยืด', 'ซองปืน', 'ชุด MOD 2 ชิ้น', 'Accessories 1 ชิ้น'],
  },
  lady: {
    type: 'gang', lady: true, min: 10, max: 30, noun: 'แก๊ง', noun_full: 'แก๊ง',
    allowed: ['สูท', 'เกราะ', 'เสื้อโปโล', 'ฮูด', 'เสื้อยืด', 'ซองปืน', 'ชุด MOD 2 ชิ้น', 'Accessories 1 ชิ้น'],
  },
  family: {
    type: 'family', lady: false, min: 6, max: 10, noun: 'ครอบครัว', noun_full: 'ครอบครัว',
    allowed: ['ฮูด', 'เสื้อยืด', 'เสื้อโปโล', 'Accessories 1 ชิ้น'],
  },
};
const blankPerson = () => ({ key: Math.random().toString(36).slice(2), ic_name: '', ic_phone: '', discord_id: '' });
const blankForm = () => ({
  kind: 'gang', declared_members: '', name: '', short_name: '', color_hex: '', color_name: '',
  leader: blankPerson(), co: [blankPerson()],
  temp_outfit_male: '', temp_outfit_female: '', protect: 'no',
});

function manageGroupsPage() {
  const MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
  const BADGE = { normal: 'badge badge-normal', blacklist: 'badge badge-blacklist', sanction: 'badge badge-sanction' };
  const firstError = (e, fallback) => (e.errors && Object.values(e.errors)[0][0]) || e.message || fallback;

  return {
    ready: false,
    me: null,

    // รายการ
    rows: [],
    meta: null,
    q: '',
    type: '',
    status: '',
    page: 1,
    loading: true,
    error: '',

    types: [
      { value: '', label: 'ทั้งหมด' },
      { value: 'gang', label: 'แก๊ง' },
      { value: 'family', label: 'ครอบครัว' },
    ],
    statuses: [
      { value: '', label: 'ทุกสถานะ' },
      { value: 'normal', label: 'ปกติ' },
      { value: 'blacklist', label: 'Blacklist' },
      { value: 'sanction', label: 'คว่ำบาตร' },
    ],
    positions: [
      { value: 'leader', label: 'หัวหน้า' },
      { value: 'co_leader', label: 'รองหัวหน้า' },
      { value: 'member', label: 'สมาชิก' },
    ],

    // สร้างกลุ่ม
    showCreate: false,
    f: blankForm(),
    files: {},
    createErrors: {},
    creating: false,
    maxCo: 2,

    // รายละเอียด
    g: null, // กลุ่มที่เปิดอยู่
    msg: '',
    err: '',
    busy: false,
    edit: { name: '', is_lady: false, description: '', logo_url: '', skip_cooldown: false },
    statusForm: { status: 'normal', reason: '' },
    lockReason: '',
    newMember: { character_name: '', position: 'member', is_reserve: false, skip_cooldown: false },
    showHistory: false,

    async init() {
      const auth = authState();
      await auth.require('member');
      this.me = auth.user;
      this.ready = true;
      await this.load();
      // ลิงก์จากหน้าแจ้งเตือนงานค้าง: /manage-groups.html?id=12
      const id = new URLSearchParams(location.search).get('id');
      if (id && /^\d+$/.test(id)) await this.openById(id);
      for (const k of ['q', 'type', 'status']) this.$watch(k, () => this.reload());
    },

    // ── รายการ ──
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
      if (this.type) p.set('type', this.type);
      if (this.status) p.set('status', this.status);

      try {
        const res = await API.get(`/api/council/groups?${p}`);
        this.rows = res.data;
        this.meta = res.meta;
      } catch (e) {
        this.rows = [];
        this.error = e.status === 403 ? 'คุณไม่มีสิทธิ์จัดการแก๊ง/ครอบครัว' : API.describe(e, 'โหลดรายการไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
      } finally {
        this.loading = false;
      }
    },

    // ── ขึ้นทะเบียน (ป๊อปอัป) ──
    get kindInfo() {
      return KINDS[this.f.kind];
    },

    get hexOk() {
      return /^#[0-9A-Fa-f]{6}$/.test(this.f.color_hex);
    },

    e(k) {
      return this.createErrors[k] || '';
    },

    openCreate() {
      this.f = blankForm();
      this.files = {};
      this.createErrors = {};
      this.showCreate = true;
      document.documentElement.classList.add('ui-locked');
    },

    closeCreate() {
      if (this.creating || !this.showCreate) return;
      this.showCreate = false;
      document.documentElement.classList.remove('ui-locked');
    },

    clampMembers() {
      const n = parseInt(this.f.declared_members, 10);
      if (!n) return;
      this.f.declared_members = Math.min(Math.max(n, this.kindInfo.min), this.kindInfo.max);
    },

    addCo() {
      if (this.f.co.length < this.maxCo) this.f.co.push(blankPerson());
    },

    removeCo(i) {
      if (this.f.co.length > 1) this.f.co.splice(i, 1);
    },

    /** เลือกไฟล์: ตรวจนามสกุล/ขนาดเบื้องต้น (ฝั่งเซิร์ฟเวอร์ตรวจเนื้อไฟล์จริงอีกชั้น) */
    pick(ev, key, ext) {
      const file = ev.target.files[0];
      delete this.files[key];
      delete this.createErrors[key];
      if (!file) return;
      const limit = { logo: 2, temp_outfit_image: 4, outfit_zip: 50 }[key];
      if (!file.name.toLowerCase().endsWith('.' + ext)) {
        this.createErrors[key] = ext === 'png' ? 'ต้องเป็นไฟล์ .PNG เท่านั้น' : 'ต้องเป็นไฟล์ .ZIP เท่านั้น';
        ev.target.value = '';
      } else if (file.size > limit * 1024 * 1024) {
        this.createErrors[key] = `ไฟล์ใหญ่เกิน ${limit} MB`;
        ev.target.value = '';
      } else {
        this.files[key] = file;
      }
    },

    /** ตรวจฝั่งหน้าเว็บก่อนส่ง เพื่อให้รู้ช่องที่ผิดทันที */
    validate() {
      const er = {};
      const k = this.kindInfo;
      const n = parseInt(this.f.declared_members, 10);
      if (!(n >= k.min && n <= k.max)) er.declared_members = `ต้องอยู่ระหว่าง ${k.min} – ${k.max} คน`;
      if (!this.f.name.trim()) er.name = 'กรุณากรอกชื่อเต็ม';
      else if (!/^[A-Za-z0-9][A-Za-z0-9 ._\-]*$/.test(this.f.name.trim())) er.name = 'ชื่อต้องเป็นภาษาอังกฤษ/ตัวเลขเท่านั้น';
      if (!/^[A-Za-z0-9]{1,4}$/.test(this.f.short_name.trim())) er.short_name = 'ชื่อย่อต้องเป็นภาษาอังกฤษ/ตัวเลข 1–4 ตัวอักษร';
      if (!this.hexOk) er.color_hex = 'โค้ดสีต้องเป็นรูปแบบ #RRGGBB';
      if (!this.f.color_name.trim()) er.color_name = 'กรุณากรอกชื่อสี';
      if (!this.files.logo) er.logo = 'กรุณาแนบรูปโลโก้ (.PNG)';
      const person = (p, key) => {
        if (!p.ic_name.trim()) er[`${key}.ic_name`] = 'กรุณากรอกชื่อ-นามสกุล IC';
        if (!/^\d{17,20}$/.test(p.discord_id.trim())) er[`${key}.discord_id`] = 'Discord ID ต้องเป็นตัวเลข 17-20 หลัก';
      };
      person(this.f.leader, 'leader');
      this.f.co.forEach((c, i) => person(c, `co_leaders.${i}`));
      if (!this.files.temp_outfit_image) er.temp_outfit_image = 'กรุณาแนบรูปชุดชั่วคราว (.PNG)';
      if (!this.f.temp_outfit_male.trim() && !this.f.temp_outfit_female.trim()) er.temp_outfit_male = 'กรอกเลขชุดอย่างน้อยฝั่งชายหรือหญิง';
      if (!this.files.outfit_zip) er.outfit_zip = 'กรุณาแนบไฟล์ชุด (.ZIP)';
      return er;
    },

    async createGroup() {
      this.createErrors = this.validate();
      if (Object.keys(this.createErrors).length) {
        this.$nextTick(() => document.querySelector('.reg-err:not([style*="display: none"])')?.scrollIntoView({ block: 'center', behavior: 'smooth' }));
        return;
      }

      const fd = new FormData();
      const k = this.kindInfo;
      fd.append('type', k.type);
      if (k.lady) fd.append('is_lady', '1');
      fd.append('declared_members', this.f.declared_members);
      for (const key of ['name', 'short_name', 'color_hex', 'color_name', 'temp_outfit_male', 'temp_outfit_female']) fd.append(key, this.f[key].trim());
      const person = (p, prefix) => {
        fd.append(`${prefix}[ic_name]`, p.ic_name.trim());
        fd.append(`${prefix}[ic_phone]`, p.ic_phone.trim());
        fd.append(`${prefix}[discord_id]`, p.discord_id.trim());
      };
      person(this.f.leader, 'leader');
      this.f.co.forEach((c, i) => person(c, `co_leaders[${i}]`));
      for (const [key, file] of Object.entries(this.files)) fd.append(key, file);
      fd.append('protect', this.f.protect === 'yes' ? '1' : '0');

      this.creating = true;
      try {
        const res = await API.postForm('/api/council/groups', fd);
        this.creating = false;
        this.closeCreate();
        await this.load();
        this.open(res.data);
        this.msg = 'ขึ้นทะเบียนเรียบร้อย';
      } catch (e) {
        if (e.status === 422 && e.errors) {
          this.createErrors = Object.fromEntries(Object.entries(e.errors).map(([key, v]) => [key, v[0]]));
        } else {
          this.createErrors = { _: e.status === 413 ? 'ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับได้' : API.describe(e, e.message || 'ขึ้นทะเบียนไม่สำเร็จ') };
        }
        this.$nextTick(() => document.querySelector('.reg-err:not([style*="display: none"])')?.scrollIntoView({ block: 'center', behavior: 'smooth' }));
      } finally {
        this.creating = false;
      }
    },

    // ── รายละเอียด ──
    async openById(id) {
      this.err = '';
      try {
        this.open((await API.get(`/api/council/groups/${id}`)).data);
      } catch (e) {
        this.error = firstError(e, 'เปิดรายละเอียดไม่สำเร็จ');
      }
    },

    open(g) {
      this.g = g;
      this.msg = '';
      this.err = '';
      this.edit = { name: g.name, is_lady: g.is_lady, description: g.description || '', logo_url: g.logo_url || '', skip_cooldown: false };
      this.statusForm = { status: g.status, reason: '' };
      this.lockReason = g.locked_reason || '';
      this.newMember = { character_name: '', position: 'member', is_reserve: false, skip_cooldown: false };
      this.showHistory = false;
      window.scrollTo({ top: 0 });
    },

    close() {
      this.g = null;
      this.load();
    },

    // เรียก API แล้วอัปเดตกลุ่มที่เปิดอยู่จากผลลัพธ์ (ตอบกลับเป็นกลุ่มฉบับเต็มเสมอ)
    async act(fn, okMsg) {
      this.busy = true;
      this.msg = '';
      this.err = '';
      try {
        const res = await fn();
        if (res && res.data) {
          this.g = res.data;
          this.edit = { name: res.data.name, is_lady: res.data.is_lady, description: res.data.description || '', logo_url: res.data.logo_url || '', skip_cooldown: false };
          this.lockReason = res.data.locked_reason || '';
        }
        if (okMsg) this.msg = okMsg;
        return true;
      } catch (e) {
        this.err = firstError(e, 'บันทึกไม่สำเร็จ');
        return false;
      } finally {
        this.busy = false;
      }
    },

    get editDirty() {
      const g = this.g;
      return !!g && (this.edit.name !== g.name || this.edit.is_lady !== g.is_lady || this.edit.description !== (g.description || '') || this.edit.logo_url !== (g.logo_url || ''));
    },

    saveInfo() {
      const body = { description: this.edit.description.trim() || null, logo_url: this.edit.logo_url.trim() || null };
      if (this.g.type === 'gang' && this.edit.is_lady !== this.g.is_lady) body.is_lady = this.edit.is_lady;
      if (this.edit.name.trim() !== this.g.name) {
        body.name = this.edit.name.trim();
        if (this.edit.skip_cooldown) body.skip_cooldown = true;
      }
      return this.act(() => API.patch(`/api/council/groups/${this.g.id}`, body), 'บันทึกข้อมูลแล้ว');
    },

    async saveStatus() {
      const to = this.statusForm.status;
      if (to === this.g.status) return;
      const label = this.statuses.find((s) => s.value === to).label;
      if (!(await UI.ask(`เปลี่ยนสถานะของ ${this.g.name} เป็น "${label}" ?`, { confirmText: 'เปลี่ยนสถานะ' }))) return;
      const ok = await this.act(() => API.patch(`/api/council/groups/${this.g.id}/status`, { status: to, reason: this.statusForm.reason.trim() || null }), `เปลี่ยนสถานะเป็น ${label} แล้ว`);
      if (ok) this.statusForm = { status: this.g.status, reason: '' };
    },

    async toggleLock() {
      const lock = !this.g.slots_locked;
      if (!(await UI.ask(lock ? 'ล็อกสล็อตสมาชิก? จะเพิ่ม/ลบ/เปลี่ยนตำแหน่งสมาชิกไม่ได้จนกว่าจะปลดล็อก' : 'ปลดล็อกสล็อตสมาชิก?', { confirmText: lock ? 'ล็อก' : 'ปลดล็อก' }))) return;
      return this.act(
        () => API.patch(`/api/council/groups/${this.g.id}/lock`, { slots_locked: lock, reason: lock ? this.lockReason.trim() || null : null }),
        lock ? 'ล็อกสล็อตแล้ว' : 'ปลดล็อกสล็อตแล้ว',
      );
    },

    async addMember() {
      const body = { character_name: this.newMember.character_name.trim(), position: this.newMember.position, is_reserve: this.newMember.position === 'member' && this.newMember.is_reserve };
      if (this.newMember.skip_cooldown) body.skip_cooldown = true;
      const ok = await this.act(() => API.post(`/api/council/groups/${this.g.id}/members`, body), `เพิ่ม ${body.character_name} แล้ว`);
      if (ok) this.newMember = { character_name: '', position: 'member', is_reserve: false, skip_cooldown: false };
    },

    async setPosition(m, value, el) {
      if (value === m.position) return;
      const body = { position: value };
      if (value !== 'member' && m.is_reserve) body.is_reserve = false; // หัวหน้า/รองหัวหน้าต้องเป็นตัวจริง
      const ok = await this.act(() => API.patch(`/api/council/groups/${this.g.id}/members/${m.id}`, body), `เปลี่ยนตำแหน่ง ${m.character_name} แล้ว`);
      if (!ok) el.value = m.position;
    },

    toggleReserve(m) {
      const to = !m.is_reserve;
      return this.act(
        () => API.patch(`/api/council/groups/${this.g.id}/members/${m.id}`, { is_reserve: to }),
        `ย้าย ${m.character_name} เป็น${to ? 'ตัวสำรอง' : 'ตัวจริง'}แล้ว`,
      );
    },

    async removeMember(m) {
      if (!(await UI.ask(`นำ ${m.character_name} ออกจาก ${this.g.name} ?\n(เข้ากลุ่มใหม่ได้หลังครบ 7 วัน)`, { kind: 'danger', confirmText: 'นำออก' }))) return;
      return this.act(() => API.del(`/api/council/groups/${this.g.id}/members/${m.id}`), `นำ ${m.character_name} ออกแล้ว`);
    },

    async deleteGroup() {
      if (!(await UI.ask(`ลบ ${this.g.name} ออกจากทะเบียน ?\nการลบกลุ่มทำให้หายจากหน้าทำเนียบทันที`, { kind: 'danger', confirmText: 'ลบกลุ่ม' }))) return;
      const name = this.g.name;
      try {
        await API.del(`/api/council/groups/${this.g.id}`);
        this.g = null;
        await this.load();
        this.msg = `ลบ ${name} แล้ว`;
      } catch (e) {
        this.err = firstError(e, 'ลบไม่สำเร็จ');
      }
    },

    // ── แสดงผล ──
    get activeMembers() {
      return this.g ? this.g.members.filter((m) => !m.left_at) : [];
    },

    get pastMembers() {
      return this.g ? this.g.members.filter((m) => m.left_at) : [];
    },

    // ยังไม่ถึงขั้นต่ำสำหรับขึ้นทะเบียน (นับทั้งตัวจริงและสำรอง)
    get belowMinimum() {
      return !!this.g && !!this.g.slots && this.activeMembers.length < this.g.slots.min;
    },

    get canDelete() {
      return !!this.me && ['vice_president', 'president', 'admin'].includes(this.me.role);
    },

    badge(s) {
      return BADGE[s] || 'badge badge-muted';
    },

    when(iso) {
      const d = new Date(iso);
      if (!iso || isNaN(d)) return '';
      return `${d.getDate()} ${MONTHS[d.getMonth()]} ${String(d.getFullYear() + 543).slice(-2)}`;
    },
  };
}
