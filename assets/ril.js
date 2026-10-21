(function () {
  "use strict";

  /* ---------- Assinatura desenhada ---------- */
  function setupPad(wrap) {
    var canvas = wrap.querySelector("canvas");
    var input = wrap.querySelector('input[name="signature"]');
    var hint = wrap.querySelector(".ril-sig-hint");
    var ctx = canvas.getContext("2d");
    var strokes = [];
    var current = null;

    function resize() {
      var ratio = Math.max(window.devicePixelRatio || 1, 1);
      var w = canvas.offsetWidth;
      var h = canvas.offsetHeight;
      if (!w || !h) return;
      canvas.width = w * ratio;
      canvas.height = h * ratio;
      ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
      redraw();
    }

    function drawStroke(pts) {
      if (!pts.length) return;
      ctx.lineCap = "round";
      ctx.lineJoin = "round";
      ctx.strokeStyle = "#1a1a1a";
      ctx.lineWidth = 2.2;
      ctx.beginPath();
      ctx.moveTo(pts[0].x, pts[0].y);
      if (pts.length === 1) {
        ctx.lineTo(pts[0].x + 0.1, pts[0].y + 0.1);
      }
      for (var i = 1; i < pts.length - 1; i++) {
        var mx = (pts[i].x + pts[i + 1].x) / 2;
        var my = (pts[i].y + pts[i + 1].y) / 2;
        ctx.quadraticCurveTo(pts[i].x, pts[i].y, mx, my);
      }
      if (pts.length > 1) {
        var last = pts[pts.length - 1];
        ctx.lineTo(last.x, last.y);
      }
      ctx.stroke();
    }

    function redraw() {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      strokes.forEach(drawStroke);
    }

    function point(e) {
      var r = canvas.getBoundingClientRect();
      return { x: e.clientX - r.left, y: e.clientY - r.top };
    }

    function save() {
      var total = strokes.reduce(function (n, s) { return n + s.length; }, 0);
      if (total < 3) {
        input.value = "";
        return;
      }
      // Exporta só a área desenhada, em tamanho fixo, para o arquivo ficar leve.
      var ratio = canvas.width / canvas.offsetWidth;
      var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
      strokes.forEach(function (s) {
        s.forEach(function (p) {
          minX = Math.min(minX, p.x); minY = Math.min(minY, p.y);
          maxX = Math.max(maxX, p.x); maxY = Math.max(maxY, p.y);
        });
      });
      var pad = 8;
      minX = Math.max(0, minX - pad); minY = Math.max(0, minY - pad);
      maxX = Math.min(canvas.offsetWidth, maxX + pad); maxY = Math.min(canvas.offsetHeight, maxY + pad);
      var w = Math.max(1, maxX - minX), h = Math.max(1, maxY - minY);
      var scale = Math.min(1, 600 / w, 200 / h) * Math.min(ratio, 2);
      var out = document.createElement("canvas");
      out.width = Math.round(w * scale);
      out.height = Math.round(h * scale);
      out.getContext("2d").drawImage(canvas, minX * ratio, minY * ratio, w * ratio, h * ratio, 0, 0, out.width, out.height);
      input.value = out.toDataURL("image/png");
    }

    canvas.addEventListener("pointerdown", function (e) {
      e.preventDefault();
      canvas.setPointerCapture(e.pointerId);
      current = [point(e)];
      strokes.push(current);
      if (hint) hint.style.display = "none";
      redraw();
    });
    canvas.addEventListener("pointermove", function (e) {
      if (!current) return;
      e.preventDefault();
      current.push(point(e));
      redraw();
    });
    function end() {
      if (!current) return;
      current = null;
      save();
    }
    canvas.addEventListener("pointerup", end);
    canvas.addEventListener("pointercancel", end);
    canvas.addEventListener("pointerleave", end);

    wrap.querySelector(".ril-sig-clear").addEventListener("click", function () {
      strokes = [];
      input.value = "";
      if (hint) hint.style.display = "";
      redraw();
    });

    window.addEventListener("resize", resize);
    resize();
  }

  document.querySelectorAll("[data-ril-sig]").forEach(setupPad);

  /* ---------- Campos repetidos do casal ficam iguais ---------- */
  document.addEventListener("input", function (e) {
    var el = e.target;
    if (!el.classList || !el.classList.contains("ril-blank")) return;
    var name = el.getAttribute("data-blank");
    document.querySelectorAll("input.ril-blank").forEach(function (other) {
      if (other !== el && other.getAttribute("data-blank") === name) other.value = el.value;
    });
  });

  /* ---------- Soft hold: só o mês ---------- */
  document.querySelectorAll("[data-ril-hold]").forEach(function (box) {
    var check = box.querySelector('input[name="softhold"]');
    var pick = box.querySelector(".ril-hold-pick");
    var date = document.querySelector("[data-ril-hold-date]");
    var events = document.querySelectorAll("[data-ril-event]");
    function apply() {
      var on = check.checked;
      pick.hidden = !on;
      if (date) {
        date.hidden = on;
        date.required = !on;
      }
      events.forEach(function (el) {
        el.required = !on;
        el.placeholder = on ? "Optional: " + el.getAttribute("data-blank") : "Fill in: " + el.getAttribute("data-blank");
      });
      pick.querySelectorAll("select").forEach(function (s) { s.required = on; });
    }
    check.addEventListener("change", apply);
    apply();
  });

  /* ---------- Enviar assinatura: evita clique duplo ---------- */
  var signForm = document.getElementById("ril-sign-form");
  if (signForm) {
    signForm.addEventListener("submit", function () {
      var btn = signForm.querySelector('button[type="submit"]');
      setTimeout(function () { btn.disabled = true; btn.textContent = "Signing…"; }, 0);
    });
  }

  /* ---------- Painel ---------- */
  document.querySelectorAll("[data-copy]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var input = document.querySelector(btn.getAttribute("data-copy"));
      var label = btn.textContent;
      function done() {
        btn.textContent = "Copiado!";
        setTimeout(function () { btn.textContent = label; }, 1800);
      }
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(input.value).then(done, function () { input.select(); document.execCommand("copy"); done(); });
      } else {
        input.select();
        document.execCommand("copy");
        done();
      }
    });
  });

  document.querySelectorAll("form[data-confirm]").forEach(function (form) {
    form.addEventListener("submit", function (e) {
      if (!window.confirm(form.getAttribute("data-confirm"))) e.preventDefault();
    });
  });

  document.querySelectorAll("form[data-ril-require-sig]").forEach(function (form) {
    form.addEventListener("submit", function (e) {
      var sig = form.querySelector('input[name="signature"]');
      if (sig && !sig.value) {
        e.preventDefault();
        window.alert("Desenhe a assinatura antes de concluir.");
      }
    });
  });

  document.querySelectorAll("[data-ril-logo]").forEach(function (box) {
    var input = box.querySelector('input[name="logo_id"]');
    var preview = box.querySelector(".ril-logo-preview");
    var frame;
    box.querySelector("[data-ril-logo-pick]").addEventListener("click", function () {
      if (!window.wp || !wp.media) return;
      frame = frame || wp.media({ title: "Logo", library: { type: "image" }, multiple: false, button: { text: "Usar esta logo" } });
      frame.off("select").on("select", function () {
        var a = frame.state().get("selection").first().toJSON();
        input.value = a.id;
        var url = (a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url);
        preview.innerHTML = '<img src="' + url + '" alt="">';
      });
      frame.open();
    });
    box.querySelector("[data-ril-logo-clear]").addEventListener("click", function () {
      input.value = "0";
      preview.innerHTML = '<span class="ril-muted">Nenhuma logo (usa a do tema, se houver)</span>';
    });
  });

  var tplSelect = document.querySelector("select[data-reload-template]");
  if (tplSelect) {
    tplSelect.addEventListener("change", function () {
      var url = new URL(window.location.href);
      url.searchParams.set("template", tplSelect.value);
      window.location.href = url.toString();
    });
  }
})();

/* ---------- Valores: sinal automático e saldo ---------- */
(function () {
  "use strict";
  function parse(v) {
    var n = parseFloat(String(v || "").replace(/[^0-9.\-]/g, ""));
    return isNaN(n) ? 0 : Math.round(n * 100);
  }
  function fmt(c) {
    return (c / 100).toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  document.querySelectorAll("[data-ril-money]").forEach(function (box) {
    var pct = parseFloat(box.getAttribute("data-pct")) || 0;
    var total = box.querySelector('input[name="total"]');
    var dep = box.querySelector('input[name="deposit"]');
    var pre = box.querySelector('input[name="prepaid"]');
    var sum = box.parentNode.querySelector("[data-ril-money-sum]");
    // O sinal acompanha o total até alguém digitar outro valor nele.
    var auto = !dep.value || parse(dep.value) === Math.round(parse(total.value) * pct / 100);
    function update() {
      var t = parse(total.value);
      if (auto) dep.value = t ? fmt(Math.round(t * pct / 100)) : "";
      var d = parse(dep.value), p = parse(pre.value);
      if (!sum) return;
      if (!t) { sum.textContent = ""; return; }
      var bal = Math.max(0, t - d - p);
      var dp = Math.round(d * 1000 / t) / 10;
      sum.textContent = "Total $" + fmt(t) + (p ? " · Pago antes $" + fmt(p) : "") + " · Sinal $" + fmt(d) + " (" + dp + "%)" + " · Saldo até o casamento $" + fmt(bal) + (d + p > t ? " — atenção: sinal + pago antes passam do total" : "");
    }
    total.addEventListener("input", update);
    pre.addEventListener("input", update);
    dep.addEventListener("input", function () { auto = dep.value === ""; update(); });
    update();
  });
})();
