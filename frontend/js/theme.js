// ตั้งธีมก่อนหน้าเว็บวาด (โหลดแบบ sync ใน <head>) กันหน้าวูบเปลี่ยนสี
(function () {
  // ค่าเริ่มต้นเป็นโหมดสว่าง ยกเว้นหน้าที่ตั้ง <html data-default-theme="dark"> (หน้าสาธารณะ) — ผู้ใช้ที่เคยสลับแล้วจะใช้ค่าที่เลือกไว้
  var theme = document.documentElement.getAttribute('data-default-theme') === 'dark' ? 'dark' : 'light';
  try {
    var saved = localStorage.getItem('theme');
    if (saved === 'light' || saved === 'dark') theme = saved;
  } catch (e) {}
  // ?theme=light|dark ใช้บังคับธีมชั่วคราว (ไม่บันทึก) เช่นตอนแชร์ลิงก์หรือทดสอบ
  var forced = new URLSearchParams(location.search).get('theme');
  if (forced === 'light' || forced === 'dark') theme = forced;
  document.documentElement.setAttribute('data-theme', theme);
})();

// URL ไม่มี .html: เซิร์ฟเวอร์เปิด /rules เป็น rules.html ให้ และเปลี่ยน /rules.html เป็น /rules
// PAGE_PATH = เส้นทางหน้านี้ในรูปแบบเดิม ('/' หรือ '/rules.html') เพื่อให้โค้ดเปรียบเทียบเมนูใช้ต่อได้
(function () {
  var p = location.pathname.replace(/\/+$/, '').replace(/\.html$/, '');
  window.PAGE_PATH = p === '' || p === '/index' ? '/' : p + '.html';

  // ลิงก์ในหน้า (<a href="/rules.html">) แสดงเป็น /rules เพื่อไม่ต้องผ่านการเปลี่ยนเส้นทางตอนกด
  var RE = /^\/([A-Za-z0-9_-]+)\.html([?#].*)?$/;
  function fix(root) {
    var list = root.querySelectorAll ? root.querySelectorAll('a[href$=".html"], a[href*=".html?"], a[href*=".html#"]') : [];
    for (var i = 0; i < list.length; i++) clean(list[i]);
    if (root.tagName === 'A') clean(root);
  }
  function clean(a) {
    var h = a.getAttribute('href');
    var m = h && RE.exec(h);
    if (!m) return;
    a.setAttribute('href', (m[1] === 'index' ? '/' : '/' + m[1]) + (m[2] || ''));
  }
  function start() {
    fix(document);
    new MutationObserver(function (muts) {
      for (var i = 0; i < muts.length; i++) {
        var m = muts[i];
        if (m.type === 'attributes') clean(m.target);
        else for (var j = 0; j < m.addedNodes.length; j++) if (m.addedNodes[j].nodeType === 1) fix(m.addedNodes[j]);
      }
    }).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['href'] });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
