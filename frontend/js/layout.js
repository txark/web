// เมนูบนสุด + ปุ่มสลับธีม ใช้ร่วมกันทุกหน้า
// ใส่ <div id="site-header"></div> แล้วโหลดสคริปต์นี้ (defer) ก่อน Alpine
(function () {
  const host = document.getElementById('site-header');
  if (!host) return;

  const here = window.PAGE_PATH || location.pathname;
  const ic = (n) => `<i class="ic" data-icon="${n}" aria-hidden="true"></i>`;
  const cur = (cond) => (cond ? ' aria-current="page"' : '');

  // ผู้เยี่ยมชม/แก๊ง/ครอบครัว
  const publicNav = [
    ['/rules.html', 'กฎและข้อปฏิบัติ'],
    ['/groups.html', 'งานทะเบียน'],
    ['/fees.html', 'ค่าบริการสภา'],
    ['/about.html', 'เกี่ยวกับ'],
    ['/members.html', 'สมาชิกสภา'],
  ]
    .map(([href, label], i) => `<a href="${href}" class="nav-pill"${cur(href === here)}><span class="nav-num">${String(i + 1).padStart(2, '0')}</span>${label}</a>`)
    .join('');

  // เมนู "จัดการ" (ดรอปดาวน์): เฉพาะรองประธานขึ้นไป
  const manage = [
    ['/dashboard.html', 'แดชบอร์ด', 'layout-dashboard'],
    ['/rules-admin.html', 'จัดการกฎ', 'book-open'],
    ['/users.html', 'จัดการผู้ใช้', 'users'],
    ['/duty-report.html', 'สรุปชั่วโมงเวร', 'clock'],
    ['/logs.html', 'บันทึกกิจกรรม', 'file-text'],
  ];
  // Admin: จัดการผู้ใช้ · บันทึกกิจกรรม · ตั้งค่าระบบ (ไม่มีเมนูงานสภา)
  const adminManage = [...manage, ['/system-settings.html', 'ตั้งค่าระบบ', 'sliders']];
  const manageActive = manage.some(([href]) => href === here);
  const manageItems = manage
    .map(([href, label, icon]) => `<a href="${href}" role="menuitem" class="menu-item"${cur(href === here)}>${ic(icon)}${label}</a>`)
    .join('');

  const adminItems = adminManage
    .map(([href, label, icon]) => `<a href="${href}" role="menuitem" class="menu-item"${cur(href === here)}>${ic(icon)}${label}</a>`)
    .join('');
  const adminActive = adminManage.some(([href]) => href === here);
  // สภา (Council ขึ้นไป): กฎ · ธุรกรรม (รวมจัดการแก๊ง/ครอบครัว) · ประกาศเรียก · แจ้งเตือนงานค้าง
  // ยังไม่เข้าเวร: ใช้ได้เฉพาะ "กฎ" ที่เหลือเป็นปุ่มล็อก (หน้าแรกและออกจากระบบใช้ได้เสมอ)
  const locked = (label) => `<span class="nav-pill nav-locked" title="ต้องเข้าเวรก่อน" aria-disabled="true">${ic('lock')}${label}</span>`;
  const councilNav = `
    <a href="/rules.html" class="nav-pill"${cur(here === '/rules.html')}>${ic('book-open')}กฎ</a>
    <template x-if="duty && dutyOk"><span class="contents">
      <a href="/transactions.html" class="nav-pill"${cur(here === '/transactions.html' || here === '/requests.html' || here === '/manage-groups.html' || here === '/outfits.html')}>${ic('arrow-left-right')}ธุรกรรม</a>
      <a href="/summons.html" class="nav-pill"${cur(here === '/summons.html' || here === '/fines.html' || here === '/penalties.html' || here === '/announcements.html')}>${ic('megaphone')}ประกาศ</a>
      <a href="/activity-rounds.html" class="nav-pill"${cur(here === '/activity-rounds.html')}>${ic('activity')}รายงานความเคลื่อนไหว</a>
      <a href="/tasks.html" class="nav-pill inline-flex items-center gap-1.5" x-data="alertBadge()"${cur(here === '/tasks.html')}>
        ${ic('bell')}<span class="lg:hidden">งานค้าง</span><span class="hidden lg:inline">แจ้งเตือนงานค้าง</span>
        <span x-show="count > 0" x-text="count > 99 ? '99+' : count" class="nav-badge" :title="count + ' งานค้าง'" aria-label="จำนวนงานค้าง"></span>
      </a>
      <template x-if="can('vice_president') && !isAdmin">
        <div class="relative" x-data="menuDropdown()" @keydown.escape.window="open = false" @click.outside="open = false">
          <button type="button" x-ref="btn" class="nav-pill inline-flex items-center gap-1" :aria-expanded="open" aria-haspopup="menu" @click="toggle()"${manageActive ? ' aria-current="page"' : ''}>
            ${ic('sliders')}จัดการ <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
          </button>
          <div x-show="open" x-cloak role="menu" class="menu-panel" :style="'top:' + top + 'px; left:' + left + 'px'" @click="open = false">${manageItems}</div>
        </div>
      </template>
      <template x-if="isAdmin">
        <div class="relative" x-data="menuDropdown()" @keydown.escape.window="open = false" @click.outside="open = false">
          <button type="button" x-ref="btn" class="nav-pill inline-flex items-center gap-1" :aria-expanded="open" aria-haspopup="menu" @click="toggle()"${adminActive ? ' aria-current="page"' : ''}>
            ${ic('sliders')}จัดการ <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
          </button>
          <div x-show="open" x-cloak role="menu" class="menu-panel" :style="'top:' + top + 'px; left:' + left + 'px'" @click="open = false">${adminItems}</div>
        </div>
      </template>
    </span></template>
    <template x-if="duty && !dutyOk"><span class="contents">${locked('ธุรกรรม')}${locked('ประกาศ')}${locked('รายงานความเคลื่อนไหว')}${locked('งานค้าง')}</span></template>`;

  // แก๊ง/ครอบครัว: ทางเข้าส่วนยื่นเรื่องกับสภา
  const myGroupLink = `<template x-if="user && (user.role === 'gang' || user.role === 'family')"><a href="/my-group.html" class="nav-pill"${cur(here === '/my-group.html')}>${ic('shield')}<span x-text="user.role === 'family' ? 'ครอบครัวของฉัน' : 'แก๊งของฉัน'"></span></a></template>`;

  const nav = `
    <template x-if="!user"><span class="contents" :class="loading ? 'invisible' : ''">${publicNav}</span></template>
    <template x-if="user && !can('member')"><span class="contents">${publicNav}${myGroupLink}</span></template>
    <template x-if="user && can('member')"><span class="contents">${councilNav}</span></template>`;

  const moon = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>';
  const sun = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';

  // จำนวนงานค้างบนเมนู (โหลดครั้งเดียวต่อหน้า; ล้มเหลวก็ไม่แสดงตัวเลข)
  window.alertBadge = () => ({
    count: 0,
    async init() {
      try {
        this.count = (await API.get('/api/council/alerts')).meta.count;
      } catch (e) {}
      // ตัวเลขอัปเดตตามการตรวจงานค้างใหม่ (js/reminder-popup.js) โดยไม่ต้องรีเฟรชหน้า
      window.addEventListener('alerts:count', (e) => { this.count = e.detail; });
    },
  });

  // ดรอปดาวน์ตำแหน่งแบบ fixed: เมนูหลักบนมือถือเลื่อนแนวนอนได้ (overflow) จึงใช้ absolute ไม่ได้
  window.menuDropdown = () => ({
    open: false,
    top: 0,
    left: 0,
    toggle() {
      if (!this.open) {
        const r = this.$refs.btn.getBoundingClientRect();
        this.top = r.bottom + 6;
        this.left = Math.max(8, Math.min(r.left, window.innerWidth - 232));
        const close = () => { this.open = false; window.removeEventListener('scroll', close); window.removeEventListener('resize', close); };
        window.addEventListener('scroll', close, { passive: true, once: true });
        window.addEventListener('resize', close, { once: true });
      }
      this.open = !this.open;
    },
  });

  host.innerHTML = `
    <header class="site-header" x-data="authState()">
      <div class="container-app site-bar flex flex-wrap items-center justify-between gap-x-3 gap-y-2 py-3 sm:gap-x-4">
        <a href="/rules.html" :href="user && can('member') ? '/index.html' : '/rules.html'" class="brand" aria-label="WAVEVY COUNCIL"><span class="brand-main">WAVEVY.</span><span class="brand-sub">COUNCIL</span></a>

        <!-- มือถือ: เมนูลงมาแถวที่สอง -->
        <nav class="order-3 flex w-full items-center gap-1 overflow-x-auto sm:order-none sm:mr-auto sm:w-auto">${nav}
        </nav>

        <div class="flex items-center gap-2 text-sm">
          <template x-if="user && can('member') && duty && duty.required">
            <a href="/index.html" class="duty-chip" :class="duty.on_duty ? 'duty-on' : 'duty-off'" :title="duty.on_duty ? 'อยู่ในเวร — กดเพื่อไปหน้าแรก/ออกเวร' : 'ยังไม่ได้เข้าเวร — กดเพื่อไปหน้าแรก'">
              <span class="duty-dot" aria-hidden="true"></span>
              <span x-text="duty.on_duty ? elapsed : 'ไม่ได้อยู่ในเวร'"></span>
            </a>
          </template>
          <template x-if="user">
            <div class="flex items-center gap-2">
              <img x-show="user.avatar" :src="user.avatar" :title="user.name + ' · ' + user.role_label" alt="" class="h-7 w-7 rounded-full">
              <span class="hidden min-w-0 max-w-36 leading-tight lg:block">
                <span class="block truncate text-sm" x-text="user.name"></span>
                <span class="block truncate text-[0.7rem] tracking-wide text-muted" :title="user.role_name" x-text="user.role_label"></span>
              </span>
              <button class="btn-outline" @click="logout()" aria-label="ออกจากระบบ"><span class="lg:hidden">ออก</span><span class="hidden lg:inline">ออกจากระบบ</span></button>
            </div>
          </template>
          <template x-if="!loading && !user">
            <a href="/login.html" class="btn-outline">Log in <span aria-hidden="true">↗</span></a>
          </template>

          <button type="button" id="theme-toggle" class="btn-outline theme-btn" aria-label="สลับโหมดสี"></button>
        </div>
      </div>
    </header>`;


  // ───────── สภา: แถบเมนูด้านซ้าย (workspace) แทนเมนูบน ─────────
  // เลือกใช้เมื่อรู้ว่าเป็นสภา (Council ขึ้นไป); จำผลไว้ใน localStorage กันหน้ากระพริบ
  try { if (localStorage.getItem('side') === '1') document.documentElement.classList.add('has-side'); } catch (e) {}

  const sideLink = (href, label, icon, active, gated) => {
    const a = `<a href="${href}" class="side-link"${cur(active)}>${ic(icon)}<span>${label}</span></a>`;
    if (!gated) return a;
    return `<template x-if="dutyOk">${a}</template><template x-if="!dutyOk"><span class="side-link is-locked" title="ต้องเข้าเวรก่อน" aria-disabled="true">${ic('lock')}<span>${label}</span></span></template>`;
  };
  const sideMain = [
    sideLink('/index.html', 'ภาพรวม', 'layout-dashboard', here === '/' || here === '/index.html', false),
    sideLink('/rules.html', 'กฎและข้อปฏิบัติ', 'book-open', here === '/rules.html', false),
    sideLink('/transactions.html', 'ธุรกรรมสภา', 'arrow-left-right', ['/transactions.html', '/requests.html', '/manage-groups.html', '/outfits.html'].includes(here), true),
    sideLink('/summons.html', 'ประกาศ', 'megaphone', ['/summons.html', '/fines.html', '/penalties.html', '/announcements.html'].includes(here), true),
    sideLink('/activity-rounds.html', 'รายงานความเคลื่อนไหว', 'activity', here === '/activity-rounds.html', true),
    sideLink('/disputes.html', 'ระบบบันทึกข้อพิพาท', 'video', here === '/disputes.html', false),
    `<template x-if="dutyOk"><a href="/tasks.html" class="side-link" x-data="alertBadge()"${cur(here === '/tasks.html')}>${ic('bell')}<span>แจ้งเตือนงานค้าง</span><b x-show="count > 0" x-text="count > 99 ? '99+' : count" class="nav-badge"></b></a></template><template x-if="!dutyOk"><span class="side-link is-locked" title="ต้องเข้าเวรก่อน" aria-disabled="true">${ic('lock')}<span>แจ้งเตือนงานค้าง</span></span></template>`,
  ].join('');
  const sideManage = (list) => list.map(([href, label, icon]) => sideLink(href, label, icon, href === here, false)).join('');
  const sideTitle = (document.title.split('|')[0] || '').trim();
  const sideHtml = `
    <div class="side-backdrop" x-data @click="document.documentElement.classList.remove('side-open')"></div>
    <aside class="side" x-data="authState()" x-effect="(() => { const on = !!user && can('member'); document.documentElement.classList.toggle('has-side', on); try { localStorage.setItem('side', on ? '1' : '0'); } catch (e) {} window.dispatchEvent(new Event('sidechange')); })()" aria-label="เมนูสภา">
      <a href="/index.html" class="brand" aria-label="WAVEVY COUNCIL"><span class="brand-main">WAVEVY.</span><span class="brand-sub">COUNCIL</span></a>
      <p class="side-label">COUNCIL WORKSPACE</p>
      <nav class="side-nav" @click="document.documentElement.classList.remove('side-open')">
        ${sideMain}
        <template x-if="can('vice_president') && !isAdmin"><span class="contents"><p class="side-label side-label-sub">จัดการ</p>${sideManage(manage)}</span></template>
        <template x-if="isAdmin"><span class="contents"><p class="side-label side-label-sub">จัดการ</p>${sideManage(adminManage)}</span></template>
      </nav>
      <div class="side-user" x-show="user" x-cloak>
        <p class="side-user-k">ชื่อผู้ใช้</p>
        <p class="side-user-name" x-text="user ? user.name : ''"></p>
        <p class="side-user-ic" x-text="user && user.character_name ? user.character_name : ''"></p>
        <dl class="side-user-meta">
          <div><dt>ยศ</dt><dd x-text="user ? user.role_name : ''"></dd></div>
        </dl>
        <a href="/index.html" class="side-duty" x-show="duty && duty.required" :class="duty && duty.on_duty ? 'is-on' : ''">
          <span class="duty-dot" aria-hidden="true"></span>
          <span x-text="duty && duty.on_duty ? 'อยู่ในเวร ' + elapsed : 'ไม่ได้อยู่ในเวร'"></span>
        </a>
        <button class="btn-outline side-logout" @click="logout()">ออกจากระบบ</button>
      </div>
    </aside>
    <div class="side-top" x-data="authState()">
      <button type="button" class="side-burger btn-outline" aria-label="เมนู" @click="document.documentElement.classList.toggle('side-open')">☰</button>
      <p class="side-crumb"><span>ศูนย์จัดการสภา</span><b>/ ${sideTitle === 'WAVEVY COUNCIL' ? 'ภาพรวมการทำงาน' : sideTitle}</b></p>
      <span class="side-top-r">
        <template x-if="dutyOk"><a href="/tasks.html" class="btn-outline side-bell" x-data="alertBadge()">${ic('bell')}<span class="hidden sm:inline">งานค้าง</span><b class="nav-badge" x-text="count"></b></a></template>
        <button type="button" class="btn-outline theme-btn"></button>
      </span>
    </div>`;
  host.insertAdjacentHTML('beforeend', sideHtml);

  // ความสูงเมนูบน (เปลี่ยนตามจอ: มือถือมี 2 แถว) ให้องค์ประกอบ sticky อื่นตรึงต่อใต้เมนู
  const headerEl = host.querySelector('.site-header');
  // เมนูตรึงเฉพาะจอ ≥640px; บนมือถือไม่ตรึง จึงให้จุดตรึงของแถบอื่นเป็น 0
  const setHeaderHeight = () => {
    const topEl = document.documentElement.classList.contains('has-side') ? host.querySelector('.side-top') : headerEl;
    const pinned = getComputedStyle(topEl).position === 'sticky';
    document.documentElement.style.setProperty('--header-h', (pinned ? topEl.offsetHeight : 0) + 'px');
  };
  setHeaderHeight();
  if (window.ResizeObserver) new ResizeObserver(setHeaderHeight).observe(headerEl);
  window.addEventListener('resize', setHeaderHeight);
  window.addEventListener('sidechange', setHeaderHeight);

  const btns = [...host.querySelectorAll('.theme-btn')];
  const paint = () => {
    const dark = document.documentElement.getAttribute('data-theme') !== 'light';
    // แสดงเฉพาะไอคอน (ดวงจันทร์ = ตอนนี้โหมดมืด, ดวงอาทิตย์ = ตอนนี้โหมดสว่าง); ข้อความอยู่ที่ title/aria-label เพื่อผู้ใช้โปรแกรมอ่านจอ
    const label = dark ? 'โหมดมืดอยู่ — กดเพื่อสลับเป็นโหมดสว่าง' : 'โหมดสว่างอยู่ — กดเพื่อสลับเป็นโหมดมืด';
    btns.forEach((btn) => {
      btn.innerHTML = (dark ? sun : moon) + '<span class="theme-label">' + (dark ? 'โหมดสว่าง' : 'โหมดมืด') + '</span>';
      btn.title = label;
      btn.setAttribute('aria-label', label);
    });
  };
  btns.forEach((btn) => btn.addEventListener('click', () => {
    const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', next);
    try { localStorage.setItem('theme', next); } catch (e) {}
    paint();
  }));
  paint();
  // วิธีใช้งานแต่ละหน้า (ปุ่มใต้หัวข้อ)
  if (!document.querySelector('script[data-help]')) {
    const hp = document.createElement('script');
    hp.src = '/js/help.js';
    hp.dataset.help = '1';
    document.head.appendChild(hp);
  }
  // เตือนความจำของสภา: เด้งกลางจอพร้อมเสียงทุก 5 นาที (ทำงานเฉพาะสภา; สคริปต์ตรวจสิทธิ์เอง)
  if (!document.querySelector('script[data-reminder-popup]')) {
    const rp = document.createElement('script');
    rp.src = '/js/reminder-popup.js';
    rp.dataset.reminderPopup = '1';
    document.head.appendChild(rp);
  }
})();
