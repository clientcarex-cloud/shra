/* SHRA — small progressive enhancements, no framework. */
(function () {
  'use strict';

  // Sidebar drawer (mobile)
  var burger = document.getElementById('burger'),
      side   = document.getElementById('sidebar'),
      scrim  = document.getElementById('scrim');
  function closeNav() { side && side.classList.remove('open'); scrim && scrim.classList.remove('on'); }
  if (burger) burger.addEventListener('click', function () {
    side.classList.toggle('open');
    scrim.classList.toggle('on', side.classList.contains('open'));
  });
  if (scrim) scrim.addEventListener('click', closeNav);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeNav(); });

  // Confirm destructive actions
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-confirm]');
    if (el && !window.confirm(el.getAttribute('data-confirm'))) { e.preventDefault(); e.stopPropagation(); }
  });

  // Block double submits
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.dataset.noguard !== undefined) return;
    if (f.dataset.sent) { e.preventDefault(); return; }
    f.dataset.sent = '1';
    setTimeout(function () {
      var b = f.querySelector('button[type=submit],button:not([type])');
      if (b) { b.disabled = true; b.dataset.old = b.innerHTML; b.innerHTML = 'Please wait…'; }
    }, 0);
    setTimeout(function () {            // re-enable if the page never navigates
      delete f.dataset.sent;
      var b = f.querySelector('button[disabled][data-old]');
      if (b) { b.disabled = false; b.innerHTML = b.dataset.old; }
    }, 12000);
  });

  // Auto-submit filter forms on change
  document.querySelectorAll('[data-autosubmit] select, [data-autosubmit] input[type=date]').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
  });

  // Live invoice line-item maths
  function recalcInvoice() {
    var rows = document.querySelectorAll('#items tbody tr'), sub = 0;
    rows.forEach(function (tr) {
      var qty  = parseFloat(tr.querySelector('.i-qty')  ? tr.querySelector('.i-qty').value  : 0) || 0,
          rate = parseFloat(tr.querySelector('.i-rate') ? tr.querySelector('.i-rate').value : 0) || 0,
          amt  = Math.round(qty * rate * 100) / 100;
      var cell = tr.querySelector('.i-amt');
      if (cell) cell.textContent = amt.toFixed(2);
      sub += amt;
    });
    var disc = parseFloat((document.getElementById('f-discount') || {}).value) || 0,
        taxp = parseFloat((document.getElementById('f-taxpct')   || {}).value) || 0;
    var taxable = Math.max(0, sub - disc),
        tax     = Math.round(taxable * taxp) / 100,
        total   = Math.round((taxable + tax) * 100) / 100;
    var set = function (id, v) { var n = document.getElementById(id); if (n) n.textContent = v.toFixed(2); };
    set('t-sub', sub); set('t-tax', tax); set('t-total', total);
    var h = document.getElementById('f-total'); if (h) h.value = total.toFixed(2);
  }
  if (document.getElementById('items')) {
    document.addEventListener('input', function (e) {
      if (e.target.closest('#items') || ['f-discount', 'f-taxpct'].indexOf(e.target.id) > -1) recalcInvoice();
    });
    // Add / remove item rows
    var addBtn = document.getElementById('add-item');
    if (addBtn) addBtn.addEventListener('click', function () {
      var tb  = document.querySelector('#items tbody'),
          tpl = document.getElementById('item-tpl').innerHTML.replace(/__i__/g, Date.now() % 100000);
      tb.insertAdjacentHTML('beforeend', tpl);
      recalcInvoice();
    });
    document.addEventListener('click', function (e) {
      if (e.target.closest('.i-del')) {
        var tr = e.target.closest('tr'), tb = tr.parentNode;
        if (tb.rows.length > 1) tr.remove(); else tr.querySelectorAll('input').forEach(function (i) { i.value = ''; });
        recalcInvoice();
      }
    });
    // Plan picker fills description + rate
    document.addEventListener('change', function (e) {
      if (!e.target.classList.contains('i-plan')) return;
      var o = e.target.selectedOptions[0], tr = e.target.closest('tr');
      if (o && o.dataset.rate) {
        tr.querySelector('.i-desc').value = o.dataset.name;
        tr.querySelector('.i-rate').value = o.dataset.rate;
        tr.querySelector('.i-qty').value  = 1;
        recalcInvoice();
      }
    });
    recalcInvoice();
  }

  // Customer type-ahead (billing / attendance pickers)
  document.querySelectorAll('[data-cust-search]').forEach(function (input) {
    var box = document.createElement('div');
    box.className = 'card';
    box.style.cssText = 'position:absolute;z-index:40;max-height:260px;overflow:auto;width:100%;margin:2px 0 0;display:none';
    input.parentNode.style.position = 'relative';
    input.parentNode.appendChild(box);
    var timer, target = document.getElementById(input.dataset.custSearch);
    input.addEventListener('input', function () {
      clearTimeout(timer);
      var v = input.value.trim();
      if (target) target.value = '';
      if (v.length < 2) { box.style.display = 'none'; return; }
      timer = setTimeout(function () {
        fetch(((window.SHRA && window.SHRA.search) || 'api_search.php') + '?q=' + encodeURIComponent(v))
          .then(function (r) { return r.json(); })
          .then(function (rows) {
            if (!rows.length) { box.innerHTML = '<div class="list-item muted">No match</div>'; }
            else box.innerHTML = rows.map(function (c) {
              return '<a class="list-item" href="#" data-id="' + c.id + '" data-label="' + c.label + '">' +
                     '<div class="g"><b>' + c.name + '</b><span>' + c.code + ' &middot; ' + c.phone + '</span></div></a>';
            }).join('');
            box.style.display = 'block';
          });
      }, 220);
    });
    box.addEventListener('click', function (e) {
      var a = e.target.closest('a[data-id]');
      if (!a) return;
      e.preventDefault();
      input.value = a.dataset.label;
      if (target) target.value = a.dataset.id;
      box.style.display = 'none';
      if (input.dataset.submitOnPick !== undefined) input.form.submit();
    });
    document.addEventListener('click', function (e) {
      if (!input.parentNode.contains(e.target)) box.style.display = 'none';
    });
  });

  // Print buttons
  document.querySelectorAll('[data-print]').forEach(function (b) {
    b.addEventListener('click', function (e) { e.preventDefault(); window.print(); });
  });

  // Copy-to-clipboard
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy]');
    if (!b) return;
    e.preventDefault();
    navigator.clipboard.writeText(b.dataset.copy).then(function () {
      var t = b.innerHTML; b.innerHTML = 'Copied!';
      setTimeout(function () { b.innerHTML = t; }, 1400);
    });
  });
})();
