// หน้า "หนังสือสัญญาเงินกู้" (loan-contract.html?id=…): เอกสารแนวนอนแบบเดียวกับหนังสือสัญญาสตอรี่ เปิดดูได้เฉพาะประธานและรองประธานที่เข้าเวรอยู่
function loanContractPage() {
  const DOC_W = 560; // A5 แนวตั้ง (148×210 มม. ที่ 96dpi) · แสดงบนจอขยายได้ถึง MAX_SCALE เท่า
  const MAX_SCALE = 1.7;

  return {
    ...StoryFmt,
    cur: null,
    loading: true,
    error: '',
    scale: 1,
    boxW: DOC_W,
    boxH: 1000,
    busy: false,
    copied: false,
    copiedLink: false,
    err: '',
    embed: new URLSearchParams(location.search).get('embed') === '1', // ฝังในป๊อปอัป: ไม่มีขอบ/ท้ายหน้า

    async init() {
      const qs = new URLSearchParams(location.search);
      const id = qs.get('id');
      try {
        if (qs.get('draft') === '1') {
          // ตัวอย่างก่อนบันทึก: หน้าการเงินส่งร่างสัญญามาทาง sessionStorage
          this.cur = JSON.parse(sessionStorage.getItem('loanDraft') || 'null');
          if (!this.cur) throw Object.assign(new Error('draft'), { status: 404 });
        } else {
          this.cur = (await API.get(`/api/council/finance/loans/${encodeURIComponent(id || '')}`)).data;
        }
        this.$nextTick(() => this.fit());
        setTimeout(() => this.fit(), 400); // รอฟอนต์/รูปโลโก้โหลดแล้ววัดความสูงอีกครั้ง
      } catch (e) {
        this.error = e.status === 401 || e.status === 403
          ? 'ต้องเข้าสู่ระบบสภาในฐานะประธานหรือรองประธาน และเข้าเวรอยู่ จึงจะดูหนังสือสัญญาเงินกู้ได้'
          : e.status === 404 ? 'ไม่พบหนังสือสัญญาเงินกู้นี้ (อาจถูกลบแล้ว)' : 'โหลดหนังสือสัญญาเงินกู้ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง';
      } finally {
        this.loading = false;
      }
    },

    /** ย่อเอกสารให้พอดีความกว้างหน้าจอ (ไม่ขยายเกินขนาดจริง) */
    fit() {
      const doc = document.getElementById('loan-contract');
      this.scale = Math.min(MAX_SCALE, (document.documentElement.clientWidth - 24) / DOC_W);
      const h = doc ? doc.offsetHeight : 1000;
      this.boxW = Math.round(DOC_W * this.scale);
      this.boxH = Math.round(h * this.scale);
    },

    /** สร้างรูป PNG ของสัญญา (ทำสำเนาที่ล้างแอตทริบิวต์ Alpine ออกก่อน เพราะ html-to-image ต้องการ XML ที่ถูกต้อง) */
    async blob() {
      const el = document.getElementById('loan-contract');
      if (!el) throw new Error('no-doc');
      const BG = '#0a1c2a';
      if (window.htmlToImage) {
        const host = document.createElement('div');
        host.style.cssText = 'position:fixed;left:-20000px;top:0;pointer-events:none;width:' + DOC_W + 'px';
        const copy = el.cloneNode(true);
        for (const node of [copy, ...copy.querySelectorAll('*')]) {
          for (const attr of [...node.attributes]) {
            if (!/^[A-Za-z_][\w.-]*$/.test(attr.name) || attr.name.startsWith('x-')) node.removeAttribute(attr.name);
          }
        }
        copy.querySelectorAll('[style*="display: none"]').forEach((n) => n.remove());
        await Promise.all(
          [...copy.querySelectorAll('img')].map(async (img) => {
            const src = img.getAttribute('src');
            if (!src || src.startsWith('data:')) return;
            try {
              const b = await (await fetch(src)).blob();
              img.src = await new Promise((res, rej) => {
                const fr = new FileReader();
                fr.onload = () => res(fr.result);
                fr.onerror = rej;
                fr.readAsDataURL(b);
              });
            } catch (e) {
              img.remove();
            }
          })
        );
        host.appendChild(copy);
        document.body.appendChild(host);
        try {
          const out = await window.htmlToImage.toBlob(copy, { pixelRatio: 3, backgroundColor: BG, cacheBust: false, width: DOC_W });
          if (out) return out;
        } finally {
          host.remove();
        }
      }
      if (typeof html2canvas !== 'function') throw new Error('no-renderer');
      const canvas = await html2canvas(el, { backgroundColor: BG, scale: 2, useCORS: true, logging: false });
      return new Promise((res) => canvas.toBlob(res, 'image/png'));
    },

    /** คัดลอกสัญญาเป็นรูปลงคลิปบอร์ด (ไปวางใน Discord) */
    async copyPng() {
      this.busy = true;
      this.err = '';
      this.copied = false;
      try {
        const out = await this.blob();
        if (!navigator.clipboard || typeof ClipboardItem === 'undefined') throw new Error('clipboard');
        await navigator.clipboard.write([new ClipboardItem({ 'image/png': out })]);
        this.copied = true;
        setTimeout(() => (this.copied = false), 6000);
      } catch (e) {
        this.err = 'คัดลอกรูปไม่สำเร็จ (เบราว์เซอร์ต้องอนุญาตคลิปบอร์ด และเปิดผ่าน https หรือ localhost)';
      } finally {
        this.busy = false;
      }
    },

    /** สร้างรูปหนังสือสัญญาแล้วอัปโหลดให้เซิร์ฟเวอร์ คืนลิงก์รูป (เปิดดูได้โดยไม่ต้องล็อกอิน · สร้างใหม่แล้วลิงก์เดิมใช้ไม่ได้) */
    async shareLink() {
      if (!this.cur || this.cur.draft) throw new Error('no-loan');
      const out = await this.blob();
      const fd = new FormData();
      fd.append('image', out, this.number() + '.png');
      const res = await API.post(`/api/council/finance/loans/${this.cur.id}/image`, fd);
      return `${(window.APP_CONFIG && window.APP_CONFIG.API_URL) || ''}/api/public/loan-contracts/${res.token}.png`;
    },

    async copyLink() {
      this.busy = true;
      this.err = '';
      this.copied = false;
      try {
        const url = await this.shareLink();
        try {
          await navigator.clipboard.writeText(url);
        } catch (e) {
          window.prompt('คัดลอกลิงก์รูปนี้', url); // เบราว์เซอร์ไม่อนุญาตคลิปบอร์ด
        }
        this.copiedLink = true;
        setTimeout(() => (this.copiedLink = false), 6000);
      } catch (e) {
        this.err = API.describe ? API.describe(e, 'สร้างลิงก์รูปไม่สำเร็จ') : 'สร้างลิงก์รูปไม่สำเร็จ';
      } finally {
        this.busy = false;
      }
    },

    /** จำนวนเงินเป็นตัวอักษรไทย เช่น 51,000 → "ห้าหมื่นหนึ่งพันวีวีดีแอล" */
    baht(n) {
      const D = ['', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า'];
      const U = ['', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน'];
      const part = (x) => {
        const s = String(x);
        let out = '';
        for (let i = 0; i < s.length; i++) {
          const d = Number(s[i]);
          const pos = s.length - i - 1;
          if (d === 0) continue;
          if (pos === 0 && d === 1 && s.length > 1) out += 'เอ็ด';
          else if (pos === 1 && d === 2) out += 'ยี่สิบ';
          else if (pos === 1 && d === 1) out += 'สิบ';
          else out += D[d] + U[pos];
        }
        return out;
      };
      let v = Math.floor(Math.abs(Number(n) || 0));
      if (v === 0) return 'ศูนย์วีวีดีแอล';
      const chunks = [];
      while (v > 0) {
        chunks.unshift(v % 1000000);
        v = Math.floor(v / 1000000);
      }
      const text = chunks.map((c, i) => (c ? part(c) + 'ล้าน'.repeat(chunks.length - i - 1) : (chunks.length - i - 1 ? 'ล้าน' : ''))).join('');
      return text + 'วีวีดีแอล';
    },

    number() {
      if (!this.cur) return '';
      return this.cur.draft ? 'ฉบับร่าง' : 'WV-LN-' + String(this.cur.id).padStart(6, '0');
    },

    short(g) {
      return g ? (g.short_name || g.name.slice(0, 3)).toUpperCase() : '';
    },

    kind(g) {
      return !g ? '' : g.type === 'family' ? 'ครอบครัว' : g.is_lady ? 'แก๊งหญิงล้วน' : 'แก๊ง';
    },

    get typeLine() {
      return this.cur && this.cur.group ? this.kind(this.cur.group) : '';
    },

    get lateRule() {
      const c = this.cur;
      if (!c || !c.late_fee_value) return 'ไม่มีค่าปรับ';
      const unit = { day: 'ต่อวันที่เกินกำหนด', week: 'ต่อสัปดาห์ที่เกินกำหนด (ปัดขึ้น)', once: 'ครั้งเดียวเมื่อเกินกำหนด' }[c.late_fee_type] || '';
      return `${this.money(c.late_fee_value)} WVDL ${unit}`;
    },

    day(s) {
      return s ? this.dateLong(s + 'T12:00:00+07:00') : '—';
    },

    get hasFee() {
      return !!(this.cur && this.cur.late_fee_total > 0);
    },

    stLabel(s) {
      return { paid: 'ชำระแล้ว', paid_late: 'ชำระช้า', overdue: 'เกินกำหนด', due: 'ถึงกำหนดวันนี้', upcoming: 'ยังไม่ถึงกำหนด' }[s] || s;
    },
  };
}
