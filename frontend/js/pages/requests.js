// หน้า "คำขอรออนุมัติ" (requests.html): คิวกลางของธุรกรรมที่รอสภาอนุมัติ — รวมคำขอขึ้นทะเบียนที่ยังไม่มีกลุ่ม (ไม่มีหน้ากลุ่มให้กดอนุมัติ)
function requestsPage() {
  const BKK = 'Asia/Bangkok';
  const OUTFIT = { suit: 'Suit', hoodie: 'Hoodie', tshirt: 'T-Shirt', polo: 'Polo', armor: 'Armor', gun: 'Gun', mod1: 'MOD 1', mod2: 'MOD 2', accessories: 'Accessories' };
  const countsText = (c) => Object.entries(c || {}).filter(([, n]) => n > 0).map(([k, n]) => `${OUTFIT[k] || k} ${n}`).join(', ');
  const person = (p) => (p ? `${p.name}${p.phone ? ' · โทร ' + p.phone : ''}${p.discord_id ? ' · Discord ' + p.discord_id : ''}` : '—');

  return {
    ready: false,
    loading: true,
    busy: false,
    err: '',
    msg: '',
    photos: {},   // รูปที่แนบกับคำขอ (blob URL) โหลดด้วยสิทธิ์สภา
    itemErr: {},   // ข้อผิดพลาดตอนอนุมัติ แยกตามคำขอ แสดงในการ์ดนั้น
    items: [],
    me: null,
    kind: '',
    focusId: new URLSearchParams(location.search).get('id'),

    kinds: [
      { value: '', label: 'ทั้งหมด' },
      { value: 'register', label: 'ขึ้นทะเบียน' },
      { value: 'change_leader', label: 'เปลี่ยน Leader / Co-Leader' },
      { value: 'outfit', label: 'ลงชุด / แก้ไขชุด' },
      { value: 'rename', label: 'เปลี่ยนชื่อ / โลโก้' },
      { value: 'recolor', label: 'เปลี่ยนสี' },
      { value: 'other', label: 'อื่น ๆ' },
    ],

    async init() {
      const auth = authState();
      await auth.require('member');
      this.me = auth.user;
      this.ready = true;
      await this.load();
      if (this.focusId) this.$nextTick(() => document.getElementById('tx-' + this.focusId)?.scrollIntoView({ block: 'center' }));
    },

    async load() {
      this.loading = true;
      this.err = '';
      try {
        // รายงานความเคลื่อนไหวอนุมัติที่หน้า "รายงานความเคลื่อนไหว" แยกต่างหาก
        this.items = (await API.get('/api/council/transactions?status=pending&per_page=100')).data.filter((t) => t.kind !== 'activity_report');
        this.items.filter((t) => t.has_photo && !this.photos[t.id]).forEach((t) => this.loadPhoto(t));
      } catch (e) {
        this.items = [];
        this.err = e.status === 403 ? 'หน้านี้เปิดได้เฉพาะสภาที่เข้าเวรอยู่' : API.describe(e, 'โหลดคำขอไม่สำเร็จ');
      } finally {
        this.loading = false;
      }
    },

    async loadPhoto(t) {
      try {
        const res = await fetch(`${window.APP_CONFIG.API_URL}/api/council/transactions/${t.id}/files/photo`, { credentials: 'include' });
        if (res.ok) this.photos = { ...this.photos, [t.id]: URL.createObjectURL(await res.blob()) };
      } catch (e) {}
    },

    catOf(t) {
      return ['register', 'change_leader', 'activity_report', 'outfit', 'rename', 'recolor'].includes(t.kind) ? t.kind : ['outfit_add', 'outfit_counts', 'outfit_file'].includes(t.kind) ? 'outfit' : 'other';
    },

    get shown() {
      return this.kind ? this.items.filter((t) => this.catOf(t) === this.kind) : this.items;
    },

    count(v) {
      return v ? this.items.filter((t) => this.catOf(t) === v).length : this.items.length;
    },

    mine(t) {
      return !!this.me && !!t.requested_by && t.requested_by.id === this.me.id;
    },

    /** รายละเอียดที่สภาต้องดูก่อนอนุมัติ ตามชนิดคำขอ */
    details(t) {
      const p = t.payload || {};
      const rows = [];
      switch (t.kind) {
        case 'register':
          rows.push(['ประเภท', (p.type === 'family' ? 'ครอบครัว' : p.is_lady ? 'แก๊งหญิง (Lady Gang)' : 'แก๊ง')]);
          rows.push(['ชื่อ / ชื่อย่อ', `${p.name} [${p.short_name}]`]);
          rows.push(['สี', `${p.color_name} (${p.color_hex})`]);
          rows.push(['Leader', person(p.leader)]);
          (p.co_leaders || []).forEach((c, i) => rows.push([`Co-Leader ${i + 1}`, person(c)]));
          if (p.protect_from) rows.push(['Protect', `${String(p.protect_from).slice(0, 10)} → ${String(p.protect_until || '').slice(0, 10)}`]);
          break;
        case 'activity_report':
          rows.push(['Leader', person(p.leader)]);
          (p.co_leaders || []).forEach((c, i) => rows.push([`Co-Leader ${i + 1}`, person(c)]));
          rows.push(['สมาชิก', p.member_name]);
          rows.push(['ยืนยันตัวละครในรูป', p.confirm_characters ? 'ผู้ยื่นยืนยันว่ามี ≥ 6 ตัวละคร' : '—']);
          if (p.note) rows.push(['หมายเหตุ', p.note]);
          break;
        case 'change_leader':
          rows.push(['ตำแหน่ง', p.position === 'co_leader' ? (p.replace_name ? `Co-Leader (แทน ${p.replace_name})` : 'Co-Leader (เพิ่มใหม่)') : 'Leader']);
          rows.push(['คนใหม่', person(p)]);
          break;
        case 'rename':
          rows.push(['ชื่อใหม่', `${p.name} [${p.short_name}]`]);
          rows.push(['โลโก้ใหม่', t.has_logo ? 'แนบไฟล์แล้ว' : '—']);
          break;
        case 'recolor':
          rows.push(['สีใหม่', `${p.color_name} (${p.color_hex})`]);
          rows.push(['ไฟล์ชุดใหม่', t.has_outfit_zip ? 'แนบไฟล์แล้ว' : '—']);
          break;
        case 'outfit':
          if (countsText(p.add)) rows.push(['ลงเพิ่ม', countsText(p.add)]);
          if (p.replace && p.replace.types) rows.push(['ลงแทน', p.replace.types.map((k) => `${OUTFIT[k] || k} → ${(p.replace.counts || {})[k] ?? '?'} ชิ้น`).join(', ')]);
          if (p.edit && p.edit.types) rows.push(['แก้ไขชุด', p.edit.types.map((k) => OUTFIT[k] || k).join(', ')]);
          rows.push(['ไฟล์ชุด', t.has_outfit_zip ? 'แนบไฟล์แล้ว' : '—']);
          if (p.note) rows.push(['หมายเหตุ', p.note]);
          break;
      }
      return rows;
    },

    async review(t, action) {
      let body = {};
      if (action === 'reject') {
        const reason = await UI.prompt({ title: 'ไม่อนุมัติคำขอ', message: t.number, label: 'เหตุผล', required: true, maxLength: 2000, confirmText: 'ไม่อนุมัติ', kind: 'danger' });
        if (!reason) return;
        body = { reason };
      } else if (!(await UI.ask(`อนุมัติ ${t.kind_label} (${t.number}) ?\n${t.summary}`, { confirmText: 'อนุมัติ' }))) return;

      this.busy = true;
      this.err = '';
      this.msg = '';
      this.itemErr = {};
      try {
        await API.post(`/api/council/transactions/${t.id}/${action}`, body);
        this.msg = action === 'approve' ? `อนุมัติ ${t.number} แล้ว` : `บันทึกการไม่อนุมัติ ${t.number} แล้ว`;
        await this.load();
      } catch (e) {
        this.itemErr = { [t.id]: (e.errors && Object.values(e.errors)[0][0]) || e.message || 'ดำเนินการไม่สำเร็จ' };
      } finally {
        this.busy = false;
      }
    },

    money(n) {
      return Number(n || 0).toLocaleString('en-US');
    },

    stamp(iso) {
      return iso ? new Intl.DateTimeFormat('th-TH-u-ca-buddhist', { timeZone: BKK, day: '2-digit', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date(iso)).replace(',', '') : '—';
    },
  };
}
