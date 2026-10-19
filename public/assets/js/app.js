// Energy & Utility DSS - Foundation JS thuần (không phụ thuộc thư viện ngoài)
// App.register(fn): khởi tạo gắn với phần tử trong vùng nội dung (<main class="app-content">) - chạy khi tải trang
// và chạy LẠI sau mỗi lần thao tác "live" thay vùng nội dung (không tải lại trang). Xử lý toàn cục (sidebar, modal xác nhận,
// submit) chỉ gắn MỘT lần.
window.App = window.App || {
  inits: [],
  register: function (fn) { this.inits.push(fn); fn(); },
  runInits: function () { this.inits.forEach(function (fn) { try { fn(); } catch (e) { if (window.console) { console.error(e); } } }); }
};
// Song ngữ: App.tr(khoá, chữ Việt gốc, {tham số}). Layout chỉ xuất #i18nData (JSON) khi ngôn ngữ KHÁC tiếng Việt -> tiếng Việt dùng đúng chữ gốc.
(function () {
  var dict = {};
  try { var el = document.getElementById('i18nData'); dict = el ? JSON.parse(el.textContent) : {}; } catch (e) { dict = {}; }
  window.App.tr = function (key, fallback, params) {
    var s = Object.prototype.hasOwnProperty.call(dict, key) ? dict[key] : fallback;
    Object.keys(params || {}).sort(function (a, b) { return b.length - a.length; }).forEach(function (k) { s = s.split(':' + k).join(String(params[k])); });
    return s;
  };
})();
(function () {
  'use strict';

  // Toggle sidebar trên mobile
  var toggleBtn = document.getElementById('sidebarToggle');
  var sidebar = document.getElementById('appSidebar');
  if (toggleBtn && sidebar) {
    toggleBtn.addEventListener('click', function (event) {
      event.stopPropagation();
      sidebar.classList.toggle('is-open');
    });
    // STEP 17: chạm ra ngoài menu hoặc bấm Esc thì đóng (mobile / tablet)
    document.addEventListener('click', function (event) {
      if (sidebar.classList.contains('is-open') && !sidebar.contains(event.target)) {
        sidebar.classList.remove('is-open');
      }
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') { sidebar.classList.remove('is-open'); }
    });
  }

  // Đổi ngôn ngữ (topbar): đóng menu khi bấm ra ngoài hoặc Esc (<details> tự mở / đóng khi bấm, không cần JS).
  document.addEventListener('click', function (event) {
    Array.prototype.forEach.call(document.querySelectorAll('[data-lang-switch][open]'), function (d) {
      if (!d.contains(event.target)) { d.removeAttribute('open'); }
    });
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      Array.prototype.forEach.call(document.querySelectorAll('[data-lang-switch][open]'), function (d) { d.removeAttribute('open'); });
    }
  });
  // Sidebar (mọi vai trò): nhóm menu thu gọn / mở rộng. Trạng thái mặc định do server render (chỉ mở nhóm chứa trang hiện tại);
  // bấm tiêu đề nhóm để mở / đóng. Nhóm đang đóng hiện tổng badge việc cần xử lý của các mục bên trong.
  document.addEventListener('click', function (event) {
    var btn = event.target.closest ? event.target.closest('[data-nav-group-toggle]') : null;
    if (!btn) { return; }
    var group = btn.closest('[data-nav-group]');
    var open = !group.classList.contains('is-open');
    group.classList.toggle('is-open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  window.App.navGroupSums = function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-nav-group]'), function (group) {
      var sum = 0;
      Array.prototype.forEach.call(group.querySelectorAll('.nav-group__inner [data-issue-count]:not([hidden]) [aria-hidden="true"], .nav-group__inner [data-payment-review-count]'), function (el) {
        sum += parseInt(el.textContent, 10) || 0;
      });
      var out = group.querySelector('[data-nav-group-sum]');
      if (out) { out.textContent = String(sum); out.hidden = sum === 0; }
    });
  };
  // STEP 6: modal xác nhận cho form có class "js-confirm" (vd: Ngừng bản ghi).
  // Form chỉ được gửi sau khi bấm Xác nhận; không có JS thì form gửi thẳng
  // (server vẫn kiểm tra quyền, CSRF và điều kiện nghiệp vụ).
  var modal = document.getElementById('confirmModal');
  var pendingForm = null;
  function closeModal() {
    if (modal) { modal.hidden = true; }
    pendingForm = null;
  }
  if (modal) {
    var okBtn = document.getElementById('confirmModalOk');
    modal.addEventListener('click', function (event) {
      if (event.target.hasAttribute('data-modal-close')) { closeModal(); }
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !modal.hidden) { closeModal(); }
    });
    okBtn.addEventListener('click', function () {
      if (pendingForm) {
        var form = pendingForm;
        form.dataset.confirmed = '1';
        closeModal();
        if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
      }
    });
  }

  App.register(function () {
    // STEP 6: dropdown Khu vực chỉ hiện khu thuộc Tòa nhà đang chọn (tiện dụng;
    // ràng buộc "khu thuộc đúng tòa" vẫn được Service kiểm tra phía server).
    var buildingSelect = document.querySelector('[data-zone-filter-source]');
    var zoneSelect = document.querySelector('[data-zone-filter-target]');
    function syncZones() {
      if (!buildingSelect || !zoneSelect) { return; }
      var buildingId = buildingSelect.value;
      Array.prototype.forEach.call(zoneSelect.options, function (option) {
        var owner = option.getAttribute('data-building');
        var visible = !owner || owner === buildingId;
        option.hidden = !visible;
        option.disabled = !visible;
        if (!visible && option.selected) { zoneSelect.value = ''; }
      });
    }
    if (buildingSelect && zoneSelect) {
      buildingSelect.addEventListener('change', syncZones);
      syncZones();
    }

    // STEP 7: modal theo id (vd modal Từ chối đề xuất có form + textarea bắt buộc).
    Array.prototype.forEach.call(document.querySelectorAll('[data-open-modal]'), function (button) {
      button.addEventListener('click', function () {
        var target = document.getElementById(button.getAttribute('data-open-modal'));
        if (target) {
          target.hidden = false;
          var field = target.querySelector('textarea, input:not([type="hidden"])');
          if (field) { field.focus(); }
        }
      });
    });
    Array.prototype.forEach.call(document.querySelectorAll('.modal'), function (dialog) {
      if (dialog.id === 'confirmModal') { return; }
      dialog.addEventListener('click', function (event) {
        if (event.target.hasAttribute('data-modal-close')) { dialog.hidden = true; }
      });
    });

    // STEP 7: bộ sửa bậc thang - thêm/xoá bậc, đánh lại số, gợi ý "Từ" = "Đến" bậc trước.
    // Chỉ hỗ trợ nhập liệu; ràng buộc [Từ, Đến) kiểm ở server (TariffService::validateTiers).
    Array.prototype.forEach.call(document.querySelectorAll('[data-tier-editor]'), function (editor) {
      var body = editor.querySelector('[data-tier-rows]');
      var counter = body.querySelectorAll('[data-tier-row]').length;
      function rows() { return body.querySelectorAll('[data-tier-row]'); }
      function renumber() {
        Array.prototype.forEach.call(rows(), function (row, index) {
          row.querySelector('[data-tier-no]').textContent = index + 1;
        });
      }
      editor.querySelector('[data-tier-add]').addEventListener('click', function () {
        var all = rows();
        var last = all[all.length - 1];
        var row = last.cloneNode(true);
        var i = counter++;
        Array.prototype.forEach.call(row.querySelectorAll('input'), function (input) {
          input.name = input.name.replace(/tiers\[\d+\]/, 'tiers[' + i + ']');
          input.value = '';
        });
        row.classList.remove('has-error-row');
        var prevTo = last.querySelector('[data-tier-to]').value;
        row.querySelector('[data-tier-from]').value = prevTo;
        body.appendChild(row);
        renumber();
        row.querySelector('[data-tier-to]').focus();
      });
      body.addEventListener('click', function (event) {
        if (!event.target.hasAttribute('data-tier-remove')) { return; }
        if (rows().length <= 1) { return; }
        event.target.closest('[data-tier-row]').remove();
        renumber();
      });
      body.addEventListener('change', function (event) {
        if (!event.target.hasAttribute('data-tier-to')) { return; }
        var row = event.target.closest('[data-tier-row]');
        var next = row.nextElementSibling;
        while (next && !next.hasAttribute('data-tier-row')) { next = next.nextElementSibling; }
        if (next && next.querySelector('[data-tier-from]').value === '') {
          next.querySelector('[data-tier-from]').value = event.target.value;
        }
      });
    });

    // STEP 7: form biểu giá - hiện phần Bậc thang (sinh hoạt) hoặc Đơn giá đồng giá (kinh doanh).
    var usageSelect = document.querySelector('[data-usage-select]');
    var utilitySelect = document.querySelector('[data-utility-select]');
    function syncUsage() {
      if (!usageSelect) { return; }
      Array.prototype.forEach.call(document.querySelectorAll('[data-usage-section]'), function (section) {
        section.hidden = section.getAttribute('data-usage-section') !== usageSelect.value;
      });
    }
    function syncUnit() {
      if (!utilitySelect) { return; }
      var option = utilitySelect.options[utilitySelect.selectedIndex];
      Array.prototype.forEach.call(document.querySelectorAll('[data-unit-label]'), function (label) {
        label.textContent = option ? option.getAttribute('data-unit') : '';
      });
    }
    if (usageSelect) { usageSelect.addEventListener('change', syncUsage); syncUsage(); }
    if (utilitySelect) { utilitySelect.addEventListener('change', syncUnit); }

    // STEP 8: xem trước CL = CSC − CSĐ (chỉ hiển thị; server tự tính lại khi lưu - BR-12).
    Array.prototype.forEach.call(document.querySelectorAll('[data-current-reading]'), function (input) {
      var preview = input.form ? input.form.querySelector('[data-consumption-preview]') : null;
      function toCents(v) {
        v = String(v || '').replace(/\s/g, '');
        if (v.indexOf('.') === -1 && (v.match(/,/g) || []).length === 1) { v = v.replace(',', '.'); }
        return /^\d+(\.\d{1,2})?$/.test(v) ? Math.round(parseFloat(v) * 100) : null;
      }
      function update() {
        if (!preview) { return; }
        var cur = toCents(input.value), prev = toCents(input.getAttribute('data-previous'));
        if (cur === null || prev === null) { preview.value = ''; return; }
        var diff = cur - prev;
        preview.value = diff < 0 ? App.tr('js.csc_invalid', 'CSC < CSĐ — không hợp lệ') : (diff / 100).toLocaleString('vi-VN');
      }
      input.addEventListener('input', update);
      update();
    });

    // STEP 8: chọn tất cả chỉ số ở màn hình duyệt.
    Array.prototype.forEach.call(document.querySelectorAll('[data-check-all]'), function (all) {
      all.addEventListener('change', function () {
        Array.prototype.forEach.call(all.closest('form').querySelectorAll('[data-check-item]'), function (box) { box.checked = all.checked; });
      });
    });
  });

  // Chống double-submit khi bấm nút Đăng nhập / các form POST quan trọng
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (modal && form.classList && form.classList.contains('js-confirm') && form.dataset.confirmed !== '1') {
      event.preventDefault();
      pendingForm = form;
      document.getElementById('confirmModalTitle').textContent = form.getAttribute('data-confirm-title') || App.tr('common.confirm', 'Xác nhận');
      document.getElementById('confirmModalMessage').textContent = form.getAttribute('data-confirm-message') || App.tr('js.are_you_sure', 'Bạn chắc chắn?');
      document.getElementById('confirmModalOk').textContent = form.getAttribute('data-confirm-button') || App.tr('common.confirm', 'Xác nhận');
      modal.hidden = false;
      document.getElementById('confirmModalOk').focus();
      return;
    }
    var submitBtn = (event.submitter && event.submitter.tagName === 'BUTTON') ? event.submitter : form.querySelector('button[type="submit"]');
    if (submitBtn && !submitBtn.disabled) {
      window.setTimeout(function () {
        submitBtn.disabled = true;
        submitBtn.dataset.originalText = submitBtn.textContent;
        submitBtn.textContent = App.tr('js.processing', 'Đang xử lý...');
      }, 0);
    }
  });
})();

// UX - màn "Thêm công tơ": tự gợi ý Mã công tơ + Nhãn hiển thị theo căn hộ + loại tiện ích. Chỉ là gợi ý:
// người dùng sửa được; backend vẫn là nguồn kiểm tra (bắt buộc, mã duy nhất, loại tiện ích, chỉ số >= 0, căn hoạt động).
App.register(function () {
  'use strict';

  var MeterSuggest = {
    // Convention mã của dự án (seed): mã căn bỏ dấu "-" + "-DIEN" / "-NUOC" (CC-2305 -> CC2305-DIEN). Mã đã tồn tại -> thêm "-2", "-3"...
    code: function (aptCode, utility, existing) {
      if (!aptCode || (utility !== 'dien' && utility !== 'nuoc')) { return ''; }
      var suffix = utility === 'dien' ? '-DIEN' : '-NUOC';
      var base = String(aptCode).replace(/-/g, '').slice(0, 30 - suffix.length);
      var taken = {};
      (existing || []).forEach(function (c) { taken[String(c).toLowerCase()] = true; });
      var candidate = base + suffix;
      for (var n = 2; taken[candidate.toLowerCase()] && n < 100; n++) {
        var extra = '-' + n;
        candidate = base.slice(0, 30 - suffix.length - extra.length) + suffix + extra;
      }
      return candidate;
    },
    label: function (aptCode, utility) {
      if (!aptCode) { return ''; }
      return utility === 'dien' ? 'Điện căn ' + aptCode : (utility === 'nuoc' ? 'Nước căn ' + aptCode : '');
    }
  };
  window.MeterSuggest = MeterSuggest;

  var form = document.querySelector('form[data-meter-suggest]');
  if (!form) { return; }
  var apartment = form.querySelector('#apartment_id');
  var utility = form.querySelector('#utility_type');
  var code = form.querySelector('#meter_code');
  var label = form.querySelector('#display_label');
  if (!apartment || !utility || !code || !label) { return; }
  var existing = [];
  try { existing = JSON.parse(form.getAttribute('data-existing-codes') || '[]'); } catch (e) { existing = []; }

  function apartmentCode() {
    var option = apartment.options[apartment.selectedIndex];
    return option ? (option.getAttribute('data-code') || '') : '';
  }
  function suggestion() {
    return { code: MeterSuggest.code(apartmentCode(), utility.value, existing), label: MeterSuggest.label(apartmentCode(), utility.value) };
  }
  // "Tự động" khi ô trống hoặc đang đúng bằng gợi ý (vd quay lại sau lỗi validate); người dùng gõ tay -> không ghi đè nữa
  var initial = suggestion();
  code.setAttribute('data-auto', code.value === '' || code.value === initial.code ? '1' : '0');
  label.setAttribute('data-auto', label.value === '' || label.value === initial.label ? '1' : '0');
  [code, label].forEach(function (field) {
    field.addEventListener('input', function () { field.setAttribute('data-auto', field.value === '' ? '1' : '0'); });
  });
  function apply() {
    var s = suggestion();
    if (code.getAttribute('data-auto') === '1') { code.value = s.code; }
    if (label.getAttribute('data-auto') === '1') { label.value = s.label; }
  }
  apartment.addEventListener('change', apply);
  utility.addEventListener('change', apply);
  apply();
});

// UX - màn "Thêm tòa nhà": tự gợi ý Mã tòa nhà từ Tên. Chỉ là gợi ý: người dùng sửa được;
// backend vẫn kiểm tra (bắt buộc, định dạng, tối đa 30 ký tự, mã duy nhất).
App.register(function () {
  'use strict';

  var BuildingSuggest = {
    // Chuẩn hoá: bỏ dấu tiếng Việt (đ -> d), in hoa, khoảng trắng -> "-", bỏ ký tự ngoài A-Z 0-9 -, gộp "-" liên tiếp, cắt "-" hai đầu, tối đa 30 ký tự
    code: function (name) {
      var s = String(name || '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[đĐ]/g, 'd').toUpperCase();
      s = s.replace(/\s+/g, '-').replace(/[^A-Z0-9-]/g, '').replace(/-+/g, '-').replace(/^-+|-+$/g, '');
      return s.slice(0, 30).replace(/-+$/, '');
    }
  };
  window.BuildingSuggest = BuildingSuggest;

  var form = document.querySelector('form[data-building-suggest]');
  if (!form) { return; }
  var name = form.querySelector('#name');
  var code = form.querySelector('#code');
  if (!name || !code) { return; }
  // "Tự động" khi ô mã trống hoặc đang đúng bằng gợi ý (vd quay lại sau lỗi validate); người dùng gõ tay -> không ghi đè nữa
  code.setAttribute('data-auto', code.value === '' || code.value === BuildingSuggest.code(name.value) ? '1' : '0');
  code.addEventListener('input', function () { code.setAttribute('data-auto', code.value === '' ? '1' : '0'); });
  name.addEventListener('input', function () {
    if (code.getAttribute('data-auto') === '1') { code.value = BuildingSuggest.code(name.value); }
  });
});

// UX - màn "Thêm khu vực": tự gợi ý Mã khu vực = <MÃ TÒA NHÀ>-<TÊN KHU CHUẨN HOÁ> (cùng quy tắc chuẩn hoá với mã tòa nhà).
// Loại khu không tham gia sinh mã. Không tự thêm -2/-3 khi trùng: backend báo "Mã khu vực đã tồn tại trong tòa nhà này."
App.register(function () {
  'use strict';

  var ZoneSuggest = {
    code: function (buildingCode, zoneName) {
      var part = window.BuildingSuggest ? window.BuildingSuggest.code(zoneName) : '';
      if (!buildingCode || !part) { return ''; }
      return (String(buildingCode) + '-' + part).slice(0, 30).replace(/-+$/, '');
    }
  };
  window.ZoneSuggest = ZoneSuggest;

  var form = document.querySelector('form[data-zone-suggest]');
  if (!form) { return; }
  var building = form.querySelector('#building_id');
  var name = form.querySelector('#name');
  var code = form.querySelector('#code');
  if (!building || !name || !code) { return; }

  function suggestion() {
    var option = building.options[building.selectedIndex];
    return ZoneSuggest.code(option ? (option.getAttribute('data-code') || '') : '', name.value);
  }
  // "Tự động" khi ô mã trống hoặc đang đúng bằng gợi ý; người dùng gõ tay -> không ghi đè; xoá trống -> tự động lại
  code.setAttribute('data-auto', code.value === '' || code.value === suggestion() ? '1' : '0');
  code.addEventListener('input', function () { code.setAttribute('data-auto', code.value === '' ? '1' : '0'); });
  function apply() {
    if (code.getAttribute('data-auto') === '1') { code.value = suggestion(); }
  }
  name.addEventListener('input', apply);
  building.addEventListener('change', apply);
});

// UX FN-04.02 - màn Nhập chỉ số dạng worklist: lọc/tìm tại chỗ, chọn công tơ không tải lại trang (panel phải cập nhật từ dữ liệu
// server đã render trong #wl-data). Chỉ là hiển thị: lưu vẫn POST lên server (CSRF, RBAC, CSĐ/CL/trạng thái do backend quyết định).
App.register(function () {
  'use strict';

  var dataEl = document.getElementById('wl-data');
  var panel = document.getElementById('wl-panel');
  if (!dataEl || !panel) { return; }
  var items = {};
  try { JSON.parse(dataEl.textContent || '[]').forEach(function (it) { items[it.id] = it; }); } catch (e) { return; }

  var rows = Array.prototype.slice.call(document.querySelectorAll('[data-wl-row]'));
  var filterForm = document.querySelector('[data-wl-filters]');
  var q = document.getElementById('wl_q'), status = document.getElementById('wl_status'), utility = document.getElementById('wl_utility');
  var form = panel.querySelector('[data-wl-form]');
  var csc = form ? form.querySelector('#current_reading') : null;
  var clientError = document.getElementById('csc_client_error');
  var badgeClass = { chua_ghi: 'badge--danger', nhap: 'badge--warning', da_xac_nhan: 'badge--active' };
  var currentId = 0;
  rows.forEach(function (r) { if (r.classList.contains('is-selected')) { currentId = parseInt(r.getAttribute('data-wl-row'), 10); } });

  function section(name) { return panel.querySelector('[data-wl-section="' + name + '"]'); }
  function show(el, visible) { if (el) { el.hidden = !visible; } }
  function setField(name, value) {
    Array.prototype.forEach.call(panel.querySelectorAll('[data-wl-field="' + name + '"]'), function (el) { el.textContent = value; });
  }
  function toCents(v) {
    v = String(v || '').replace(/\s/g, '');
    if (v.indexOf('.') === -1 && (v.match(/,/g) || []).length === 1) { v = v.replace(',', '.'); }
    return /^\d+(\.\d{1,2})?$/.test(v) ? Math.round(parseFloat(v) * 100) : null;
  }
  function filterValues() { return { q: q ? q.value.trim() : '', status: status ? status.value : '', utility: utility ? utility.value : '' }; }
  function syncUrl() {
    if (!window.history || !window.history.replaceState) { return; }
    var f = filterValues(), params = new URLSearchParams();
    if (currentId) { params.set('meter_id', String(currentId)); }
    ['q', 'status', 'utility'].forEach(function (k) { if (f[k]) { params.set(k, f[k]); } });
    var qs = params.toString();
    try { window.history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : '')); } catch (e) { /* file:// hoặc sandbox */ }
  }

  // ---------------------------------------------------------------- lọc / tìm
  function applyFilters() {
    var f = filterValues(), term = f.q.toLowerCase(), visible = 0;
    rows.forEach(function (r) {
      var ok = (!f.status || r.getAttribute('data-state') === f.status) && (!f.utility || r.getAttribute('data-utility') === f.utility)
        && (!term || (r.getAttribute('data-search') || '').indexOf(term) !== -1);
      r.hidden = !ok;
      visible += ok ? 1 : 0;
    });
    var counter = document.querySelector('[data-wl-visible]');
    if (counter) { counter.textContent = App.tr('worklist.visible', 'Hiển thị :visible / :total công tơ', { visible: visible, total: rows.length }); }
    show(document.querySelector('[data-wl-empty]'), visible === 0);
    if (form) {
      ['q', 'status', 'utility'].forEach(function (k) { var h = form.querySelector('[data-wl-keep="' + k + '"]'); if (h) { h.value = f[k]; } });
    }
    syncUrl();
  }
  if (filterForm) {
    filterForm.addEventListener('submit', function (e) { e.preventDefault(); applyFilters(); });
    if (q) { q.addEventListener('input', applyFilters); }
    [status, utility].forEach(function (s) { if (s) { s.addEventListener('change', applyFilters); } });
  }

  // ---------------------------------------------------------------- chọn công tơ
  function clearServerErrors() {
    Array.prototype.forEach.call(panel.querySelectorAll('[data-wl-server-error], .form-summary'), function (el) { el.parentNode.removeChild(el); });
    Array.prototype.forEach.call(panel.querySelectorAll('.has-error'), function (el) { el.classList.remove('has-error'); });
    Array.prototype.forEach.call(panel.querySelectorAll('.form-group .form-error:not(#csc_client_error)'), function (el) { el.parentNode.removeChild(el); });
    if (csc) { csc.removeAttribute('aria-invalid'); csc.setAttribute('aria-describedby', 'csc_hint csc_client_error'); }
  }
  function setClientError(msg) {
    if (!clientError || !csc) { return; }
    clientError.textContent = msg || '';
    clientError.hidden = !msg;
    if (msg) { csc.setAttribute('aria-invalid', 'true'); csc.closest('.form-group').classList.add('has-error'); }
    else if (!panel.querySelector('[data-wl-server-error]')) { csc.removeAttribute('aria-invalid'); csc.closest('.form-group').classList.remove('has-error'); }
  }
  function validateCsc(onSubmit) {
    if (!csc) { return true; }
    var raw = csc.value.trim();
    if (raw === '') { setClientError(onSubmit ? App.tr('js.csc_required', 'Vui lòng nhập CSC (chỉ số cuối).') : ''); return !onSubmit; }
    var cur = toCents(raw), prev = toCents(csc.getAttribute('data-previous'));
    if (cur !== null && prev !== null && cur < prev) {
      setClientError(App.tr('js.csc_lt_csd', 'CSC nhỏ hơn CSĐ (:prev). Kiểm tra lại số đọc — hệ thống không lưu CSC < CSĐ.', { prev: (prev / 100).toLocaleString('vi-VN') }));
      return false;
    }
    setClientError('');
    return true;
  }

  function select(id, focus) {
    var it = items[id];
    if (!it) { return; }
    var changed = id !== currentId;
    currentId = id;
    rows.forEach(function (r) {
      var on = parseInt(r.getAttribute('data-wl-row'), 10) === id;
      r.classList.toggle('is-selected', on);
      var link = r.querySelector('.wl-row__link');
      if (link) { if (on) { link.setAttribute('aria-current', 'true'); } else { link.removeAttribute('aria-current'); } }
    });
    ['apartment', 'code', 'utilityLabel', 'usageLabel', 'csd', 'unit', 'csdSource', 'blocked', 'csc', 'cl'].forEach(function (k) { setField(k, it[k] == null ? '' : String(it[k])); });
    setField('label', it.label ? it.label : '—');
    var badge = panel.querySelector('[data-wl-field="stateBadge"]');
    if (badge) { badge.className = 'badge ' + (badgeClass[it.state] || ''); badge.hidden = false; }
    setField('stateBadge', it.stateLabel);
    setField('savedMsg', it.state === 'da_xac_nhan' ? App.tr('worklist.saved_approved', 'Chỉ số đã được BQL duyệt.') : App.tr('worklist.saved_draft', 'Công tơ này đã có chỉ số Nháp trong kỳ — đang chờ BQL duyệt (mỗi công tơ chỉ có một chỉ số trong một kỳ).'));
    var detail = panel.querySelector('[data-wl-link="detail"]'), edit = panel.querySelector('[data-wl-link="edit"]');
    if (it.readingId) {
      if (detail) { detail.href = detail.href.replace(/\/readings\/\d+$/, '/readings/' + it.readingId); }
      if (edit) { edit.href = edit.href.replace(/\/readings\/\d+\/edit$/, '/readings/' + it.readingId + '/edit'); }
    }
    show(edit, it.state === 'nhap');

    var locked = section('locked');
    var canEnter = !!form && it.state === 'chua_ghi' && !it.blocked;
    show(section('none'), false);
    show(section('info'), true);
    show(locked, true);
    show(section('blocked'), !locked && it.state === 'chua_ghi' && !!it.blocked);
    show(section('saved'), it.state !== 'chua_ghi');
    show(section('entry'), canEnter);

    if (form && changed) {
      clearServerErrors();
      form.querySelector('[data-wl-input="meter_id"]').value = String(id);
      form.querySelector('[data-wl-input="csd"]').value = it.csdInput;
      csc.setAttribute('data-previous', it.csdInput);
      csc.value = '';
      var note = form.querySelector('#note');
      if (note) { note.value = ''; }
      setClientError('');
      csc.dispatchEvent(new Event('input', { bubbles: true })); // cập nhật CL xem trước
    }
    var hiddenMeter = filterForm ? filterForm.querySelector('input[name="meter_id"]') : null;
    if (hiddenMeter) { hiddenMeter.value = String(id); }
    syncUrl();

    if (focus) {
      var stacked = window.matchMedia && window.matchMedia('(max-width: 1280px)').matches;
      if (canEnter) { csc.focus({ preventScroll: !stacked }); } else { panel.focus({ preventScroll: !stacked }); }
      if (stacked && panel.scrollIntoView) { panel.scrollIntoView({ block: 'start', behavior: 'smooth' }); }
    }
  }

  rows.forEach(function (r) {
    var id = parseInt(r.getAttribute('data-wl-row'), 10);
    r.addEventListener('click', function (e) {
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) { return; } // mở tab mới: để trình duyệt xử lý link
      e.preventDefault();
      select(id, true);
    });
  });

  if (csc) {
    csc.addEventListener('input', function () { validateCsc(false); });
    form.addEventListener('submit', function (e) {
      if (!validateCsc(true)) { e.preventDefault(); e.stopImmediatePropagation(); csc.focus(); }
    }, true);
    if (!csc.value && section('entry') && !section('entry').hidden && currentId) { csc.focus({ preventScroll: true }); }
  }
  window.MeterWorklist = { select: select, applyFilters: applyFilters };
});

// UX - THAO TÁC "LIVE": form POST trong vùng nội dung gửi bằng fetch (header X-Live-Request), không tải lại cả trang.
// Server xử lý y hệt (auth, RBAC, CSRF, validate) rồi trả {redirect}; trình duyệt tải trang đích, chỉ thay <main class="app-content">
// + badge sidebar + tiêu đề, chuyển thông báo flash thành toast. HTML do server render vẫn là nguồn sự thật (trạng thái, badge,
// timeline, nút, cảnh báo cập nhật cùng lúc). Không có fetch / lỗi mạng / trang khác bố cục -> rơi về tải trang bình thường.
// Bỏ qua: form có data-no-live, form ngoài vùng nội dung (đăng nhập / đăng xuất).
(function () {
  'use strict';

  var stack = document.getElementById('toastStack');
  var main = document.querySelector('main.app-content');
  if (!window.fetch || !window.DOMParser || !window.FormData || !main) { return; }

  var TITLES = { success: App.tr('js.toast_success', 'Thành công'), error: App.tr('js.toast_error', 'Thất bại'), info: App.tr('js.toast_warning', 'Cảnh báo'), warning: App.tr('js.toast_warning', 'Cảnh báo') };
  function toast(type, message) {
    if (!stack || !message) { return; }
    var el = document.createElement('div');
    el.className = 'toast toast--' + (type === 'info' ? 'warning' : type);
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    var title = document.createElement('strong');
    title.textContent = TITLES[type] || App.tr('js.toast_default', 'Thông báo');
    var body = document.createElement('span');
    body.textContent = message;
    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'toast__close';
    close.setAttribute('aria-label', App.tr('js.toast_close', 'Đóng thông báo'));
    close.textContent = '×';
    close.addEventListener('click', function () { el.remove(); });
    el.appendChild(title);
    el.appendChild(body);
    el.appendChild(close);
    stack.appendChild(el);
    window.setTimeout(function () { el.classList.add('is-leaving'); window.setTimeout(function () { el.remove(); }, 300); }, type === 'error' ? 9000 : 5000);
  }
  window.App.toast = toast;

  function eligible(form) {
    return form && (form.getAttribute('method') || 'get').toLowerCase() === 'post' && !form.hasAttribute('data-no-live')
      && main.contains(form);
  }

  function restore(form) {
    Array.prototype.forEach.call(form.querySelectorAll('button[type="submit"]'), function (b) {
      if (b.dataset.originalText) { b.textContent = b.dataset.originalText; }
      b.disabled = false;
    });
  }

  function syncBadges(doc) {
    Array.prototype.forEach.call(document.querySelectorAll('[data-issue-count]'), function (badge) {
      var fresh = doc.querySelector('[data-issue-count="' + badge.getAttribute('data-issue-count') + '"]');
      if (fresh) {
        badge.innerHTML = fresh.innerHTML;
        badge.hidden = fresh.hidden;
        badge.title = fresh.title;
      }
    });
  }

  function swap(html, url, samePage) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    var fresh = doc.querySelector('main.app-content');
    if (!fresh) { window.location.assign(url); return; } // vd hết phiên -> trang đăng nhập: tải bình thường
    var flashes = [];
    Array.prototype.forEach.call(fresh.querySelectorAll('[data-flash]'), function (f) {
      flashes.push([f.getAttribute('data-flash'), f.textContent.trim()]);
      f.parentNode.removeChild(f);
    });
    var scroll = window.pageYOffset;
    main.innerHTML = fresh.innerHTML;
    syncBadges(doc);
    window.App.navGroupSums();
    if (doc.title) { document.title = doc.title; }
    try {
      if (url !== window.location.href) { window.history.pushState({ live: true }, '', url); }
    } catch (e) { /* bỏ qua */ }
    window.App.runInits();
    window.scrollTo(0, samePage ? scroll : 0);
    if (!samePage) { main.setAttribute('tabindex', '-1'); main.focus({ preventScroll: true }); }
    var openModal = main.querySelector('.modal:not([hidden])'); // lỗi validate -> modal mở lại kèm lỗi
    if (openModal) {
      var field = openModal.querySelector('.has-error textarea, .has-error input, .has-error select, textarea, input:not([type="hidden"]), select');
      if (field) { field.focus(); }
    }
    flashes.forEach(function (f) { toast(f[0], f[1]); });
    document.dispatchEvent(new CustomEvent('app:live-updated', { detail: { url: url, flashes: flashes } }));
  }

  function load(url, samePage) {
    return fetch(url, { credentials: 'same-origin', headers: { 'X-Live-Request': '1' } })
      .then(function (res) { return res.text().then(function (html) { swap(html, res.url || url, samePage); }); });
  }

  function message(status) {
    if (status === 403) { return App.tr('js.err_403', 'Không có quyền thực hiện hoặc phiên làm việc đã hết hạn (403). Vui lòng tải lại trang.'); }
    if (status === 404) { return App.tr('js.err_404', 'Không tìm thấy dữ liệu (404) — có thể đã bị thay đổi.'); }
    return App.tr('js.err_server', 'Máy chủ gặp lỗi (:status). Dữ liệu chưa được lưu — vui lòng thử lại.', { status: status });
  }

  function submit(form, submitter) {
    var data = new FormData(form);
    if (submitter && submitter.name) { data.append(submitter.name, submitter.value); }
    var before = window.location.href;
    fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Live-Request': '1', 'Accept': 'application/json' } })
      .then(function (res) {
        var type = res.headers.get('Content-Type') || '';
        if (type.indexOf('application/json') !== -1) {
          return res.json().then(function (json) {
            if (!json || !json.redirect) { throw new Error('bad response'); }
            var target = new URL(json.redirect, window.location.href);
            return load(json.redirect, target.pathname === new URL(before).pathname);
          });
        }
        return res.text().then(function (html) {
          if (res.ok) { swap(html, res.url || form.action, false); return; } // action trả thẳng trang (không redirect)
          restore(form);
          toast('error', message(res.status));
        });
      })
      .catch(function () {
        restore(form);
        toast('error', App.tr('js.err_network', 'Không kết nối được máy chủ. Dữ liệu chưa được lưu — vui lòng thử lại.'));
      });
  }

  // Bắt submit SAU bộ xử lý js-confirm / chống double-submit (đăng ký trước trên document): chỉ khi chưa bị chặn
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (event.defaultPrevented || !eligible(form)) { return; }
    event.preventDefault();
    var modal = form.closest('.modal');
    if (modal && modal.id !== 'confirmModal') { modal.hidden = true; }
    submit(form, event.submitter || null);
  });

  // Back / Forward sau khi đã đổi URL bằng pushState: tải lại trang đích (đơn giản, luôn đúng dữ liệu)
  try { window.history.replaceState({ live: true }, '', window.location.href); } catch (e) { /* bỏ qua */ }
  window.addEventListener('popstate', function (event) {
    if (event.state && event.state.live) { window.location.reload(); }
  });
})();

// [BA Proposal J-16] Kết luận BQL: "Ngày theo dõi lại" CHỈ hiện + bắt buộc với D (Chưa xác định); A/B/C ẩn, không bắt buộc, xoá giá trị cũ.
// Backend kiểm tra tương tự (D bắt buộc ngày; A/B/C lưu NULL).
App.register(function () {
  'use strict';
  Array.prototype.forEach.call(document.querySelectorAll('[data-conclusion-select]'), function (select) {
    var form = select.form;
    var group = form ? form.querySelector('[data-followup-field]') : null;
    var input = group ? group.querySelector('input[type="date"]') : null;
    if (!group || !input) { return; }
    function sync() {
      var isD = select.value === 'undetermined';
      group.hidden = !isD;
      input.required = isD;
      input.setAttribute('aria-required', isD ? 'true' : 'false');
      if (!isD) { input.value = ''; }
    }
    select.addEventListener('change', sync);
    sync();
  });
});

// Trang đăng nhập (chỉ giao diện): hiện / ẩn mật khẩu, trạng thái đang xử lý, báo lỗi ngay trong thẻ KHÔNG tải lại trang.
// Gửi POST /login y như form thường (CSRF, khoá tạm, session do server xử lý); server trả {redirect} (Request::isLive):
//   về /login = thất bại -> đọc thông báo nghiệp vụ của server từ trang /login (flash) + CSRF mới, hiển thị trong thẻ;
//   nơi khác = thành công -> chuyển trang. Không có fetch -> form gửi bình thường. Không hiển thị lỗi kỹ thuật.
(function () {
  'use strict';
  var form = document.querySelector('[data-login-form]');
  if (!form) { return; }
  var pwd = form.querySelector('#password');
  var user = form.querySelector('#username');
  var toggle = form.querySelector('[data-toggle-password]');
  var alertBox = document.querySelector('[data-login-alert]');
  var alertText = alertBox ? alertBox.querySelector('[data-login-alert-text]') : null;
  var submit = form.querySelector('[data-login-submit]');
  var submitText = form.querySelector('[data-login-submit-text]');

  if (toggle && pwd) {
    toggle.hidden = false;
    toggle.addEventListener('click', function () {
      var show = pwd.type === 'password';
      pwd.type = show ? 'text' : 'password';
      toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
      toggle.setAttribute('aria-label', show ? App.tr('js.hide_password', 'Ẩn mật khẩu') : App.tr('js.show_password', 'Hiện mật khẩu'));
      pwd.focus();
    });
  }

  function showError(message) {
    if (!alertBox || !alertText) { return; }
    alertText.textContent = message;
    alertBox.hidden = false;
    alertBox.classList.remove('is-shake');
    void alertBox.offsetWidth; // chạy lại hiệu ứng rung
    alertBox.classList.add('is-shake');
  }
  function loading(on) {
    submit.disabled = on;
    submit.classList.toggle('is-loading', on);
    submit.setAttribute('aria-busy', on ? 'true' : 'false');
    submitText.textContent = on ? App.tr('js.logging_in', 'Đang đăng nhập...') : App.tr('js.login', 'Đăng nhập');
  }
  function failed(message) {
    loading(false);
    showError(message);
    if (pwd) { pwd.value = ''; pwd.focus(); }
  }

  form.addEventListener('submit', function (event) {
    // chặn bộ chống double-submit chung (nó thay chữ nút) - trang này tự quản lý trạng thái nút
    event.stopImmediatePropagation();
    if (!window.fetch || !window.DOMParser || !window.FormData) { loading(true); return; }
    event.preventDefault();
    if (submit.disabled) { return; }
    if (user.value.trim() === '' || pwd.value === '') {
      showError(App.tr('js.login_required', 'Vui lòng nhập tên đăng nhập và mật khẩu.'));
      (user.value.trim() === '' ? user : pwd).focus();
      return;
    }
    loading(true);
    fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'X-Live-Request': '1', 'Accept': 'application/json' } })
      .then(function (res) {
        if ((res.headers.get('Content-Type') || '').indexOf('application/json') === -1) {
          throw new Error(res.status === 403 ? 'expired' : 'server');
        }
        return res.json();
      })
      .then(function (json) {
        var target = new URL(json.redirect, window.location.href);
        if (!/\/login\/?$/.test(target.pathname)) {
          window.location.assign(json.redirect); // thành công: giữ trạng thái đang xử lý tới khi chuyển trang
          return null;
        }
        return fetch(json.redirect, { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (html) {
          var doc = new DOMParser().parseFromString(html, 'text/html');
          var token = doc.querySelector('input[name="csrf_token"]');
          var mine = form.querySelector('input[name="csrf_token"]');
          if (token && mine) { mine.value = token.value; }
          var msg = doc.querySelector('[data-login-alert-text]');
          failed(msg && msg.textContent.trim() !== '' ? msg.textContent.trim() : App.tr('js.login_failed', 'Đăng nhập không thành công. Vui lòng thử lại.'));
        });
      })
      .catch(function (e) {
        failed(e && e.message === 'expired' ? App.tr('js.session_expired', 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang rồi đăng nhập.') : App.tr('js.network_login', 'Không kết nối được máy chủ. Vui lòng thử lại.'));
      });
  }, true);
})();

// Chi tiết kỳ ghi chỉ số: "Xem tất cả N công tơ" - hiện / thu gọn các dòng sau N dòng đầu, không tải lại trang
// (khi không có JS, nút là link tới màn Tra cứu chỉ số kỳ). Chỉ thay đổi hiển thị.
App.register(function () {
  Array.prototype.forEach.call(document.querySelectorAll('[data-meter-list-toggle]'), function (btn) {
    if (btn.hasAttribute('data-bound')) { return; }
    btn.setAttribute('data-bound', '1');
    btn.setAttribute('role', 'button');
    btn.addEventListener('click', function (event) {
      event.preventDefault();
      var panel = btn.closest('[data-meter-list]');
      var preview = parseInt(panel.getAttribute('data-meter-list-preview'), 10) || 5;
      var expand = btn.getAttribute('aria-expanded') !== 'true';
      var rows = panel.querySelectorAll('[data-meter-row]');
      Array.prototype.forEach.call(rows, function (row, i) { if (i >= preview) { row.hidden = !expand; } });
      btn.setAttribute('aria-expanded', expand ? 'true' : 'false');
      btn.textContent = btn.getAttribute(expand ? 'data-label-less' : 'data-label-more');
      var shown = panel.querySelector('[data-meter-list-shown]');
      if (shown) { shown.textContent = String(expand ? rows.length : Math.min(preview, rows.length)); }
    });
  });
});


// Cỡ chữ A / A+ / A++ (lưu theo từng máy; html[data-text] do <head> áp dụng trước khi vẽ để không bị nhấp nháy).
(function () {
  'use strict';
  var root = document.documentElement;
  function current() { var s = root.getAttribute('data-text'); return s === 'lg' || s === 'xl' ? s : 'sm'; }
  function paint() {
    var cur = current();
    Array.prototype.forEach.call(document.querySelectorAll('.text-size button'), function (b) {
      b.setAttribute('aria-pressed', b.getAttribute('data-size') === cur ? 'true' : 'false');
    });
  }
  document.addEventListener('click', function (event) {
    var btn = event.target.closest ? event.target.closest('.text-size button') : null;
    if (!btn) { return; }
    var size = btn.getAttribute('data-size');
    if (size === 'sm') { root.removeAttribute('data-text'); } else { root.setAttribute('data-text', size); }
    try { localStorage.setItem('dssTextSize', size); } catch (e) { /* trình duyệt chặn lưu: vẫn đổi được trong phiên */ }
    paint();
  });
  paint();
})();
