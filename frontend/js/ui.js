// กล่องข้อความกลางจอ (แทน confirm / alert / prompt ของเบราว์เซอร์) ใช้สไตล์เดียวกับระบบและสลับธีมได้
//   await UI.confirm({ title, message, confirmText, cancelText, kind })  → true | false
//   await UI.prompt({ title, message, label, placeholder, required, maxLength, confirmText, kind })
//                                                                        → ข้อความ | null (ยกเลิก)
//   await UI.alert({ title, message, okText, kind })                      → (ปิดแล้ว)
// kind: 'info' | 'warn' | 'danger' | 'ok'   ข้อความทั้งหมดใส่ด้วย textContent (ไม่ตีความ HTML)
const UI = (() => {
  const ICONS = {
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>',
    warn: '<path d="M12 3 2.5 20h19L12 3z"/><path d="M12 10v4"/><path d="M12 17h.01"/>',
    danger: '<circle cx="12" cy="12" r="9"/><path d="M12 7v6"/><path d="M12 16.5h.01"/>',
    ok: '<circle cx="12" cy="12" r="9"/><path d="m8 12.5 3 3 5-6"/>',
  };

  let queue = Promise.resolve();
  let seq = 0;

  const el = (tag, cls, text) => {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
  };

  function show(opts) {
    // ซ้อนกันไม่ได้: ถ้ามีกล่องเปิดอยู่ให้รอคิว
    const run = () =>
      new Promise((resolve) => {
        const id = ++seq;
        const kind = ICONS[opts.kind] ? opts.kind : 'info';
        const previous = document.activeElement;

        const backdrop = el('div', 'ui-backdrop');
        const modal = el('div', 'ui-modal');
        modal.setAttribute('role', opts.cancelText === null ? 'alertdialog' : 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', `ui-title-${id}`);

        const icon = el('div', `ui-icon ui-icon-${kind}`);
        icon.innerHTML = `<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONS[kind]}</svg>`;
        modal.appendChild(icon);

        const title = el('h2', 'ui-title', opts.title || '');
        title.id = `ui-title-${id}`;
        modal.appendChild(title);

        if (opts.message) {
          const msg = el('p', 'ui-message', opts.message);
          msg.id = `ui-msg-${id}`;
          modal.setAttribute('aria-describedby', msg.id);
          modal.appendChild(msg);
        }

        // ช่องกรอก (prompt)
        let input = null;
        let error = null;
        if (opts.input) {
          const field = el('div', 'ui-field');
          if (opts.input.label) {
            const lab = el('label', 'ui-label', opts.input.label);
            lab.htmlFor = `ui-input-${id}`;
            field.appendChild(lab);
          }
          input = el('textarea', 'input');
          input.id = `ui-input-${id}`;
          input.rows = 3;
          if (opts.input.placeholder) input.placeholder = opts.input.placeholder;
          if (opts.input.maxLength) input.maxLength = opts.input.maxLength;
          field.appendChild(input);
          error = el('p', 'ui-error');
          error.hidden = true;
          field.appendChild(error);
          modal.appendChild(field);
        }

        const actions = el('div', 'ui-actions');
        let cancelBtn = null;
        if (opts.cancelText !== null) {
          cancelBtn = el('button', 'ui-btn ui-btn-ghost', opts.cancelText || 'ยกเลิก');
          cancelBtn.type = 'button';
          actions.appendChild(cancelBtn);
        }
        const okBtn = el('button', `ui-btn ${kind === 'danger' ? 'ui-btn-danger' : 'ui-btn-primary'}`, opts.confirmText || 'ตกลง');
        okBtn.type = 'button';
        okBtn.dataset.ok = '';
        actions.appendChild(okBtn);
        modal.appendChild(actions);

        backdrop.appendChild(modal);
        document.body.appendChild(backdrop);
        document.documentElement.classList.add('ui-locked');

        let done = false;
        const close = (value) => {
          if (done) return;
          done = true;
          document.removeEventListener('keydown', onKey, true);
          backdrop.classList.add('ui-closing');
          document.documentElement.classList.remove('ui-locked');
          setTimeout(() => backdrop.remove(), 140);
          if (previous && previous.focus) previous.focus();
          resolve(value);
        };

        const submit = () => {
          if (!input) return close(true);
          const value = input.value.trim();
          if (opts.input.required && value === '') {
            error.textContent = opts.input.requiredMessage || 'กรุณากรอกข้อมูล';
            error.hidden = false;
            input.setAttribute('aria-invalid', 'true');
            input.focus();
            return;
          }
          close(value);
        };
        const cancel = () => close(input ? null : false);

        function onKey(e) {
          if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            if (cancelBtn) cancel();
            else close(undefined);
          } else if (e.key === 'Enter' && !(input && e.target === input && !e.ctrlKey && !e.metaKey)) {
            if (e.target === cancelBtn) return; // ให้ปุ่มยกเลิกทำงานตามปกติ
            e.preventDefault();
            submit();
          } else if (e.key === 'Tab') {
            // วนโฟกัสอยู่ในกล่อง
            const f = [...modal.querySelectorAll('button, textarea')].filter((x) => !x.disabled);
            if (!f.length) return;
            const first = f[0];
            const last = f[f.length - 1];
            if (e.shiftKey && document.activeElement === first) {
              e.preventDefault();
              last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
              e.preventDefault();
              first.focus();
            }
          }
        }
        document.addEventListener('keydown', onKey, true);

        okBtn.addEventListener('click', submit);
        if (cancelBtn) cancelBtn.addEventListener('click', cancel);
        if (input) input.addEventListener('input', () => {
          error.hidden = true;
          input.removeAttribute('aria-invalid');
        });
        // คลิกพื้นหลัง = ยกเลิก (ยกเว้นกล่องที่ต้องกรอกข้อมูลหรือยืนยันการลบ เพื่อกันกดพลาด)
        backdrop.addEventListener('mousedown', (e) => {
          if (e.target === backdrop && cancelBtn && !input && kind !== 'danger') cancel();
        });

        (input || okBtn).focus();
      });

    const p = queue.then(run, run);
    queue = p.catch(() => {});
    return p;
  }

  return {
    // ทางลัดแทน confirm("หัวข้อ\nรายละเอียด"): บรรทัดแรกเป็นหัวข้อ ที่เหลือเป็นคำอธิบาย
    ask: (text, o = {}) => {
      const [title, ...rest] = String(text).split('\n');
      return show({ kind: 'warn', title, message: rest.join('\n'), ...o });
    },
    confirm: (o = {}) => show({ kind: 'warn', ...o }),
    prompt: (o = {}) =>
      show({
        kind: 'warn',
        ...o,
        input: { label: o.label, placeholder: o.placeholder, required: o.required !== false, maxLength: o.maxLength, requiredMessage: o.requiredMessage },
      }),
    alert: (o = {}) => show({ kind: 'info', cancelText: null, confirmText: 'รับทราบ', ...o }).then(() => undefined),
  };
})();
