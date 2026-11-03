// หน้า "รายงานความเคลื่อนไหว" (activity-rounds.html): สภาเปิด/ปิดรอบรายงานของแก๊ง/ครอบครัว และลงรายงานตัว
function activityRoundsPage() {
  const BKK = 'Asia/Bangkok';

  return {
    ready: false,
    loading: true,
    busy: false,
    me: null,
    items: [],     // รายงานความเคลื่อนไหวที่แก๊ง/ครอบครัวยื่นเข้ามา รอสภาบันทึกรายงานตัว
    photos: {},    // รูปที่แนบ (blob URL) โหลดด้วยสิทธิ์สภา
    itemErr: {},   // ข้อผิดพลาดตอนบันทึก แยกตามรายการ
    focusId: new URLSearchParams(location.search).get('id'),
    err: '',
    msg: '',
    // รายงานตัวของกลุ่ม
    showReport: false,
    groups: [],
    repLoading: false,
    repQ: '',
    repStatus: '',
    repStatuses: [
      { value: '', label: 'ทั้งหมด' },
      { value: 'unreported', label: 'ยังไม่รายงานรอบนี้' },
      { value: 'normal', label: 'ปกติ' },
      { value: 'inactive', label: 'ขาดการเคลื่อนไหว' },
    ],
    rounds: [],
    showRoundForm: false,
    roundForm: { title: '', opens_at: '', closes_at: '' },
    roundErr: {},

    async init() {
      const auth = authState();
      await auth.require('member');
      this.me = auth.user;
      this.ready = true;
      await Promise.all([this.loadRounds(), this.loadRequests()]);
      if (this.focusId) this.$nextTick(() => document.getElementById('tx-' + this.focusId)?.scrollIntoView({ block: 'center' }));
    },

    async loadRequests() {
      this.loading = true;
      try {
        this.items = (await API.get('/api/council/transactions?kind=activity_report&status=pending&per_page=100')).data;
        this.items.filter((t) => t.has_photo && !this.photos[t.id]).forEach((t) => this.loadPhoto(t));
      } catch (e) {
        this.items = [];
        this.err = e.status === 403 ? 'หน้านี้เปิดได้เฉพาะสภาที่เข้าเวรอยู่' : API.describe(e, 'โหลดคำร้องไม่สำเร็จ');
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

    mine(t) {
      return !!this.me && !!t.requested_by && t.requested_by.id === this.me.id;
    },

    /** รายละเอียดที่สภาตรวจก่อนบันทึกรายงานตัว */
    details(t) {
      const p = t.payload || {};
      const person = (x) => (x ? `${x.name}${x.phone ? ' · โทร ' + x.phone : ''}` : '—');
      const rows = [['Leader', person(p.leader)]];
      (p.co_leaders || []).forEach((c, i) => rows.push([`Co-Leader ${i + 1}`, person(c)]));
      rows.push(['สมาชิก', p.member_name || '—']);
      rows.push(['ยืนยันตัวละครในรูป', p.confirm_characters ? 'ผู้ยื่นยืนยันว่ามี ≥ 6 ตัวละคร' : '—']);
      if (p.note) rows.push(['หมายเหตุ', p.note]);
      return rows;
    },

    async review(t, action) {
      let body = {};
      if (action === 'reject') {
        const reason = await UI.prompt({ title: 'ตีกลับรายงาน', message: t.number, label: 'เหตุผลที่ตีกลับ (เช่น รูปมีตัวละครไม่ถึง 6 ตัว)', required: true, maxLength: 2000, confirmText: 'ตีกลับ', kind: 'danger' });
        if (!reason) return;
        body = { reason };
      } else if (!(await UI.ask(`บันทึกรายงานตัวของ ${t.group ? t.group.name : ''} (${t.number}) ?
ระบบจะลงบันทึกว่ากลุ่มมีความเคลื่อนไหวแล้ว`, { confirmText: 'บันทึกรายงานตัว' }))) return;

      this.busy = true;
      this.err = '';
      this.msg = '';
      this.itemErr = {};
      try {
        await API.post(`/api/council/transactions/${t.id}/${action}`, body);
        this.msg = action === 'approve' ? `บันทึกรายงานตัว ${t.number} แล้ว` : `ตีกลับรายงาน ${t.number} แล้ว`;
        await Promise.all([this.loadRequests(), this.loadRounds()]);
      } catch (e) {
        this.itemErr = { [t.id]: (e.errors && Object.values(e.errors)[0][0]) || e.message || 'ดำเนินการไม่สำเร็จ' };
      } finally {
        this.busy = false;
      }
    },


    async loadRounds() {
      try {
        this.rounds = (await API.get('/api/council/activity-rounds')).data;
      } catch (e) {}
    },

    async createRound() {
      this.roundErr = {};
      this.busy = true;
      try {
        const f = this.roundForm;
        await API.post('/api/council/activity-rounds', {
          title: f.title.trim(),
          opens_at: f.opens_at ? new Date(f.opens_at).toISOString() : '',
          closes_at: f.closes_at ? new Date(f.closes_at).toISOString() : '',
        });
        this.roundForm = { title: '', opens_at: '', closes_at: '' };
        this.showRoundForm = false;
        this.msg = 'เปิดรอบรับรายงานแล้ว';
        await this.loadRounds();
      } catch (e) {
        this.roundErr = e.errors ? Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])) : { title: e.message || 'เปิดรอบไม่สำเร็จ' };
      } finally {
        this.busy = false;
      }
    },

    async closeRound(r) {
      if (!(await UI.ask(`${r.phase === 'open' ? 'ปิดรอบ' : 'ยกเลิกรอบ'} "${r.title}" ?`, { kind: 'danger', confirmText: r.phase === 'open' ? 'ปิดรอบ' : 'ยกเลิกรอบ' }))) return;
      try {
        await API.post(`/api/council/activity-rounds/${r.id}/close`);
        await this.loadRounds();
      } catch (e) {
        this.err = e.message || 'ดำเนินการไม่สำเร็จ';
      }
    },

    toggleReport() {
      this.showReport = !this.showReport;
      if (this.showReport && this.groups.length === 0) this.loadGroups();
    },

    async loadGroups() {
      this.repLoading = true;
      try {
        const p = new URLSearchParams({ per_page: 100 });
        if (this.repQ.trim()) p.set('q', this.repQ.trim());
        this.groups = (await API.get(`/api/council/groups?${p}`)).data;
      } catch (e) {
        this.err = API.describe(e, 'โหลดรายชื่อกลุ่มไม่สำเร็จ');
      } finally {
        this.repLoading = false;
      }
    },

    /** กลุ่มที่แสดง: กรองตามสถานะ เรียงตามรายงานตัวล่าสุดเก่าสุดขึ้นก่อน (ที่ไม่เคยรายงานอยู่บนสุด) */
    /** รอบที่เปิดรับอยู่ตอนนี้ */
    get openRound() {
      return this.rounds.find((r) => r.phase === 'open') || null;
    },

    /** กลุ่มนี้รายงานตัวในรอบที่เปิดอยู่แล้วหรือยัง (มีบันทึกรายงานตัวหลังเวลาเปิดรอบ ไม่ว่าจะมาจากรายงานที่สภาบันทึกหรือสภาลงเอง) */
    reportedThisRound(g) {
      const r = this.openRound;
      return !!r && !!g.last_report_at && Date.parse(g.last_report_at) >= Date.parse(r.opens_at);
    },

    /** สรุปรอบนี้: รายงานแล้วกี่กลุ่มจากทั้งหมด (เฉพาะกลุ่มที่ใช้รายงานตัวได้) */
    get roundProgress() {
      const eligible = this.groups.filter((g) => g.status === 'normal' || g.status === 'inactive');
      return { done: eligible.filter((g) => this.reportedThisRound(g)).length, total: eligible.length };
    },

    get repShown() {
      const list = this.repStatus === 'unreported'
        ? this.groups.filter((g) => (g.status === 'normal' || g.status === 'inactive') && !this.reportedThisRound(g))
        : this.repStatus ? this.groups.filter((g) => g.status === this.repStatus) : this.groups;
      return [...list].sort((a, b) => (a.last_report_at ? Date.parse(a.last_report_at) : 0) - (b.last_report_at ? Date.parse(b.last_report_at) : 0));
    },

    /** สองฝั่ง: ซ้าย = แก๊ง (แก๊งหญิงต่อท้าย) · ขวา = ครอบครัว */
    get repColumns() {
      const rows = this.repShown;
      const gang = rows.filter((g) => g.type === 'gang').sort((a, b) => Number(!!a.is_lady) - Number(!!b.is_lady));
      return [
        { key: 'gang', title: 'แก๊ง', groups: gang },
        { key: 'family', title: 'ครอบครัว', groups: rows.filter((g) => g.type === 'family') },
      ];
    },

    async reportIn(g, status) {
      let note = null;
      if (status === 'inactive') {
        note = await UI.prompt({ title: 'ขาดการเคลื่อนไหว', message: g.name, label: 'เหตุผล/หมายเหตุ (ไม่บังคับ)', required: false, maxLength: 2000, confirmText: 'บันทึก', kind: 'danger' });
        if (note === null || note === undefined) return;
      } else if (!(await UI.ask(`บันทึกรายงานตัวของ ${g.name} ?${g.status === 'inactive' ? '\nสถานะจะกลับเป็น "ปกติ"' : ''}`, { confirmText: 'รายงานตัว' }))) return;

      this.busy = true;
      this.err = '';
      this.msg = '';
      try {
        await API.post(`/api/council/groups/${g.id}/records`, { kind: 'report', status, note: (note || '').trim() || null });
        this.msg = status === 'inactive' ? `บันทึก ${g.name} ขาดการเคลื่อนไหวแล้ว` : `บันทึกรายงานตัวของ ${g.name} แล้ว`;
        await this.loadGroups();
      } catch (e) {
        this.err = (e.errors && Object.values(e.errors)[0][0]) || e.message || 'บันทึกไม่สำเร็จ';
      } finally {
        this.busy = false;
      }
    },

    stamp(iso) {
      return iso ? new Intl.DateTimeFormat('th-TH-u-ca-buddhist', { timeZone: BKK, day: '2-digit', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date(iso)).replace(',', '') : '—';
    },
  };
}
