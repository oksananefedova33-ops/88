(function(){
  var RIGHT_MARGIN = 0; // выравнивание по кнопке Mobile

  function findMobileBtn(){
    var tb = document.querySelector('.topbar, .editor-toolbar, .toolbar, .controls');
    if(!tb) return null;
    return tb.querySelector('[data-mode="mobile"],[data-view="mobile"],.mobile,[title*="Mobile" i]') ||
           Array.from(tb.querySelectorAll('button,a,div,span'))
                .find(function(el){return /^\s*mobile\s*$/i.test((el.textContent||"").trim());}) || null;
  }

  function resizeBar(){
    var bar = document.getElementById('seoBar');
    if(!bar) return;
    var br = bar.getBoundingClientRect();
    var mobile = findMobileBtn();
    var w = 550; // <- МЕНЯЙТЕ ЭТУ ЦИФРУ ДЛЯ НУЖНОЙ ШИРИНЫ
    
    if(w){ bar.style.width = w+'px'; bar.style.maxWidth = w+'px'; }
  }

  function labelFor(el){
    if(!el) return null;
    var wrap = el.closest('.group, .form-group, .field, .item, .col, .cell') || el.parentElement;
    var lbl = (el.labels && el.labels[0]) ||
              (wrap ? wrap.querySelector('label, .label') : null) ||
              (el.previousElementSibling && el.previousElementSibling.matches('label, .label') ? el.previousElementSibling : null);
    return lbl || null;
  }

  function setupCount(el, max, fallback){
    if(!el) return;
    var lbl = labelFor(el);
    if(!lbl) return;
    var base = (lbl.textContent || fallback || '').replace(/\s*\d+\s*$/, '').trim() || fallback || '';
    lbl.innerHTML = base + ' <span class="seo-count">0</span>';
    var out = lbl.querySelector('.seo-count');
    function upd(){
      var len = (el.value || '').length;
      out.textContent = len;
      out.classList.toggle('over', len > max);
      out.classList.toggle('ok', len <= max);
    }
    el.addEventListener('input', upd);
    el.addEventListener('change', upd);
    setTimeout(upd, 0);
  }

  function removeHints(container){
    if(!container) return;
    Array.from(container.querySelectorAll('.label, .hint, p, small, div')).forEach(function(el){
      var t = (el.textContent || '').trim();
      if(/^Рекомендации:/i.test(t) || /^Recommendations:/i.test(t)) el.remove();
    });
  }

  function move(){
    var bar  = document.getElementById('seoBar');
    var props= document.getElementById('props');
    if(!bar || !props) return;

    var title = props.querySelector('#seoTitle,[name="seoTitle"],[name="seo_title"]');
    if(!title) return;

    var row = title.closest('.row') || title.closest('[class*="row"]') || title.parentElement;
    if(!row) return;

    // возможная подсказка после ряда — удаляем
    var hint = row.nextElementSibling;
    if(hint && /label/i.test(hint.className) && /Рекомендации|Recommendations/i.test(hint.textContent||'')) hint.remove();

    // переносим блок
    bar.innerHTML = '';
    bar.appendChild(row);

    // одна колонка, минимальные отступы, без внутренних рамок
    row.style.display = 'grid';
    row.style.gridTemplateColumns = '1fr';
    row.style.gap = '4px';
    Array.from(row.querySelectorAll('.group,.form-group,.field,.item')).forEach(function(g){
      g.style.margin = '0';
      g.style.padding = '0';
      g.style.border  = '0';
      g.style.background = 'transparent';
      g.style.boxShadow = 'none';
    });

    removeHints(bar);

    // определить поля
    var ctrls = row.querySelectorAll('input, textarea');
    var desc  = ctrls[1] || row.querySelector('#seoDescription,[name="seoDescription"],[name="seo_description"]');

    // счётчики с порогами
    setupCount(title, 60,  'SEO Title');
    if(desc) setupCount(desc, 160, 'SEO Description');

    resizeBar();
  }

  function init(){
    var props = document.getElementById('props');
    if(!props) return;
    new MutationObserver(function(){ move(); }).observe(props, {childList:true, subtree:true});
    window.addEventListener('resize', resizeBar);
    window.addEventListener('orientationchange', resizeBar);

    setTimeout(move, 0);
    setTimeout(move, 200);
    setTimeout(move, 600);
    setTimeout(move, 1200);
  }

  if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
