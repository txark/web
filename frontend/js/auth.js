// สถานะผู้ใช้ปัจจุบัน + guard หน้า. ใช้ผ่าน Alpine: x-data="authState()"
// Admin อยู่นอกลำดับสภา: ไม่ผ่านการตรวจสิทธิ์ระดับใด ๆ (ใช้ can('admin') หรือ require(..., { also: ['admin'] }))
const ROLE_LEVEL = { gang: 1, family: 1, trainee: 2, member: 2, operations: 2, senior: 2, secretary: 2, vice_president: 3, president: 4, admin: 0 };

// เข้า/ออกเวรของสภา: สถานะกลางที่ทุกส่วนของหน้าใช้ร่วมกัน (เมนูบน หน้าแรก ตัวตรวจสิทธิ์)
// เวลานับจากนาฬิกาเซิร์ฟเวอร์ (offset) ไม่ใช่นาฬิกาเครื่องผู้ใช้ จึงแก้เวลาเครื่องเพื่อโกงชั่วโมงเวรไม่ได้
const Duty = (() => {
  let state = null;
  let loading = null;
  const listeners = new Set();

  // หน้าที่สภาเปิดได้แม้ไม่ได้อยู่ในเวร (นอกนั้นต้องเข้าเวรก่อน)
  const FREE_PAGES = ['/', '/index.html', '/rules.html', '/login.html', '/groups.html'];

  // ส่งสัญญาณเป็นระยะว่าหน้าเว็บยังเปิดอยู่ ถ้าเงียบเกินกำหนด (ปิดเว็บ/เบราว์เซอร์/คอม) เซิร์ฟเวอร์บังคับออกเวรให้
  const HEARTBEAT_MS = 30000;
  let timer = null;

  const beat = async () => {
    try {
      const was = state && state.on_duty;
      const d = apply((await API.post('/api/duty/heartbeat')).data);
      // ถูกบังคับออกเวรระหว่างที่หน้านี้เปิดอยู่ (เช่น เน็ตหลุดนาน): กลับหน้าแรกถ้าหน้านี้ต้องอยู่ในเวร
      if (was && !d.on_duty && d.required && !FREE_PAGES.includes(location.pathname)) {
        location.href = '/index.html?duty=required';
      }
    } catch (e) {}
  };

  const syncHeartbeat = () => {
    if (state && state.on_duty && !timer) {
      timer = setInterval(beat, HEARTBEAT_MS);
      // กลับมาที่แท็บ/ปลุกเครื่องจากหลับ: ส่งสัญญาณทันที ไม่รอรอบถัดไป
      document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && state && state.on_duty) beat();
      });
    } else if (state && !state.on_duty && timer) {
      clearInterval(timer);
      timer = null;
    }
  };

  const apply = (d) => {
    state = { ...d, offset: Date.parse(d.server_time) - Date.now() };
    syncHeartbeat();
    listeners.forEach((fn) => fn(state));
    return state;
  };

  const pad = (n) => String(n).padStart(2, '0');

  return {
    // force = ไม่ใช้ค่าที่จำไว้
    load(force = false) {
      if (state && !force) return Promise.resolve(state);
      if (!loading || force) {
        loading = API.get('/api/duty').then((r) => apply(r.data)).finally(() => (loading = null));
      }
      return loading;
    },
    async start() {
      return apply((await API.post('/api/duty/start')).data);
    },
    // note = หมายเหตุ (จำเป็นเมื่อออกเวรก่อนครบเวลาขั้นต่ำ; เซิร์ฟเวอร์ตรวจซ้ำ)
    async end(note) {
      return apply((await API.post('/api/duty/end', note ? { note } : {})).data);
    },
    // ออกเวรตอนนี้จะสั้นกว่าเวลาขั้นต่ำที่ไม่ต้องใส่หมายเหตุไหม
    isShort() {
      return !!state && state.on_duty && this.elapsedMs() < state.short_shift_hours * 3600 * 1000;
    },
    onChange(fn) {
      listeners.add(fn);
    },
    get state() {
      return state;
    },
    // มิลลิวินาทีที่อยู่ในเวรแล้ว
    elapsedMs() {
      if (!state || !state.on_duty) return 0;
      return Math.max(0, Date.now() + state.offset - Date.parse(state.started_at));
    },
    format(ms) {
      const s = Math.floor(ms / 1000);
      return `${pad(Math.floor(s / 3600))}:${pad(Math.floor((s % 3600) / 60))}:${pad(s % 60)}`;
    },
  };
})();

function authState() {
  return {
    user: null,
    loading: true,
    duty: null, // null = ไม่เกี่ยว (ไม่ใช่สภา) หรือยังโหลดไม่เสร็จ
    tick: 0,

    async init() {
      try {
        this.user = (await API.get('/api/user')).data;
      } catch (e) {
        this.user = null;
      } finally {
        this.loading = false;
      }
      if (this.user && this.can('member')) await this.loadDuty();
    },

    async loadDuty() {
      try {
        this.duty = await Duty.load();
        Duty.onChange((s) => (this.duty = s));
        setInterval(() => this.tick++, 1000); // ให้เวลาที่แสดงเดินทุกวินาที
      } catch (e) {}
    },

    can(minRole) {
      if (!this.user) return false;
      if (this.user.role === 'admin' || minRole === 'admin') return this.user.role === minRole;
      return (ROLE_LEVEL[this.user.role] ?? 0) >= ROLE_LEVEL[minRole];
    },

    get isAdmin() {
      return !!this.user && this.user.role === 'admin';
    },

    // เข้าเวรอยู่ หรือไม่ถูกบังคับให้เข้าเวร (ยังไม่รู้สถานะ = false; เมนูรอจน duty โหลดเสร็จก่อนค่อยแสดง)
    get dutyOk() {
      return !!this.duty && (!this.duty.required || this.duty.on_duty);
    },

    // เวลาที่อยู่ในเวร (HH:MM:SS) เปลี่ยนทุกวินาทีตาม tick
    get elapsed() {
      void this.tick;
      return Duty.format(Duty.elapsedMs());
    },

    // เรียกในหน้าที่ต้องล็อกอิน/ต้องมีสิทธิ์ขั้นต่ำ
    // สภาที่ยังไม่เข้าเวรจะถูกส่งกลับหน้าแรก (หน้าแรกและหน้ากฎไม่เรียกฟังก์ชันนี้)
    // เมื่อสั่งเปลี่ยนหน้าแล้ว จะไม่ resolve อีก เพื่อให้โค้ดโหลดข้อมูลของหน้านั้นไม่ทำงานต่อระหว่างรอเปลี่ยนหน้า
    // opts.also = บทบาทที่ผ่านด้วยแม้ไม่ถึงระดับขั้นต่ำ (เช่น ['admin'] สำหรับหน้าจัดการผู้ใช้)
    async require(minRole = 'gang', opts = {}) {
      await this.init();
      const go = (url) => {
        location.href = url;
        return new Promise(() => {});
      };
      if (!this.user) return go('/login.html');
      if (!this.can(minRole) && !(opts.also || []).includes(this.user.role)) return go('/index.html');
      if (this.duty && this.duty.required && !this.duty.on_duty) return go('/index.html?duty=required');
    },

    async logout() {
      await API.post('/api/logout');
      location.href = '/login.html';
    },
  };
}
