function homePage() {
  const auth = authState();
  const SEV = {
    high: { label: 'เร่งด่วน', cls: 'badge badge-sanction' },
    medium: { label: 'ควรทำ', cls: 'badge badge-blacklist' },
    low: { label: 'ทบทวน', cls: 'badge badge-muted' },
  };
  const pad = (n) => String(n).padStart(2, '0');

  return {
    user: null,
    loading: true,
    duty: null,
    tick: 0,
    busy: false,
    err: '',
    alerts: [],
    alertCount: 0,

    async init() {
      await auth.init();
      this.user = auth.user;
      const blocked = new URLSearchParams(location.search).get('duty') === 'required';

      if (this.council()) {
        try {
          this.duty = await Duty.load();
        } catch (e) {}
        Duty.onChange((s) => {
          this.duty = s;
          this.notifyForcedEnd();
        });
        setInterval(() => this.tick++, 1000);
        await this.loadAlerts();
      }
      this.loading = false;

      // ป๊อปอัปแจ้งเตือน (กลางจอ) หลังหน้าแสดงผลแล้ว
      if (this.council()) {
        await this.notifyForcedEnd();
        if (blocked && !this.onDuty) {
          history.replaceState(null, '', location.pathname); // รีเฟรชแล้วไม่เด้งซ้ำ
          await UI.alert({ kind: 'warn', title: 'ต้องเข้าเวรก่อน', message: 'หน้าที่คุณเปิดใช้ได้เฉพาะตอนเข้าเวร' + String.fromCharCode(10) + 'กด "เข้าเวร" แล้วลองใหม่อีกครั้ง', confirmText: 'รับทราบ' });
        }
      }
    },

    // เวรครั้งก่อนถูกระบบบังคับออก (ปิดเว็บ/เบราว์เซอร์/คอมโดยไม่ออกเวร): แจ้งครั้งเดียวต่อเวร
    async notifyForcedEnd() {
      const f = this.duty && !this.duty.on_duty ? this.duty.last_forced_end : null;
      if (!f) return;
      let seen = null;
      try {
        seen = localStorage.getItem('dutyForcedSeen');
      } catch (e) {}
      if (String(f.id) === seen) return;
      try {
        localStorage.setItem('dutyForcedSeen', String(f.id));
      } catch (e) {}

      const dur = Duty.format(Date.parse(f.ended_at) - Date.parse(f.started_at));
      await UI.alert({
        kind: 'warn',
        title: 'เวรครั้งก่อนถูกบังคับออกเวร',
        message: `${f.note}
ระบบไม่พบสัญญาณจากหน้าเว็บ (ปิดเว็บ ปิดเบราว์เซอร์ หรือปิดคอมพิวเตอร์โดยไม่ได้ออกเวร)
นับเวลาเวรครั้งนั้นได้ ${dur}

เข้าเวรใหม่เพื่อใช้งานต่อ`,
        confirmText: 'รับทราบ',
      });
    },

    // สภา (Council ขึ้นไป) เห็นหน้าแรกแบบงานสภา; คนอื่นเห็นหน้าแรกสาธารณะ
    council() {
      return auth.can('member');
    },

    admin() {
      return auth.can('vice_president');
    },

    // ต้องเข้าเวรไหม (บทบาทที่ตั้งยกเว้นไว้จะเป็น false)
    get dutyRequired() {
      return !!this.duty && this.duty.required;
    },

    get onDuty() {
      return !!this.duty && this.duty.on_duty;
    },

    // เมนูงานสภาเปิดใช้ได้: เข้าเวรอยู่ หรือไม่ถูกบังคับให้เข้าเวร
    get open() {
      return !!this.duty && (!this.duty.required || this.duty.on_duty);
    },

    // เวลาที่อยู่ในเวร HH:MM:SS (เดินทุกวินาทีตาม tick)
    get elapsed() {
      void this.tick;
      return Duty.format(Duty.elapsedMs());
    },

    get startedAt() {
      if (!this.duty || !this.duty.started_at) return '';
      const d = new Date(this.duty.started_at);
      return `${pad(d.getHours())}:${pad(d.getMinutes())} น.`;
    },

    async toggleDuty() {
      if (this.busy) return;
      this.err = '';

      if (!this.onDuty) {
        this.busy = true;
        try {
          await Duty.start();
          await this.loadAlerts();
        } catch (e) {
          this.err = e.message || 'ดำเนินการไม่สำเร็จ';
        } finally {
          this.busy = false;
        }
        return;
      }

      // ออกเวร: ถ้าอยู่ในเวรไม่ถึงเวลาขั้นต่ำ ต้องระบุหมายเหตุ
      const hours = this.duty.short_shift_hours;
      let note = '';
      if (Duty.isShort()) {
        note = await UI.prompt({
          kind: 'warn',
          title: 'ออกเวรก่อนครบ ' + hours + ' ชั่วโมง',
          message: `คุณอยู่ในเวรมาแล้ว ${this.elapsed}
กรุณาระบุหมายเหตุที่ออกเวรก่อนเวลา`,
          label: 'หมายเหตุ',
          placeholder: 'เช่น ติดธุระด่วน / หมดเวลาเล่น',
          maxLength: 500,
          confirmText: 'ออกเวร',
          requiredMessage: 'กรุณาระบุหมายเหตุก่อนออกเวร',
        });
        if (note === null) return;
      } else if (!(await UI.confirm({ kind: 'warn', title: 'ออกเวรตอนนี้?', message: 'ออกเวรแล้วจะใช้งานส่วนอื่นของสภาไม่ได้' + String.fromCharCode(10) + 'จนกว่าจะเข้าเวรใหม่', confirmText: 'ออกเวร' }))) {
        return;
      }

      this.busy = true;
      try {
        await Duty.end(note);
        this.alerts = [];
        this.alertCount = 0;
      } catch (e) {
        this.err = (e.errors && Object.values(e.errors)[0][0]) || e.message || 'ดำเนินการไม่สำเร็จ';
      } finally {
        this.busy = false;
      }
    },

    // งานค้างโหลดได้เฉพาะตอนอยู่ในเวร (เซิร์ฟเวอร์บังคับ)
    async loadAlerts() {
      if (!this.open) return;
      try {
        const res = await API.get('/api/council/alerts');
        this.alerts = res.data;
        this.alertCount = res.meta.count;
      } catch (e) {}
    },

    get displayName() {
      return this.user ? this.user.character_name || this.user.name : '';
    },

    get topAlerts() {
      return this.alerts.slice(0, 3);
    },

    sev(s) {
      return SEV[s] || SEV.low;
    },
  };
}
