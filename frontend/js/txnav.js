// แถบรายการด้านข้างของหมวด "ธุรกรรม" ใช้ร่วมกันทุกหน้าในหมวดนี้
// ใส่ <aside id="tx-sidebar" x-data="txSidebar()"></aside> แล้วโหลดสคริปต์นี้ (defer) ก่อน Alpine
// เพิ่มรายการใหม่ที่ ITEMS (soon: true = ยังไม่เปิดใช้; min = บทบาทขั้นต่ำที่เห็นรายการนี้)
(function () {
  const ITEMS = [
    { href: '/manage-groups.html', label: 'จัดการแก๊ง/ครอบครัว', hint: 'ขึ้นทะเบียน สมาชิก สถานะ' },
    { href: '/outfits.html', label: 'ลงชุดเพิ่ม/แก้ไขชุด', hint: 'ชุดแก๊งและครอบครัว' },
    { href: '/stories.html', label: 'สตอรี่', hint: 'สัญญาสตอรี่ เริ่ม–จบ' },
    { href: '/finance.html', label: 'รายรับ-รายจ่าย / ค่าบริการ', hint: 'ค่าบริการและค่าใช้จ่ายของสภา', min: 'vice_president' },
  ];

  const here = location.pathname;
  const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

  const rows = ITEMS.map((it) => {
    const inner = `<span class="tx-item-label">${esc(it.label)}</span><span class="tx-item-hint">${esc(it.hint)}</span>`;
    const body = it.soon
      ? `<span class="tx-item tx-item-soon" aria-disabled="true" title="กำลังพัฒนา">${inner}<span class="tx-soon">เร็วๆ นี้</span></span>`
      : `<a href="${it.href}" class="tx-item"${it.href === here ? ' aria-current="page"' : ''}>${inner}</a>`;
    return it.min ? `<template x-if="auth && auth.can('${it.min}')">${body}</template>` : body;
  }).join('');

  window.txSidebar = () => ({
    auth: null,
    async init() {
      this.auth = authState();
      await this.auth.init();
    },
  });

  const host = document.getElementById('tx-sidebar');
  if (host) {
    host.classList.add('tx-sidebar');
    host.setAttribute('aria-label', 'รายการธุรกรรม');
    host.innerHTML = `<p class="tx-title"><a href="/transactions.html"${here === '/transactions.html' ? ' aria-current="page"' : ''}>ธุรกรรม</a></p><nav class="tx-list">${rows}</nav>`;
  }
})();
