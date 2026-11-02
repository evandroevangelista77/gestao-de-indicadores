/**
 * Indicadores — painel executivo: balão com os indicadores ao passar o mouse numa célula da grade,
 * mini-gráficos de tendência e os gráficos do detalhe da área (SVG puro, sem bibliotecas).
 */
(() => {
    const COLORS = {ok: '#22c55e', warn: '#f59e0b', bad: '#ef4444', none: '#94a3b8', info: '#64748b'};
    const color = (s) => COLORS[s] || COLORS.none;
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const nf = (dec) => new Intl.NumberFormat('pt-BR', {minimumFractionDigits: dec, maximumFractionDigits: dec});
    const fmt = (v, unit) => {
        if (v === null || v === undefined) return '—';
        const dec = unit === 'R$' ? 2 : Math.abs(v - Math.round(v)) < 0.05 ? 0 : 1;
        const n = nf(dec).format(v);
        return {'%': `${n}%`, d: `${n} d`, h: `${n} h`, 'R$': `R$ ${n}`}[unit] || n;
    };

    // ---------------------------------------------------------------- gráficos
    /** Linha com área sob a curva; pontos nulos interrompem a linha. Devolve o SVG como texto. */
    function line(values, {w = 84, h = 26, pad = 3, stroke = COLORS.none, fill = true, dots = false, min = null, max = null, ref = null} = {}) {
        const nums = values.filter((v) => v !== null && v !== undefined);
        if (!nums.length) return `<svg viewBox="0 0 ${w} ${h}" preserveAspectRatio="none"></svg>`;
        let lo = min ?? Math.min(...nums, ...(ref !== null ? [ref] : []));
        let hi = max ?? Math.max(...nums, ...(ref !== null ? [ref] : []));
        if (hi === lo) { hi += 1; lo -= 1; }
        const x = (i) => pad + (values.length === 1 ? (w - 2 * pad) / 2 : (i * (w - 2 * pad)) / (values.length - 1));
        const y = (v) => h - pad - ((v - lo) / (hi - lo)) * (h - 2 * pad);
        let d = '';
        let area = '';
        let seg = [];
        const flush = () => {
            if (seg.length > 1) {
                d += `M${seg.map(([i, v]) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`).join('L')}`;
                if (fill) area += `M${x(seg[0][0]).toFixed(1)},${h - pad}L${seg.map(([i, v]) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`).join('L')}L${x(seg[seg.length - 1][0]).toFixed(1)},${h - pad}Z`;
            }
            seg = [];
        };
        values.forEach((v, i) => { if (v === null || v === undefined) flush(); else seg.push([i, v]); });
        flush();
        const lastIdx = values.map((v) => v !== null && v !== undefined).lastIndexOf(true);
        const refLine = ref !== null ? `<line x1="${pad}" x2="${w - pad}" y1="${y(ref).toFixed(1)}" y2="${y(ref).toFixed(1)}" stroke="${COLORS.none}" stroke-dasharray="3 3" stroke-width="1" opacity=".7"/>` : '';
        const pts = (dots ? values.map((v, i) => (v === null || v === undefined ? '' : `<circle cx="${x(i).toFixed(1)}" cy="${y(v).toFixed(1)}" r="2.2" fill="${stroke}"/>`)).join('')
            : `<circle cx="${x(lastIdx).toFixed(1)}" cy="${y(values[lastIdx]).toFixed(1)}" r="2.6" fill="${stroke}"/>`);
        return `<svg viewBox="0 0 ${w} ${h}" preserveAspectRatio="none">${refLine}${fill ? `<path d="${area}" fill="${stroke}" opacity=".13"/>` : ''}<path d="${d}" fill="none" stroke="${stroke}" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>${pts}</svg>`;
    }

    // ---------------------------------------------------------------- tooltip simples
    let tip = null;
    const showTip = (ev, html) => {
        if (!tip) { tip = document.createElement('div'); tip.className = 'ind-tip'; document.body.appendChild(tip); }
        tip.innerHTML = html;
        tip.hidden = false;
        tip.style.left = `${Math.min(window.innerWidth - tip.offsetWidth - 8, ev.clientX + 12)}px`;
        tip.style.top = `${ev.clientY + 14}px`;
    };
    const hideTip = () => { if (tip) tip.hidden = true; };

    /** Gráfico interativo: mostra o mês e o valor do ponto mais próximo ao passar o mouse. */
    function chart(host, values, meses, {unit = '', stroke = COLORS.none, ref = null, min = null, max = null, w = 300, h = 90} = {}) {
        host.innerHTML = line(values, {w, h, pad: 6, stroke, fill: true, dots: true, ref, min, max});
        const svg = host.querySelector('svg');
        svg.addEventListener('mousemove', (ev) => {
            const r = svg.getBoundingClientRect();
            const i = Math.max(0, Math.min(values.length - 1, Math.round(((ev.clientX - r.left) / r.width) * (values.length - 1))));
            showTip(ev, `<b>${esc(meses[i] || '')}</b> · ${unit === 'nota' || unit === '' ? (values[i] === null ? '—' : nf(0).format(values[i])) : fmt(values[i], unit)}`);
        });
        svg.addEventListener('mouseleave', hideTip);
    }

    // ---------------------------------------------------------------- balão da grade
    const pop = document.getElementById('ind-pop');
    const cache = new Map();
    let timer = null;
    let hideTimer = null;
    let currentCell = null;
    const baseUrl = (() => {
        const s = [...document.scripts].map((x) => x.src).find((u) => u.includes('/plugins/indicadores/'));
        return s ? s.split('/public/')[0].split('/js/')[0].replace(/\/$/, '') : '/plugins/indicadores';
    })();

    function trend(d) {
        if (d.score === null || d.anterior === null || d.anterior === undefined) return '';
        const diff = Math.round((d.score - d.anterior) * 10) / 10;
        if (!diff) return '<small>= mês anterior</small>';
        return `<small style="color:${diff > 0 ? COLORS.ok : COLORS.bad}">${diff > 0 ? '▲ +' : '▼ −'}${nf(1).format(Math.abs(diff))} vs. mês ant.</small>`;
    }

    function render(d, cell) {
        if (d.tipo === 'vazio') return `<div class="ind-pop-load">Sem dados para ${esc(d.mes || 'este mês')}.</div>`;
        const c = d.cor || '#64748b';
        const head = `<div class="ind-pop-head" style="--c:${color(d.status)}">
            <span class="ind-area-ico" style="--c:${esc(c)}"><i class="${esc(d.icone || 'ti ti-gauge')}"></i></span>
            <div><h4>${esc(d.titulo)}</h4><small>${esc(d.mes)}</small></div>
            <div class="ind-pop-score"><b>${d.score === null ? '—' : nf(0).format(d.score)}</b>${trend(d)}</div></div>`;
        let body = '';
        if (d.tipo === 'geral') {
            body += `<div class="ind-pop-chart" id="ind-pop-chart"></div><div class="ind-pop-body">`;
            d.areas.forEach((a) => {
                body += `<div class="ind-pop-row"><i class="ind-dot" style="--c:${color(a.status)}"></i>
                    <div class="nm"><i class="${esc(a.icone)}" style="color:${esc(a.cor)}"></i> ${esc(a.nome)}<small>${a.bad} crítico(s) · ${a.warn} em atenção</small></div>
                    <div class="vl">${a.score === null ? '—' : nf(0).format(a.score)}</div>${line(a.serie, {stroke: color(a.status), min: 0, max: 100})}</div>`;
            });
            body += '</div>';
        } else {
            body += '<div class="ind-pop-body">';
            d.kpis.forEach((k) => {
                const st = k.dir === 'info' ? 'info' : k.status;
                body += `<div class="ind-pop-row"><i class="ind-dot" style="--c:${color(st)}"></i>
                    <div class="nm">${esc(k.nome)}${k.vivo ? '<small>situação atual</small>' : ''}</div>
                    <div class="vl">${esc(k.fmt)}${k.fmtMeta ? `<small>meta ${esc(k.fmtMeta)}</small>` : ''}</div>
                    ${line(k.serie, {stroke: color(st), ref: k.meta})}</div>`;
            });
            body += '</div>';
        }
        return `${head}${body}<div class="ind-pop-foot">${d.tipo === 'geral' ? 'Média das notas das áreas · últimos 12 meses' : 'Tendência dos últimos 12 meses · clique para ver o detalhe'}</div>`;
    }

    function place(cell) {
        const r = cell.getBoundingClientRect();
        const pw = pop.offsetWidth;
        const ph = pop.offsetHeight;
        let left = r.right + 10;
        if (left + pw > window.innerWidth - 12) left = Math.max(12, r.left - pw - 10);
        let top = r.top - 8;
        top = Math.max(12, Math.min(top, window.innerHeight - ph - 12));
        pop.style.left = `${left}px`;
        pop.style.top = `${top}px`;
    }

    async function open(cell) {
        const key = `${cell.dataset.area}|${cell.dataset.month}`;
        currentCell = cell;
        pop.hidden = false;
        if (!cache.has(key)) {
            pop.innerHTML = '<div class="ind-pop-load">Carregando indicadores…</div>';
            place(cell);
            try {
                const res = await fetch(`${baseUrl}/front/ajax.php?area=${encodeURIComponent(cell.dataset.area)}&month=${encodeURIComponent(cell.dataset.month)}`, {credentials: 'same-origin'});
                cache.set(key, await res.json());
            } catch (e) {
                pop.innerHTML = '<div class="ind-pop-load">Não foi possível carregar.</div>';
                return;
            }
        }
        if (currentCell !== cell) return;
        const d = cache.get(key);
        pop.innerHTML = render(d, cell);
        const big = pop.querySelector('#ind-pop-chart');
        if (big) chart(big, d.serie, d.meses, {unit: 'nota', stroke: color(d.status), min: 0, max: 100, w: 360, h: 70});
        place(cell);
    }

    function close() {
        currentCell = null;
        if (pop) pop.hidden = true;
        hideTip();
    }

    if (pop) {
        document.addEventListener('mouseover', (ev) => {
            const cell = ev.target.closest && ev.target.closest('.ind-cell[data-area]');
            if (cell) {
                clearTimeout(hideTimer);
                if (cell === currentCell) return;
                clearTimeout(timer);
                timer = setTimeout(() => open(cell), 140);
            } else if (ev.target.closest && ev.target.closest('#ind-pop')) {
                clearTimeout(hideTimer);
            }
        });
        document.addEventListener('mouseout', (ev) => {
            const from = ev.target.closest && (ev.target.closest('.ind-cell[data-area]') || ev.target.closest('#ind-pop'));
            if (!from) return;
            const to = ev.relatedTarget && ev.relatedTarget.closest && (ev.relatedTarget.closest('.ind-cell[data-area]') || ev.relatedTarget.closest('#ind-pop'));
            if (to) return;
            clearTimeout(timer);
            hideTimer = setTimeout(close, 120);
        });
        document.addEventListener('focusin', (ev) => { const c = ev.target.closest && ev.target.closest('.ind-cell[data-area]'); if (c) open(c); });
        document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') close(); });
        window.addEventListener('scroll', close, {passive: true});
    }

    // ---------------------------------------------------------------- gráfico do cartão principal
    document.querySelectorAll('.ind-hero-spark').forEach((el) => {
        try {
            const serie = JSON.parse(el.dataset.serie);
            const meses = JSON.parse(el.dataset.meses);
            const status = serie.filter((v) => v !== null).slice(-1)[0];
            const stroke = el.dataset.cor || (status >= 80 ? COLORS.ok : status >= 60 ? COLORS.warn : COLORS.bad);
            const min = el.dataset.min !== undefined ? Number(el.dataset.min) : 0;
            const max = el.dataset.max !== undefined ? Number(el.dataset.max) : 100;
            chart(el, serie, meses, {unit: 'nota', stroke, min, max, w: 400, h: 70});
        } catch (e) { /* sem gráfico */ }
    });

    // ---------------------------------------------------------------- detalhe da área
    const detail = document.getElementById('ind-detail');
    const host = document.getElementById('ind-kpis');
    if (detail && host) {
        const d = JSON.parse(detail.textContent);
        d.kpis.forEach((k) => {
            const st = k.dir === 'info' ? 'info' : k.status;
            const card = document.createElement('div');
            card.className = 'ind-kpi';
            card.style.setProperty('--c', color(st));
            const dirTxt = k.dir === 'up' ? '↑ quanto maior, melhor' : k.dir === 'down' ? '↓ quanto menor, melhor' : 'informativo';
            card.innerHTML = `<div class="ind-kpi-top"><h4>${esc(k.nome)}<small>${esc(k.dica || dirTxt)}</small></h4>
                <span class="ind-pill ind-${esc(st)}">${esc({ok: 'Na meta', warn: 'Atenção', bad: 'Crítico', none: 'Sem dados', info: 'Informativo'}[st])}</span></div>
                <div class="ind-kpi-val">${esc(k.fmt)}</div>
                <div class="ind-kpi-meta">${k.fmtMeta ? `Meta ${esc(k.fmtMeta)} · limite ${esc(fmt(k.limite, k.unit))} · ${esc(dirTxt)}` : esc(dirTxt)}${k.vivo ? ' · situação atual (sem histórico antes da foto)' : ''}</div>
                <div class="ind-kpi-chart"></div>`;
            host.appendChild(card);
            chart(card.querySelector('.ind-kpi-chart'), k.serie, d.meses, {unit: k.unit, stroke: color(st), ref: k.meta, w: 300, h: 90});
        });
    }
})();
