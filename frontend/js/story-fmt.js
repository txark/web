// ตัวช่วยแสดงผลสตอรี่ที่ใช้ร่วมกัน: หน้าสตอรี่ (stories.html) และหน้าหนังสือสัญญาตามลิงก์ (contract.html)
const STORY_TYPE_LABEL = { gang: 'แก๊ง', lady: 'แก๊งหญิงล้วน', family: 'ครอบครัว' };

const stThai = (iso, opt = {}) => {
  const d = new Date(iso);
  if (!iso || isNaN(d)) return '—';
  return new Intl.DateTimeFormat('th-TH-u-ca-buddhist', { timeZone: 'Asia/Bangkok', day: '2-digit', month: opt.long ? 'long' : 'short', year: opt.long ? 'numeric' : '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).format(d).replace(',', '');
};

/** 31 ตุลาคม 2569 (เวลาไทย) */
const stDateLong = (iso) => {
  const d = new Date(iso);
  return !iso || isNaN(d) ? '—' : new Intl.DateTimeFormat('th-TH-u-ca-buddhist', { timeZone: 'Asia/Bangkok', day: 'numeric', month: 'long', year: 'numeric' }).format(d);
};
/** 01:02 (เวลาไทย 24 ชั่วโมง) */
const stTimeHm = (iso) => {
  const d = new Date(iso);
  return !iso || isNaN(d) ? '—' : new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Bangkok', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(d);
};

const StoryFmt = {
  thai: stThai,
  dateLong: stDateLong,
  timeHm: stTimeHm,
  money: (n) => Number(n || 0).toLocaleString('th-TH'),
  typeLabel: (t) => STORY_TYPE_LABEL[t] || t,
  toxic: (s) => (s.toxic_level === 100 ? '100%' : '70% (ตามกฎประเทศ)'),
  yesNo: (v) => (v ? 'อนุญาต' : 'ไม่อนุญาต'),
  /** ไม่อนุญาตทั้งสลับสล็อตตัวสำรองและเพิ่ม-ถอนสล็อต = ล็อกสล็อตทันทีที่สร้าง (ครอบครัวไม่มีตัวสำรอง) */
  lockNow: (s) => (s.story_type === 'family' || !s.allow_reserve_swap) && !s.allow_slot_change,
  /** ครอบครัวไม่มีตัวสำรอง */
  hasReserve: (s) => s.story_type !== 'family',
  /** รูปแบบการเล่น/สถานที่: ถ้าได้จาก "สุ่มโดยระบบ" แสดงว่าสุ่มโดยระบบ */
  picked: (s, key) => (s[key + '_random'] ? 'สุ่มโดยระบบ' : s[key]),
  /** พื้นหลังโลโก้ตามสีหลักของกลุ่ม (มีรูปโลโก้ = แสดงรูปล้วนไม่มีพื้นหลัง; ตัวอักษรสลับขาว/ดำให้อ่านออก) */
  logoStyle(g) {
    if (g && g.logo_url) return 'background:transparent;border-color:transparent';
    const h = g && g.color_hex;
    if (!/^#[0-9a-f]{6}$/i.test(h || '')) return '';
    const lum = (parseInt(h.slice(1, 3), 16) * 299 + parseInt(h.slice(3, 5), 16) * 587 + parseInt(h.slice(5, 7), 16) * 114) / 1000;
    return `background:${h};color:${lum > 150 ? '#111' : '#fff'}`;
  },
};
