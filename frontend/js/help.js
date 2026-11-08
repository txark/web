// วิธีใช้งานแต่ละหน้า: ปุ่ม "วิธีใช้งานหน้านี้" ใต้หัวข้อ กดแล้วเปิดคำแนะนำเป็นข้อ ๆ
// โหลดโดย layout.js · หน้าที่ไม่มีหัวข้อแบบ .rg-head หรือไม่มีเนื้อหาในรายการนี้จะไม่แสดงปุ่ม
(function () {
  const HELP = {
    '/index.html': ['ภาพรวมการทำงาน', [
      'ดูสรุปของวันนี้: จำนวนแก๊ง ครอบครัว คำขอรออนุมัติ และผู้ที่กำลังเข้าเวร',
      'ส่วน "ธุรกรรมล่าสุด" แสดงรายการที่เพิ่งเกิดขึ้น กดเพื่อไปดูต่อ',
      'ต้องเข้าเวรก่อนจึงจะใช้เมนูธุรกรรม ประกาศ รายงานความเคลื่อนไหว และแจ้งเตือนงานค้างได้ (ดูกฎได้เสมอ)',
      'กด "รีเฟรช" เพื่ออัปเดตตัวเลข',
    ]],
    '/transactions.html': ['ธุรกรรม', [
      'เลือกประเภทงานที่ต้องการจากการ์ด เช่น คำขอ ทะเบียนแก๊ง/ครอบครัว ชุด สตอรี่ และการเงิน',
      'ทุกธุรกรรมมีเลขธุรกรรมของตัวเอง ใช้อ้างอิงหรือค้นหาย้อนหลังได้',
      'การทำรายการต้องเข้าเวรก่อน',
    ]],
    '/requests.html': ['คำขอและรออนุมัติ', [
      'แท็บบนสุดแยกคำขอและธุรกรรมที่รออนุมัติ กดเข้าไปดูรายละเอียดก่อนตัดสินใจ',
      'กด "อนุมัติ" หรือ "ไม่อนุมัติ" (ควรใส่เหตุผลเมื่อไม่อนุมัติ)',
      'อนุมัติคำขอที่ตัวเองยื่นไม่ได้ ต้องให้สภาคนอื่นอนุมัติ',
    ]],
    '/manage-groups.html': ['จัดการแก๊งและครอบครัว', [
      'ใช้ช่องค้นหาและตัวกรองเพื่อหาแก๊งหรือครอบครัว กดที่รายการเพื่อดูรายละเอียด',
      'เพิ่มหรือแก้ไขข้อมูล สมาชิก สี และสถานะได้จากปุ่มในแต่ละรายการ',
      'การออกลอยคิดค่าบริการตามอัตราในหน้า "ค่าบริการสภา" (ถ้าโดนใบแดงติ๊กช่องใบแดง)',
      'ทุกการแก้ไขถูกบันทึกในบันทึกกิจกรรม',
    ]],
    '/outfits.html': ['ธุรกรรมชุด', [
      'เลือกแก๊งหรือครอบครัว แล้วกรอกรายการชุดและจำนวนที่ต้องการ',
      'กดบันทึกเพื่อสร้างธุรกรรม ระบบจะออกเลขธุรกรรมให้อัตโนมัติ',
      'ดูรายการย้อนหลังและค้นหาด้วยเลขธุรกรรมได้ด้านล่าง',
    ]],
    '/summons.html': ['ประกาศเรียก', [
      'เลือกวันที่ด้วยปุ่ม ‹ › หรือช่องวันที่ เพื่อดูประกาศของวันนั้น',
      'สร้างประกาศเรียก โดยเลือกแก๊ง/ครอบครัว มาตรการ และจุดนัดหมาย',
      'กดคัดลอกข้อความแล้วนำไปวางใน Discord',
    ]],
    '/fines.html': ['ประกาศปรับ', [
      'เลือกวันที่ แล้วกด "บันทึกค่าปรับ" เพื่อสร้างรายการ',
      'ใส่รายละเอียดค่าปรับของทะเบียนให้ครบ แล้วบันทึก',
      'กดคัดลอกประกาศปรับแล้วนำไปวางใน Discord',
    ]],
    '/penalties.html': ['โทษบำเพ็ญ', [
      'ดูโทษบำเพ็ญที่บันทึกไว้จากหน้าประกาศปรับ สลับดูรายวันหรือเฉพาะที่ค้างรับโทษได้',
      'เมื่อกลุ่มรับโทษเรียบร้อยแล้ว ให้กดคัดลอกประกาศ',
    ]],
    '/announcements.html': ['ประกาศ', [
      'กด "สร้างประกาศ" เขียนหัวข้อและเนื้อหา แล้วบันทึก',
      'ใช้ช่องค้นหาและปุ่มเลขหน้าด้านล่างเพื่อหาประกาศเก่า',
      'ประกาศเห็นได้เฉพาะสภา แก๊ง/ครอบครัวไม่เห็น',
    ]],
    '/activity-rounds.html': ['รายงานความเคลื่อนไหว', [
      'เปิดรอบ ตั้งช่วงเวลาที่ให้แก๊ง/ครอบครัวยื่นรายงาน',
      'เมื่อมีรายงานเข้ามา ตรวจแล้วกด "บันทึกรายงานตัว" ที่ส่วนคำร้องด้านล่าง',
      'แก๊ง/ครอบครัวที่ไม่ส่งรายงานภายในรอบจะขึ้นในงานค้าง',
    ]],
    '/tasks.html': ['แจ้งเตือนงานค้าง', [
      'ระบบรวมรายการที่ต้องดำเนินการ เช่น แก๊ง/ครอบครัวที่ยังไม่ครบเงื่อนไข และเงินกู้ที่ยังไม่คืน',
      'เลือกกลุ่มที่ด้านบน แล้วเลือกหมวดย่อยเพื่อกรองรายการ',
      'ใช้ปุ่ม "คัดลอกข้อความทวง" ส่งใน Discord',
      'แก้ปัญหาแล้วรายการจะหายไปเอง ไม่ต้องกดลบ',
    ]],
  };

  const entry = HELP[location.pathname === '/' ? '/index.html' : location.pathname];
  if (!entry) return;

  function mount() {
    const sub = document.querySelector('.rg-head .rg-sub') || document.querySelector('.rg-head .rg-title');
    if (!sub || document.getElementById('help-open')) return;
    const [title, steps] = entry;

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.id = 'help-open';
    btn.className = 'help-link';
    btn.innerHTML = '<span aria-hidden="true">?</span> วิธีใช้งานหน้านี้';
    sub.insertAdjacentElement('afterend', btn);

    const el = document.createElement('div');
    el.className = 'reg-backdrop';
    el.hidden = true;
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    el.setAttribute('aria-label', 'วิธีใช้งาน ' + title);
    const esc = (t) => String(t).replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
    el.innerHTML =
      '<div class="reg-modal"><header class="reg-head"><h2 class="reg-title">วิธีใช้งาน · ' + esc(title) + '</h2>' +
      '<button type="button" class="reg-close" aria-label="ปิด"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg></button></header>' +
      '<div class="reg-body"><ol class="help-steps">' + steps.map((s) => '<li>' + esc(s) + '</li>').join('') + '</ol></div>' +
      '<footer class="reg-foot"><button type="button" class="btn-solid help-ok">เข้าใจแล้ว</button></footer></div>';
    document.body.appendChild(el);

    const open = () => { el.hidden = false; el.querySelector('.help-ok').focus(); };
    const close = () => { el.hidden = true; btn.focus(); };
    btn.addEventListener('click', open);
    el.querySelector('.reg-close').addEventListener('click', close);
    el.querySelector('.help-ok').addEventListener('click', close);
    el.addEventListener('mousedown', (e) => { if (e.target === el) close(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !el.hidden) close(); });
  }

  // หัวข้อของบางหน้าถูกวาดหลัง Alpine เริ่มทำงาน จึงรอจนกว่าจะเจอ
  let tries = 0;
  const timer = setInterval(() => {
    mount();
    if (document.getElementById('help-open') || ++tries > 40) clearInterval(timer);
  }, 150);
})();
