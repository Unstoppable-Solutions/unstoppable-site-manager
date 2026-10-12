/* Unstoppable Site Manager admin. Bulk jobs run one site per request, in sequence. */
(function () {
	'use strict';
	var cfg = window.USMD || {};

	function post(action, data) {
		var body = new FormData();
		body.append('action', 'usmd_' + action);
		body.append('nonce', cfg.nonce);
		Object.keys(data || {}).forEach(function (k) {
			var v = data[k];
			if (Array.isArray(v)) { v.forEach(function (x) { body.append(k + '[]', x); }); } else { body.append(k, v); }
		});
		return fetch(cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (r) { return r.json().catch(function () { return { success: false, data: { message: 'HTTP ' + r.status + ' (not JSON)' } }; }); })
			.catch(function (e) { return { success: false, data: { message: e.message } }; });
	}

	/* ---------- Progress panel: one site at a time, with a bar, counts and a problems list ----------
	 * Shared with plugins.js through window.USMDProgress. Built with textContent only (no HTML injection). */
	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) { n.className = cls; }
		if (text !== undefined) { n.textContent = text; }
		return n;
	}
	function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }
	var Progress = (function () {
		var root = document.getElementById('usmd-progress');
		function reset(kind) {
			root.textContent = '';
			root.hidden = false;
			root.className = 'usmd-progress ' + kind;
		}
		function closeButton() {
			var x = el('button', 'usmd-prog-close', '×');
			x.type = 'button';
			x.setAttribute('aria-label', 'Close');
			x.addEventListener('click', function () { root.hidden = true; });
			return x;
		}
		return {
			/* A single message, e.g. one site's error or "nothing to do". kind: info | ok | bad */
			note: function (text, kind) {
				if (!root) { return; }
				reset('is-note is-' + (kind || 'info'));
				var head = el('div', 'usmd-prog-head');
				head.appendChild(el('span', 'usmd-prog-icon', kind === 'bad' ? '⚠' : (kind === 'ok' ? '✓' : 'ℹ')));
				head.appendChild(el('span', 'usmd-prog-title', text));
				head.appendChild(closeButton());
				root.appendChild(head);
			},
			/* A run over many sites. verb is what happens to each, e.g. "Checking", "Updating". */
			start: function (title, total, verb) {
				var run = { ok: 0, bad: 0, done: 0, problems: [] };
				if (!root) { return { now: function () {}, result: function () {}, finish: function () {} }; }
				reset('is-running');
				root.setAttribute('role', 'status');
				var head = el('div', 'usmd-prog-head');
				var spin = el('span', 'usmd-prog-spinner'); spin.setAttribute('aria-hidden', 'true');
				var titleEl = el('span', 'usmd-prog-title', title);
				var count = el('span', 'usmd-prog-count', '0 / ' + total);
				var close = closeButton(); close.hidden = true;
				head.appendChild(spin); head.appendChild(titleEl); head.appendChild(count); head.appendChild(close);
				var bar = el('progress', 'usmd-prog-bar'); bar.max = total; bar.value = 0;
				var nowEl = el('div', 'usmd-prog-now');
				var stats = el('div', 'usmd-prog-stats');
				var okEl = el('span', 'usmd-prog-ok', '✓ 0 OK');
				var badEl = el('span', 'usmd-prog-bad', ''); badEl.hidden = true;
				var lastEl = el('span', 'usmd-prog-last');
				stats.appendChild(okEl); stats.appendChild(badEl); stats.appendChild(lastEl);
				var probs = el('details', 'usmd-prog-problems'); probs.hidden = true;
				var probSum = el('summary'); var probList = el('ul');
				probs.appendChild(probSum); probs.appendChild(probList);
				[head, bar, nowEl, stats, probs].forEach(function (n) { root.appendChild(n); });

				run.now = function (name) {
					nowEl.textContent = '';
					nowEl.appendChild(document.createTextNode((verb || 'Working on') + ' '));
					nowEl.appendChild(el('strong', '', name));
					nowEl.appendChild(document.createTextNode('…'));
				};
				run.result = function (name, good, detail) {
					run.done++;
					if (good) { run.ok++; } else {
						run.bad++;
						run.problems.push(name);
						var li = el('li'); li.appendChild(el('strong', '', name)); li.appendChild(document.createTextNode(' — ' + (detail || 'failed')));
						probList.appendChild(li);
						probSum.textContent = plural(run.bad, 'problem', 'problems') + ' — show details';
						probs.hidden = false;
						badEl.hidden = false;
						badEl.textContent = '⚠ ' + plural(run.bad, 'problem', 'problems');
					}
					okEl.textContent = '✓ ' + run.ok + ' OK';
					count.textContent = run.done + ' / ' + total;
					bar.value = run.done;
					lastEl.textContent = '';
					lastEl.className = 'usmd-prog-last ' + (good ? 'is-ok' : 'is-bad');
					lastEl.appendChild(el('strong', '', name));
					lastEl.appendChild(document.createTextNode(': ' + (detail || (good ? 'OK' : 'failed'))));
				};
				run.finish = function (doneTitle) {
					root.className = 'usmd-progress is-done ' + (run.bad ? 'is-bad' : 'is-ok');
					spin.className = 'usmd-prog-icon';
					spin.textContent = run.bad ? '⚠' : '✓';
					titleEl.textContent = doneTitle || (run.bad ? 'Done: ' + run.ok + ' OK, ' + plural(run.bad, 'problem', 'problems') : 'Done: all ' + plural(run.ok, 'site', 'sites') + ' OK');
					nowEl.textContent = '';
					lastEl.textContent = '';
					close.hidden = false;
					if (run.bad) { probs.open = true; }
				};
				return run;
			}
		};
	})();
	window.USMDProgress = Progress;
	function log(line, kind) { Progress.note(line, kind); }

	function cardFor(id) { return document.querySelector('.usmd-card[data-id="' + id + '"]'); }
	function nameOf(id) { var c = cardFor(id); var n = c && c.querySelector('.usmd-card-name'); return n ? n.textContent : 'Site ' + id; }

	/* ---------- one-click login: WP Admin opens the client's wp-admin already signed in ----------
	 * The tab is opened straight away (inside the click, so pop-up blockers allow it), then pointed at the
	 * single-use login link once the site has issued one. Ctrl/Cmd/middle-click keeps the plain wp-admin link. */
	function fmt(str) {
		var args = Array.prototype.slice.call(arguments, 1), n = 0;
		return String(str || '').replace(/%(\d+\$)?s/g, function (m, pos) { return args[pos ? parseInt(pos, 10) - 1 : n++]; });
	}
	function loginNote(text, kind) {
		if (document.getElementById('usmd-site-status')) { siteStatus(text, kind === 'bad' ? 'bad' : (kind === 'ok' ? 'ok' : 'busy')); return; }
		Progress.note(text, kind);
	}
	function siteName(id) {
		var h = document.querySelector('.usmd-site-head h1');
		return cardFor(id) ? nameOf(id) : (h ? h.textContent : nameOf(id));
	}
	document.addEventListener('click', function (e) {
		var a = e.target.closest && e.target.closest('a.usmd-login');
		if (!a || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) { return; }
		e.preventDefault();
		var i18n = cfg.i18n || {};
		var id = a.dataset.site, name = siteName(id), fallback = a.href;
		var win = window.open('', '_blank');
		if (win) {
			try {
				win.opener = null;
				win.document.title = i18n.signingIn || 'Signing in…';
				win.document.body.textContent = fmt(i18n.signingInTo || 'Signing in to %s…', name);
			} catch (err) { /* ignore */ }
		}
		a.classList.add('is-busy');
		a.setAttribute('aria-busy', 'true');
		post('login', { site: id }).then(function (res) {
			a.classList.remove('is-busy');
			a.removeAttribute('aria-busy');
			var url = res.success ? res.data.url : ((res.data && res.data.fallback) || fallback);
			if (res.success) {
				loginNote(fmt(i18n.signedIn || 'Opened %1$s signed in as %2$s.', name, res.data.user), 'ok');
			} else {
				loginNote(name + ': ' + fmt(i18n.loginFailed || 'Could not sign in automatically: %s', (res.data && res.data.message) || 'unknown error'), 'bad');
			}
			if (win && !win.closed) {
				win.location.replace(url);
			} else {
				var w2 = window.open(url, '_blank');
				if (w2) { try { w2.opener = null; } catch (err) { /* ignore */ } } else { loginNote(i18n.popup || 'Pop-up blocked.', 'bad'); }
			}
		});
	});

	function replaceCard(id, html) {
		var old = cardFor(id);
		if (!old || !html) { return; }
		var t = document.createElement('div');
		t.innerHTML = html.trim();
		old.replaceWith(t.firstElementChild);
		applyFilter();
	}

	function summarize(res) {
		if (!res.success) { return 'error: ' + (res.data && res.data.message ? res.data.message : 'unknown'); }
		var c = res.data.counts;
		if (!c) { return 'ok'; }
		var s = c.updated + ' updated';
		if (c.failed) { s += ', ' + c.failed + ' failed (' + res.data.errors.join('; ') + ')'; }
		return s;
	}

	function runOne(act, id, items) {
		var card = cardFor(id);
		if (card) { card.classList.add('is-busy'); }
		var data = { site: id };
		if (items) { data.items = items; }
		return post(act, data).then(function (res) {
			if (res.data && res.data.card) { replaceCard(id, res.data.card); } else if (card) { card.classList.remove('is-busy'); }
			return res;
		});
	}

	/* Runs a job over many sites one after another; one failure never stops the rest. */
	function queue(act, ids, button) {
		if (!ids.length) { log('Nothing to do.'); return; }
		if (button) { button.disabled = true; }
		var run = Progress.start((act === 'update' ? 'Updating ' : 'Checking ') + plural(ids.length, 'site', 'sites'), ids.length, act === 'update' ? 'Updating' : 'Checking');
		// Several sites at once: each is its own request and the work happens on the client sites.
		var lanes = Math.min(act === 'refresh' ? 4 : 2, ids.length), next = 0, busy = [];
		function lane() {
			if (next >= ids.length) { return Promise.resolve(); }
			var id = ids[next++], name = nameOf(id);
			busy.push(name);
			run.now(busy.join(', '));
			return runOne(act, id).then(function (res) {
				busy.splice(busy.indexOf(name), 1);
				var good = res.success && !(res.data.counts && res.data.counts.failed);
				var detail = !res.success ? (res.data && res.data.message) || 'error' : (act === 'update' ? summarize(res) : updatesText(id));
				run.result(name, good, detail);
				return lane();
			});
		}
		var all = [];
		for (var i = 0; i < lanes; i++) { all.push(lane()); }
		Promise.all(all).then(function () {
			run.finish();
			if (button) { button.disabled = false; }
		});
	}

	/* "2 plugin updates, 1 theme update, WordPress core update" from a card's data. */
	function updatesBreakdown(c) {
		var parts = [], p = parseInt(c.dataset.pluginUpdates, 10) || 0, t = parseInt(c.dataset.themeUpdates, 10) || 0;
		if (p) { parts.push(plural(p, 'plugin update', 'plugin updates')); }
		if (t) { parts.push(plural(t, 'theme update', 'theme updates')); }
		if (c.dataset.coreUpdate === '1') { parts.push('WordPress core update'); }
		return parts.join(', ');
	}

	/* "2 plugin updates, 1 theme update available" / "up to date", read from the refreshed card. */
	function updatesText(id) {
		var c = cardFor(id), n = c ? parseInt(c.dataset.updates, 10) || 0 : 0;
		return n ? updatesBreakdown(c) + ' available' : 'up to date';
	}

	/* ---------- grid filters ---------- */
	var filter = 'all';
	var search = document.getElementById('usmd-search');
	function applyFilter() {
		var q = search ? search.value.trim().toLowerCase() : '';
		document.querySelectorAll('.usmd-card').forEach(function (c) {
			var show = (!q || c.dataset.search.indexOf(q) !== -1) &&
				(filter === 'all' ||
				(filter === 'updates' && c.dataset.updates !== '0') ||
				(filter === 'plugins' && c.dataset.pluginUpdates !== '0') ||
				(filter === 'themes' && c.dataset.themeUpdates !== '0') ||
				(filter === 'core' && c.dataset.coreUpdate === '1') ||
				(filter === 'problem' && c.dataset.problem === '1') ||
				(filter === 'ssl' && c.dataset.ssl === '1') ||
				(filter === 'connector' && !!c.dataset.connector) ||
				(filter === 'vulns' && c.dataset.vulns && c.dataset.vulns !== '0'));
			c.hidden = !show;
		});
		updateSections();
		updateStats();
	}

	/* Tiles at the top: totals across all sites, recounted from the cards after every change. */
	function updateStats() {
		var box = document.getElementById('usmd-stats');
		if (!box) { return; }
		var t = { sites: 0, plugins: 0, pluginSites: 0, themes: 0, themeSites: 0, core: 0, errors: 0, conn: 0, connManual: 0, vulns: 0, vulnSites: 0 };
		document.querySelectorAll('.usmd-card').forEach(function (c) {
			var p = parseInt(c.dataset.pluginUpdates, 10) || 0, th = parseInt(c.dataset.themeUpdates, 10) || 0;
			t.sites++;
			t.plugins += p; t.pluginSites += p ? 1 : 0;
			t.themes += th; t.themeSites += th ? 1 : 0;
			t.core += c.dataset.coreUpdate === '1' ? 1 : 0;
			t.errors += c.classList.contains('is-problem') && c.querySelector('.usmd-card-error') ? 1 : 0;
			t.conn += c.dataset.connector ? 1 : 0;
			t.connManual += c.dataset.connector === 'manual' ? 1 : 0;
			var vn = parseInt(c.dataset.vulns, 10) || 0;
			t.vulns += vn; t.vulnSites += vn ? 1 : 0;
		});
		var set = function (key, text) { var n = box.querySelector('[data-stat="' + key + '"]'); if (n) { n.textContent = text; } };
		var on = function (n) { return n ? 'on ' + plural(n, 'site', 'sites') : 'all up to date'; };
		set('sites', t.sites); set('plugins', t.plugins); set('themes', t.themes); set('core', t.core); set('errors', t.errors);
		set('plugin-sites', on(t.pluginSites)); set('theme-sites', on(t.themeSites));
		set('core-sites', on(t.core));
		box.querySelector('.usmd-stat-plugins').classList.toggle('has-updates', t.plugins > 0);
		box.querySelector('.usmd-stat-themes').classList.toggle('has-updates', t.themes > 0);
		box.querySelector('.usmd-stat-core').classList.toggle('has-updates', t.core > 0);
		box.querySelector('.usmd-stat-errors').classList.toggle('is-bad', t.errors > 0);
		var vt = box.querySelector('.usmd-stat-vulns');
		if (vt) {
			set('vulns', t.vulns); set('vuln-sites', on(t.vulnSites));
			vt.classList.toggle('is-bad', t.vulns > 0);
		}
		var ct = box.querySelector('.usmd-stat-connector');
		if (ct) {
			set('connector', t.conn);
			set('connector-manual', t.connManual ? t.connManual + (t.connManual === 1 ? ' needs' : ' need') + ' a manual update' : 'update from Add plugin');
			ct.hidden = t.conn === 0;
			ct.classList.toggle('has-updates', t.conn > 0);
			if (!t.conn && filter === 'connector') { setFilter('all'); }
		}
	}

	/* Starred section shows only when it has cards; counts reflect what is visible. */
	function updateSections() {
		var starredGrid = document.getElementById('usmd-grid-starred');
		if (!starredGrid) { return; }
		var starredSection = document.getElementById('usmd-starred-section');
		var allSection = document.getElementById('usmd-all-section');
		var total = starredGrid.querySelectorAll('.usmd-card').length;
		var shown = starredGrid.querySelectorAll('.usmd-card:not([hidden])').length;
		starredSection.hidden = total === 0;
		allSection.querySelector('.usmd-grid-title').hidden = total === 0;
		starredSection.querySelector('[data-count]').textContent = '(' + shown + ')';
		allSection.querySelector('[data-count]').textContent = '(' + document.querySelectorAll('#usmd-grid .usmd-card:not([hidden])').length + ')';
	}
	if (search) { search.addEventListener('input', applyFilter); }
	function setFilter(f) {
		filter = f;
		document.querySelectorAll('.usmd-filters .button, .usmd-stat').forEach(function (x) { x.classList.toggle('is-active', x.dataset.filter === f && f !== 'all'); });
		var allBtn = document.querySelector('.usmd-filters .button[data-filter="all"]');
		if (allBtn) { allBtn.classList.toggle('is-active', f === 'all'); }
		applyFilter();
	}
	document.querySelectorAll('.usmd-filters .button').forEach(function (b) {
		b.addEventListener('click', function () { setFilter(b.dataset.filter); });
	});
	/* Clicking a tile shows just those sites; clicking it again shows all. */
	document.querySelectorAll('.usmd-stat').forEach(function (b) {
		b.addEventListener('click', function () { setFilter(filter === b.dataset.filter ? 'all' : b.dataset.filter); });
	});

	function visibleIds(onlyWithUpdates) {
		return Array.prototype.filter.call(document.querySelectorAll('.usmd-card'), function (c) {
			return !c.hidden && (!onlyWithUpdates || c.dataset.updates !== '0');
		}).map(function (c) { return c.dataset.id; });
	}

	var syncAll = document.getElementById('usmd-sync-all');
	if (syncAll) { syncAll.addEventListener('click', function () { queue('refresh', visibleIds(false), syncAll); }); }

	var updAll = document.getElementById('usmd-update-all');
	if (updAll) {
		updAll.addEventListener('click', function () {
			var ids = visibleIds(true);
			if (!ids.length) { log('No visible sites have updates.', 'ok'); return; }
			if (window.confirm('Install all available updates on ' + ids.length + ' site(s)?')) { queue('update', ids, updAll); }
		});
	}

	/* ---------- single-site buttons (cards and detail page) ---------- */
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.usmd-act');
		if (!btn) { return; }
		var act = btn.dataset.act, id = btn.dataset.site, reload = btn.dataset.reload === '1';

		if (act === 'remove') {
			if (!window.confirm('Remove this site from the dashboard? The connector on the site will be disconnected.')) { return; }
			btn.disabled = true;
			post('remove', { site: id }).then(function () { window.location = cfg.grid; });
			return;
		}
		if (act === 'update' && !window.confirm('Install all available updates on this site?')) { return; }
		if (act === 'thumb') {
			var panel = btn.closest('.usmd-shot-panel');
			if (panel && panel.dataset.thumbSrc) {
				if (shotBusy[panel.dataset.id]) { return; }
				btn.disabled = true; btn.textContent = 'Taking…';
				shotBusy[panel.dataset.id] = true;
				captureInBrowser(panel).then(function () { delete shotBusy[panel.dataset.id]; btn.disabled = false; btn.textContent = 'Retake'; });
				return;
			}
			btn.disabled = true; btn.textContent = 'Taking…';
			post('thumb', { site: id }).then(function (res) {
				if (res.success && res.data.url) { window.location.reload(); return; }
				window.alert(res.data && res.data.message ? res.data.message : 'Could not take a screenshot.');
				btn.disabled = false; btn.textContent = 'Retake';
			});
			return;
		}

		if (reload && act === 'refresh') { refreshSitePage(true); return; }
		btn.disabled = true;
		var label = btn.textContent;
		btn.textContent = act === 'update' ? 'Updating…' : 'Refreshing…';
		runOne(act, id).then(function (res) {
			if (reload) {
				var bad = !res.success || (res.data.counts && res.data.counts.failed);
				reloadSitePage(bad ? { text: 'Update finished with problems: ' + summarize(res), kind: 'bad' } : { text: summarize(res) + '. Up to date as of just now.', kind: 'ok' });
				return;
			}
			if (!res.success) { log(nameOf(id) + ': ' + summarize(res), 'bad'); }
			btn.disabled = false;
			btn.textContent = label;
		});
	});

	/* ---------- site page: check for updates on open, and refresh in place ---------- */
	function siteStatus(text, kind) {
		var s = document.getElementById('usmd-site-status');
		if (!s) { return; }
		s.className = 'usmd-site-status is-' + kind;
		s.textContent = '';
		if (kind === 'busy') { var sp = el('span', 'usmd-prog-spinner'); sp.setAttribute('aria-hidden', 'true'); s.appendChild(sp); }
		s.appendChild(document.createTextNode(text));
	}

	/* Fetch this page again and swap it in, keeping the screenshot panel (it may be mid-capture). */
	function reloadSitePage(message) {
		var current = document.querySelector('.usmd-site');
		return fetch(window.location.href, { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (html) {
			var doc = new DOMParser().parseFromString(html, 'text/html');
			var fresh = doc.querySelector('.usmd-site');
			if (!fresh || !current) { window.location.reload(); return; }
			var oldShot = current.querySelector('.usmd-shot-panel'), newShot = fresh.querySelector('.usmd-shot-panel');
			if (oldShot && newShot) { newShot.replaceWith(oldShot); }
			current.replaceWith(fresh);
			initSitePage();
			if (message) { siteStatus(message.text, message.kind); }
		}).catch(function () { window.location.reload(); });
	}

	var sitePageBusy = false;
	function refreshSitePage() {
		var pageEl = document.querySelector('.usmd-site');
		if (!pageEl || sitePageBusy) { return; }
		sitePageBusy = true;
		var btn = pageEl.querySelector('.usmd-act[data-act="refresh"]');
		if (btn) { btn.disabled = true; }
		siteStatus('Checking this site for updates…', 'busy');
		post('refresh', { site: pageEl.dataset.site }).then(function (res) {
			if (!res.success) {
				sitePageBusy = false;
				if (btn) { btn.disabled = false; }
				siteStatus('Could not check the site: ' + ((res.data && res.data.message) || 'unknown error'), 'bad');
				return;
			}
			var tmp = document.createElement('div'); tmp.innerHTML = (res.data.card || '').trim();
			var c = tmp.firstElementChild, n = c ? parseInt(c.dataset.updates, 10) || 0 : 0;
			return reloadSitePage({ text: (n ? updatesBreakdown(c) + ' available' : 'Up to date') + ' — checked just now.', kind: n ? 'warn' : 'ok' }).then(function () { sitePageBusy = false; });
		});
	}

	function initSitePage() {
		var pageEl = document.querySelector('.usmd-site');
		if (!pageEl) { return; }
		var selBtn = document.getElementById('usmd-update-selected');
		if (selBtn) {
			var sync = function () { selBtn.disabled = !pageEl.querySelector('.usmd-item:checked'); };
			pageEl.querySelectorAll('.usmd-item').forEach(function (b) { b.addEventListener('change', sync); });
			selBtn.addEventListener('click', function () {
				var items = Array.prototype.map.call(pageEl.querySelectorAll('.usmd-item:checked'), function (b) { return b.value; });
				selBtn.disabled = true;
				selBtn.textContent = 'Updating ' + items.length + '…';
				siteStatus('Updating ' + plural(items.length, 'item', 'items') + '…', 'busy');
				runOne('update', pageEl.dataset.site, items).then(function (res) {
					var bad = !res.success || (res.data.counts && res.data.counts.failed);
					reloadSitePage({ text: bad ? 'Finished with problems: ' + summarize(res) : summarize(res) + '.', kind: bad ? 'bad' : 'ok' });
				});
			});
		}
	}
	initSitePage();
	if (document.querySelector('.usmd-site')) { refreshSitePage(); }

	/* ---------- card size (remembered in this browser) ---------- */
	var grid = document.getElementById('usmd-grid');
	function setSize(size) {
		if (!grid || ['s', 'm', 'l'].indexOf(size) === -1) { return; }
		document.querySelectorAll('.usmd-grid').forEach(function (g) {
			g.classList.remove('usmd-size-s', 'usmd-size-m', 'usmd-size-l');
			g.classList.add('usmd-size-' + size);
		});
		document.querySelectorAll('.usmd-sizes .button').forEach(function (b) { b.classList.toggle('is-active', b.dataset.size === size); });
	}
	if (grid) {
		var saved = 's';
		try { saved = window.localStorage.getItem('usmdCardSize') || 's'; } catch (e) {}
		setSize(saved);
		document.querySelectorAll('.usmd-sizes .button').forEach(function (b) {
			b.addEventListener('click', function () {
				setSize(b.dataset.size);
				try { window.localStorage.setItem('usmdCardSize', b.dataset.size); } catch (e) {}
			});
		});
	}

	/* ---------- screenshots taken by the browser (WordPress.com mShots) ----------
	 * mShots refuses many hosting servers but serves browsers, and lets other sites
	 * read its images. The browser fetches the screenshot and uploads a copy to the
	 * dashboard. While mShots is still rendering, it answers with a small
	 * "generating" image, so we wait and ask again. */
	var SHOT_TRIES = 10, SHOT_WAIT = 15000;

	function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

	function shotStatus(el, text, bad) {
		var st = el.querySelector('.usmd-thumb-status');
		if (!st) { return; }
		st.hidden = !text;
		st.textContent = text || '';
		st.classList.toggle('is-bad', !!bad);
	}

	function showImage(el, src) {
		var box = el.querySelector('.usmd-card-thumb, .usmd-shot');
		if (!box) { return; }
		var img = box.querySelector('img');
		if (!img) {
			img = document.createElement('img');
			img.alt = '';
			var empty = box.querySelector('.usmd-thumb-empty');
			if (empty) { empty.remove(); }
			box.insertBefore(img, box.firstChild);
		}
		img.src = src;
	}

	/* Resolves true if the image loads as a normal <img> within 20 seconds. */
	function preload(src) {
		return new Promise(function (resolve) {
			var img = new Image(), t = setTimeout(function () { resolve(false); }, 20000);
			img.onload = function () { clearTimeout(t); resolve(img.naturalWidth >= 600); };
			img.onerror = function () { clearTimeout(t); resolve(false); };
			img.src = src;
		});
	}

	function imageWidth(blob) {
		if (!window.createImageBitmap) { return Promise.resolve(0); }
		return createImageBitmap(blob).then(function (b) { var w = b.width; if (b.close) { b.close(); } return w; }).catch(function () { return 0; });
	}

	/* One try. Resolves {state: 'ok'|'pending'|'error'|'blocked', message, url, card} */
	function shotAttempt(el) {
		return fetch(el.dataset.thumbSrc, { mode: 'cors', cache: 'no-store', credentials: 'omit' }).then(function (res) {
			if (res.status === 403 || res.status === 401) {
				return { state: 'error', message: 'WordPress.com refused the screenshot request (HTTP ' + res.status + ').' };
			}
			if (res.status === 429 || res.status >= 500) { return { state: 'pending', message: 'busy (HTTP ' + res.status + ')' }; }
			if (!res.ok) { return { state: 'error', message: 'The screenshot service answered HTTP ' + res.status + '.' }; }
			return res.blob().then(function (blob) {
				if (res.redirected || !/^image\/(jpeg|png|webp)/.test(blob.type) || blob.size < 5000) { return { state: 'pending' }; }
				return imageWidth(blob).then(function (w) {
					if (w && w < 600) { return { state: 'pending' }; }
					var body = new FormData();
					body.append('action', 'usmd_thumb_upload');
					body.append('nonce', cfg.nonce);
					body.append('site', el.dataset.id);
					body.append('image', blob, 'screenshot.' + (blob.type.split('/')[1] || 'jpg'));
					return fetch(cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' })
						.then(function (r) { return r.json(); })
						.then(function (j) {
							if (j.success && j.data.url) { return { state: 'ok', url: j.data.url, card: j.data.card }; }
							if (j.success && j.data.pending) { return { state: 'pending' }; }
							return { state: 'error', message: (j.data && j.data.message) || 'The dashboard could not save the screenshot.' };
						})
						.catch(function () { return { state: 'error', message: 'The dashboard could not save the screenshot.' }; });
				});
			});
		}).catch(function () {
			// Network failure, or the browser wasn't allowed to read the image.
			return { state: 'blocked', message: 'Your browser could not download the screenshot (network, ad blocker or privacy setting).' };
		});
	}

	function captureInBrowser(el) {
		var n = 0;
		shotStatus(el, 'Taking screenshot…');
		function loop() {
			n++;
			return shotAttempt(el).then(function (r) {
				if (r.state === 'ok') {
					shotStatus(el, '');
					if (el.classList.contains('usmd-card') && r.card) {
						replaceCard(el.dataset.id, r.card);
					} else {
						showImage(el, r.url);
						var err = el.querySelector('.usmd-shot-error'); if (err) { err.hidden = true; }
						var meta = el.querySelector('.usmd-shot-meta .usmd-muted'); if (meta) { meta.textContent = 'Taken just now'; }
					}
					return r;
				}
				if (r.state === 'pending' && n < SHOT_TRIES) {
					shotStatus(el, 'WordPress.com is rendering… (' + n + '/' + SHOT_TRIES + ')');
					return wait(SHOT_WAIT).then(loop);
				}
				var msg = r.state === 'pending' ? 'WordPress.com did not finish the screenshot in time. It will try again next time you open the dashboard.' : r.message;
				// If the browser couldn't read the image, it may still be able to display it.
				var preview = r.state === 'blocked' ? preload(el.dataset.thumbSrc) : Promise.resolve(false);
				return preview.then(function (shown) {
					if (shown) { showImage(el, el.dataset.thumbSrc); }
					shotStatus(el, shown ? 'Preview only (not saved)' : 'Screenshot failed', true);
					var errEl = el.querySelector('.usmd-shot-error');
					if (errEl) { errEl.hidden = false; errEl.textContent = 'Last attempt failed: ' + msg; }
					var box = el.querySelector('.usmd-card-thumb'); if (box) { box.title = 'Screenshot: ' + msg; }
					post('thumb_failed', { site: el.dataset.id, message: msg });
					return r;
				});
			});
		}
		return loop();
	}

	/* ---------- Screenshot queue: two at a time, never the same site twice at once ---------- */
	var shotBusy = {}, shotQueue = [], shotWorkers = 0;

	/* Server-side capture (custom screenshot service). */
	function captureOnServer(el) {
		shotStatus(el, 'Taking screenshot…');
		return post('thumb', { site: el.dataset.id }).then(function (res) {
			if (res.success && res.data.url) {
				shotStatus(el, '');
				if (el.classList.contains('usmd-card') && res.data.card) { replaceCard(el.dataset.id, res.data.card); } else { showImage(el, res.data.url); }
				return;
			}
			if (res.success && res.data.pending) { shotStatus(el, 'Rendering… check back in a minute'); return; }
			shotStatus(el, 'Screenshot failed', true);
			var errEl = el.querySelector('.usmd-shot-error');
			if (errEl) { errEl.hidden = false; errEl.textContent = 'Last attempt failed: ' + ((res.data && res.data.message) || 'unknown error'); }
		});
	}

	function takeShot(el) {
		var live = el.classList.contains('usmd-card') ? (cardFor(el.dataset.id) || el) : el;
		return live.dataset.thumbSrc ? captureInBrowser(live) : captureOnServer(live);
	}

	function shotWorker() {
		var el = shotQueue.shift();
		if (!el) { shotWorkers--; updateRetakeMissing(); return Promise.resolve(); }
		var id = el.dataset.id;
		// A short pause between sites keeps us well under the screenshot service's rate limit.
		var next = function () { delete shotBusy[id]; updateRetakeMissing(); return wait(1500).then(shotWorker); };
		return takeShot(el).then(next, next);
	}

	function queueShots(els) {
		els.forEach(function (el) {
			if (!el || shotBusy[el.dataset.id]) { return; }
			shotBusy[el.dataset.id] = true;
			shotQueue.push(el);
		});
		while (shotWorkers < 2 && shotQueue.length) { shotWorkers++; shotWorker(); }
		updateRetakeMissing();
	}

	/* Cards still without a saved screenshot (never taken, failed, or preview only). */
	function missingCards() {
		return Array.prototype.filter.call(document.querySelectorAll('.usmd-card[data-has-thumb="0"]'), function (c) { return !shotBusy[c.dataset.id]; });
	}
	var retakeMissing = document.getElementById('usmd-retake-missing');
	function updateRetakeMissing() {
		if (!retakeMissing) { return; }
		var n = missingCards().length, busy = Object.keys(shotBusy).length;
		retakeMissing.disabled = !n || busy > 0;
		retakeMissing.textContent = busy ? 'Taking screenshots… (' + busy + ' left)' : 'Retake missing screenshots (' + n + ')';
	}
	if (retakeMissing) { retakeMissing.addEventListener('click', function () { queueShots(missingCards()); }); }

	/* Retake button on each card. */
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.usmd-retake');
		if (!btn) { return; }
		e.preventDefault();
		queueShots([cardFor(btn.dataset.site)]);
	});

	/* Take any due screenshots while the dashboard is open. */
	queueShots(Array.prototype.slice.call(document.querySelectorAll('[data-thumb-src][data-thumb-due="1"]')));
	updateRetakeMissing();

	/* ---------- Settings: test the screenshot service ---------- */
	var testBtn = document.getElementById('usmd-thumb-test');
	if (testBtn) {
		var out = document.getElementById('usmd-thumb-test-result');
		var say = function (text, cls) { out.textContent = text; out.className = 'usmd-test-result' + (cls ? ' ' + cls : ''); };
		testBtn.addEventListener('click', function () {
			var provider = (document.querySelector('input[name=thumb_provider]:checked') || {}).value;
			if (provider === 'off') { say('Screenshots are off.'); return; }
			testBtn.disabled = true;
			say('Testing…');
			var done = function () { testBtn.disabled = false; };
			if (provider === 'custom') {
				post('thumb_test', { template: document.getElementById('usmd-thumb-tpl').value }).then(function (res) {
					say(res.data && res.data.message ? res.data.message : 'No answer.', res.success ? 'is-ok' : 'is-bad'); done();
				});
				return;
			}
			fetch(testBtn.dataset.mshots, { mode: 'cors', cache: 'no-store', credentials: 'omit' }).then(function (res) {
				if (!res.ok) { say('WordPress.com refused the request (HTTP ' + res.status + ').', 'is-bad'); done(); return; }
				return res.blob().then(function (blob) {
					return imageWidth(blob).then(function (w) {
						if (!res.redirected && /^image\/(jpeg|png|webp)/.test(blob.type) && blob.size >= 5000 && (!w || w >= 600)) {
							say('Working. Your browser received a ' + (w ? w + 'px wide ' : '') + 'screenshot and can save it.', 'is-ok');
						} else {
							say('Reachable. WordPress.com is still rendering the test page; click Test again in a minute.', 'is-ok');
						}
						done();
					});
				});
			}).catch(function () {
				say('Your browser could not download from WordPress.com (network, ad blocker or privacy setting). Try another browser, or use another service.', 'is-bad'); done();
			});
		});
	}

	/* ---------- star / unstar (pinned to the top) ---------- */
	function placeCard(card) {
		var target = document.getElementById(card.dataset.starred === '1' ? 'usmd-grid-starred' : 'usmd-grid');
		if (!target) { return; }
		var next = Array.prototype.find.call(target.querySelectorAll('.usmd-card'), function (c) {
			return c !== card && c.dataset.name > card.dataset.name;
		});
		target.insertBefore(card, next || null);
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.usmd-star');
		if (!btn) { return; }
		e.preventDefault();
		var id = btn.dataset.site, makeStarred = btn.getAttribute('aria-pressed') !== 'true';
		btn.setAttribute('aria-pressed', makeStarred ? 'true' : 'false');
		post('star', { site: id, starred: makeStarred ? 1 : 0 }).then(function (res) {
			if (!res.success) { btn.setAttribute('aria-pressed', makeStarred ? 'false' : 'true'); log(nameOf(id) + ': ' + summarize(res)); return; }
			var old = cardFor(id);
			if (!old) { return; }
			var t = document.createElement('div');
			t.innerHTML = res.data.card.trim();
			var fresh = t.firstElementChild;
			fresh.hidden = old.hidden;
			old.remove();
			placeCard(fresh);
			applyFilter();
		});
	});
	updateSections();

	/* ---------- Add Site: open the client's Add Plugins screen, searched for the connector ---------- */
	var openForm = document.getElementById('usmd-connector-open');
	if (openForm) {
		openForm.addEventListener('submit', function (e) {
			e.preventDefault();
			var raw = document.getElementById('usmd-client-url').value.trim();
			if (!/^https?:\/\//i.test(raw)) { raw = 'https://' + raw; }
			var base;
			try { base = new URL(raw); } catch (err) { return; }
			var url = base.origin + base.pathname.replace(/\/+$/, '') + '/wp-admin/plugin-install.php?tab=upload';
			window.open(url, '_blank', 'noopener');
		});
	}

	/* ---------- Add Site: nudge to click Save once a connector ZIP is chosen ---------- */
	var czFile = document.getElementById('usmd-connector-file');
	var czNudge = document.getElementById('usmd-connector-nudge');
	if (czFile && czNudge) {
		czFile.addEventListener('change', function () {
			czNudge.hidden = !czFile.files.length;
			if (czFile.files.length) { document.getElementById('usmd-connector-save').focus(); }
		});
	}

	/* ---------- Security: lock sites to this dashboard's address, switch permissions off ---------- */
	function secReplace(id, data) {
		var tmp = document.createElement('tbody');
		var row = document.querySelector('.usmd-sec-table tr[data-site="' + id + '"]');
		if (row && data.row) { tmp.innerHTML = data.row; if (tmp.firstElementChild) { row.replaceWith(tmp.firstElementChild); } }
		var panel = document.getElementById('usmd-conn-security');
		if (panel && data.panel) { var box = document.createElement('div'); box.innerHTML = data.panel; if (box.firstElementChild) { panel.replaceWith(box.firstElementChild); } }
	}
	function secProgress(text, kind) {
		var p = document.getElementById('usmd-sec-progress');
		if (!p) { if (kind === 'bad') { window.alert(text); } return; }
		p.hidden = false; p.textContent = text; p.className = 'usmd-sec-progress' + (kind ? ' is-' + kind : '');
	}
	document.addEventListener('click', function (e) {
		var i18n = cfg.i18n || {};
		var lock = e.target.closest && e.target.closest('.usmd-sec-lock');
		var off = e.target.closest && e.target.closest('.usmd-sec-off');
		var btn = lock || off;
		if (!btn) { return; }
		e.preventDefault();
		if (!window.confirm(lock ? i18n.secLockAsk : fmt(i18n.secOffAsk, btn.dataset.label))) { return; }
		btn.disabled = true;
		var data = { site: btn.dataset.site, op: lock ? 'lock' : 'restrict' };
		if (off) { data.perm = btn.dataset.perm; }
		post('secure', data).then(function (res) {
			btn.disabled = false;
			if (res.success) { secReplace(btn.dataset.site, res.data); } else { secProgress((res.data && res.data.message) || 'Failed', 'bad'); }
		});
	});
	var lockAll = document.getElementById('usmd-sec-lock-all');
	if (lockAll) {
		lockAll.addEventListener('click', function () {
			var i18n = cfg.i18n || {};
			var rows = Array.prototype.filter.call(document.querySelectorAll('.usmd-sec-table tr[data-site]'), function (r) { return r.dataset.ready === '1' && r.dataset.locked !== '1'; });
			if (!rows.length) { secProgress(i18n.secNone, 'ok'); return; }
			if (!window.confirm(fmt(i18n.secLockAll, rows.length))) { return; }
			lockAll.disabled = true;
			var ok = 0, bad = 0, ids = rows.map(function (r) { return r.dataset.site; });
			var names = rows.map(function (r) { var a = r.querySelector('a'); return a ? a.textContent : ''; });
			(function next(i) {
				if (i >= ids.length) { lockAll.disabled = false; secProgress(fmt(i18n.secDone, ok, bad), bad ? 'bad' : 'ok'); return; }
				secProgress(fmt(i18n.secLocking, i + 1, ids.length, names[i]));
				post('secure', { site: ids[i], op: 'lock' }).then(function (res) {
					if (res.success) { ok++; secReplace(ids[i], res.data); } else {
						bad++;
						var row = document.querySelector('.usmd-sec-table tr[data-site="' + ids[i] + '"] td:nth-child(2)');
						if (row) { var n = document.createElement('div'); n.className = 'usmd-sec-bad'; n.textContent = (res.data && res.data.message) || 'Failed'; row.appendChild(n); }
					}
					next(i + 1);
				});
			})(0);
		});
	}
	document.querySelectorAll('.usmd-select-all').forEach(function (t) { t.addEventListener('focus', function () { t.select(); }); });

	/* ---------- Report: work notes ---------- */
	var workTitle = document.getElementById('usmd-work-title');
	var workCount = document.getElementById('usmd-work-count');
	if (workTitle && workCount) {
		var countWork = function () { workCount.textContent = workTitle.value.length + ' / ' + workTitle.maxLength; };
		workTitle.addEventListener('input', countWork);
		countWork();
	}
	document.querySelectorAll('.usmd-work-delete').forEach(function (f) {
		f.addEventListener('submit', function (e) { if (!window.confirm((cfg.i18n && cfg.i18n.workDelete) || 'Delete this task?')) { e.preventDefault(); } });
	});

	/* ---------- Settings: logo picker and preview ---------- */
	var logoInput = document.getElementById('usmd-logo');
	if (logoInput) {
		var i18nL = cfg.i18n || {};
		var preview = document.getElementById('usmd-logo-preview');
		var status = document.getElementById('usmd-logo-status');
		var clearBtn = document.getElementById('usmd-logo-clear');
		var pickBtn = document.getElementById('usmd-logo-pick');
		var showStatus = function (text, kind) { status.textContent = text || ''; status.className = 'usmd-logo-status' + (kind ? ' is-' + kind : ''); };
		var check = function () {
			var url = logoInput.value.trim();
			clearBtn.hidden = !url;
			if (!url) { preview.hidden = true; preview.removeAttribute('src'); showStatus(''); return; }
			if (location.protocol === 'https:' && /^http:\/\//i.test(url)) { showStatus(i18nL.logoInsecure, 'bad'); }
			preview.onload = function () { preview.hidden = false; if (!/^http:\/\//i.test(url) || location.protocol !== 'https:') { showStatus(i18nL.logoOk, 'ok'); } };
			preview.onerror = function () { preview.hidden = true; showStatus(i18nL.logoBad, 'bad'); };
			preview.src = url;
		};
		var timer;
		logoInput.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(check, 400); });
		logoInput.addEventListener('change', check);
		clearBtn.addEventListener('click', function () { logoInput.value = ''; check(); });
		if (logoInput.value.trim()) {
			// Check the saved logo too, so a broken one is spotted straight away.
			preview.addEventListener('error', function () { preview.hidden = true; showStatus(i18nL.logoBad, 'bad'); }, { once: true });
			if (preview.complete && preview.naturalWidth === 0 && preview.getAttribute('src')) { preview.hidden = true; showStatus(i18nL.logoBad, 'bad'); }
		}
		if (pickBtn && window.wp && wp.media) {
			var frame;
			pickBtn.addEventListener('click', function () {
				if (!frame) {
					frame = wp.media({ title: i18nL.logoTitle, button: { text: i18nL.logoButton }, library: { type: 'image' }, multiple: false });
					frame.on('select', function () {
						var att = frame.state().get('selection').first().toJSON();
						logoInput.value = att.url; check();
					});
				}
				frame.open();
			});
		}
	}

	/* ---------- Client report: a logo that can't load falls back to the agency name ---------- */
	document.querySelectorAll('.usmd-r-logo').forEach(function (img) {
		var fallback = function () {
			img.hidden = true;
			var name = img.parentNode.querySelector('.usmd-r-agency-fallback');
			if (name) { name.hidden = false; }
			var hint = document.getElementById('usmd-logo-broken');
			if (hint) {
				hint.hidden = false;
				var why = document.getElementById('usmd-logo-broken-why');
				if (why && location.protocol === 'https:' && /^http:\/\//i.test(img.getAttribute('src') || '')) {
					why.textContent = 'It starts with http:// but this dashboard uses https://, so the browser blocks it.';
				}
			}
		};
		if (img.complete && img.naturalWidth === 0) { fallback(); } else { img.addEventListener('error', fallback); }
	});

	/* ---------- Client report: print button ---------- */
	var printBtn = document.getElementById('usmd-print');
	if (printBtn) { printBtn.addEventListener('click', function () { window.print(); }); }
})();
