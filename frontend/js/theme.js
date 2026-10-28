// ตั้งธีมก่อนหน้าเว็บวาด (โหลดแบบ sync ใน <head>) กันหน้าวูบเปลี่ยนสี
(function () {
  var theme = 'light'; // ค่าเริ่มต้นเป็นโหมดสว่าง (ผู้ใช้ที่เคยสลับแล้วจะใช้ค่าที่เลือกไว้)
  try {
    var saved = localStorage.getItem('theme');
    if (saved === 'light' || saved === 'dark') theme = saved;
  } catch (e) {}
  // ?theme=light|dark ใช้บังคับธีมชั่วคราว (ไม่บันทึก) เช่นตอนแชร์ลิงก์หรือทดสอบ
  var forced = new URLSearchParams(location.search).get('theme');
  if (forced === 'light' || forced === 'dark') theme = forced;
  document.documentElement.setAttribute('data-theme', theme);
})();
