// หน้า "สร้างสตอรี่ / แก้ไขข้อมูลสตอรี่" (story-form.html[?id=]): กรอกสัญญา → เซิร์ฟเวอร์ตรวจกติกา (StoryService) → กลับหน้ารายการ
const SF_TYPES = [
  { value: 'gang', label: 'GANG' },
  { value: 'lady', label: 'LADY-GANG' },
  { value: 'family', label: 'FAMILY' },
];
const SF_PHASE = { waiting: 'รอเข้าสู่การเล่น', playing: 'กำลังเล่น' };

const sfThai = (d) =>
  new Intl.DateTimeFormat('th-TH-u-ca-buddhist', { timeZone: 'Asia/Bangkok', day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false }).format(d).replace(',', ' เวลา');

/** ISO → ค่าของ <input type="datetime-local"> เป็นเวลาไทย */
const toBangkokInput = (iso) => {
  const d = new Date(iso);
  if (!iso || isNaN(d)) return '';
  const p = Object.fromEntries(new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Bangkok', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(d).map((x) => [x.type, x.value]));
  return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}`;
};
/** ค่า datetime-local (ถือเป็นเวลาไทย) → Date */
const fromBangkokInput = (v) => (v ? new Date(v + ':00+07:00') : null);

function storyFormPage() {
  const firstError = (e, fallback) => (e.errors && Object.values(e.errors)[0][0]) || e.message || fallback;

  return {
    ready: false,
    id: new URLSearchParams(location.search).get('id'),
    types: SF_TYPES,
    options: { formats: [], venues: [], ticket_options: ['ใบเป็นใบ', 'ไม่แจ้งใบ'], stake_min: 3000000, stake_max: 5000000, stake_enforce: false, stake_cap: 1000000000, stake_presets: [3000000, 4000000, 5000000], fights_options: [5, 6, 7], fights_custom: false, fights_min: 1, fights_max: 30, lock_minutes: 15 },
    f: {
      story_type: 'gang', play_format: 'random', starts_at: '', a: null, b: null, fights: '', fights_custom: '', venue: 'random', stake_amount: '', stake_late: 'none',
      guns: '', ticket_policy: 'ใบเป็นใบ', reserve_swap: 'no', slot_change: 'no', toxic: '70', extra_conditions: '',
    },
    combo: { a: { q: '', open: false, items: [] }, b: { q: '', open: false, items: [] } },
    errors: {},
    busy: false,
    loading: false,
    loadError: '',
    warnings: [],

    get editing() {
      return !!this.id;
    },

    async init() {
      await authState().require('member');
      this.ready = true;
      try {
        const res = await API.get('/api/council/stories?per_page=1');
        this.options = { ...this.options, ...(res.options || {}) };
        if (!this.options.ticket_options.includes(this.f.ticket_policy)) this.f.ticket_policy = this.options.ticket_options[0];
        if (this.id) await this.loadStory();
      } catch (e) {
        this.loadError = e.status === 403 ? 'ทำรายการได้เฉพาะตอนเข้าเวร' : API.describe(e, 'โหลดข้อมูลไม่สำเร็จ');
      }
      this.$watch('f.story_type', () => this.onTypeChange());
    },

    async loadStory() {
      const s = (await API.get(`/api/council/stories/${this.id}`)).data;
      if (s.status !== 'active') {
        this.loadError = 'แก้ไขได้เฉพาะสตอรี่ที่ยังไม่สิ้นสุด';
        return;
      }
      this.orig = s;
      this.f = {
        story_type: s.story_type, play_format: s.play_format_random ? 'random' : s.play_format, starts_at: toBangkokInput(s.starts_at), a: s.group_a, b: s.group_b,
        fights: this.fightsChoice(s.fights), fights_custom: this.options.fights_options.includes(s.fights) || !this.options.fights_custom ? '' : String(s.fights), venue: s.venue_random ? 'random' : s.venue, stake_amount: String(s.stake_amount), stake_late: s.stake_late_a && s.stake_late_b ? 'both' : s.stake_late_a ? 'a' : s.stake_late_b ? 'b' : 'none', guns: String(s.guns),
        ticket_policy: s.ticket_policy, reserve_swap: s.allow_reserve_swap ? 'yes' : 'no', slot_change: s.allow_slot_change ? 'yes' : 'no',
        toxic: s.toxic_level === 100 ? '100' : '70', extra_conditions: s.extra_conditions || '',
      };
    },

    err(k) {
      return this.errors[k] || '';
    },

    // ── ตัวเลือกแบบติ๊ก (เลือกได้ข้อเดียวต่อหัวข้อ: ติ๊กข้อใหม่ ข้อเดิมจะหลุด) ──
    get choiceGroups() {
      const yn = [{ value: 'yes', label: 'อนุญาต' }, { value: 'no', label: 'ไม่อนุญาต' }];
      return [
        {
          key: 'stake_late', label: 'ชำระเดิมพันหลังเริ่ม', hint: 'ไม่บังคับ · ฝั่งที่ระบุจ่ายเป็น 2 เท่า (DEAL-011)', wide: true,
          options: [{ value: 'none', label: 'ไม่มี' }, { value: 'a', label: 'ฝั่ง A', hint: 'ชำระหลังเริ่ม' }, { value: 'b', label: 'ฝั่ง B', hint: 'ชำระหลังเริ่ม' }, { value: 'both', label: 'ทั้งสองฝั่ง', hint: 'ชำระหลังเริ่ม' }],
        },
        { key: 'ticket_policy', label: 'การแจ้งใบ', options: this.options.ticket_options.map((v) => ({ value: v, label: v })) },
        // ครอบครัวไม่มีตัวสำรอง จึงไม่มีตัวเลือกสลับ Slot ตัวสำรอง
        ...(this.f.story_type === 'family' ? [] : [{ key: 'reserve_swap', label: 'การสลับ Slot ตัวสำรอง', options: yn }]),
        { key: 'slot_change', label: 'การเพิ่ม-ถอน Slot', options: yn },
        { key: 'toxic', label: 'ปลด TOXIC', options: [{ value: '70', label: '70%', hint: 'ตามกฎประเทศ' }, { value: '100', label: '100%', hint: 'ปลดเต็มที่' }] },
      ];
    },

    setOpt(key, value) {
      this.f[key] = value; // ติ๊กซ้ำที่ข้อเดิมจะไม่หลุด (ต้องมีค่าเสมอ)
    },

    // ── ช่องเลือก (ปุ่ม + รายการแบบเดียวกับช่องเลือกฝั่ง A/B) ──
    openSel: '',
    orig: null, // สตอรี่เดิมที่กำลังแก้ไข

    optionsFor(key) {
      const yn = [{ value: 'yes', label: 'อนุญาต' }, { value: 'no', label: 'ไม่อนุญาต' }];
      const named = (list, cur) => [{ value: 'random', label: 'สุ่มโดยระบบ' }, ...this.withCurrent(list, cur).map((v) => ({ value: v, label: v }))];
      switch (key) {
        case 'story_type':
          return this.types;
        case 'play_format':
          return named(this.options.formats, this.f.play_format);
        case 'venue':
          return named(this.options.venues, this.f.venue);
        case 'fights': {
          // รายการจากเซิร์ฟเวอร์ (+ ค่าเดิมของสตอรี่ที่แก้อยู่ ถ้าไม่อยู่ในรายการแล้ว) + "กำหนดเอง" เมื่อเปิดโหมดนี้
          const nums = [...this.options.fights_options];
          const cur = Number(this.f.fights);
          if (this.f.fights && this.f.fights !== 'custom' && !nums.includes(cur)) nums.push(cur);
          const list = nums.sort((x, y) => x - y).map((n) => ({ value: String(n), label: n + ' ไฟต์' }));
          if (this.options.fights_custom) list.push({ value: 'custom', label: 'กำหนดเอง...' });
          return list;
        }
        case 'stake_late':
          return [{ value: 'none', label: 'ไม่มี' }, { value: 'a', label: 'ฝั่ง A ชำระหลังเริ่ม' }, { value: 'b', label: 'ฝั่ง B ชำระหลังเริ่ม' }, { value: 'both', label: 'ทั้งสองฝั่งชำระหลังเริ่ม' }];
        default:
          return [];
      }
    },

    /** ค่าที่ใช้ในช่องเลือกของจำนวนไฟต์ (นอกรายการและเปิดโหมดกำหนดเอง = custom) */
    fightsChoice(n) {
      return this.options.fights_options.includes(n) || !this.options.fights_custom ? String(n) : 'custom';
    },

    get fightsNum() {
      return this.f.fights === 'custom' ? Number(this.f.fights_custom) : Number(this.f.fights);
    },

    labelFor(key) {
      const o = this.optionsFor(key).find((x) => x.value === this.f[key]);
      return o ? o.label : '— เลือก —';
    },

    toggleSel(key) {
      if (this.editing && key === 'story_type') return;
      this.openSel = this.openSel === key ? '' : key;
    },

    closeSel(key) {
      if (this.openSel === key) this.openSel = '';
    },

    choose(key, value) {
      this.f[key] = value;
      this.openSel = '';
      delete this.errors[key];
    },

    // ── เลือกกลุ่ม (ช่องค้นหา + รายการ) ──
    async onTypeChange() {
      if (this.f.story_type === 'family') this.f.reserve_swap = 'no';
      if (this.editing) return; // แก้ไขเปลี่ยนคู่/ประเภทไม่ได้
      this.f.a = null;
      this.f.b = null;
      this.combo.a = { q: '', open: false, items: [] };
      this.combo.b = { q: '', open: false, items: [] };
    },

    async fetchGroups(side) {
      const box = this.combo[side];
      const p = new URLSearchParams({ per_page: 30, kind: this.f.story_type });
      if (box.q.trim()) p.set('q', box.q.trim());
      try {
        const res = await API.get(`/api/council/groups?${p}`);
        const other = this.f[side === 'a' ? 'b' : 'a'];
        box.items = res.data.filter((g) => !other || other.id !== g.id);
      } catch (e) {
        box.items = [];
      }
    },

    async toggleList(side) {
      if (this.editing) return;
      const box = this.combo[side];
      box.open = !box.open;
      if (box.open) await this.fetchGroups(side);
    },

    async typing(side) {
      if (this.editing) return;
      this.combo[side].open = true;
      await this.fetchGroups(side);
    },

    pick(side, g) {
      this.f[side] = g;
      this.combo[side] = { q: '', open: false, items: [] };
      delete this.errors['group_' + side + '_id'];
    },

    clear(side) {
      if (this.editing) return;
      this.f[side] = null;
    },

    // ── คำนวณแสดงสด ──
    get startsDate() {
      return fromBangkokInput(this.f.starts_at);
    },

    get autoStatus() {
      const d = this.startsDate;
      if (!d || isNaN(d)) return { title: 'กรอกวันและเวลาเริ่มเล่น', sub: '' };
      return { title: Date.now() < d.getTime() ? SF_PHASE.waiting : SF_PHASE.playing, sub: 'ก่อนถึงเวลา: รอเข้าสู่การเล่น · ถึงเวลา: กำลังเล่น · บันทึกคะแนนถึงจำนวนไฟต์: สิ้นสุด' };
    },

    get lockInfo() {
      const d = this.startsDate;
      const m = this.options.lock_minutes;
      // ไม่อนุญาตทั้งสลับสล็อตตัวสำรองและเพิ่ม-ถอนสล็อต = ล็อกทันทีที่สร้าง (ไม่ต้องรอเวลา)
      const noReserve = this.f.story_type === 'family'; // ครอบครัวไม่มีตัวสำรอง
      if ((noReserve || this.f.reserve_swap === 'no') && this.f.slot_change === 'no') {
        return { title: 'ล็อก Slot ทันทีที่บันทึกสตอรี่', sub: 'เพราะ' + (noReserve ? 'ไม่อนุญาตการเพิ่ม-ถอน Slot' : 'ไม่อนุญาตทั้งการสลับ Slot ตัวสำรองและการเพิ่ม-ถอน Slot') + ' · ปลดล็อกเมื่อสตอรี่สิ้นสุดหรือยกเลิก' };
      }
      if (!d || isNaN(d)) return { title: 'กรอกวันและเวลาเริ่มเล่น', sub: `ล็อก Slot ${m} นาทีก่อนเริ่มเล่น` };
      return { title: sfThai(new Date(d.getTime() - m * 60000)), sub: `ล็อก Slot ${m} นาทีก่อนเริ่มเล่น · ปลดล็อกเมื่อสตอรี่สิ้นสุดหรือยกเลิก` };
    },

    /** แก้ไขสตอรี่ที่เคยสุ่มไว้และไม่ได้เปลี่ยนตัวเลือก: ส่งค่าที่สุ่มได้เดิม + flag เพื่อไม่สุ่มใหม่ */
    randomPart(key) {
      const v = this.f[key];
      if (this.editing && v === 'random' && this.orig && this.orig[key + '_random']) return { [key]: this.orig[key], [key + '_random']: true };
      return { [key]: v };
    },

    money: (n) => Number(n || 0).toLocaleString('th-TH'),

    setStake(n) {
      this.f.stake_amount = String(n);
      delete this.errors.stake_amount;
    },

    get stakeNum() {
      const s = String(this.f.stake_amount ?? '').replace(/[,\s]/g, '');
      return /^\d{1,10}$/.test(s) ? Number(s) : null;
    },

    get stakeHint() {
      const n = this.stakeNum;
      const o = this.options;
      const range = `${this.money(o.stake_min)}–${this.money(o.stake_max)}`;
      if (n === null || n === 0 || (n >= o.stake_min && n <= o.stake_max)) return '';
      return o.stake_enforce ? `ต้องอยู่ในช่วง ${range} WVDL` : `นอกมาตรฐาน ${range} WVDL ต้องเป็นข้อตกลงของสองฝั่ง (DEAL-010)`;
    },

    /** ตัวเลือกจากรายการที่ตั้งไว้ + ค่าเดิมของสตอรี่ที่กำลังแก้ (เผื่อถูกถอดออกจากรายการภายหลัง) */
    withCurrent(list, value) {
      return value && value !== 'random' && !list.includes(value) ? [...list, value] : list;
    },

    // ── ส่ง ──
    async save() {
      const f = this.f;
      const er = {};
      if (!f.play_format) er.play_format = 'กรุณาเลือกรูปแบบการเล่น';
      if (!f.starts_at) er.starts_at = 'กรุณากรอกวันและเวลาเริ่มเล่น';
      if (!f.a) er.group_a_id = 'กรุณาเลือกฝั่ง A';
      if (!f.b) er.group_b_id = 'กรุณาเลือกฝั่ง B';
      if (!f.fights) er.fights = 'กรุณาเลือกจำนวนไฟต์';
      else if (f.fights === 'custom' && !(this.fightsNum >= this.options.fights_min && this.fightsNum <= this.options.fights_max)) er.fights = `กรอกจำนวนไฟต์ ${this.options.fights_min}–${this.options.fights_max}`;
      if (this.stakeNum === null) er.stake_amount = 'กรุณากรอกเดิมพันฝั่งละเป็นตัวเลข (ไม่มี = 0)';
      else if (this.stakeNum > this.options.stake_cap) er.stake_amount = `เดิมพันต้องไม่เกิน ${this.money(this.options.stake_cap)} WVDL`;
      else if (this.options.stake_enforce && this.stakeNum !== 0 && this.stakeHint) er.stake_amount = this.stakeHint;
      if (!/^\d{1,2}$/.test(String(f.guns))) er.guns = 'กรุณากรอกจำนวนปืน (ไม่เล่นปืน = 0)';
      if (Object.keys(er).length) {
        this.errors = er;
        this.$nextTick(() => document.querySelector('[aria-invalid="true"]')?.scrollIntoView({ block: 'center', behavior: 'smooth' }));
        return;
      }
      this.busy = true;
      this.errors = {};
      const body = {
        story_type: f.story_type,
        ...this.randomPart('play_format'),
        starts_at: f.starts_at, // เวลาไทย (เซิร์ฟเวอร์แปลง)
        group_a_id: f.a.id,
        group_b_id: f.b.id,
        fights: this.fightsNum,
        ...this.randomPart('venue'),
        stake_amount: this.stakeNum,
        stake_late: f.stake_late,
        guns: Number(f.guns),
        ticket_policy: f.ticket_policy || this.options.ticket_options[0],
        allow_reserve_swap: f.story_type === 'family' ? false : f.reserve_swap === 'yes',
        allow_slot_change: f.slot_change === 'yes',
        toxic_level: Number(f.toxic),
        extra_conditions: f.extra_conditions.trim() || null,
      };
      try {
        const res = this.editing ? await API.put(`/api/council/stories/${this.id}`, body) : await API.post('/api/council/stories', body);
        // กลับหน้ารายการ พร้อมเปิดรายละเอียดของสตอรี่ที่เพิ่งบันทึก
        location.href = `/stories.html?id=${res.data.id}`;
      } catch (e) {
        if (e.status === 422 && e.errors) this.errors = Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]]));
        else this.errors = { _: firstError(e, 'บันทึกสตอรี่ไม่สำเร็จ') };
        this.busy = false;
      }
    },
  };
}
