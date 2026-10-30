// เมนูบนสุด + ปุ่มสลับธีม ใช้ร่วมกันทุกหน้า
// ใส่ <div id="site-header"></div> แล้วโหลดสคริปต์นี้ (defer) ก่อน Alpine
(function () {
  const host = document.getElementById('site-header');
  if (!host) return;

  const here = location.pathname;
  const onHome = here === '/' || here === '/index.html';
  const cur = (cond) => (cond ? ' aria-current="page"' : '');

  // ผู้เยี่ยมชม/แก๊ง/ครอบครัว
  const publicNav = [
    ['/rules.html', 'กฎและข้อปฏิบัติ'],
    ['/groups.html', 'ทำเนียบ'],
  ]
    .map(([href, label]) => `<a href="${href}" class="nav-pill"${cur(href === here)}>${label}</a>`)
    .join('');

  // เมนู "จัดการ" (ดรอปดาวน์): เฉพาะรองประธานขึ้นไป
  const manage = [
    ['/users.html', 'จัดการผู้ใช้'],
    ['/duty-report.html', 'สรุปชั่วโมงเวร'],
    ['/logs.html', 'บันทึกกิจกรรม'],
  ];
  // Admin: จัดการผู้ใช้ · บันทึกกิจกรรม · ตั้งค่าระบบ (ไม่มีเมนูงานสภา)
  const adminManage = [
    ['/users.html', 'จัดการผู้ใช้'],
    ['/logs.html', 'บันทึกกิจกรรม'],
    ['/system-settings.html', 'ตั้งค่าระบบ'],
  ];
  const manageActive = manage.some(([href]) => href === here);
  const manageItems = manage
    .map(([href, label]) => `<a href="${href}" role="menuitem" class="menu-item"${cur(href === here)}>${label}</a>`)
    .join('');

  // สภา (Council ขึ้นไป): กฎ · ธุรกรรม (รวมจัดการแก๊ง/ครอบครัว) · ประกาศเรียก · แจ้งเตือนงานค้าง
  // ยังไม่เข้าเวร: ใช้ได้เฉพาะ "กฎ" ที่เหลือเป็นปุ่มล็อก (หน้าแรกและออกจากระบบใช้ได้เสมอ)
  const locked = (label) => `<span class="nav-pill nav-locked" title="ต้องเข้าเวรก่อน" aria-disabled="true">🔒 ${label}</span>`;
  const councilNav = `
    <a href="/rules.html" class="nav-pill"${cur(here === '/rules.html')}>กฎ</a>
    <template x-if="duty && dutyOk"><span class="contents">
      <a href="/transactions.html" class="nav-pill"${cur(here === '/transactions.html' || here === '/manage-groups.html' || here === '/outfits.html')}>ธุรกรรม</a>
      <a href="/announcements.html" class="nav-pill"${cur(here === '/announcements.html')}>ประกาศเรียก</a>
      <a href="/tasks.html" class="nav-pill inline-flex items-center gap-1.5" x-data="alertBadge()"${cur(here === '/tasks.html')}>
        <span class="lg:hidden">งานค้าง</span><span class="hidden lg:inline">แจ้งเตือนงานค้าง</span>
        <span x-show="count > 0" x-text="count > 99 ? '99+' : count" class="nav-badge" :title="count + ' งานค้าง'" aria-label="จำนวนงานค้าง"></span>
      </a>
      <template x-if="can('vice_president')">
        <div class="relative" x-data="menuDropdown()" @keydown.escape.window="open = false" @click.outside="open = false">
          <button type="button" x-ref="btn" class="nav-pill inline-flex items-center gap-1" :aria-expanded="open" aria-haspopup="menu" @click="toggle()"${manageActive ? ' aria-current="page"' : ''}>
            จัดการ <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
          </button>
          <div x-show="open" x-cloak role="menu" class="menu-panel" :style="'top:' + top + 'px; left:' + left + 'px'" @click="open = false">${manageItems}</div>
        </div>
      </template>
    </span></template>
    <template x-if="duty && !dutyOk"><span class="contents">${locked('ธุรกรรม')}${locked('ประกาศเรียก')}${locked('งานค้าง')}</span></template>`;

  const adminItems = adminManage
    .map(([href, label]) => `<a href="${href}" role="menuitem" class="menu-item"${cur(href === here)}>${label}</a>`)
    .join('');
  const adminActive = adminManage.some(([href]) => href === here);
  const adminNav = `
    <a href="/rules.html" class="nav-pill"${cur(here === '/rules.html')}>กฎ</a>
    <div class="relative" x-data="menuDropdown()" @keydown.escape.window="open = false" @click.outside="open = false">
      <button type="button" x-ref="btn" class="nav-pill inline-flex items-center gap-1" :aria-expanded="open" aria-haspopup="menu" @click="toggle()"${adminActive ? ' aria-current="page"' : ''}>
        จัดการ <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
      </button>
      <div x-show="open" x-cloak role="menu" class="menu-panel" :style="'top:' + top + 'px; left:' + left + 'px'" @click="open = false">${adminItems}</div>
    </div>`;

  const nav = `
    <template x-if="!user"><span class="contents" :class="loading ? 'invisible' : ''">${publicNav}</span></template>
    <template x-if="user && !can('member') && !isAdmin"><span class="contents">${publicNav}</span></template>
    <template x-if="user && isAdmin"><span class="contents">${adminNav}</span></template>
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
      <div class="container-app flex flex-wrap items-center justify-between gap-x-3 gap-y-2 py-3 sm:gap-x-4">
        <a href="/index.html" class="font-display flex items-center gap-2 text-base font-bold tracking-wide max-[380px]:text-sm sm:text-lg">
          <img src="/img/favicon.png" alt="" class="h-7 w-7">
          <span>WAVEVY <span class="text-accent">COUNCIL</span></span>
        </a>

        <!-- มือถือ: เมนูลงมาแถวที่สอง -->
        <nav class="order-3 flex w-full items-center gap-1 overflow-x-auto sm:order-none sm:mr-auto sm:w-auto" x-show="${onHome ? 'false' : 'true'}">${nav}
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
            <a href="/login.html" class="btn-outline">Log in</a>
          </template>

          <button type="button" id="theme-toggle" class="btn-outline" aria-label="สลับโหมดสี"></button>
        </div>
      </div>
    </header>`;

  // ความสูงเมนูบน (เปลี่ยนตามจอ: มือถือมี 2 แถว) ให้องค์ประกอบ sticky อื่นตรึงต่อใต้เมนู
  const headerEl = host.querySelector('.site-header');
  // เมนูตรึงเฉพาะจอ ≥640px; บนมือถือไม่ตรึง จึงให้จุดตรึงของแถบอื่นเป็น 0
  const setHeaderHeight = () => {
    const pinned = getComputedStyle(headerEl).position === 'sticky';
    document.documentElement.style.setProperty('--header-h', (pinned ? headerEl.offsetHeight : 0) + 'px');
  };
  setHeaderHeight();
  if (window.ResizeObserver) new ResizeObserver(setHeaderHeight).observe(headerEl);
  window.addEventListener('resize', setHeaderHeight);

  const btn = document.getElementById('theme-toggle');
  const paint = () => {
    const dark = document.documentElement.getAttribute('data-theme') !== 'light';
    // แสดงเฉพาะไอคอน (ดวงจันทร์ = ตอนนี้โหมดมืด, ดวงอาทิตย์ = ตอนนี้โหมดสว่าง); ข้อความอยู่ที่ title/aria-label เพื่อผู้ใช้โปรแกรมอ่านจอ
    btn.innerHTML = dark ? moon : sun;
    const label = dark ? 'โหมดมืดอยู่ — กดเพื่อสลับเป็นโหมดสว่าง' : 'โหมดสว่างอยู่ — กดเพื่อสลับเป็นโหมดมืด';
    btn.title = label;
    btn.setAttribute('aria-label', label);
  };
  btn.addEventListener('click', () => {
    const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', next);
    try { localStorage.setItem('theme', next); } catch (e) {}
    paint();
  });
  paint();
})();
