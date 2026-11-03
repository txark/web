// เสียง/ป๊อปอัปแจ้งเตือนของสภา (ทุกคนในสภาได้รับ):
//  1) งานค้างใหม่ (คำขอ รายงาน เตือนความจำ ฯลฯ) → เสียง + แจ้งมุมจอทันทีที่มีรายการใหม่ (ตรวจทุก 30 วินาที)
//  2) เตือนความจำของสภา: เด้งกลางจอพร้อมเสียงทุก 5 นาที เมื่อมีบันทึกเตือนความจำที่ครบกำหนดวันนี้หรือเลยกำหนด และยังไม่เสร็จ
// โหลดโดย layout.js ทุกหน้า ทำงานเฉพาะสภา (member ขึ้นไป) ที่พร้อมใช้งาน (เข้าเวรแล้วหรือไม่ต้องเข้าเวร)
// ช่วงเวลา 5 นาทีนับร่วมกันทุกแท็บ/ทุกหน้าด้วย localStorage จึงไม่เด้งซ้ำเมื่อเปลี่ยนหน้า
(function () {
  const EVERY_MS = 5 * 60 * 1000;   // เด้งซ้ำทุก 5 นาที
  const TICK_MS = 30 * 1000;        // ตรวจเวลาทุก 30 วินาที
  const KEY = 'reminderPopupAt';
  let showing = false;
  let audio = null;

  const read = () => {
    try { return Number(localStorage.getItem(KEY) || 0); } catch (e) { return 0; }
  };
  const write = () => {
    try { localStorage.setItem(KEY, String(Date.now())); } catch (e) {}
  };

  /** เสียงเตือนสั้น ๆ (ระฆังสองโทน) — เบราว์เซอร์ที่ยังไม่มีการกดบนหน้าจะบล็อกเสียง จึงเงียบได้ ไม่กระทบป๊อปอัป */
  function chime() {
    try {
      audio = audio || new (window.AudioContext || window.webkitAudioContext)();
      if (audio.state === 'suspended') audio.resume();
      [[880, 0], [660, 0.28], [880, 0.56]].forEach(([freq, at]) => {
        const o = audio.createOscillator();
        const g = audio.createGain();
        o.type = 'sine';
        o.frequency.value = freq;
        g.gain.setValueAtTime(0.0001, audio.currentTime + at);
        g.gain.exponentialRampToValueAtTime(0.25, audio.currentTime + at + 0.03);
        g.gain.exponentialRampToValueAtTime(0.0001, audio.currentTime + at + 0.26);
        o.connect(g).connect(audio.destination);
        o.start(audio.currentTime + at);
        o.stop(audio.currentTime + at + 0.28);
      });
    } catch (e) {}
  }

  const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

  function show(items, onDone) {
    showing = true;
    const root = document.createElement('div');
    root.className = 'rm-backdrop';
    root.setAttribute('role', 'alertdialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-labelledby', 'rm-title');
    root.innerHTML = `
      <div class="rm-pop">
        <div class="rm-head"><span class="rm-bell"><i class="ic" data-icon="bell" aria-hidden="true"></i></span>
          <div><h2 id="rm-title">เตือนความจำ</h2><p>มี ${items.length} รายการที่ครบกำหนดแล้ว</p></div></div>
        <ul class="rm-list">${items.map((a) => `
          <li data-id="${a.reminder_id}">
            <div><b>${esc(a.title)}</b><span>${esc(a.detail)}</span></div>
            <button type="button" class="rm-done" data-done="${a.reminder_id}">เสร็จแล้ว</button>
          </li>`).join('')}
        </ul>
        <div class="rm-foot">
          <a href="/tasks.html" class="rm-link">เปิดหน้าแจ้งเตือนงานค้าง</a>
          <button type="button" class="rm-ok" id="rm-ok">รับทราบ</button>
        </div>
      </div>`;
    document.body.appendChild(root);
    chime();

    const close = () => {
      root.remove();
      showing = false;
      write();   // รับทราบแล้ว: นับ 5 นาทีใหม่ (ถ้ายังไม่เสร็จจะเด้งอีก)
    };
    root.querySelector('#rm-ok').addEventListener('click', close);
    root.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
    root.querySelector('#rm-ok').focus();
    root.addEventListener('click', async (e) => {
      const b = e.target.closest('[data-done]');
      if (!b) return;
      b.disabled = true;
      try {
        await API.post(`/api/council/reminders/${b.dataset.done}/done`);
        b.closest('li').remove();
        if (!root.querySelector('li')) close();
      } catch (err) {
        b.disabled = false;
      }
    });
  }

  // ── 1) งานค้างใหม่: เสียง + แจ้งมุมจอ ──
  const SEEN_KEY = 'alertSeenIds';
  let me = null;

  const readSeen = () => {
    try { return JSON.parse(localStorage.getItem(SEEN_KEY) || 'null'); } catch (e) { return null; }
  };
  const writeSeen = (ids) => {
    try { localStorage.setItem(SEEN_KEY, JSON.stringify(ids)); } catch (e) {}
  };

  function toast(items) {
    let host = document.getElementById('al-toasts');
    if (!host) {
      host = document.createElement('div');
      host.id = 'al-toasts';
      host.className = 'al-toasts';
      host.setAttribute('aria-live', 'polite');
      document.body.appendChild(host);
    }
    const el = document.createElement('a');
    el.href = '/tasks.html';
    el.className = 'al-toast';
    const first = items[0];
    el.innerHTML = `<span class="al-toast-ic"><i class="ic" data-icon="bell" aria-hidden="true"></i></span>
      <span class="al-toast-txt"><b>มีงานค้างใหม่ ${items.length} รายการ</b><span>${esc(first.title)}${items.length > 1 ? ' และอีก ' + (items.length - 1) + ' รายการ' : ''}</span></span>`;
    host.appendChild(el);
    setTimeout(() => el.remove(), 14000);
  }

  async function pollAlerts() {
    try {
      const alerts = (await API.get('/api/council/alerts')).data;
      window.dispatchEvent(new CustomEvent('alerts:count', { detail: alerts.length }));   // อัปเดตตัวเลขบนเมนู
      const ids = alerts.map((a) => a.id);
      const seen = readSeen();
      writeSeen(ids);   // อัปเดตก่อนแจ้ง: แท็บอื่นของคนเดียวกันจะไม่แจ้งซ้ำ
      if (seen === null) return;   // ครั้งแรก: เก็บเป็นฐาน ไม่แจ้งของที่มีอยู่แล้ว
      const fresh = alerts.filter((a) => !seen.includes(a.id) && !(a.type === 'reminder' && me && a.created_by === me.id));
      if (fresh.length === 0) return;
      if (!showing) chime();
      toast(fresh);
    } catch (e) {
      /* ยังไม่ได้เข้าเวร/ออฟไลน์: ข้ามรอบนี้ */
    }
  }

  // ── 2) เตือนความจำครบกำหนด: เด้งกลางจอทุก 5 นาที ──
  async function check() {
    if (showing || Date.now() - read() < EVERY_MS) return;
    try {
      const alerts = (await API.get('/api/council/alerts')).data;
      const due = alerts.filter((a) => a.type === 'reminder' && a.days_left !== null && a.days_left <= 0);
      if (due.length === 0) return;
      write();   // จองเวลาก่อนแสดง กันหลายแท็บเด้งพร้อมกัน
      show(due);
    } catch (e) {
      /* ยังไม่ได้เข้าเวร/หมดเวร/ออฟไลน์: ข้ามรอบนี้ */
    }
  }

  async function start() {
    if (typeof authState !== 'function' || typeof API === 'undefined') return;
    const auth = authState();
    try { await auth.init(); } catch (e) { return; }
    if (!auth.user || !auth.can('member')) return;
    me = auth.user;
    check();
    pollAlerts();
    setInterval(check, TICK_MS);
    setInterval(pollAlerts, TICK_MS);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
