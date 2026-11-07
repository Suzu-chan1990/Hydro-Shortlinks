/* Hydro Shortlinks admin UI (vanilla JS, Chart.js 4, qrcode-generator). */
(function () {
	'use strict';

	const H = window.HydroUI || {};
	const $ = (s, r = document) => r.querySelector(s);
	const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
	const nf = new Intl.NumberFormat(document.documentElement.lang || undefined);
	const fmt = (n) => nf.format(n || 0);
	const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const C = { primary: '#06b6d4', accent: '#7c3aed', muted: '#94a3b8', grid: '#eef2f7', text: '#0f1720' };
	const PALETTE = ['#06b6d4', '#7c3aed', '#0ea5e9', '#a855f7', '#14b8a6', '#6366f1', '#f59e0b', '#ec4899', '#64748b', '#10b981'];

	/* ---------------------------------------------------------------- helpers */
	async function post(action, data = {}) {
		const body = new FormData();
		body.append('action', action);
		body.append('_ajax_nonce', H.nonce);
		for (const [k, v] of Object.entries(data)) {
			if (Array.isArray(v)) v.forEach((x) => body.append(k + '[]', x));
			else if (v !== undefined && v !== null) body.append(k, v);
		}
		const res = await fetch(H.ajax, { method: 'POST', body, credentials: 'same-origin' });
		let json = null;
		try { json = await res.json(); } catch (e) { /* not JSON */ }
		if (!json || !json.success) throw new Error((json && json.data) || 'Request failed (' + res.status + ')');
		return json.data;
	}

	let toastTimer;
	function toast(msg, isError) {
		const t = $('#hy-toast');
		if (!t) return;
		t.textContent = msg;
		t.classList.toggle('error', !!isError);
		t.classList.add('show');
		clearTimeout(toastTimer);
		toastTimer = setTimeout(() => t.classList.remove('show'), 2600);
	}

	async function copy(text) {
		try { await navigator.clipboard.writeText(text); }
		catch (e) {
			const ta = document.createElement('textarea');
			ta.value = text; document.body.appendChild(ta); ta.select();
			document.execCommand('copy'); ta.remove();
		}
		toast('Copied: ' + text);
	}

	function busy(btn, on) { if (btn) { btn.classList.toggle('is-busy', on); btn.disabled = on; } }

	/* ---------------------------------------------------------------- QR codes */
	function qrSvg(text) {
		if (typeof window.qrcode !== 'function') return '';
		const qr = window.qrcode(0, 'M');
		qr.addData(text);
		qr.make();
		return qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
	}
	function download(name, blob) {
		const a = document.createElement('a');
		a.href = URL.createObjectURL(blob);
		a.download = name;
		document.body.appendChild(a); a.click(); a.remove();
		setTimeout(() => URL.revokeObjectURL(a.href), 1000);
	}
	function qrDownload(text, type) {
		const svg = qrSvg(text).replace('<svg ', '<svg width="1024" height="1024" ');
		const slug = text.replace(/\/+$/, '').split('/').pop() || 'qr';
		if (type === 'svg') return download('qr-' + slug + '.svg', new Blob([svg], { type: 'image/svg+xml' }));
		const img = new Image();
		img.onload = () => {
			const c = document.createElement('canvas');
			c.width = c.height = 1024;
			const ctx = c.getContext('2d');
			ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, 1024, 1024);
			ctx.drawImage(img, 0, 0, 1024, 1024);
			c.toBlob((b) => download('qr-' + slug + '.png', b), 'image/png');
		};
		img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
	}
	function qrModal(text) {
		let m = $('#hy-qr-modal');
		if (!m) {
			m = document.createElement('div');
			m.id = 'hy-qr-modal';
			m.className = 'hy-modal';
			m.innerHTML = '<div class="hy-modal-box" role="dialog" aria-modal="true" aria-label="QR code"><div class="hy-qr"></div><code></code>' +
				'<div class="hy-qr-actions"><button type="button" class="hy-btn" data-t="png"><span class="dashicons dashicons-download"></span>PNG</button>' +
				'<button type="button" class="hy-btn" data-t="svg"><span class="dashicons dashicons-download"></span>SVG</button>' +
				'<button type="button" class="hy-btn" data-t="close">Close</button></div></div>';
			(document.querySelector('.hydro-wrap') || document.body).appendChild(m);
			m.addEventListener('click', (e) => {
				const b = e.target.closest('[data-t]');
				if (e.target === m || (b && b.dataset.t === 'close')) { m.hidden = true; return; }
				if (b) qrDownload(m.dataset.text, b.dataset.t);
			});
		}
		m.dataset.text = text;
		$('.hy-qr', m).innerHTML = qrSvg(text);
		$('code', m).textContent = text;
		m.hidden = false;
	}

	/* ---------------------------------------------------------------- charts */
	function chartDefaults() {
		if (!window.Chart) return false;
		Chart.defaults.font.family = 'Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif';
		Chart.defaults.font.size = 11;
		Chart.defaults.color = '#64748b';
		Chart.defaults.plugins.legend.labels.boxWidth = 10;
		Chart.defaults.plugins.legend.labels.boxHeight = 10;
		Chart.defaults.plugins.tooltip.backgroundColor = '#0f1720';
		Chart.defaults.plugins.tooltip.padding = 10;
		Chart.defaults.plugins.tooltip.cornerRadius = 6;
		return true;
	}
	const hasChart = chartDefaults();

	function gradientFill(ctx) {
		const { chart } = ctx;
		const { ctx: c, chartArea } = chart;
		if (!chartArea) return 'rgba(6,182,212,.12)';
		const g = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
		g.addColorStop(0, 'rgba(6,182,212,.28)');
		g.addColorStop(1, 'rgba(124,58,237,.02)');
		return g;
	}
	function shortLabel(l) {
		// "2026-11-04" → "4 Nov", "2026-11-04 13:00" → "13:00"
		if (/^\d{4}-\d{2}-\d{2} \d{2}:00$/.test(l)) return l.slice(11);
		const d = new Date(l + 'T00:00:00');
		return isNaN(d) ? l : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
	}
	function lineChart(canvas, labels, data, prev) {
		const datasets = [{
			label: 'Clicks', data, borderColor: C.primary, backgroundColor: gradientFill, fill: true,
			tension: .35, borderWidth: 2.5, pointRadius: data.length > 40 ? 0 : 2.5, pointHoverRadius: 5, pointBackgroundColor: C.primary,
		}];
		if (prev) datasets.push({
			label: 'Previous period', data: prev, borderColor: 'rgba(124,58,237,.55)', borderDash: [5, 4],
			fill: false, tension: .35, borderWidth: 1.6, pointRadius: 0,
		});
		return new Chart(canvas, {
			type: 'line',
			data: { labels: labels.map(shortLabel), datasets },
			options: {
				responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
				plugins: { legend: { display: !!prev, position: 'bottom' }, tooltip: { callbacks: { title: (it) => labels[it[0].dataIndex] } } },
				scales: {
					x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 12 } },
					y: { beginAtZero: true, grid: { color: C.grid }, ticks: { precision: 0 } },
				},
			},
		});
	}
	function donut(canvas, obj, existing) {
		const labels = Object.keys(obj), data = Object.values(obj);
		if (existing) existing.destroy();
		return new Chart(canvas, {
			type: 'doughnut',
			data: { labels, datasets: [{ data, backgroundColor: PALETTE, borderWidth: 2, borderColor: '#fff', hoverOffset: 6 }] },
			options: {
				responsive: true, maintainAspectRatio: false, cutout: '68%',
				plugins: {
					legend: { position: 'right', labels: { generateLabels: (ch) => {
						const total = data.reduce((a, b) => a + b, 0) || 1;
						return labels.map((l, i) => ({ text: l + '  ' + Math.round(data[i] / total * 100) + ' %', fillStyle: PALETTE[i % PALETTE.length], strokeStyle: '#fff', index: i, hidden: !ch.getDataVisibility(i) }));
					} } },
				},
			},
		});
	}
	function hbar(canvas, obj, existing) {
		if (existing) existing.destroy();
		const labels = Object.keys(obj).slice(0, 7), data = Object.values(obj).slice(0, 7);
		return new Chart(canvas, {
			type: 'bar',
			data: { labels, datasets: [{ data, backgroundColor: labels.map((_, i) => PALETTE[i % PALETTE.length]), borderRadius: 4, barThickness: 12 }] },
			options: {
				indexAxis: 'y', responsive: true, maintainAspectRatio: false,
				plugins: { legend: { display: false } },
				scales: { x: { beginAtZero: true, grid: { color: C.grid }, ticks: { precision: 0 } }, y: { grid: { display: false } } },
			},
		});
	}

	/* ---------------------------------------------------------------- global clicks */
	document.addEventListener('click', (e) => {
		const cp = e.target.closest('[data-hy-copy]');
		if (cp && cp.dataset.hyCopy) { e.preventDefault(); copy(cp.dataset.hyCopy); return; }
		const qr = e.target.closest('[data-hy-qr]');
		if (qr && qr.dataset.hyQr) { e.preventDefault(); qrModal(qr.dataset.hyQr); return; }
		const tg = e.target.closest('[data-hy-toggle]');
		if (tg) { const t = $(tg.dataset.hyToggle); if (t) t.hidden = !t.hidden; }
	});
	document.addEventListener('submit', (e) => {
		const f = e.target.closest('[data-hy-confirm]');
		if (f && !window.confirm(f.dataset.hyConfirm)) e.preventDefault();
	});
	document.addEventListener('keydown', (e) => {
		if (e.key !== 'Escape') return;
		const m = $('#hy-qr-modal'); if (m && !m.hidden) { m.hidden = true; return; }
		closeDrawer();
	});

	/* ---------------------------------------------------------------- extensions */
	$$('.hy-module input[type=checkbox]').forEach((cb) => cb.addEventListener('change', () => cb.closest('.hy-module').classList.toggle('is-on', cb.checked)));

	/* ---------------------------------------------------------------- create forms */
	$$('[data-hy-create]').forEach((form) => {
		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			const btn = $('button[type=submit]', form);
			busy(btn, true);
			try {
				const data = Object.fromEntries(new FormData(form).entries());
				const res = await post('hydro_ajax_create_shortlink', data);
				const into = form.dataset.insert && $(form.dataset.insert);
				if (into && res.html) {
					const empty = $('.hy-empty-row', into); if (empty) empty.remove();
					into.insertAdjacentHTML('afterbegin', res.html);
					into.firstElementChild.classList.add('is-new');
				}
				const box = form.parentElement.querySelector('.hy-quick-result');
				if (box) {
					const a = $('.hy-quick-url a', box);
					a.href = a.textContent = res.short;
					$('[data-hy-copy]', box).dataset.hyCopy = res.short;
					$('[data-hy-qr]', box).dataset.hyQr = res.short;
					box.hidden = false;
				}
				form.reset();
				copy(res.short);
			} catch (err) {
				toast(err.message, true);
			} finally { busy(btn, false); }
		});
	});

	/* ---------------------------------------------------------------- links list */
	const rows = $('#hy-rows');
	if (rows) {
		const bulkBtn = $('[data-hy-bulk-delete]');
		const all = $('[data-hy-check-all]');
		const sync = () => { if (bulkBtn) { const n = $$('[data-hy-check]:checked').length; bulkBtn.disabled = !n; bulkBtn.lastChild.textContent = n ? 'Delete ' + n : 'Delete selected'; } };
		rows.addEventListener('change', (e) => { if (e.target.matches('[data-hy-check]')) sync(); });
		if (all) all.addEventListener('change', () => { $$('[data-hy-check]').forEach((c) => (c.checked = all.checked)); sync(); });

		async function remove(ids) {
			if (!ids.length || !window.confirm(ids.length === 1 ? 'Delete this shortlink and its click history?' : 'Delete ' + ids.length + ' shortlinks and their click history?')) return;
			ids.forEach((id) => { const tr = $('tr[data-id="' + id + '"]', rows); if (tr) tr.classList.add('is-removing'); });
			try {
				const r = await post('hydro_link_delete', { ids });
				r.ids.forEach((id) => { const tr = $('tr[data-id="' + id + '"]', rows); if (tr) tr.remove(); });
				toast(r.deleted + ' deleted');
				if (all) all.checked = false;
				sync();
			} catch (err) {
				$$('.is-removing', rows).forEach((tr) => tr.classList.remove('is-removing'));
				toast(err.message, true);
			}
		}
		if (bulkBtn) bulkBtn.addEventListener('click', () => remove($$('[data-hy-check]:checked').map((c) => c.value)));
		rows.addEventListener('click', (e) => {
			const d = e.target.closest('[data-hy-delete]');
			if (d) { remove([d.dataset.hyDelete]); return; }
			const o = e.target.closest('[data-hy-open]');
			if (o) openDrawer(o.dataset.hyOpen);
		});
	}

	/* ---------------------------------------------------------------- drawer */
	const drawer = $('#hy-drawer');
	let drawerChart = null, drawerLink = null;
	function closeDrawer() {
		if (!drawer || !drawer.classList.contains('is-open')) return;
		drawer.classList.remove('is-open');
		drawer.setAttribute('aria-hidden', 'true');
		const bd = $('.hy-drawer-backdrop'); if (bd) bd.hidden = true;
	}
	async function openDrawer(id) {
		if (!drawer) return;
		drawer.classList.add('is-open');
		drawer.setAttribute('aria-hidden', 'false');
		const bd = $('.hy-drawer-backdrop'); if (bd) bd.hidden = false;
		$('#hy-drawer-title code').textContent = '…';
		$$('[data-k]', drawer).forEach((el) => { if (!/^(analytics|qr)$/.test(el.dataset.k)) el.textContent = ''; });
		try {
			const r = await post('hydro_link_get', { id });
			const l = r.link, s = r.stats;
			drawerLink = l;
			$('#hy-drawer-title code').textContent = '/' + l.slug;
			const k = (n) => $('[data-k="' + n + '"]', drawer);
			k('total').textContent = fmt(l.clicks);
			k('clicks').textContent = fmt(s.kpi.clicks);
			k('visitors').textContent = fmt(s.kpi.visitors);
			k('delta').innerHTML = delta(s.kpi.clicks, s.prev.clicks);
			k('refs').innerHTML = Object.entries(s.referrers).map(([n, v]) => '<li><span>' + esc(n) + '</span><strong>' + fmt(v) + '</strong></li>').join('') || '<li><span class="hy-muted">no clicks</span></li>';
			k('countries').innerHTML = s.countries.map((c) => '<li><span>' + esc(c.flag + ' ' + c.name) + '</span><strong>' + fmt(c.clicks) + '</strong></li>').join('') || '<li><span class="hy-muted">–</span></li>';
			k('last').textContent = s.last ? 'Last click: ' + s.last + ' · created ' + l.created : 'Created ' + l.created + ' · not clicked yet';
			k('analytics').href = r.analytics;
			k('qr').innerHTML = qrSvg(l.short);
			const f = $('[data-hy-edit]', drawer);
			['id', 'url', 'redirect_type', 'expire', 'password', 'utm_source', 'utm_medium', 'utm_campaign'].forEach((n) => { if (f.elements[n]) f.elements[n].value = l[n] ?? ''; });
			// Passwords are stored as hashes: the field stays empty, the placeholder tells whether one is set
			if (f.elements.password) f.elements.password.placeholder = l.has_password ? 'set – leave empty to keep' : 'none – visitors open /slug?pw=…';
			if (f.elements.password_clear) { f.elements.password_clear.checked = false; f.elements.password_clear.closest('label').hidden = !l.has_password; }
			if (hasChart) {
				if (drawerChart) drawerChart.destroy();
				drawerChart = lineChart($('#hy-drawer-chart'), s.series.labels, s.series.data);
			}
		} catch (err) { toast(err.message, true); closeDrawer(); }
	}
	if (drawer) {
		$$('[data-hy-close]').forEach((b) => b.addEventListener('click', closeDrawer));
		$('[data-hy-edit]', drawer).addEventListener('submit', async (e) => {
			e.preventDefault();
			const f = e.target, btn = $('button[type=submit]', f);
			busy(btn, true);
			try {
				const r = await post('hydro_link_update', Object.fromEntries(new FormData(f).entries()));
				const tr = rows && $('tr[data-id="' + f.elements.id.value + '"]', rows);
				if (tr) { tr.insertAdjacentHTML('afterend', r.html); tr.nextElementSibling.classList.add('is-new'); tr.remove(); }
				toast('Saved');
			} catch (err) { toast(err.message, true); }
			finally { busy(btn, false); }
		});
		$('[data-hy-check-target]', drawer).addEventListener('click', async (e) => {
			const btn = e.currentTarget, out = $('[data-k="check"]', drawer);
			busy(btn, true); out.textContent = 'checking…'; out.className = 'hy-check-result';
			try {
				const r = await post('hydro_link_check', { id: drawerLink.id });
				out.textContent = r.code ? 'HTTP ' + r.code + (r.ok ? ' – reachable' : ' – problem') : 'not reachable' + (r.error ? ': ' + r.error : '');
				out.classList.add(r.ok ? 'ok' : 'bad');
			} catch (err) { out.textContent = err.message; out.classList.add('bad'); }
			finally { busy(btn, false); }
		});
		$$('[data-hy-qr-download]', drawer).forEach((b) => b.addEventListener('click', () => drawerLink && qrDownload(drawerLink.short, b.dataset.hyQrDownload)));
	}

	function delta(now, before) {
		if (!before) return now ? '<span class="hy-delta up">new</span>' : '';
		const p = (now - before) / before * 100;
		const cls = p > 0.05 ? 'up' : (p < -0.05 ? 'down' : '');
		return '<span class="hy-delta ' + cls + '">' + (p > 0 ? '+' : '') + p.toFixed(1) + ' %</span>';
	}

	/* ---------------------------------------------------------------- dashboard */
	const dash = $('#hy-dash-chart');
	if (dash && hasChart) {
		const s = JSON.parse(dash.dataset.series || '{"labels":[],"data":[]}');
		lineChart(dash, s.labels, s.data);
	}
	const live = $('[data-live]');
	if (live) {
		const render = (c, isNew) => '<li data-id="' + c.id + '"' + (isNew ? ' class="is-new"' : '') + '><span class="dashicons dashicons-' + esc(c.device) + '" title="' + esc(c.device) + '"></span>' +
			'<span class="hy-live-main"><a href="' + esc(H.anaUrl + '&link_id=' + c.link_id) + '"><code>/' + esc(c.slug) + '</code></a><span class="hy-live-title">' + esc(c.title) + '</span></span>' +
			'<span class="hy-live-meta">' + esc((c.flag + ' ' + c.ref).trim()) + '</span><time datetime="' + esc(c.time) + '">' + esc(c.ago) + '</time></li>';
		if (!live.children.length) live.innerHTML = '<li class="hy-empty">No clicks yet.</li>';
		const tick = async () => {
			if (document.hidden) return;
			try {
				const list = await post('hydro_live');
				const known = new Set($$('li[data-id]', live).map((li) => li.dataset.id));
				live.innerHTML = list.map((c) => render(c, !known.has(String(c.id)))).join('') || '<li class="hy-empty">No clicks yet.</li>';
			} catch (e) { /* ignore, next tick */ }
		};
		setInterval(tick, 20000);
	}

	/* ---------------------------------------------------------------- analytics */
	const ana = $('#hy-ana');
	if (ana) {
		const charts = {};
		const field = (n) => $('[name="' + n + '"]', ana);
		const today = ana.dataset.today;
		const dayShift = (d, n) => { const x = new Date(d + 'T12:00:00'); x.setDate(x.getDate() + n); return x.toISOString().slice(0, 10); };
		let compare = true, last = null;

		$$('.hy-preset', ana).forEach((b) => b.addEventListener('click', () => {
			$$('.hy-preset', ana).forEach((x) => x.classList.toggle('active', x === b));
			field('to').value = today;
			field('from').value = dayShift(today, -(parseInt(b.dataset.days, 10) - 1));
			run();
		}));
		['from', 'to'].forEach((n) => field(n).addEventListener('change', () => { $$('.hy-preset', ana).forEach((x) => x.classList.remove('active')); run(); }));
		field('slug').addEventListener('change', () => { field('link_id').value = 0; run(); });
		field('country').addEventListener('change', () => run());
		$('[data-hy-run]', ana).addEventListener('click', () => run(true));
		$('[data-hy-compare]').addEventListener('change', (e) => { compare = e.target.checked; if (last) renderTrend(last); });
		$('[data-hy-export]', ana).addEventListener('click', () => {
			const f = $('#hy-export-form');
			['from', 'to', 'link_id', 'country'].forEach((n) => (f.elements[n].value = field(n).value));
			f.submit();
		});

		async function run(fresh) {
			ana.classList.add('is-loading');
			const btn = $('[data-hy-run]', ana); busy(btn, true);
			try {
				const r = await post('hydro_report', {
					from: field('from').value, to: field('to').value, link_id: field('link_id').value,
					slug: field('slug').value, country: field('country').value.toUpperCase(), fresh: fresh ? 1 : '',
				});
				if (r.args.link_id) field('link_id').value = r.args.link_id;
				last = r;
				render(r);
			} catch (err) { toast(err.message, true); }
			finally { ana.classList.remove('is-loading'); busy(btn, false); }
		}

		function renderTrend(r) {
			if (!hasChart) return;
			if (charts.trend) charts.trend.destroy();
			charts.trend = lineChart($('#hy-trend'), r.series.labels, r.series.data, compare ? r.prevSeries.data : null);
		}

		function bars(el, items) {
			const max = Math.max(1, ...items.map((i) => i.v));
			el.innerHTML = items.length ? items.map((i) =>
				'<li><div' + (i.title ? ' title="' + esc(i.title) + '"' : '') + '><span class="hy-bar-label">' + i.label + '</span><span class="hy-bar-val">' + fmt(i.v) + (i.sub ? '<small>' + i.sub + '</small>' : '') + '</span><span class="hy-bar" style="--w:' + (i.v / max * 100).toFixed(1) + '%"></span></div></li>'
			).join('') : '<li class="hy-empty">No data</li>';
		}

		function render(r) {
			const k = r.kpi, total = k.clicks || 0;
			const set = (n, v) => { const el = $('[data-kpi="' + n + '"]', ana); if (el) el.textContent = v; };
			set('clicks', fmt(k.clicks)); set('visitors', fmt(k.visitors)); set('per_day', fmt(k.per_day));
			set('mobile', total ? Math.round(k.mobile / total * 100) + ' %' : '–');
			set('direct', total ? Math.round(k.direct / total * 100) + ' %' : '–');
			set('peak', fmt(r.peak.value));
			set('peak_label', r.peak.value ? 'Peak · ' + shortLabel(r.peak.label) : 'Peak');
			$('[data-delta="clicks"]', ana).innerHTML = delta(k.clicks, r.prev.clicks);
			$('[data-delta="visitors"]', ana).innerHTML = delta(k.visitors, r.prev.visitors);

			renderTrend(r);
			heatmap(r.heatmap);
			if (hasChart) {
				charts.dev = donut($('#hy-devices'), r.devices, charts.dev);
				charts.br = hbar($('#hy-browsers'), r.browsers, charts.br);
				charts.os = hbar($('#hy-os'), r.os, charts.os);
			}
			bars($('#hy-refs'), Object.entries(r.referrers).map(([n, v]) => ({ label: esc(n), v, sub: total ? Math.round(v / total * 100) + '%' : '' })));
			bars($('#hy-countries'), r.countries.slice(0, 15).map((c) => ({ label: esc(c.flag + ' ' + c.name), v: c.clicks, sub: fmt(c.unique) + ' uniq.' })));
			$('#hy-unresolved').textContent = r.unresolved ? fmt(r.unresolved) + ' clicks still without country (resolved hourly).' : '';

			$('#hy-top').innerHTML = r.top.length ? r.top.map((t, i) =>
				'<tr><td class="hy-muted">' + (i + 1) + '</td><td class="hy-col-short"><div class="hy-short"><a href="' + esc(H.home + t.slug) + '" target="_blank" rel="noopener"><code>/' + esc(t.slug) + '</code></a>' +
				'<button type="button" class="hy-icon-btn" data-hy-copy="' + esc(H.home + t.slug) + '" title="Copy"><span class="dashicons dashicons-admin-page"></span></button></div></td>' +
				'<td class="hy-col-url"><div class="hy-url-title">' + esc(t.title) + '</div><a class="hy-url" href="' + esc(H.anaUrl + '&link_id=' + t.id) + '" title="' + esc(t.url) + '">' + esc(t.url) + '</a></td>' +
				'<td class="hy-col-num"><strong>' + fmt(t.clicks) + '</strong></td><td class="hy-col-num">' + fmt(t.unique) + '</td><td class="hy-col-num">' + (total ? (t.clicks / total * 100).toFixed(1) : 0) + ' %</td></tr>'
			).join('') : '<tr class="hy-empty-row"><td colspan="6">No clicks in this period.</td></tr>';

			$('#hy-meta').textContent = r.args.from + ' – ' + r.args.to + ' · ' + (r.args.agg === 'hour' ? 'hourly' : 'daily') +
				' · compared with ' + r.prev.from + ' – ' + r.prev.to + ' · ' + (r.cached ? 'cached ' : 'computed in ' + r.took_ms + ' ms ') + '(' + r.generated + ')';
		}

		function heatmap(m) {
			const el = $('#hy-heat');
			const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
			const max = Math.max(1, ...m.flat());
			let html = '<span></span>';
			for (let h = 0; h < 24; h++) html += '<span class="hy-heat-hour">' + (h % 3 === 0 ? h : '') + '</span>';
			m.forEach((row, d) => {
				html += '<span class="hy-heat-day">' + days[d] + '</span>';
				row.forEach((v, h) => {
					const a = v ? 0.12 + 0.88 * (v / max) : 0;
					const bg = v ? 'background:rgba(' + (a > .6 ? '124,58,237' : '6,182,212') + ',' + a.toFixed(2) + ')' : '';
					html += '<span class="hy-heat-cell" data-v="' + v + '" style="' + bg + '" title="' + days[d] + ' ' + String(h).padStart(2, '0') + ':00 – ' + fmt(v) + ' clicks"></span>';
				});
			});
			el.innerHTML = html;
		}

		run();
	}
})();
