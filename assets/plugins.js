/* Unstoppable Site Manager: Plugins and Add plugin screens.
 * Jobs run one site per request, in sequence; one failing site never stops the rest. */
(function () {
	'use strict';
	var C = window.USMD || {}, P = window.USMDP || {};
	if (!P.sites) { return; }

	/* ---------- helpers ---------- */
	function el(tag, attrs, kids) {
		var n = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'text') { n.textContent = attrs[k]; } else if (k === 'class') { n.className = attrs[k]; } else if (attrs[k] !== null && attrs[k] !== false) { n.setAttribute(k, attrs[k] === true ? '' : attrs[k]); }
		});
		(kids || []).forEach(function (k) { if (k) { n.appendChild(typeof k === 'string' ? document.createTextNode(k) : k); } });
		return n;
	}
	function post(action, data, file) {
		var body = new FormData();
		body.append('action', 'usmd_' + action);
		body.append('nonce', C.nonce);
		Object.keys(data || {}).forEach(function (k) {
			var v = data[k];
			if (Array.isArray(v)) { v.forEach(function (x) { body.append(k + '[]', x); }); } else { body.append(k, v); }
		});
		if (file) { body.append(file.field, file.blob, file.name); }
		return (window.USMDSend ? window.USMDSend(body) : fetch(C.ajax, { method: 'POST', body: body, credentials: 'same-origin' }))
			.then(function (r) { return r.json().catch(function () { return { success: false, data: { message: 'HTTP ' + r.status + ' (the server did not answer with JSON)' } }; }); })
			.catch(function (e) { return { success: false, data: { message: e.message } }; });
	}
	function fmt(s) { var a = Array.prototype.slice.call(arguments, 1), i = 0; return s.replace(/%(\d)\$[sd]|%[sd]/g, function (m, n) { return a[n ? n - 1 : i++]; }); }
	function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }
	var progressEl = document.getElementById('usmd-progress');
	var Progress = window.USMDProgress;
	function log(line, kind) { Progress.note(line, kind || 'bad'); }
	var siteById = {};
	P.sites.forEach(function (s) { siteById[s.id] = s; });
	function runSequential(jobs, fn) {
		return jobs.reduce(function (p, job, i) { return p.then(function () { return fn(job, i); }); }, Promise.resolve());
	}

	/* ---------- site picker ---------- */
	var picked = {};
	P.sites.forEach(function (s) { picked[s.id] = true; });
	var pickerList = document.getElementById('usmd-picker-list');
	var pickerSearch = document.getElementById('usmd-picker-search');
	function pickedIds() { return P.sites.filter(function (s) { return picked[s.id]; }).map(function (s) { return s.id; }); }
	function renderPicker() {
		var q = (pickerSearch.value || '').toLowerCase();
		pickerList.textContent = '';
		P.sites.forEach(function (s) {
			if (q && (s.name + ' ' + s.host).toLowerCase().indexOf(q) === -1) { return; }
			var box = el('input', { type: 'checkbox', checked: !!picked[s.id] });
			box.addEventListener('change', function () { picked[s.id] = box.checked; pickerChanged(); });
			pickerList.appendChild(el('li', { class: s.offline ? 'is-offline' : '' }, [
				el('label', {}, [box, el('span', { class: 'usmd-picker-name' }, [
					s.starred ? el('span', { class: 'usmd-star-icon', 'aria-label': 'Starred', text: '★ ' }) : null,
					el('strong', { text: s.name }), el('br'), el('span', { class: 'usmd-muted', text: s.host + (s.offline ? ' · offline' : '') })
				])])
			]));
		});
		pickerChanged();
	}
	function pickerChanged() {
		document.getElementById('usmd-picker-count').textContent = fmt('%d of %d sites', pickedIds().length, P.sites.length);
		if (typeof onPickerChange === 'function') { onPickerChange(); }
	}
	var onPickerChange = null;
	pickerSearch.addEventListener('input', renderPicker);
	document.getElementById('usmd-pick-all').addEventListener('click', function () { P.sites.forEach(function (s) { picked[s.id] = true; }); renderPicker(); });
	document.getElementById('usmd-pick-none').addEventListener('click', function () { picked = {}; renderPicker(); });
	document.getElementById('usmd-pick-starred').addEventListener('click', function () { picked = {}; P.sites.forEach(function (s) { if (s.starred) { picked[s.id] = true; } }); renderPicker(); });
	var pickClient = document.getElementById('usmd-pick-client');
	if (pickClient) {
		pickClient.addEventListener('change', function () {
			var cid = parseInt(pickClient.value, 10) || 0;
			if (!cid) { return; }
			picked = {};
			P.sites.forEach(function (s) { if (s.client === cid) { picked[s.id] = true; } });
			renderPicker();
		});
		// Arriving from a client (?client=ID): start with just their sites.
		var fromUrl = parseInt(new URLSearchParams(window.location.search).get('client'), 10) || 0;
		if (fromUrl && pickClient.querySelector('option[value="' + fromUrl + '"]')) { pickClient.value = String(fromUrl); pickClient.dispatchEvent(new Event('change')); }
	}

	if (P.view === 'add') { initAdd(); } else { initManage(); }
	renderPicker();

	/* =====================================================================
	 * Manage plugins
	 * ===================================================================== */
	function initManage() {
		var list = document.getElementById('usmd-plugin-list');
		var filter = document.getElementById('usmd-plugin-filter');
		var sort = document.getElementById('usmd-plugin-sort');
		var show = document.getElementById('usmd-plugin-show');
		var selectAll = document.getElementById('usmd-plugin-all');
		var buttons = document.querySelectorAll('.usmd-plugin-buttons [data-op]');
		var selected = {}, plugins = P.plugins || [], open = {};

		function isProtected(p) { return P.protect.indexOf(p.slug) !== -1; }
		function stats(p) {
			var picks = pickedIds(), active = 0, updates = 0, latest = '', versions = {}, inScope = 0;
			p.sites.forEach(function (s) {
				if (s.active) { active++; }
				if (s.update) { updates++; latest = s.update; }
				versions[s.version] = (versions[s.version] || 0) + 1;
				if (picks.indexOf(s.id) !== -1) { inScope++; }
			});
			return { installed: p.sites.length, active: active, updates: updates, latest: latest, versions: versions, inScope: inScope };
		}
		function visible() {
			var q = filter.value.trim().toLowerCase(), mode = show.value;
			var rows = plugins.filter(function (p) {
				var st = stats(p);
				if (q && (p.name + ' ' + p.file).toLowerCase().indexOf(q) === -1) { return false; }
				if (mode === 'updates') { return st.updates > 0; }
				if (mode === 'active') { return st.active > 0; }
				if (mode === 'inactive') { return st.active === 0; }
				return true;
			});
			rows.sort(function (a, b) {
				var sa = stats(a), sb = stats(b);
				if (sort.value === 'installs' && sb.installed !== sa.installed) { return sb.installed - sa.installed; }
				if (sort.value === 'updates' && sb.updates !== sa.updates) { return sb.updates - sa.updates; }
				return a.name.localeCompare(b.name);
			});
			return rows;
		}
		function tile(name) {
			var h = 0; for (var i = 0; i < name.length; i++) { h = (h * 31 + name.charCodeAt(i)) % 8; }
			return el('span', { class: 'usmd-plugin-tile usmd-tile-' + h, 'aria-hidden': 'true', text: (name.replace(/[^A-Za-z0-9]/g, '').slice(0, 2) || '?').toUpperCase() });
		}
		function render() {
			var rows = visible();
			list.textContent = '';
			rows.forEach(function (p) {
				var st = stats(p), prot = isProtected(p);
				var box = el('input', { type: 'checkbox', checked: !!selected[p.file], disabled: prot, 'aria-label': p.name });
				box.addEventListener('change', function () { if (box.checked) { selected[p.file] = true; } else { delete selected[p.file]; } selectionChanged(); });
				var versionText = Object.keys(st.versions).map(function (v) { return v + ' (' + plural(st.versions[v], 'site', 'sites') + ')'; }).join(' · ');
				var toggle = el('button', { type: 'button', class: 'button-link usmd-plugin-toggle', 'aria-expanded': open[p.file] ? 'true' : 'false', text: open[p.file] ? 'Hide sites ▴' : 'Show sites ▾' });
				toggle.addEventListener('click', function () { open[p.file] = !open[p.file]; render(); });
				var li = el('li', { class: 'usmd-plugin-row' + (selected[p.file] ? ' is-selected' : '') }, [
					el('div', { class: 'usmd-plugin-check' }, [box]),
					tile(p.name),
					el('div', { class: 'usmd-plugin-info' }, [
						el('div', { class: 'usmd-plugin-name' }, [
							el('strong', { text: p.name }),
							prot ? el('span', { class: 'usmd-pill usmd-pill-ok', title: 'The dashboard never deactivates or deletes its own connector.', text: 'Connector' }) : null,
							st.updates ? el('span', { class: 'usmd-pill usmd-pill-warn', text: 'Update to ' + st.latest + ' on ' + plural(st.updates, 'site', 'sites') }) : null
						]),
						el('div', { class: 'usmd-muted usmd-plugin-meta', text: 'Version ' + versionText }),
						toggle
					]),
					el('div', { class: 'usmd-plugin-counts' }, [
						el('span', {}, ['Active on ', el('strong', { text: plural(st.active, 'site', 'sites') })]),
						el('span', {}, ['Installed on ', el('strong', { text: plural(st.installed, 'site', 'sites') })])
					])
				]);
				if (open[p.file]) {
					var detail = el('ul', { class: 'usmd-plugin-sites' });
					p.sites.forEach(function (s) {
						var site = siteById[s.id] || { name: 'Site ' + s.id, host: '' };
						detail.appendChild(el('li', { class: picked[s.id] ? '' : 'is-out-of-scope' }, [
							el('span', { class: 'usmd-plugin-site-name', text: site.name }),
							el('span', { class: 'usmd-muted', text: s.version }),
							el('span', { class: s.active ? 'usmd-on' : 'usmd-muted', text: s.active ? 'Active' : 'Inactive' }),
							s.update ? el('span', { class: 'usmd-pill usmd-pill-warn', text: '→ ' + s.update }) : el('span')
						]));
					});
					li.appendChild(detail);
				}
				list.appendChild(li);
			});
			if (!rows.length) { list.appendChild(el('li', { class: 'usmd-muted usmd-plugin-empty', text: 'No plugins match.' })); }
			document.getElementById('usmd-plugin-count').textContent = fmt('Showing %d of %d plugins', rows.length, plugins.length);
			selectionChanged();
		}
		function selectedFiles() { return Object.keys(selected); }
		function selectionChanged() {
			var n = selectedFiles().length;
			document.getElementById('usmd-selected-count').textContent = n ? plural(n, 'plugin', 'plugins') + ' selected' : 'None selected';
			buttons.forEach(function (b) { b.disabled = !n || !pickedIds().length; });
			var vis = visible().filter(function (p) { return !isProtected(p); });
			selectAll.checked = vis.length > 0 && vis.every(function (p) { return selected[p.file]; });
		}
		selectAll.addEventListener('change', function () {
			visible().forEach(function (p) { if (isProtected(p)) { return; } if (selectAll.checked) { selected[p.file] = true; } else { delete selected[p.file]; } });
			render();
		});
		[filter, sort, show].forEach(function (c) { c.addEventListener(c === filter ? 'input' : 'change', render); });
		onPickerChange = function () { selectionChanged(); };

		var verbs = { activate: 'Activate', deactivate: 'Deactivate', update: 'Update', delete: 'Delete' };
		function wants(op, s) {
			if (op === 'activate') { return !s.active; }
			if (op === 'deactivate') { return s.active; }
			if (op === 'update') { return !!s.update; }
			return true;
		}
		buttons.forEach(function (b) {
			b.addEventListener('click', function () {
				var op = b.dataset.op, files = selectedFiles(), picks = pickedIds(), bySite = {};
				plugins.forEach(function (p) {
					if (files.indexOf(p.file) === -1 || isProtected(p)) { return; }
					p.sites.forEach(function (s) {
						if (picks.indexOf(s.id) !== -1 && wants(op, s)) { (bySite[s.id] = bySite[s.id] || []).push(p.file); }
					});
				});
				var jobs = Object.keys(bySite).map(Number);
				if (!jobs.length) { window.alert(P.i18n.nothingToDo); return; }
				var question = op === 'delete' ? fmt(P.i18n.confirmDelete, files.length, jobs.length) : fmt(P.i18n.confirmBulk, verbs[op], files.length, jobs.length);
				if (!window.confirm(question)) { return; }
				buttons.forEach(function (x) { x.disabled = true; });
				var run = Progress.start(verbs[op] + ' ' + plural(files.length, 'plugin', 'plugins') + ' on ' + plural(jobs.length, 'site', 'sites'), jobs.length, { activate: 'Activating on', deactivate: 'Deactivating on', update: 'Updating on', delete: 'Deleting from' }[op]);
				runSequential(jobs, function (id) {
					run.now((siteById[id] || {}).name || ('Site ' + id));
					var req = op === 'update'
						? post('update', { site: id, items: bySite[id].map(function (f) { return 'plugin:' + f; }) })
						: post('plugin_action', { site: id, op: op, files: bySite[id] });
					return req.then(function (res) {
						var name = (siteById[id] || {}).name || ('Site ' + id), line;
						var good = true;
						if (!res.success) { good = false; line = (res.data && res.data.message) || 'error'; } else {
							var results = res.data.results || [], counts = {}, errs = [];
							results.forEach(function (r) { counts[r.result] = (counts[r.result] || 0) + 1; if (r.result === 'failed') { errs.push((r.name || r.id) + ': ' + (r.error || 'failed')); } });
							line = Object.keys(counts).map(function (k) { return counts[k] + ' ' + k.replace('_', ' '); }).join(', ') || 'nothing to do';
							if (errs.length) { good = false; line = errs.join('; '); }
						}
						run.result(name, good, line);
					});
				}).then(function () {
					run.finish();
					return post('plugin_index', {}).then(function (res) {
						if (res.success) { plugins = P.plugins = res.data.plugins; }
						selected = {};
						render();
					});
				});
			});
		});
		render();
	}

	/* =====================================================================
	 * Add plugin
	 * ===================================================================== */
	function initAdd() {
		var queue = [], seq = 0;
		var queueEl = document.getElementById('usmd-queue');
		var go = document.getElementById('usmd-install-go');

		/* tabs */
		document.querySelectorAll('.usmd-tab').forEach(function (t) {
			t.addEventListener('click', function () {
				document.querySelectorAll('.usmd-tab').forEach(function (x) { x.classList.toggle('is-active', x === t); });
				document.querySelectorAll('.usmd-tab-panel').forEach(function (p) { p.hidden = p.dataset.panel !== t.dataset.tab; });
			});
		});

		var statusEl = document.getElementById('usmd-install-status'), running = false, showingResult = false;
		function status(text, cls) {
			statusEl.className = 'usmd-install-status' + (cls ? ' ' + cls : '');
			statusEl.textContent = '';
			if (text) { statusEl.appendChild(document.createTextNode(text)); }
			return statusEl;
		}
		function readyItems() { return queue.filter(function (q) { return q.ready; }); }
		function refreshGo() {
			var n = readyItems().length, s = pickedIds().length;
			go.disabled = running || !n || !s || queue.some(function (q) { return !q.ready; });
			go.textContent = n ? fmt('Install %s on %s', plural(n, 'plugin', 'plugins'), plural(s, 'site', 'sites')) : 'Install';
			if (running || showingResult) { return; } // Keep a finished run's result on screen until the list changes.
			if (n && !s) { status('Tick at least one site on the left.', 'is-bad'); } else if (n) { status('Nothing is installed yet: click Install to put ' + (n === 1 ? 'it' : 'them') + ' on your sites.', 'is-hint'); } else if (!/is-done/.test(statusEl.className)) { status(''); }
		}
		onPickerChange = refreshGo;
		function renderQueue() {
			queueEl.textContent = '';
			if (!queue.length) { queueEl.appendChild(el('li', { class: 'usmd-muted usmd-queue-empty', text: 'Nothing selected yet. Add plugins from the directory, a URL or a ZIP.' })); }
			queue.forEach(function (q) {
				var rm = el('button', { type: 'button', class: 'button-link usmd-queue-remove', 'aria-label': 'Remove ' + q.name, text: '✕' });
				rm.addEventListener('click', function () { showingResult = false; queue = queue.filter(function (x) { return x !== q; }); renderQueue(); syncRepoButtons(); });
				queueEl.appendChild(el('li', { class: q.ready ? '' : 'is-pending' }, [
					el('span', { class: 'usmd-queue-type', text: q.type === 'wporg' ? 'Directory' : (q.type === 'zip' ? 'ZIP' : 'URL') }),
					el('span', { class: 'usmd-queue-name', text: q.name + (q.ready ? '' : ' — uploading…') }),
					rm
				]));
			});
			refreshGo();
		}
		function add(item) {
			if (item.slug && queue.some(function (q) { return q.slug === item.slug; })) { return; }
			showingResult = false;
			item.key = ++seq;
			queue.push(item);
			renderQueue();
		}

		/* --- directory search --- */
		var results = document.getElementById('usmd-repo-results'), lastQ = '', page = 1;
		function syncRepoButtons() {
			results.querySelectorAll('[data-slug]').forEach(function (b) {
				var on = queue.some(function (q) { return q.slug === b.dataset.slug; });
				b.textContent = on ? '✓ On install list' : 'Add to install list';
				b.classList.toggle('button-primary', !on);
			});
		}
		function stars(r) { var n = Math.round(r / 20); return '★★★★★'.slice(0, n) + '☆☆☆☆☆'.slice(0, 5 - n); }
		function installs(n) { return n >= 1000000 ? Math.floor(n / 1000000) + '+ million' : (n >= 1000 ? Math.floor(n / 1000) + ',000+' : (n ? n + '+' : 'Fewer than 10')); }
		function search(append) {
			if (!append) { page = 1; results.textContent = ''; }
			var more = results.querySelector('.usmd-repo-more'); if (more) { more.remove(); }
			var wait = el('p', { class: 'usmd-muted', text: 'Searching…' }); results.appendChild(wait);
			post('repo_search', { q: lastQ, paged: page }).then(function (res) {
				wait.remove();
				if (!res.success) { results.appendChild(el('p', { class: 'is-bad', text: res.data.message })); return; }
				if (!res.data.plugins.length && page === 1) { results.appendChild(el('p', { class: 'usmd-muted', text: 'No plugins found.' })); return; }
				var grid = results.querySelector('.usmd-repo-grid') || results.appendChild(el('div', { class: 'usmd-repo-grid' }));
				res.data.plugins.forEach(function (p) {
					var btn = el('button', { type: 'button', class: 'button button-primary', 'data-slug': p.slug, text: 'Add to install list' });
					btn.addEventListener('click', function () {
						if (queue.some(function (q) { return q.slug === p.slug; })) { showingResult = false; queue = queue.filter(function (q) { return q.slug !== p.slug; }); renderQueue(); } else { add({ type: 'wporg', slug: p.slug, name: p.name, ready: true }); }
						syncRepoButtons();
					});
					grid.appendChild(el('div', { class: 'usmd-repo-card' }, [
						p.icon ? el('img', { src: p.icon, alt: '', width: 64, height: 64, loading: 'lazy' }) : el('span', { class: 'usmd-plugin-tile', text: p.name.slice(0, 2).toUpperCase() }),
						el('div', { class: 'usmd-repo-body' }, [
							el('strong', { text: p.name }),
							el('span', { class: 'usmd-muted', text: 'By ' + p.author }),
							el('p', { text: p.desc }),
							el('span', { class: 'usmd-repo-meta', text: stars(p.rating) + '  ·  ' + installs(p.installs) + ' active installs  ·  v' + p.version + (p.tested ? '  ·  tested to ' + p.tested : '') })
						]),
						btn
					]));
				});
				syncRepoButtons();
				if (res.data.page < res.data.pages) {
					var m = el('button', { type: 'button', class: 'button usmd-repo-more', text: 'More results' });
					m.addEventListener('click', function () { page++; search(true); });
					results.appendChild(m);
				}
			});
		}
		document.getElementById('usmd-repo-form').addEventListener('submit', function (e) {
			e.preventDefault();
			lastQ = document.getElementById('usmd-repo-q').value.trim();
			if (lastQ.length >= 2) { search(false); }
		});

		/* --- URL --- */
		document.getElementById('usmd-url-form').addEventListener('submit', function (e) {
			e.preventDefault();
			var input = document.getElementById('usmd-url'), url = input.value.trim();
			if (!/^https?:\/\/.+/i.test(url)) { input.focus(); return; }
			add({ type: 'url', url: url, name: decodeURIComponent(url.split('/').pop().split('?')[0]) || url, ready: true });
			input.value = '';
		});

		/* --- ZIP drop / choose --- */
		var zone = document.getElementById('usmd-dropzone'), picker = document.getElementById('usmd-zip-input');
		function upload(files) {
			Array.prototype.forEach.call(files, function (f) {
				if (!/\.zip$/i.test(f.name)) { log(f.name + ': only .zip files can be installed.'); return; }
				var item = { type: 'zip', name: f.name, ready: false };
				add(item);
				post('package_upload', {}, { field: 'zip', blob: f, name: f.name }).then(function (res) {
					if (!res.success) {
						queue = queue.filter(function (q) { return q !== item; });
						log(f.name + ': ' + ((res.data && res.data.message) || 'upload failed'));
					} else {
						item.token = res.data.token; item.name = res.data.name || f.name; item.ready = true;
					}
					renderQueue();
				});
			});
		}
		['dragenter', 'dragover'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('is-over'); }); });
		['dragleave', 'drop'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.remove('is-over'); }); });
		zone.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files.length) { upload(e.dataTransfer.files); } });
		picker.addEventListener('change', function () { upload(picker.files); picker.value = ''; });

		/* --- install --- */
		go.addEventListener('click', function () {
			var items = readyItems().map(function (q) { return { type: q.type, slug: q.slug, url: q.url, token: q.token, name: q.name }; });
			var sites = pickedIds();
			if (!window.confirm(fmt('Install %s on %s?', plural(items.length, 'plugin', 'plugins'), plural(sites.length, 'site', 'sites')))) { return; }
			running = true; go.disabled = true;
			status('Installing… 0 of ' + plural(sites.length, 'site', 'sites') + ' done', 'is-running');
			var activate = document.getElementById('usmd-opt-activate').checked ? 1 : 0;
			var overwrite = document.getElementById('usmd-opt-overwrite').checked ? 1 : 0;
			var run = Progress.start('Installing ' + plural(items.length, 'plugin', 'plugins') + ' on ' + plural(sites.length, 'site', 'sites'), sites.length, 'Installing on');
			var ok = 0, bad = 0;
			runSequential(sites, function (id, i) {
				run.now((siteById[id] || {}).name || ('Site ' + id));
				return post('plugin_install', { site: id, items: JSON.stringify(items), activate: activate, overwrite: overwrite }).then(function (res) {
					var name = (siteById[id] || {}).name || ('Site ' + id), parts, problem = false;
					if (!res.success) { bad++; problem = true; parts = [(res.data && res.data.message) || 'error']; } else {
						parts = (res.data.results || []).map(function (r) {
							if (r.result === 'installed') {
								if (r.error) { problem = true; return (r.name || r.id) + ' installed (' + r.error + ')'; }
								return (r.name || r.id) + ' ' + (r.version ? r.version + ' ' : '') + 'installed' + (r.activated ? ' and activated' : '');
							}
							problem = true;
							return (r.name || r.id) + ': ' + (r.error || r.result);
						});
						if (problem) { bad++; } else { ok++; }
					}
					run.result(name, !problem, parts.join('; '));
					status('Installing… ' + (i + 1) + ' of ' + plural(sites.length, 'site', 'sites') + ' done' + (bad ? ', ' + bad + ' with problems' : ''), 'is-running');
				});
			}).then(function () {
				run.finish();
				if (!document.getElementById('usmd-back-to-plugins')) {
					var back = el('a', { class: 'button', id: 'usmd-back-to-plugins', href: P.manage, text: 'Back to Plugins' });
					progressEl.parentNode.insertBefore(el('p', {}, [back]), progressEl.nextSibling);
				}
				running = false;
				if (!bad) { queue = []; renderQueue(); syncRepoButtons(); }
				showingResult = true;
				var s = status((bad ? 'Done: ' + plural(ok, 'site', 'sites') + ' OK, ' + bad + ' with problems. ' : 'Done: installed on ' + plural(ok, 'site', 'sites') + '. '), bad ? 'is-bad is-done' : 'is-ok is-done');
				var details = el('button', { type: 'button', class: 'button-link', text: 'See details' });
				details.addEventListener('click', function () { progressEl.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
				s.appendChild(details);
				refreshGo();
			});
		});
		renderQueue();
	}
})();
