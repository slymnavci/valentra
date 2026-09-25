/* Excel benzeri sütun genişliği ayarlama: her tablonun th'lerine sürüklenebilir
   bir tutamaç ekler, genişlik localStorage'a kaydedilir ve bir sonraki açılışta
   (aynı sayfa + aynı tablo + aynı sütun için) otomatik uygulanır. Finansal rapor
   sayfaları (finansal_rapor_v2.php, satis_rapor.php, uretim_rapor.php) filtre
   değiştikçe tabloları innerHTML ile yeniden oluşturduğundan, bir
   MutationObserver ile her değişiklikten sonra tutamaçlar yeniden eklenir. */
(function () {
  'use strict';
  var KEY_PREFIX = 'ymm_col_w::' + location.pathname + '::';

  function tableId(table) {
    if (table.id) return table.id;
    if (!table.dataset.rsIdx) {
      table.dataset.rsIdx = 't' + Array.prototype.indexOf.call(document.querySelectorAll('table'), table);
    }
    return table.dataset.rsIdx;
  }
  function storeKey(table, i) { return KEY_PREFIX + tableId(table) + '::' + i; }

  /* table-layout:auto sütun genişliklerini her zaman o an DOM'daki en geniş
     hücre içeriğine göre yeniden hesaplar — bu yüzden bir sütunu daraltıp
     sonra bir satırı genişlettiğimizde (yeni, daha geniş içerikli satırlar
     eklendiğinde), tarayıcı sütunu kullanıcının ayarladığı genişliği yok
     sayıp tekrar genişletiyordu. Çözüm: başlık satırındaki her sütunun o
     anki (varsayılan veya daha önce kaydedilmiş) genişliğini bir kere
     "dondurup" tabloyu table-layout:fixed'e geçiriyoruz — bundan sonra
     sütun genişlikleri yalnızca elle sürüklemeyle değişir, yeni eklenen
     satırların içeriği sütunu asla yeniden genişletemez. */
  function freezeColumns(table, row) {
    if (table.dataset.rsFixed) return;
    for (var i = 0; i < row.cells.length; i++) {
      var th = row.cells[i];
      if (th.colSpan > 1) continue;
      if (!th.style.width) {
        var w = Math.round(th.getBoundingClientRect().width);
        if (w > 0) { th.style.width = w + 'px'; th.style.minWidth = w + 'px'; }
      }
    }
    table.dataset.rsFixed = '1';
  }

  function ensureHandle(th, table, i) {
    try {
      var w = localStorage.getItem(storeKey(table, i));
      if (w) { th.style.width = w + 'px'; th.style.minWidth = w + 'px'; }
    } catch (e) {}
    if (th.querySelector('.ymm-col-rh')) return;
    if (!th.style.position) th.style.position = 'relative';
    var h = document.createElement('span');
    h.className = 'ymm-col-rh';
    h.title = 'Sütun genişliğini değiştirmek için sürükleyin';
    h.addEventListener('mousedown', function (e) {
      e.preventDefault(); e.stopPropagation();
      var startX = e.clientX, startW = th.getBoundingClientRect().width;
      function onMove(ev) {
        var w = Math.max(40, Math.round(startW + ev.clientX - startX));
        th.style.width = w + 'px'; th.style.minWidth = w + 'px';
      }
      function onUp() {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        try { localStorage.setItem(storeKey(table, i), parseInt(th.style.width, 10)); } catch (e2) {}
      }
      document.addEventListener('mousemove', onMove);
      document.addEventListener('mouseup', onUp);
    });
    th.appendChild(h);
  }

  function scan() {
    document.querySelectorAll('table').forEach(function (table) {
      for (var r = 0; r < table.rows.length; r++) {
        var row = table.rows[r], allTh = true;
        for (var c = 0; c < row.cells.length; c++) if (row.cells[c].tagName !== 'TH') { allTh = false; break; }
        if (!allTh || !row.cells.length) continue;
        for (var i = 0; i < row.cells.length; i++) {
          var th = row.cells[i];
          if (th.colSpan > 1) continue;
          ensureHandle(th, table, i);
        }
        freezeColumns(table, row);
      }
    });
  }

  var style = document.createElement('style');
  style.textContent = '.ymm-col-rh{position:absolute;right:-3px;top:0;width:7px;height:100%;cursor:col-resize;z-index:5}' +
    '.ymm-col-rh:hover{background:rgba(37,99,235,.25)}' +
    'table[data-rs-fixed]{table-layout:fixed}' +
    'table[data-rs-fixed] td{overflow:hidden;text-overflow:ellipsis}' +
    'table[data-rs-fixed] td:first-child{overflow:visible;text-overflow:clip}';
  document.head.appendChild(style);

  var scheduled = false;
  function scanDebounced() {
    if (scheduled) return;
    scheduled = true;
    setTimeout(function () { scheduled = false; scan(); }, 60);
  }
  new MutationObserver(scanDebounced).observe(document.body, { subtree: true, childList: true });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan); else scan();
})();
