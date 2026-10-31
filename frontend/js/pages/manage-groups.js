const GANG_ALLOWED = ['สูท 2 ชิ้น (สูทปิด + สูทเปิด)', 'เกราะ 1 ชิ้น', 'เสื้อโปโล 1 ชิ้น', 'ฮูด 1 ชิ้น (โมเดลตัวเกม)', 'เสื้อยืด 1 ชิ้น', 'ซองปืน 1 ชิ้น', 'ชุด MOD 2 ชิ้น', 'Accessories 1 ชิ้น'];
const KINDS = {
  gang: {
    type: 'gang', lady: false, min: 10, max: 25, reserve: true, maxCo: 2, noun: 'แก๊ง', noun_full: 'แก๊ง',
    allowed: GANG_ALLOWED,
  },
  lady: {
    type: 'gang', lady: true, min: 10, max: 30, reserve: true, maxCo: 2, noun: 'แก๊ง', noun_full: 'แก๊ง',
    allowed: GANG_ALLOWED,
  },
  family: {
    type: 'family', lady: false, min: 6, max: 10, maxCo: 2, noun: 'ครอบครัว', noun_full: 'ครอบครัว',
    allowed: ['เสื้อโปโล 1 ชิ้น', 'ฮูด 1 ชิ้น (โมเดลตัวเกม)', 'เสื้อยืด 1 ชิ้น', 'Accessories 1 ชิ้น'],
  },
};
/** สีของชุดชั่วคราวเบอร์ 1–23 (ตามเท็กซ์เจอร์ polofm/polomale 1–23) ใช้แสดงตัวอย่างสี */
const TEMP_COLORS = [
  '#ef4444', '#3b82f6', '#10b981', '#fcd34d', '#8b5cf6', '#f9a8d4', '#f97316', '#92613a', '#e0d5c8', '#ff7066', '#00008b', '#ff00ff',
  '#98fb98', '#a52a2a', '#b5305f', '#556b2f', '#99ffff', '#b8860b', '#da60ff', '#a0a0a0', '#ffe0e0', '#e0e8e0', '#7cfc00',
];
const blankPerson = () => ({ key: Math.random().toString(36).slice(2), ic_name: '', ic_phone: '', discord_id: '' });
const blankForm = () => ({
  kind: 'gang', name: '', short_name: '', color_hex: '', color_name: '', color_auto: true,
  leader: blankPerson(), co: [],
  temp_outfit_color: null, protect: 'no',
});

/** วันเวลาแบบไทย เช่น 29 ต.ค. 69 22:16 (เวลาไทย) */
const fmtThai = (d, time = true) => {
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat('th-TH-u-ca-buddhist', Object.assign({ timeZone: 'Asia/Bangkok', day: 'numeric', month: 'short', year: '2-digit' }, time ? { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' } : {}))
      .formatToParts(d).map((p) => [p.type, p.value]),
  );
  return `${parts.day} ${parts.month} ${parts.year}` + (time ? ` ${parts.hour}:${parts.minute}` : '');
};

/** dd/mm/yyyy (พ.ศ.) เช่น 20/11/2569 */
const fmtThaiDate = (iso) => new Intl.DateTimeFormat('th-TH-u-ca-buddhist', { timeZone: 'Asia/Bangkok', day: '2-digit', month: '2-digit', year: 'numeric' }).format(new Date(iso));

/**
 * ตรวจว่าชื่อสีตรงกับโค้ด HEX ไหม — จำลองตรรกะเดียวกับ backend (app/Support/ColorNameMatcher.php)
 * ใช้แจ้งเตือนก่อนส่ง ตัวตัดสินจริงคือฝั่งเซิร์ฟเวอร์ คืนข้อความผิดพลาด หรือ '' ถ้าตรงกัน
 */
const COLOR_WORDS = {
  red: ['แดง', 'red', 'crimson', 'scarlet', 'maroon'],
  orange: ['ส้ม', 'orange'],
  yellow: ['เหลือง', 'yellow'],
  gold: ['ทอง', 'gold'],
  green: ['เขียว', 'green', 'lime'],
  cyan: ['ฟ้า', 'cyan', 'sky', 'teal', 'turquoise'],
  blue: ['น้ำเงิน', 'blue', 'navy'],
  purple: ['ม่วง', 'purple', 'violet', 'lavender'],
  pink: ['ชมพู', 'pink', 'magenta', 'บานเย็น'],
  brown: ['น้ำตาล', 'brown'],
  black: ['ดำ', 'black'],
  white: ['ขาว', 'white'],
  gray: ['เทา', 'gray', 'grey'],
  silver: ['เงิน', 'silver'],
};
const COLOR_LABELS = { red: 'แดง', orange: 'ส้ม', yellow: 'เหลือง', gold: 'ทอง', green: 'เขียว', cyan: 'ฟ้า', blue: 'น้ำเงิน', purple: 'ม่วง', pink: 'ชมพู', brown: 'น้ำตาล', black: 'ดำ', white: 'ขาว', gray: 'เทา', silver: 'เงิน' };

function colorTonesFor(hex) {
  const m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex);
  if (!m) return null;
  const [r, g, b] = [parseInt(m[1], 16) / 255, parseInt(m[2], 16) / 255, parseInt(m[3], 16) / 255];
  const max = Math.max(r, g, b);
  const min = Math.min(r, g, b);
  const l = (max + min) / 2;
  const d = max - min;
  const s = d === 0 ? 0 : d / (1 - Math.abs(2 * l - 1));
  let h = 0;
  if (d !== 0) {
    if (max === r) h = 60 * (((g - b) / d) % 6);
    else if (max === g) h = 60 * ((b - r) / d + 2);
    else h = 60 * ((r - g) / d + 4);
  }
  h = (h + 360) % 360;

  if (s < 0.12 || l < 0.1 || l > 0.94) {
    const t = ['gray'];
    if (l < 0.3) t.push('black');
    if (l > 0.75) t.push('white');
    if (l >= 0.5) t.push('silver');
    return t;
  }
  let t;
  if (h < 12 || h >= 348) t = ['red'];
  else if (h < 40) t = ['orange'].concat(h < 22 ? ['red'] : [], h >= 30 ? ['gold'] : []);
  else if (h < 68) t = ['yellow', 'gold'].concat(h < 48 ? ['orange'] : []);
  else if (h < 95) t = ['yellow', 'green'];
  else if (h < 165) t = ['green'];
  else if (h < 195) t = ['green', 'cyan'];
  else if (h < 250) t = ['cyan', 'blue'];
  else if (h < 270) t = ['blue', 'purple'];
  else if (h < 300) t = ['purple'];
  else t = ['pink', 'purple'].concat(h >= 330 ? ['red'] : []);
  if (h < 48 && l < 0.45) t.push('brown');
  if ((h < 15 || h >= 330) && l > 0.7) t.push('pink');
  return [...new Set(t)];
}

function colorNameError(hex, name) {
  const accepted = colorTonesFor(hex);
  if (!accepted) return '';
  const n = String(name).toLowerCase();
  const noBlue = n.split('น้ำเงิน').join(' '); // "น้ำเงิน" มีคำว่า "เงิน" อยู่ข้างใน
  const found = Object.keys(COLOR_WORDS).filter((tone) => COLOR_WORDS[tone].some((w) => (tone === 'silver' ? noBlue : n).includes(w)));
  const examples = accepted.map((t) => COLOR_LABELS[t]).join(' / ');
  if (!found.length) return 'ชื่อสีต้องมีคำบอกสีหลัก เช่น ' + examples;
  if (!found.some((t) => accepted.includes(t))) {
    return `ชื่อสี "${String(name).trim()}" (${found.map((t) => COLOR_LABELS[t]).join(' / ')}) ไม่ตรงกับโค้ด ${String(hex).toUpperCase()} — โค้ดนี้เป็นโทนสี ${examples}`;
  }
  return '';
}

/**
 * เสนอชื่อสีจากโค้ด HEX (เช่น #B91C1C → "แดงเข้ม") — ชื่อที่ได้ผ่านการตรวจ colorNameError เสมอ
 * ใช้เติมช่อง "ชื่อสี" อัตโนมัติเมื่อกรอกโค้ดครบ แก้ไขทับได้
 */
function colorNameFor(hex) {
  const tones = colorTonesFor(hex);
  if (!tones) return '';
  const m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex);
  const [r, g, b] = [parseInt(m[1], 16) / 255, parseInt(m[2], 16) / 255, parseInt(m[3], 16) / 255];
  const l = (Math.max(r, g, b) + Math.min(r, g, b)) / 2;
  if (tones.includes('black') && l < 0.15) return 'ดำ';
  if (tones.includes('white') && l > 0.88) return 'ขาว';
  if (tones.includes('brown') && tones[0] !== 'red') return 'น้ำตาล'; // ส้ม/เหลืองที่มืด = น้ำตาล
  const base = COLOR_LABELS[tones[0]];
  if (tones[0] === 'gray') return base;
  return base + (l < 0.3 ? 'เข้ม' : l > 0.72 ? 'อ่อน' : '');
}

const ENGLISH_NAME = /^(?=.*[A-Za-z])[A-Za-z0-9][A-Za-z0-9 ._\-]*$/;
const SHORT_NAME = /^[A-Z0-9]{1,4}$/;

const OUTFITS = [
  { key: 'suit', label: 'Suit' },
  { key: 'hoodie', label: 'Hoodie' },
  { key: 'tshirt', label: 'T-Shirt' },
  { key: 'polo', label: 'Polo' },
  { key: 'armor', label: 'Armor' },
  { key: 'gun', label: 'Gun' },
  { key: 'mod1', label: 'MOD 1' },
  { key: 'mod2', label: 'MOD 2' },
  { key: 'accessories', label: 'Accessories' },
];
const FAMILY_OUTFITS = ['hoodie', 'tshirt', 'polo', 'accessories']; // ครอบครัวลงได้เฉพาะ ฮูด เสื้อยืด เสื้อโปโล และ accessories
const blankTxn = () => ({ kind: 'rename', name: '', short_name: '', logo: null, color_hex: '', color_name: '', color_auto: true, outfit_zip: null, leader_name: '', leader_phone: '', leader_discord: '', member_names: '', reason: '' });

/** วันเวลาปัจจุบันแบบไทย เช่น 29 ต.ค. 69 22:08 · เวลาไทย (UTC+7) */
const nowThai = () => {
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat('th-TH-u-ca-buddhist', { timeZone: 'Asia/Bangkok', day: 'numeric', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
      .formatToParts(new Date()).map((p) => [p.type, p.value]),
  );
  return `${parts.day} ${parts.month} ${parts.year} ${parts.hour}:${parts.minute} · เวลาไทย (UTC+7)`;
};

function manageGroupsPage() {
  const MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
  const BADGE = { normal: 'badge badge-normal', blacklist: 'badge badge-blacklist', sanction: 'badge badge-sanction', inactive: 'badge badge-muted' };
  const firstError = (e, fallback) => (e.errors && Object.values(e.errors)[0][0]) || e.message || fallback;

  return {
    ready: false,
    me: null,

    // รายการ
    rows: [],
    meta: null,
    q: '',
    kind: '', // '' ทั้งหมด | gang | lady | family
    scope: 'active', // active | dissolved | deleted
    status: '',
    counts: { active: 0, gang: 0, lady: 0, family: 0, dissolved: 0, deleted: 0 },
    updatedAt: '',
    page: 1,
    loading: true,
    error: '',

    tiles: [
      { kind: '', count: 'active', label: 'สังกัดที่ใช้งาน', icon: '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5"/><circle cx="17" cy="9" r="2.4"/><path d="M16.5 14.6c2.6.1 4.5 1.9 4.5 4.4"/></svg>' },
      { kind: 'gang', count: 'gang', label: 'GANG · แก๊ง', icon: '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5"/><circle cx="17" cy="9" r="2.4"/><path d="M16.5 14.6c2.6.1 4.5 1.9 4.5 4.4"/></svg>' },
      { kind: 'family', count: 'family', label: 'FAMILY · ครอบครัว', icon: '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="3.5" width="7" height="7" rx="1.6"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.6"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.6"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.6"/></svg>' },
      { kind: 'lady', count: 'lady', label: 'LADY · เลดี้', icon: '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v5.5c0 4.2-2.9 7.6-7 9.5-4.1-1.9-7-5.3-7-9.5V6z"/><path d="M9 12l2.2 2.2L15.5 10"/></svg>' },
    ],
    statuses: [
      { value: '', label: 'ทุกสถานะ' },
      { value: 'normal', label: 'ปกติ' },
      { value: 'blacklist', label: 'Blacklist' },
      { value: 'sanction', label: 'คว่ำบาตร' },
      { value: 'inactive', label: 'ขาดการเคลื่อนไหว' },
    ],
    // เก็บเฉพาะรายชื่อหัวหน้าและรองหัวหน้า (ไม่เก็บรายชื่อสมาชิกทั่วไป)
    staffPositions: [
      { value: 'leader', label: 'Leader' },
      { value: 'co_leader', label: 'Sub Leader' },
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
    previews: {}, // ตัวอย่างรูปที่แนบ (object URL)
    createErrors: {},
    creating: false,

    // รายละเอียด
    g: null, // กลุ่มที่เปิดอยู่
    tab: 'info',
    tabs: [
      { key: 'info', label: 'แก้ไขข้อมูล' },
      { key: 'txn', label: 'สร้างธุรกรรม' },
      { key: 'report', label: 'บันทึกรายงานตัว' },
      { key: 'protect', label: 'จัดการ Protect' },
      { key: 'fine', label: 'บันทึกค่าปรับ' },
    ],
    outfitEdit: false,
    outfitForm: {},
    outfitErr: '',
    hist: [],
    histLoading: false,
    stories: [], // ประวัติการเล่นสตอรี่ของทะเบียนนี้
    // ย่อ/ขยายการ์ดในรายละเอียดทะเบียน (จำค่าล่าสุดไว้ในเบราว์เซอร์)
    storyOpen: (() => { try { return localStorage.getItem('gdStoryOpen') !== '0'; } catch (e) { return true; } })(),
    histOpen: (() => { try { return localStorage.getItem('gdHistOpen') !== '0'; } catch (e) { return true; } })(),
    storyWeek: 0, // 0 = สัปดาห์นี้ · -1 = สัปดาห์ก่อน ... (ย้อนดูได้ภายใน 1 เดือน)
    contractView: null, // แถวสตอรี่ที่เปิดหนังสือสัญญาเป็นป๊อปอัปอยู่
    storiesLoading: false,
    storiesError: '',
    histError: '',
    // อัตราค่าบริการของแต่ละธุรกรรม (โหลดจากเซิร์ฟเวอร์ ค่าตั้งต้นนี้ใช้ก่อนโหลดเสร็จ)
    fees: { currency: 'WVDL', register: 0, rename: 1000000, rename_logo: 2000000, recolor: 1000000, change_leader: 0, release_slot: 300000, release_free: 500000, dissolve: 0 },
    txnKinds: [
      { value: 'rename', label: 'เปลี่ยนชื่อ' },
      { value: 'recolor', label: 'เปลี่ยนสี' },
      { value: 'change_leader', label: 'เปลี่ยน Leader' },
      { value: 'release_slot', label: 'ออกสล็อต (ผู้ออกมายืนยันตัวตน)' },
      { value: 'release_free', label: 'ออกลอย (ผู้ออกไม่มายืนยันตัวตน)' },
      { value: 'dissolve', label: 'ยุบ' },
    ],
    txn: blankTxn(),
    txErrors: {},
    txBusy: false,
    txMsg: '',
    txLogoPreview: '',
    manageMenu: false,
    tempPreview: '', // object URL ของรูปชุดชั่วคราว (ไฟล์ส่วนตัว โหลดผ่าน API ด้วยสิทธิ์สภา)
    tempLoading: false,

    // ป๊อปอัปย่อย: report | protect | fine
    dlg: null,
    dlgBusy: false,
    dlgErrors: {},
    rep: { status: 'normal', note: '' },
    reportStatuses: [
      { value: 'normal', label: 'ปกติ' },
      { value: 'inactive', label: 'ขาดการเคลื่อนไหว' },
    ],
    pro: { until: '' },
    fineQuery: '', // คำค้นในช่องข้อหา (combobox)
    fineOpen: false,
    fineActive: 0,
    fine: { type: 'fine', category: '', rule_id: '', amount: '', units: '', charge: '', note: '' },
    fineSections: [], // หมวดที่มีข้อกฎเป็นเหตุปรับได้ (โหลดจากตารางกฎ)
    fineRules: [], // ข้อกฎที่มีบทลงโทษ พร้อมจำนวนเงินที่อ่านได้จากบทลงโทษ
    records: [],
    msg: '',
    err: '',
    busy: false,
    edit: { name: '', is_lady: false, description: '', logo_url: '', skip_cooldown: false },
    statusForm: { status: 'normal', reason: '' },
    lockReason: '',
    newMember: { character_name: '', position: 'co_leader', is_reserve: false, skip_cooldown: false },
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
      for (const k of ['q', 'kind', 'scope', 'status']) this.$watch(k, () => this.reload());
      // กรอกโค้ดสีครบแล้วเติมชื่อสีให้อัตโนมัติ (ถ้ายังว่างหรือเป็นชื่อที่ระบบเติมเอง)
      this.$watch('f.color_hex', (v) => this.autoColorName(this.f, v));
      this.$watch('txn.color_hex', (v) => this.autoColorName(this.txn, v));
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
      if (this.kind) p.set('kind', this.kind);
      if (this.scope !== 'active') p.set('scope', this.scope);
      if (this.status) p.set('status', this.status);

      try {
        const res = await API.get(`/api/council/groups?${p}`);
        this.rows = res.data;
        this.meta = res.meta;
        if (res.counts) this.counts = res.counts;
        this.updatedAt = nowThai();
      } catch (e) {
        this.rows = [];
        this.error = e.status === 403 ? 'คุณไม่มีสิทธิ์จัดการแก๊ง/ครอบครัว' : API.describe(e, 'โหลดรายการไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
      } finally {
        this.loading = false;
      }
    },

    /** เติม obj.color_name จากโค้ด HEX เมื่อกรอกครบ 6 หลัก */
    autoColorName(obj, hex) {
      const v = String(hex || '').trim();
      if (!/^#[0-9A-Fa-f]{6}$/.test(v)) return;
      if (obj.color_name.trim() && !obj.color_auto) return;
      obj.color_name = colorNameFor(v);
      obj.color_auto = true;
      delete this.createErrors.color_name;
      delete this.txErrors.color_name;
    },

    // ── ขึ้นทะเบียน (ป๊อปอัป) ──
    get kindInfo() {
      return KINDS[this.f.kind];
    },

    /** รองที่กรอกจริง (แถวที่ปล่อยว่างทั้งแถวไม่นับ) */
    filledCo() {
      return this.f.co.map((c, i) => [c, i]).filter(([c]) => c.ic_name.trim() || c.ic_phone.trim() || c.discord_id.trim());
    },

    get maxCo() {
      return this.kindInfo.maxCo;
    },

    get hexOk() {
      return /^#[0-9A-Fa-f]{6}$/.test(this.f.color_hex);
    },

    e(k) {
      return this.createErrors[k] || '';
    },

    // ── ชุดชั่วคราว: เลือกเบอร์สี 1–23 (วันเดียวกันซ้ำกันไม่ได้) ──
    tempColors: TEMP_COLORS,
    tempTaken: [], // เบอร์ที่ถูกใช้ไปแล้ววันนี้
    tempIds: { male: '1254', female: '1300' },

    async loadTempColors() {
      try {
        const r = await API.get('/api/council/groups/temp-colors');
        this.tempTaken = r.taken || [];
        this.tempIds = { male: r.male || '1254', female: r.female || '1300' };
        if (this.f.temp_outfit_color && this.tempTaken.includes(this.f.temp_outfit_color)) this.f.temp_outfit_color = null;
      } catch (e) {
        this.tempTaken = []; // โหลดไม่ได้ก็เลือกได้ เซิร์ฟเวอร์ตรวจซ้ำให้
      }
    },

    pickTempColor(n) {
      if (this.tempTaken.includes(n)) return;
      this.f.temp_outfit_color = n;
      delete this.createErrors.temp_outfit_color;
    },

    openCreate() {
      this.f = blankForm();
      this.loadTempColors();
      this.clearPreviews();
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
      if (this.f.co.length > this.maxCo) this.f.co.splice(this.maxCo);
    },

    addCo() {
      if (this.f.co.length < this.maxCo) this.f.co.push(blankPerson());
    },

    removeCo(i) {
      this.f.co.splice(i, 1);
    },

    /** เลือกไฟล์: ตรวจนามสกุล/ขนาดเบื้องต้น (ฝั่งเซิร์ฟเวอร์ตรวจเนื้อไฟล์จริงอีกชั้น) */
    clearPreviews() {
      for (const u of Object.values(this.previews)) URL.revokeObjectURL(u);
      this.previews = {};
    },

    /** ขนาดไฟล์อ่านง่าย */
    size(file) {
      return file.size >= 1048576 ? (file.size / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(file.size / 1024)) + ' KB';
    },

    pick(ev, key, ext) {
      const file = ev.target.files[0];
      delete this.files[key];
      if (this.previews[key]) {
        URL.revokeObjectURL(this.previews[key]);
        delete this.previews[key];
      }
      delete this.createErrors[key];
      if (!file) return;
      const limit = { logo: 2, outfit_zip: 50 }[key];
      if (!file.name.toLowerCase().endsWith('.' + ext)) {
        this.createErrors[key] = ext === 'png' ? 'ต้องเป็นไฟล์ .PNG เท่านั้น' : 'ต้องเป็นไฟล์ .ZIP เท่านั้น';
        ev.target.value = '';
      } else if (file.size > limit * 1024 * 1024) {
        this.createErrors[key] = `ไฟล์ใหญ่เกิน ${limit} MB`;
        ev.target.value = '';
      } else {
        this.files[key] = file;
        if (ext === 'png') this.previews[key] = URL.createObjectURL(file);
      }
    },

    /** ตรวจฝั่งหน้าเว็บก่อนส่ง เพื่อให้รู้ช่องที่ผิดทันที */
    validate() {
      const er = {};
      const k = this.kindInfo;
      if (!this.f.name.trim()) er.name = 'กรุณากรอกชื่อเต็ม';
      else if (!ENGLISH_NAME.test(this.f.name.trim())) er.name = 'ชื่อเต็มต้องเป็นภาษาอังกฤษ (ใช้ตัวเลขร่วมได้) และมีตัวอักษรอย่างน้อย 1 ตัว';
      if (!SHORT_NAME.test(this.f.short_name.trim())) er.short_name = 'ชื่อย่อต้องเป็นตัวพิมพ์ใหญ่ภาษาอังกฤษหรือตัวเลข 1–4 ตัวอักษร';
      if (!this.hexOk) er.color_hex = 'โค้ดสีต้องเป็นรูปแบบ #RRGGBB';
      if (!this.f.color_name.trim()) er.color_name = 'กรุณากรอกชื่อสี';
      else if (this.hexOk && colorNameError(this.f.color_hex, this.f.color_name)) er.color_name = colorNameError(this.f.color_hex, this.f.color_name);
      if (!this.files.logo) er.logo = 'กรุณาแนบรูปโลโก้ (.PNG)';
      const person = (p, key, discordRequired) => {
        if (!p.ic_name.trim()) er[`${key}.ic_name`] = 'กรุณากรอกชื่อ-นามสกุล IC';
        const d = p.discord_id.trim();
        if ((discordRequired || d) && !/^\d{17,20}$/.test(d)) er[`${key}.discord_id`] = 'Discord ID ต้องเป็นตัวเลข 17-20 หลัก';
      };
      person(this.f.leader, 'leader', true);
      this.filledCo().forEach(([c, i]) => person(c, `co_leaders.${i}`, false));
      if (!this.f.temp_outfit_color) er.temp_outfit_color = 'กรุณาเลือกเบอร์สีชุดชั่วคราว';
      else if (this.tempTaken.includes(this.f.temp_outfit_color)) er.temp_outfit_color = 'เบอร์นี้ถูกใช้ไปแล้ววันนี้ กรุณาเลือกเบอร์อื่น';
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
      for (const key of ['name', 'short_name', 'color_hex', 'color_name']) fd.append(key, this.f[key].trim());
      fd.append('temp_outfit_color', this.f.temp_outfit_color);
      const person = (p, prefix) => {
        fd.append(`${prefix}[ic_name]`, p.ic_name.trim());
        fd.append(`${prefix}[ic_phone]`, p.ic_phone.trim());
        if (p.discord_id.trim()) fd.append(`${prefix}[discord_id]`, p.discord_id.trim());
      };
      person(this.f.leader, 'leader');
      this.filledCo().forEach(([c], n) => person(c, `co_leaders[${n}]`));
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
      this.tab = 'info';
      this.resetTxn();
      this.hist = [];
      this.records = [];
      this.histError = '';
      this.stories = [];
      this.storiesError = '';
      this.storyWeek = 0;
      this.dlg = null;
      this.manageMenu = false;
      this.outfitEdit = false;
      document.documentElement.classList.add('ui-locked');
      this.loadHistory();
      this.loadStories();
      this.loadTempPreview();
    },

    close() {
      if (this.contractView) {
        this.contractView = null; // ปิดป๊อปอัปหนังสือสัญญาก่อน
        return;
      }
      if (this.dlg) return this.closeDlg();
      if (!this.g) return;
      this.dlg = null;
      this.manageMenu = false;
      this.clearTempPreview();
      this.g = null;
      this.resetTxn();
      if (!this.showCreate) document.documentElement.classList.remove('ui-locked');
      this.load();
    },

    // ── ประวัติการเล่นสตอรี่ ──
    async loadStories() {
      if (!this.g) return;
      const id = this.g.id;
      this.storiesLoading = true;
      this.storiesError = '';
      try {
        const w = this.storyWeekRange;
        const res = await API.get(`/api/council/stories?group_id=${id}&from=${w.from}&to=${w.to}&per_page=100`);
        if (this.g && this.g.id === id) this.stories = res.data;
      } catch (e) {
        this.stories = [];
        this.storiesError = e.status === 403 ? 'ดูประวัติสตอรี่ได้เฉพาะตอนเข้าเวร' : API.describe(e, 'โหลดประวัติการเล่นสตอรี่ไม่สำเร็จ');
      } finally {
        this.storiesLoading = false;
      }
    },

    toggleFold(flag) {
      this[flag] = !this[flag];
      try {
        localStorage.setItem(flag === 'storyOpen' ? 'gdStoryOpen' : 'gdHistOpen', this[flag] ? '1' : '0');
      } catch (e) {
        /* ใช้ไม่ได้ก็แค่ไม่จำค่า */
      }
    },

    /** สัปดาห์ (จันทร์–อาทิตย์ ตามเวลาไทย) ที่ offset: 0 = สัปดาห์นี้ · -1 = สัปดาห์ก่อน ... → { from, to, label } */
    weekRange(offset) {
      const MS = 86400000;
      const p = Object.fromEntries(new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Bangkok', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date()).map((x) => [x.type, x.value]));
      const today = Date.UTC(+p.year, +p.month - 1, +p.day);
      const monday = today - ((new Date(today).getUTCDay() + 6) % 7) * MS + offset * 7 * MS;
      const sunday = monday + 6 * MS;
      const iso = (t) => new Date(t).toISOString().slice(0, 10);
      const fmt = (t, withYear) => new Intl.DateTimeFormat('th-TH-u-ca-buddhist', { timeZone: 'UTC', day: 'numeric', month: 'short', ...(withYear ? { year: 'numeric' } : {}) }).format(new Date(t));
      const name = offset === 0 ? 'สัปดาห์นี้' : offset === -1 ? 'สัปดาห์ก่อน' : `${-offset} สัปดาห์ก่อน`;
      return { from: iso(monday), to: iso(sunday), label: `${name} · ${fmt(monday)} – ${fmt(sunday, true)}` };
    },

    get storyWeekRange() {
      return this.weekRange(this.storyWeek);
    },

    /** ย้อนดูประวัติได้ภายใน 1 เดือน: ย้อนได้ตราบที่สัปดาห์นั้นยังมีวันอยู่ในช่วง 1 เดือนล่าสุด */
    get canStoryPrev() {
      const t = new Date();
      t.setMonth(t.getMonth() - 1);
      return this.weekRange(this.storyWeek - 1).to >= t.toISOString().slice(0, 10);
    },

    async storyWeekStep(delta) {
      if (delta < 0 && !this.canStoryPrev) return;
      if (delta > 0 && this.storyWeek >= 0) return;
      this.storyWeek += delta;
      await this.loadStories();
    },

    /** แถวประวัติสตอรี่จากมุมมองของทะเบียนที่เปิดอยู่: คู่แข่ง · คะแนน (เรา:เขา) · ผล */
    get storyRows() {
      if (!this.g) return [];
      const me = this.g.id;
      return this.stories.map((s) => {
        const isA = s.group_a.id === me;
        const opp = isA ? s.group_b : s.group_a;
        const mine = isA ? s.score_a : s.score_b;
        const theirs = isA ? s.score_b : s.score_a;
        let outcome = { key: s.phase, label: s.phase_label };
        if (s.status === 'ended') {
          if (s.end_kind === 'no_show') outcome = { key: 'draw', label: 'ไม่มาเจรจาปิดเรื่อง' };
          else if (s.winner_group_id === me) outcome = { key: 'win', label: s.end_kind === 'surrender' ? 'ชนะ (คู่แข่งยอมแพ้)' : 'ชนะ' };
          else outcome = { key: 'lose', label: s.end_kind === 'surrender' ? 'แพ้ (ยอมแพ้)' : 'แพ้' };
        }
        return { id: s.id, number: s.number, me: this.g, opp, mine, theirs, outcome, startsAt: s.starts_at, venue: s.venue_random ? 'สุ่มโดยระบบ' : s.venue, active: s.status === 'active' };
      });
    },

    /** สรุปว่าเคยเล่นกับกลุ่มไหนมาบ้าง (ไม่นับที่ยกเลิก): [{ name, kind, n, win, lose }] เรียงตามจำนวนครั้ง */
    get storyOpponents() {
      const by = new Map();
      for (const r of this.storyRows) {
        if (r.outcome.key === 'cancelled') continue;
        const o = by.get(r.opp.id) || { id: r.opp.id, name: r.opp.name, type: r.opp.type, is_lady: r.opp.is_lady, n: 0, win: 0, lose: 0 };
        o.n++;
        if (r.outcome.key === 'win') o.win++;
        if (r.outcome.key === 'lose') o.lose++;
        by.set(r.opp.id, o);
      }
      return [...by.values()].sort((a, b) => b.n - a.n || a.name.localeCompare(b.name));
    },

    storyKind(o) {
      return o.type === 'family' ? 'FAMILY' : o.is_lady ? 'LADY' : 'GANG';
    },

    // ── ประวัติและธุรกรรมของทะเบียน ──
    async loadHistory() {
      if (!this.g) return;
      this.histLoading = true;
      this.histError = '';
      try {
        const [tx, rec] = await Promise.all([
          API.get(`/api/council/transactions?group_id=${this.g.id}&per_page=50`),
          API.get(`/api/council/groups/${this.g.id}/records`),
        ]);
        this.hist = tx.data;
        if (tx.fees) this.fees = tx.fees;
        this.records = rec.data;
        if (rec.meta) {
          this.fineSections = rec.meta.fine_sections || [];
          this.fineRules = rec.meta.fine_rules || [];
        }
      } catch (e) {
        this.hist = [];
        this.records = [];
        this.histError = e.status === 403 ? 'ดูธุรกรรมได้เฉพาะตอนเข้าเวร' : API.describe(e, 'โหลดประวัติธุรกรรมไม่สำเร็จ');
      } finally {
        this.histLoading = false;
      }
    },

    /** รวมธุรกรรม + ประวัติสถานะ เรียงใหม่สุดก่อน */
    get timeline() {
      if (!this.g) return [];
      const items = this.hist.map((tx) => ({
        key: 't' + tx.id,
        title: tx.kind_label + (tx.status !== 'approved' ? ' · ' + tx.status_label : ''),
        meta: [tx.requested_by ? tx.requested_by.name : '', tx.number].filter(Boolean).join(' · '),
        at: tx.created_at,
        lines: this.diffLines(tx),
        swatch: this.swatchOf(tx),
        tx,
      }));
      for (const r of this.records) {
        const d = r.data || {};
        let title = 'รายงานตัว · ปกติ';
        const lines = [];
        if (r.kind === 'inactive') {
          title = 'ขาดการเคลื่อนไหว';
        } else if (r.kind === 'fine') {
          title = d.penalty_type === 'penance'
            ? 'ส่งบำเพ็ญ ' + Number(d.penance_units || 0).toLocaleString('th-TH') + ' ไม้ · ' + (d.category_label || '')
            : 'ค่าปรับ ' + Number(d.amount || 0).toLocaleString('th-TH') + ' · ' + (d.category_label || '');
          lines.push('ข้อหา: ' + (d.charge || '—'));
        } else if (r.kind === 'outfit') {
          title = 'แก้ไขจำนวนชุด';
          const lab = Object.fromEntries(OUTFITS.map((o) => [o.key, o.label]));
          const b = d.before || {};
          const a = d.after || {};
          for (const k of Object.keys(lab)) {
            if (b[k] !== a[k]) lines.push(`${lab[k]}: ${b[k] === undefined ? '—' : b[k]} → ${a[k] === undefined ? '—' : a[k]}`);
          }
        } else if (r.kind === 'protect') {
          title = d.after && d.after.protect_until ? 'ปรับ Protect' : 'ยกเลิก Protect';
          if (d.after && d.after.protect_until) lines.push('สิ้นสุด ' + fmtThai(new Date(d.after.protect_until)));
        }
        if (r.note) lines.push(r.note);
        items.push({ key: 'r' + r.id, title, meta: r.recorded_by || '', at: r.created_at, lines, tx: null });
      }
      for (const h of this.g.history || []) {
        if ((h.reason || '').startsWith('ขึ้นทะเบียน')) continue;
        items.push({ key: 's' + h.started_at, title: 'เปลี่ยนสถานะ · ' + h.status_label, meta: h.changed_by || '', at: h.started_at, lines: h.reason ? [h.reason] : [], tx: null });
      }
      if (!this.hist.some((t) => t.kind === 'register')) {
        items.push({ key: 'reg', title: 'ขึ้นทะเบียน', meta: '', at: this.g.registered_at, lines: [], tx: null });
      }
      return items.sort((a, b) => new Date(b.at) - new Date(a.at));
    },

    /** สรุปสิ่งที่เปลี่ยนจากภาพก่อน-หลังของกลุ่ม */
    diffLines(tx) {
      const base = this.diffBase(tx);
      const fee = tx.payload && tx.payload.fee;
      if (!fee || !(Number(fee.total) > 0)) return base;
      const n = (v) => Number(v).toLocaleString('th-TH');
      const perHead = (tx.kind === 'release_slot' || tx.kind === 'release_free') && fee.items && fee.items.length === 1;
      return base.concat(perHead ? `ค่าบริการ ${n(fee.unit_price)} × ${fee.count} คน = ${n(fee.total)} ${fee.currency || ''}`.trim() : `ค่าบริการ ${n(fee.total)} ${fee.currency || ''}`.trim());
    },

    /** ธุรกรรมเปลี่ยนสี: สีเดิม → สีใหม่ (ยังไม่อนุมัติ/ไม่มีภาพก่อน-หลัง ใช้สีปัจจุบันของกลุ่มเป็นสีเดิม) */
    swatchOf(tx) {
      if (tx.kind !== 'recolor') return null;
      const p = tx.payload || {};
      const from = tx.before && tx.before.color_hex ? tx.before : this.g;
      const to = tx.after && tx.after.color_hex ? tx.after : { color_hex: p.color_hex, color_name: p.color_name };
      return { from: { hex: from.color_hex, name: from.color_name }, to: { hex: to.color_hex, name: to.color_name } };
    },

    /** สรุปสิ่งที่ธุรกรรมทำ (ไม่รวมค่าบริการ) */
    diffBase(tx) {
      if (tx.kind === 'recolor') return ['แนบไฟล์ชุดใหม่ (.ZIP) เป็นไฟล์ชุดล่าสุด (ไฟล์เดิมยังเก็บไว้)'];
      const b = tx.before;
      const a = tx.after;
      if (tx.kind === 'release_slot' || tx.kind === 'release_free') return [`ออก: ${(tx.payload.member_names || []).join(', ')}`];
      if (tx.kind === 'register') return a ? [`ชื่อเต็ม: ${a.name}`, `สมาชิก: ${a.members_count} คน`] : [tx.summary];
      if (!b || !a) return [tx.summary];
      if (a.dissolved) return ['ยุบกลุ่ม'];
      const out = [];
      if (b.name !== a.name) out.push(`ชื่อ: ${b.name} → ${a.name}`);
      if (b.short_name !== a.short_name) out.push(`ชื่อย่อ: ${b.short_name || '—'} → ${a.short_name || '—'}`);
      if (b.color_hex !== a.color_hex || b.color_name !== a.color_name) out.push(`สี: ${b.color_name || ''} ${b.color_hex || '—'} → ${a.color_name || ''} ${a.color_hex || '—'}`);
      if (b.leader !== a.leader) out.push(`Leader: ${b.leader || '—'} → ${a.leader || '—'}`);
      if (b.members_count !== a.members_count) out.push(`สมาชิก: ${b.members_count} → ${a.members_count} คน`);
      if (b.logo_url !== a.logo_url) out.push('เปลี่ยนโลโก้');
      return out.length ? out : [tx.summary];
    },

    async reviewTx(tx, action) {
      let body = {};
      if (action === 'reject') {
        const reason = await UI.prompt({ title: 'ไม่อนุมัติธุรกรรม', message: tx.number, label: 'เหตุผล', required: true, maxLength: 2000, confirmText: 'ไม่อนุมัติ', kind: 'danger' });
        if (!reason) return;
        body = { reason };
      } else if (!(await UI.ask(action === 'approve' ? `อนุมัติธุรกรรม ${tx.number} ?` : `ยกเลิกธุรกรรม ${tx.number} ?`, { confirmText: action === 'approve' ? 'อนุมัติ' : 'ยกเลิกธุรกรรม' }))) return;

      this.busy = true;
      this.err = '';
      this.msg = '';
      try {
        await API.post(`/api/council/transactions/${tx.id}/${action}`, body);
        this.msg = { approve: 'อนุมัติแล้ว', reject: 'บันทึกการไม่อนุมัติแล้ว', cancel: 'ยกเลิกธุรกรรมแล้ว' }[action];
        await this.openById(this.g.id);
      } catch (e) {
        this.err = firstError(e, 'ดำเนินการไม่สำเร็จ');
      } finally {
        this.busy = false;
      }
    },

    // ── จำนวนชุดที่ลงทะเบียน ──
    get outfitTypes() {
      return this.g && this.g.type === 'family' ? OUTFITS.filter((o) => FAMILY_OUTFITS.includes(o.key)) : OUTFITS;
    },

    outfitValue(key) {
      const v = this.g && this.g.outfit_counts ? this.g.outfit_counts[key] : undefined;
      return v === undefined || v === null ? null : Number(v);
    },

    startOutfitEdit() {
      this.outfitForm = Object.fromEntries(this.outfitTypes.map((o) => [o.key, this.outfitValue(o.key) === null ? '' : String(this.outfitValue(o.key))]));
      this.outfitErr = '';
      this.outfitEdit = true;
    },

    async saveOutfits() {
      const counts = {};
      for (const o of this.outfitTypes) {
        const v = String(this.outfitForm[o.key] ?? '').replace(/[,\s]/g, '');
        if (v === '') continue;
        if (!/^\d{1,4}$/.test(v)) {
          this.outfitErr = `จำนวน ${o.label} ต้องเป็นตัวเลข 0-9999`;
          return;
        }
        counts[o.key] = Number(v);
      }
      this.outfitErr = '';
      this.busy = true;
      this.err = '';
      try {
        await API.post(`/api/council/groups/${this.g.id}/records`, { kind: 'outfit', counts });
        this.outfitEdit = false;
        await this.openById(this.g.id);
        this.msg = 'บันทึกจำนวนชุดแล้ว';
      } catch (e) {
        this.outfitErr = firstError(e, 'บันทึกไม่สำเร็จ');
      } finally {
        this.busy = false;
      }
    },

    // ── แท็บ / เมนูจัดการทะเบียน / ป๊อปอัปย่อย ──
    openTab(key) {
      this.manageMenu = false;
      if (['report', 'protect', 'fine'].includes(key)) return this.openDlg(key);
      this.tab = key;
    },

    manageGo(action) {
      this.manageMenu = false;
      if (action === 'dissolve') {
        this.tab = 'txn';
        this.txn.kind = 'dissolve';
      } else if (action === 'members') {
        this.tab = 'manage';
      }
      this.txErrors = {};
      this.txMsg = '';
    },

    /** ISO → 'YYYY-MM-DDTHH:mm' ตามเวลาไทย (สำหรับ datetime-local) */
    toThaiLocal(iso) {
      if (!iso) return '';
      const p = Object.fromEntries(new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Bangkok', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date(iso)).map((x) => [x.type, x.value]));
      return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}`;
    },

    // ── บันทึกค่าปรับ: ข้อมูลทั้งหมดมาจากกฎ ──
    /** ตัวเลือกข้อหา: แสดงเฉพาะข้อกฎของหมวดหมู่ที่เลือก; พิมพ์ = กรองในหมวดนั้น (ข้อความกฎ/บทลงโทษ) + ตัวเลือก "อื่นๆ" เสมอ */
    get fineOptions() {
      const q = this.fineQuery.trim().toLowerCase();
      // ข้อความในช่องเป็นข้อกฎที่เลือกอยู่แล้ว (ยังไม่ได้พิมพ์ค้นต่อ) แสดงรายการของหมวดตามปกติ
      const typed = q && !(this.selectedRule && this.fineQuery === this.shorten(this.selectedRule.text, 120));
      let pool = this.fineRules.filter((r) => r.section === this.fine.category);
      if (typed) pool = pool.filter((r) => `${r.text} ${r.penalty}`.toLowerCase().includes(q));
      const items = pool.slice(0, 80).map((r) => ({ key: 'r' + r.id, value: String(r.id), label: this.shorten(r.text, 140), tag: '', rule: r }));
      items.push({ key: 'other', value: 'other', label: 'อื่นๆ (ระบุข้อหาเอง)', tag: '', other: true });
      return items;
    },

    onFineQuery() {
      this.fineOpen = true;
      this.fineActive = 0;
      // แก้ข้อความหลังเลือกแล้ว = ยกเลิกการเลือกข้อกฎเดิม
      if (this.fine.rule_id) {
        this.fine.rule_id = '';
        this.fine.amount = '';
        this.fine.units = '';
      }
      delete this.dlgErrors.rule_id;
    },

    pickFine(o) {
      this.fine.rule_id = o.value;
      this.fineQuery = o.other ? 'อื่นๆ (ระบุข้อหาเอง)' : this.shorten(o.rule.text, 120);
      this.fineOpen = false;
      this.onFineRule();
    },

    fineMove(step) {
      this.fineOpen = true;
      const n = this.fineOptions.length;
      this.fineActive = (this.fineActive + step + n) % n;
    },

    fineEnter(ev) {
      if (!this.fineOpen) return;
      ev.preventDefault(); // Enter ไม่ส่งฟอร์มตอนกำลังเลือก
      const o = this.fineOptions[this.fineActive];
      if (o) this.pickFine(o);
    },

    get fineRuleList() {
      return this.fineRules.filter((r) => r.section === this.fine.category);
    },

    get selectedRule() {
      return this.fineRules.find((r) => String(r.id) === String(this.fine.rule_id)) || null;
    },

    /** คำใบ้จำนวนเงินจากกฎ: ถ้ากฎระบุช่วง/หลายอัตรา แสดงช่วงให้เลือกเอง */
    get amountHint() {
      const r = this.selectedRule;
      if (r && r.amount_min === null) return 'กฎข้อนี้ไม่ได้ระบุจำนวนเงิน (กรอกเองได้)';
      if (!r || r.amount_min === r.amount_max) return '';
      return `กฎระบุ ${Number(r.amount_min).toLocaleString('th-TH')} – ${Number(r.amount_max).toLocaleString('th-TH')} WVDL (ตามดุลยพินิจ)`;
    },

    shorten(text, n) {
      const t = String(text || '').replace(/\s+/g, ' ').trim();
      return t.length > n ? t.slice(0, n - 1) + '…' : t;
    },

    onFineCategory() {
      this.fineQuery = '';
      this.fine.rule_id = '';
      this.fine.amount = '';
      this.fine.units = '';
      delete this.dlgErrors.rule_id;
    },

    /** เลือกข้อกฎแล้วเติมจำนวนเงินให้เมื่อกฎระบุจำนวนเดียว (แก้ได้) */
    onFineRule() {
      delete this.dlgErrors.rule_id;
      delete this.dlgErrors.charge;
      const r = this.selectedRule;
      this.fine.amount = r && r.amount ? String(r.amount) : '';
      this.fine.units = r && r.penance ? String(r.penance) : '';
    },

    /** สลับ ค่าปรับ / ส่งบำเพ็ญ แล้วเติมค่าจากกฎข้อที่เลือกให้ตามชนิด */
    setFineType(type) {
      this.fine.type = type;
      this.dlgErrors = {};
      const r = this.selectedRule;
      if (type === 'fine' && !this.fine.amount) this.fine.amount = r && r.amount ? String(r.amount) : '';
      if (type === 'penance' && !this.fine.units) this.fine.units = r && r.penance ? String(r.penance) : '';
    },

    /** คำใบ้จำนวนไม้จากกฎ (กฎบางข้อไม่ได้ระบุบำเพ็ญ จะแจ้งให้ทราบ) */
    get penanceHint() {
      const r = this.selectedRule;
      if (!r) return '';
      if (r.penance === null && r.penance_min === null) return 'กฎข้อนี้ไม่ได้ระบุให้ส่งบำเพ็ญ (กรอกจำนวนไม้เองได้)';
      if (r.penance_min !== r.penance_max) return `กฎระบุ ${Number(r.penance_min).toLocaleString('th-TH')} – ${Number(r.penance_max).toLocaleString('th-TH')} ไม้`;
      return '';
    },

    /** Blacklist/คว่ำบาตร เปลี่ยนด้วยปุ่มรายงานตัวไม่ได้ */
    get reportLocked() {
      return !!this.g && !['normal', 'inactive'].includes(this.g.status);
    },

    get dlgTitle() {
      const n = this.g ? ' ' + this.g.name : '';
      return { report: 'บันทึกรายงานตัว' + n, protect: 'จัดการ Protect' + n, fine: 'บันทึกค่าปรับ' }[this.dlg] || '';
    },

    dlgErr(k) {
      return this.dlgErrors[k] || '';
    },

    openDlg(key) {
      this.dlgErrors = {};
      if (key === 'report') this.rep = { status: ['normal', 'inactive'].includes(this.g.status) ? this.g.status : 'normal', note: '' };
      if (key === 'protect') this.pro = { until: this.toThaiLocal(this.g.protect_until) };
      if (key === 'fine') {
        this.fineQuery = '';
        this.fineOpen = false;
        this.fineActive = 0;
      }
      if (key === 'fine') this.fine = { type: 'fine', category: (this.fineSections[0] || {}).value || '', rule_id: '', amount: '', units: '', charge: '', note: '' };
      this.dlg = key;
    },

    closeDlg() {
      if (this.dlgBusy) return;
      this.dlg = null;
    },

    async submitDlg() {
      const key = this.dlg;
      let body;
      if (key === 'report') {
        body = { kind: 'report', status: this.rep.status, note: this.rep.note.trim() || null };
      } else if (key === 'protect') {
        body = { kind: 'protect', protect_until: this.pro.until ? this.pro.until + ':00+07:00' : null };
      } else {
        const penance = this.fine.type === 'penance';
        const raw = String(penance ? this.fine.units : this.fine.amount).replace(/[,\s]/g, '');
        const other = this.fine.rule_id === 'other';
        const er = {};
        if (!this.fine.rule_id) er.rule_id = 'กรุณาเลือกข้อหา (ข้อกฎ) หรือ "อื่นๆ"';
        if (other && !this.fine.charge.trim()) er.charge = 'กรุณากรอกข้อหา';
        if (!/^\d+$/.test(raw) || Number(raw) < 1) er[penance ? 'penance_units' : 'amount'] = raw ? (penance ? 'จำนวนไม้ต้องเป็นตัวเลขมากกว่า 0' : 'จำนวนเงินต้องเป็นตัวเลข') : (penance ? 'กรุณากรอกจำนวนไม้ที่ต้องบำเพ็ญ' : 'กรุณากรอกจำนวนเงินในเกม');
        if (Object.keys(er).length) {
          this.dlgErrors = er;
          return;
        }
        // เลือกข้อกฎ: เซิร์ฟเวอร์ดึงหมวด/ข้อหาจากข้อกฎเอง; อื่นๆ: ระบุหมวด+ข้อหาเอง
        body = { kind: 'fine', penalty_type: penance ? 'penance' : 'fine', note: this.fine.note.trim() || null };
        if (penance) body.penance_units = Number(raw);
        else body.amount = Number(raw);
        if (other) {
          body.category = this.fine.category;
          body.charge = this.fine.charge.trim();
        } else {
          body.rule_id = Number(this.fine.rule_id);
        }
      }

      this.dlgBusy = true;
      this.dlgErrors = {};
      try {
        await API.post(`/api/council/groups/${this.g.id}/records`, body);
        this.dlgBusy = false;
        this.dlg = null;
        const okMsg = { report: this.rep.status === 'inactive' ? 'บันทึกสถานะ ขาดการเคลื่อนไหว แล้ว' : 'บันทึกรายงานตัวแล้ว', protect: body.protect_until ? 'ปรับ Protect แล้ว' : 'ยกเลิก Protect แล้ว', fine: this.fine.type === 'penance' ? 'บันทึกส่งบำเพ็ญแล้ว' : 'บันทึกค่าปรับแล้ว' }[key];
        await this.openById(this.g.id);
        this.msg = okMsg;
      } catch (e) {
        this.dlgErrors = e.status === 422 && e.errors ? Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])) : { _: firstError(e, 'บันทึกไม่สำเร็จ') };
        if (e.status === 422 && !e.errors) this.dlgErrors = { _: e.message };
      } finally {
        this.dlgBusy = false;
      }
    },

    // ── สร้างธุรกรรม ──
    resetTxn() {
      if (this.txLogoPreview) URL.revokeObjectURL(this.txLogoPreview);
      this.txLogoPreview = '';
      this.txn = blankTxn();
      this.txErrors = {};
      this.txMsg = '';
    },

    txErr(k) {
      return this.txErrors[k] || '';
    },

    /** ตัวอย่างโลโก้ในฟอร์มเปลี่ยนสี: พื้นหลังตามโค้ดสีที่กรอก และตัวอักษรสลับขาว/ดำให้อ่านออก */
    get txColorStyle() {
      const h = this.txn.color_hex;
      if (!/^#[0-9a-f]{6}$/i.test(h || '')) return '';
      const lum = (parseInt(h.slice(1, 3), 16) * 299 + parseInt(h.slice(3, 5), 16) * 587 + parseInt(h.slice(5, 7), 16) * 114) / 1000;
      return `background:${h};color:${lum > 150 ? '#111' : '#fff'}`;
    },

    pickTxZip(ev) {
      const file = ev.target.files[0];
      this.txn.outfit_zip = null;
      delete this.txErrors.outfit_zip;
      if (!file) return;
      if (!file.name.toLowerCase().endsWith('.zip')) {
        this.txErrors.outfit_zip = 'ต้องเป็นไฟล์ .ZIP เท่านั้น';
        ev.target.value = '';
      } else if (file.size > 50 * 1024 * 1024) {
        this.txErrors.outfit_zip = 'ไฟล์ใหญ่เกิน 50 MB';
        ev.target.value = '';
      } else {
        this.txn.outfit_zip = file;
      }
    },

    pickTxLogo(ev) {
      const file = ev.target.files[0];
      this.txn.logo = null;
      if (this.txLogoPreview) URL.revokeObjectURL(this.txLogoPreview);
      this.txLogoPreview = '';
      delete this.txErrors.logo;
      if (!file) return;
      if (!file.name.toLowerCase().endsWith('.png')) {
        this.txErrors.logo = 'ต้องเป็นไฟล์ .PNG เท่านั้น';
        ev.target.value = '';
      } else if (file.size > 2 * 1024 * 1024) {
        this.txErrors.logo = 'ไฟล์ใหญ่เกิน 2 MB';
        ev.target.value = '';
      } else {
        this.txn.logo = file;
        this.txLogoPreview = URL.createObjectURL(file);
      }
    },

    /** รายชื่อสมาชิกที่ออก: แยกด้วยบรรทัดใหม่/จุลภาค ตัดช่องว่าง ตัดชื่อซ้ำ (ไม่สนตัวพิมพ์) */
    get releaseNames() {
      const seen = new Set();
      const out = [];
      for (const raw of String(this.txn.member_names || '').split(/[\r\n,]+/)) {
        const n = raw.trim();
        if (n && !seen.has(n.toLowerCase())) {
          seen.add(n.toLowerCase());
          out.push(n);
        }
      }
      return out;
    },

    /** รายการค่าบริการของธุรกรรมที่กำลังกรอก (ออกสล็อต/ออกลอย = ราคาต่อคน x จำนวนที่ออก) */
    get feeLines() {
      const f = this.fees;
      switch (this.txn.kind) {
        case 'rename':
          return [{ label: 'เปลี่ยนชื่อ', amount: f.rename }, { label: 'เปลี่ยนโลโก้', amount: f.rename_logo }];
        case 'recolor':
          return [{ label: 'เปลี่ยนสี', amount: f.recolor }];
        case 'change_leader':
          return [{ label: 'เปลี่ยน Leader', amount: f.change_leader }];
        case 'dissolve':
          return [{ label: 'ยุบ', amount: f.dissolve }];
        case 'release_slot':
        case 'release_free': {
          const n = this.releaseNames.length;
          const unit = Number(f[this.txn.kind] || 0);
          return [{ label: `${this.txn.kind === 'release_slot' ? 'ออกสล็อต' : 'ออกลอย'} ${this.money(unit)} × ${n} คน`, amount: unit * n }];
        }
        default:
          return [];
      }
    },

    get feeTotal() {
      return this.feeLines.reduce((sum, l) => sum + Number(l.amount || 0), 0);
    },

    money(n) {
      return Number(n || 0).toLocaleString('th-TH') + ' ' + (this.fees.currency || 'WVDL');
    },

    /** ตรวจชื่อ/ชื่อย่อ/ชื่อสีของธุรกรรมก่อนส่ง (เซิร์ฟเวอร์ตรวจซ้ำอีกชั้น) */
    txnPreCheck() {
      const t = this.txn;
      const er = {};
      if (t.kind === 'rename') {
        if (!ENGLISH_NAME.test(t.name.trim())) er.name = 'ชื่อต้องเป็นภาษาอังกฤษ (ใช้ตัวเลขร่วมได้) และมีตัวอักษรอย่างน้อย 1 ตัว';
        if (!SHORT_NAME.test(t.short_name.trim())) er.short_name = 'ชื่อย่อต้องเป็นตัวพิมพ์ใหญ่ภาษาอังกฤษหรือตัวเลข 1–4 ตัวอักษร';
      }
      if ((t.kind === 'release_slot' || t.kind === 'release_free') && !this.releaseNames.length) er.member_names = 'กรุณากรอกชื่อสมาชิกที่ออกอย่างน้อย 1 ชื่อ';
      if ((t.kind === 'release_slot' || t.kind === 'release_free') && this.releaseNames.length > 50) er.member_names = 'ออกพร้อมกันได้ไม่เกิน 50 ชื่อต่อธุรกรรม';
      if (t.kind === 'recolor') {
        if (!t.outfit_zip) er.outfit_zip = 'กรุณาแนบไฟล์ชุดใหม่ (.ZIP)';
        const msg = /^#[0-9A-Fa-f]{6}$/.test(t.color_hex.trim()) ? colorNameError(t.color_hex.trim(), t.color_name) : '';
        if (msg) er.color_name = msg;
      }
      return er;
    },

    async submitTxn() {
      const t = this.txn;
      const pre = this.txnPreCheck();
      if (Object.keys(pre).length) {
        this.txErrors = pre;
        return;
      }
      const fd = new FormData();
      fd.append('kind', t.kind);
      fd.append('group_id', this.g.id);
      const put = (k, v) => fd.append(`payload[${k}]`, v);
      if (t.kind === 'rename') {
        put('name', t.name.trim());
        put('short_name', t.short_name.trim());
        if (t.logo) fd.append('payload[logo]', t.logo);
      } else if (t.kind === 'recolor') {
        put('color_hex', t.color_hex.trim());
        put('color_name', t.color_name.trim());
        if (t.outfit_zip) fd.append('payload[outfit_zip]', t.outfit_zip);
      } else if (t.kind === 'change_leader') {
        put('name', t.leader_name.trim());
        put('phone', t.leader_phone.trim());
        put('discord_id', t.leader_discord.trim());
      } else if (t.kind === 'release_slot' || t.kind === 'release_free') {
        this.releaseNames.forEach((n) => fd.append('payload[member_names][]', n));
      } else {
        put('reason', t.reason.trim());
      }

      if (t.kind === 'dissolve' && !(await UI.ask(`ยุบ ${this.g.name} ? สมาชิกทุกคนจะถูกนำออกและกลุ่มออกจากทะเบียนที่ใช้งาน`, { kind: 'danger', confirmText: 'ยุบกลุ่ม' }))) return;

      this.txBusy = true;
      this.txErrors = {};
      this.txMsg = '';
      try {
        const res = await API.postForm('/api/council/transactions', fd);
        const number = res.data.number;
        if (t.kind === 'dissolve') {
          this.close();
          this.msg = `ยุบกลุ่มเรียบร้อย (${number})`;
          return;
        }
        this.resetTxn();
        await this.openById(this.g.id);
        this.tab = 'info';
        this.msg = `บันทึกธุรกรรม ${number} เรียบร้อย`;
      } catch (e) {
        if (e.status === 422 && e.errors) {
          this.txErrors = Object.fromEntries(Object.entries(e.errors).map(([key, v]) => [key.replace(/^payload\./, ''), v[0]]));
        } else {
          this.txErrors = { _: e.status === 403 && /เวร/.test(e.message || '') ? 'ต้องเข้าเวรก่อนทำธุรกรรม' : firstError(e, 'บันทึกธุรกรรมไม่สำเร็จ') };
        }
      } finally {
        this.txBusy = false;
      }
    },

    clearTempPreview() {
      if (this.tempPreview) URL.revokeObjectURL(this.tempPreview);
      this.tempPreview = '';
    },

    /** โหลดรูปชุดชั่วคราวมาแสดง (ไฟล์เก็บส่วนตัว จึงดึงผ่าน API แล้วทำ object URL) */
    async loadTempPreview() {
      this.clearTempPreview();
      const g = this.g;
      if (!g || !g.has_temp_outfit) return;
      this.tempLoading = true;
      try {
        const res = await fetch(`${window.APP_CONFIG.API_URL}/api/council/groups/${g.id}/files/temp_outfit`, { credentials: 'include' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const url = URL.createObjectURL(await res.blob());
        if (this.g && this.g.id === g.id) this.tempPreview = url;
        else URL.revokeObjectURL(url); // เปลี่ยน/ปิดกลุ่มไปแล้วระหว่างโหลด
      } catch (e) {
        this.tempPreview = '';
      } finally {
        this.tempLoading = false;
      }
    },

    openTempPreview() {
      if (this.tempPreview) window.open(this.tempPreview, '_blank', 'noopener');
    },

    async download(kind) {
      this.err = '';
      try {
        const res = await fetch(`${window.APP_CONFIG.API_URL}/api/council/groups/${this.g.id}/files/${kind}`, { credentials: 'include' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const blob = await res.blob();
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `${this.g.name}-${kind}.${kind.startsWith('outfit_zip') ? 'zip' : 'png'}`;
        a.click();
        setTimeout(() => URL.revokeObjectURL(a.href), 1000);
      } catch (e) {
        this.err = 'ดาวน์โหลดไฟล์ไม่สำเร็จ';
      }
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
      return !!g && (this.edit.name !== g.name || this.edit.logo_url !== (g.logo_url || ''));
    },

    saveInfo() {
      const body = { logo_url: this.edit.logo_url.trim() || null };
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
      const body = { character_name: this.newMember.character_name.trim(), position: this.newMember.position };
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
    // เก็บ/แสดงเฉพาะหัวหน้าและรองหัวหน้า
    get activeMembers() {
      return this.g ? this.g.members.filter((m) => !m.left_at && m.position !== 'member') : [];
    },

    get pastMembers() {
      return this.g ? this.g.members.filter((m) => m.left_at && m.position !== 'member') : [];
    },

    // ยังไม่ถึงขั้นต่ำสำหรับขึ้นทะเบียน (นับทั้งตัวจริงและสำรอง)
    get belowMinimum() {
      return !!this.g && !!this.g.slots && this.activeMembers.length < this.g.slots.min;
    },

    get leaderMember() {
      return this.activeMembers.find((m) => m.position === 'leader') || null;
    },

    get coLeaders() {
      return this.activeMembers.filter((m) => m.position === 'co_leader');
    },

    /** ป้ายที่หัวการ์ดสรุป: "Protect" เมื่อเปิดอยู่ ถ้าไม่มีก็ไม่แสดง */
    get protectBadge() {
      return this.protectOn ? 'Protect' : '';
    },

    /** Protect เปิดอยู่ไหม: มีวันสิ้นสุดและยังไม่ถึง */
    get protectOn() {
      const g = this.g;
      return !!g && !!g.protect_until && new Date(g.protect_until) > new Date();
    },

    /** เริ่ม Protect: วันที่บันทึกไว้ ถ้าไม่มี (ทะเบียนเก่า) ใช้เวลาที่กดขึ้นทะเบียน เมื่อเคยตั้ง Protect */
    get protectStart() {
      const g = this.g;
      if (!g) return '';
      return g.protect_from || (g.protect_until ? g.registered_at : '');
    },

    get tempOutfitText() {
      const g = this.g;
      const parts = [];
      if (g && g.temp_outfit_color) parts.push('สีเบอร์ ' + g.temp_outfit_color);
      if (g && g.temp_outfit_male) parts.push('ชาย ' + g.temp_outfit_male);
      if (g && g.temp_outfit_female) parts.push('หญิง ' + g.temp_outfit_female);
      return parts.join(' · '); // ว่าง = ไม่ระบุ (หน้าจอซ่อนแถวนี้)
    },

    whenFull(iso) {
      const d = new Date(iso);
      return !iso || isNaN(d) ? '—' : fmtThai(d);
    },

    get canDelete() {
      return !!this.me && ['vice_president', 'president'].includes(this.me.role);
    },

    kindOf(r) {
      return r.type === 'family' ? 'FAMILY' : r.is_lady ? 'LADY' : 'GANG';
    },

    get scopeLabel() {
      return { active: 'ทะเบียนที่ใช้งานอยู่', dissolved: 'ทะเบียนที่ยุบแล้ว', deleted: 'ทะเบียนที่ลบแล้ว' }[this.scope];
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
