// ดรอปดาวน์แบบเดียวกันทั้งเว็บ: แทน <select class="input"> ของเบราว์เซอร์ด้วยปุ่ม + รายการสไตล์ระบบ
// select เดิมยังอยู่ (ซ่อนไว้) และเป็นแหล่งค่าจริง: Alpine x-model / @change ทำงานเหมือนเดิม
// ไม่ต้องการ: ใส่ data-native บน <select>
(function () {
  const LAYOUT = /^(?:[a-z0-9]+:)*!?(?:w-|min-w-|max-w-|ml-|mr-|mx-|self-|flex-1|grow|shrink|col-span)/;
  const CHEV = '<svg class="fx-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
  const all = new Set();
  let openBox = null;

  const sig = (s) => [s.value, s.disabled, s.options.length, Array.from(s.options, (o) => o.text).join('|'), s.getAttribute('aria-invalid'), s.style.display === 'none'].join('~');

  function enhance(select) {
    if (select.__fx || select.dataset.native !== undefined || select.multiple || select.size > 1 || !select.classList.contains('input')) return;
    const box = document.createElement('div');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.setAttribute('aria-haspopup', 'listbox');
    btn.setAttribute('aria-expanded', 'false');
    const text = document.createElement('span');
    text.className = 'truncate';
    btn.append(text);
    btn.insertAdjacentHTML('beforeend', CHEV);
    const list = document.createElement('div');
    list.className = 'fx-list';
    list.setAttribute('role', 'listbox');
    list.hidden = true;

    // class ด้านความกว้าง/ตำแหน่งไปที่กล่องครอบ ที่เหลือ (รวม .input) อยู่ที่ปุ่ม
    const keep = [];
    const wrap = ['fx-select'];
    select.classList.forEach((c) => (LAYOUT.test(c) ? wrap : keep).push(c));
    box.className = wrap.join(' ');
    btn.className = keep.join(' ') + ' fx-pick';
    if (select.id) {
      btn.id = select.id;
      select.removeAttribute('id');
    }
    const lbl = select.getAttribute('aria-label');
    if (lbl) btn.setAttribute('aria-label', lbl);

    select.parentNode.insertBefore(box, select);
    box.append(select, btn, list);
    select.className = 'fx-native';
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');
    select.__fx = { box, btn, text, list, sig: '' };
    all.add(select);

    const close = () => {
      list.hidden = true;
      btn.setAttribute('aria-expanded', 'false');
      if (openBox === box) openBox = null;
    };
    const items = () => Array.from(list.children);
    const focusItem = (i) => {
      const it = items();
      if (it.length) it[Math.max(0, Math.min(it.length - 1, i))].focus();
    };
    const selectedIndex = () => items().findIndex((b) => b.getAttribute('aria-selected') === 'true');
    const open = () => {
      if (select.disabled) return;
      if (openBox && openBox !== box) openBox.__close();
      list.hidden = false;
      render(select, true);
      btn.setAttribute('aria-expanded', 'true');
      openBox = box;
      const el = items()[Math.max(0, selectedIndex())];
      if (el) el.scrollIntoView({ block: 'nearest' });
    };
    box.__close = close;

    btn.addEventListener('click', () => (list.hidden ? open() : close()));
    btn.addEventListener('keydown', (e) => {
      if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
      e.preventDefault();
      if (list.hidden) open();
      focusItem(e.key === 'ArrowDown' ? Math.max(0, selectedIndex()) : items().length - 1);
    });
    list.addEventListener('keydown', (e) => {
      const i = items().indexOf(document.activeElement);
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        focusItem(i + 1);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        focusItem(i - 1);
      } else if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
        close();
        btn.focus();
      } else if (e.key === 'Tab') {
        close();
      }
    });
    list.addEventListener('click', (e) => {
      const b = e.target.closest('button[data-i]');
      if (!b) return;
      const i = Number(b.dataset.i);
      close();
      btn.focus();
      if (select.selectedIndex !== i) {
        select.selectedIndex = i;
        select.dispatchEvent(new Event('input', { bubbles: true }));
        select.dispatchEvent(new Event('change', { bubbles: true }));
      }
      render(select);
    });
    render(select, true);
  }

  function render(select, rebuild) {
    const f = select.__fx;
    const cur = select.options[select.selectedIndex];
    f.text.textContent = cur ? cur.text : '';
    f.text.classList.toggle('text-muted', !!cur && cur.value === '');
    f.btn.disabled = select.disabled;
    if (select.getAttribute('aria-invalid') === 'true') f.btn.setAttribute('aria-invalid', 'true');
    else f.btn.removeAttribute('aria-invalid');
    f.box.hidden = select.style.display === 'none';
    if (rebuild || !f.list.hidden) {
      f.list.replaceChildren(
        ...Array.from(select.options, (o, i) => {
          const b = document.createElement('button');
          b.type = 'button';
          b.dataset.i = i;
          b.setAttribute('role', 'option');
          b.setAttribute('aria-selected', String(i === select.selectedIndex));
          b.disabled = o.disabled;
          b.textContent = o.text;
          return b;
        })
      );
    }
    f.sig = sig(select);
  }

  function scan(root) {
    if (root.querySelectorAll) root.querySelectorAll('select.input').forEach(enhance);
  }

  // Alpine ตั้งค่า select ด้วยโค้ด (ไม่มี event) และสร้าง/ลบ option ภายหลัง จึงตรวจเป็นระยะ
  setInterval(() => {
    all.forEach((s) => {
      if (!s.isConnected || !s.parentNode || s.parentNode !== s.__fx.box) {
        s.__fx.box.remove();
        all.delete(s);
        return;
      }
      if (sig(s) !== s.__fx.sig) render(s, true);
    });
  }, 200);

  new MutationObserver((muts) => {
    for (const m of muts) {
      m.addedNodes.forEach((n) => {
        if (n.nodeType !== 1) return;
        if (n.matches('select.input')) enhance(n);
        else scan(n);
      });
    }
  }).observe(document.documentElement, { childList: true, subtree: true });

  document.addEventListener('click', (e) => {
    if (openBox && !openBox.contains(e.target)) openBox.__close();
  });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => scan(document));
  else scan(document);
})();
