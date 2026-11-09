// แถบรายการด้านข้างของหมวด "ธุรกรรม" ใช้ร่วมกันทุกหน้าในหมวดนี้
// ใส่ <aside id="tx-sidebar" x-data="txSidebar()"></aside> แล้วโหลดสคริปต์นี้ (defer) ก่อน Alpine
// เพิ่มรายการใหม่ที่ ITEMS (soon: true = ยังไม่เปิดใช้; min = บทบาทขั้นต่ำที่เห็นรายการนี้)
(function () {
  const ITEMS = [
    { href: '/requests.html', icon: 'inbox', label: 'คำขอรออนุมัติ', hint: 'คำขอจากแก๊ง/ครอบครัว และธุรกรรมที่รอ' },
    { href: '/manage-groups.html', icon: 'users', label: 'จัดการแก๊ง/ครอบครัว', hint: 'ขึ้นทะเบียน สมาชิก สถานะ' },
    { href: '/outfits.html', icon: 'shirt', label: 'ลงชุดเพิ่ม/แก้ไขชุด', hint: 'ชุดแก๊งและครอบครัว' },
    { href: '/stories.html', icon: 'scroll', label: 'สตอรี่', hint: 'สัญญาสตอรี่ เริ่ม–จบ' },
    { href: '/finance.html', icon: 'wallet', label: 'ธุรกรรมการเงิน', hint: 'รายรับ รายจ่าย กองทุน เงินกู้ โบนัส', min: 'vice_president' },
  ];

  const here = window.PAGE_PATH || location.pathname;
  const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

  const rows = ITEMS.map((it) => {
    const inner = `<i class="ic" data-icon="${it.icon || 'list-checks'}" aria-hidden="true"></i>${esc(it.label)}`;
    const body = it.soon
      ? `<span class="fn-tab" aria-disabled="true" title="กำลังพัฒนา">${inner}</span>`
      : `<a href="${it.href}" class="fn-tab"${it.href === here ? ' aria-current="page" aria-selected="true"' : ''}>${inner}</a>`;
    return it.min ? `<template x-if="auth && auth.can('${it.min}')">${body}</template>` : body;
  }).join('');

  window.txSidebar = () => ({
    auth: null,
    async init() {
      this.auth = authState();
      await this.auth.init();
    },
  });

  // แท็บแนวนอนใต้หัวหน้า (เหมือนหน้าประกาศ): ย้ายไปไว้หลังหัวเรื่องของหน้านั้น
  const host = document.getElementById('tx-sidebar');
  if (host) {
    host.setAttribute('aria-label', 'รายการธุรกรรม');
    host.innerHTML = `<nav class="fn-tabs mt-6">${rows}</nav>`;
    const head = document.querySelector('.rg-head');
    if (head) head.insertAdjacentElement('afterend', host);
  }
})();
