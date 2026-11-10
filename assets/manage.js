/* Compressino: the Images section (list and bulk run) and the Compressino line in the media popup. */
(function () {
	'use strict';
	var C = window.compressinoManage;
	if (!C) { return; }
	var T = C.i18n;

	function post(action, data) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', C.nonce);
		Object.keys(data || {}).forEach(function (key) {
			var value = data[key];
			if (Array.isArray(value)) { value.forEach(function (v) { body.append(key + '[]', v); }); }
			else { body.append(key, value); }
		});
		return fetch(C.ajax, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				if (!json || !json.success) { throw new Error((json && json.data && json.data.message) || T.failed); }
				if (json.data && json.data.originals) { setOriginals(json.data.originals); }
				return json.data;
			});
	}
	function esc(value) { var d = document.createElement('div'); d.textContent = value == null ? '' : String(value); return d.innerHTML; }
	function size(bytes) {
		if (bytes >= 1048576) { return (bytes / 1048576).toFixed(1) + ' MB'; }
		return Math.max(1, Math.round(bytes / 1024)) + ' KB';
	}
	function fill(text, values) {
		var i = 0;
		return text.replace(/%(\d)\$[sd]|%[sd]/g, function (match, n) { return n ? values[n - 1] : values[i++]; }).replace('%%', '%');
	}

	/* ---------- Estimates: what compressing would give. Worked out on the server, two images at a time. ---------- */
	var estimates = {}, waiting = [], busy = false, listeners = [];
	function estimate(id) {
		id = String(id);
		if (estimates[id]) { notify(estimates[id]); return; }
		if (waiting.indexOf(id) === -1) { waiting.push(id); }
		pump();
	}
	function pump() {
		if (busy || !waiting.length) { return; }
		busy = true;
		var ids = waiting.splice(0, 2);
		post('compressino_estimate', { ids: ids }).then(function (data) {
			data.estimates.forEach(function (e) { estimates[String(e.id)] = e; notify(e); });
		}).catch(function () {
			ids.forEach(function (id) { notify({ id: id, error: T.failed }); });
		}).then(function () { busy = false; pump(); });
	}
	function notify(e) { listeners.forEach(function (fn) { fn(e); }); }

	var page = null;

	/* ---------- Media popup and Edit Media screen ---------- */
	function scanFields() {
		document.querySelectorAll('[data-compressino-estimate]:not([data-asked])').forEach(function (span) {
			span.setAttribute('data-asked', '1');
			estimate(span.getAttribute('data-compressino-estimate'));
		});
	}
	listeners.push(function (e) {
		document.querySelectorAll('[data-compressino-estimate="' + e.id + '"]').forEach(function (span) {
			span.textContent = e.error ? '' : e.text;
			if (!e.error && !e.smaller) {
				var field = span.closest('[data-compressino-field]');
				field.querySelectorAll('.compressino-field__button,.compressino-field__help').forEach(function (el) { el.hidden = true; });
			}
		});
	});
	if (window.MutationObserver) {
		var timer = null;
		new MutationObserver(function () { clearTimeout(timer); timer = setTimeout(scanFields, 150); }).observe(document.body, { childList: true, subtree: true });
	}
	scanFields();

	document.addEventListener('click', function (event) {
		var button = event.target.closest ? event.target.closest('[data-compressino-action]') : null;
		if (!button) { return; }
		event.preventDefault();
		var field = button.closest('[data-compressino-field]');
		var id = button.getAttribute('data-id');
		var restore = button.getAttribute('data-compressino-action') === 'restore';
		var status = field && field.querySelector('[data-compressino-status]');
		button.disabled = true;
		if (status) { status.textContent = restore ? T.restoring : T.compressing; }
		post(restore ? 'compressino_restore' : 'compressino_compress', { ids: [id] }).then(function (data) {
			var result = data.results[0];
			delete estimates[String(id)];
			if (field && result && result.field) {
				var wrap = document.createElement('div');
				wrap.innerHTML = result.field;
				var fresh = wrap.firstElementChild;
				field.replaceWith(fresh);
				if (result.result !== 'done') { fresh.querySelector('[data-compressino-status]').textContent = result.message; }
			}
			// Let the media popup show the new file name, size and thumbnail.
			if (result && result.result === 'done' && window.wp && wp.media && wp.media.attachment) {
				var attachment = wp.media.attachment(id);
				var request = attachment && attachment.fetch();
				if (request && request.done) {
					request.done(function () {
						// The Media Library popup does not redraw on a new file by itself.
						var frame = wp.media.frame;
						if (frame && typeof frame.rerender === 'function' && frame.model === attachment) { frame.rerender(attachment); }
					});
				}
			}
			if (page) { page.counts(data.counts); }
		}).catch(function (error) {
			button.disabled = false;
			if (status) { status.textContent = error.message; }
		});
	});

	/* ---------- Clean up originals box ---------- */
	var cleanup = document.querySelector('[data-cleanup]');
	function setOriginals(o) {
		if (!cleanup || !o) { return; }
		cleanup.querySelector('[data-cleanup-count]').textContent = o.count_text;
		cleanup.querySelector('[data-cleanup-size]').textContent = o.size;
		var run = cleanup.querySelector('[data-cleanup-run]');
		if (o.count) { cleanup.hidden = false; run.hidden = false; cleanup.querySelector('[data-cleanup-status]').textContent = ''; }
		else { run.hidden = true; if (!cleanup.querySelector('[data-cleanup-status]').textContent) { cleanup.hidden = true; } }
	}
	if (cleanup) {
		var cleanupRun = cleanup.querySelector('[data-cleanup-run]');
		var cleanupStatus = cleanup.querySelector('[data-cleanup-status]');
		cleanupRun.addEventListener('click', function () {
			if (!window.confirm(cleanupRun.getAttribute('data-confirm'))) { return; }
			cleanupRun.disabled = true;
			cleanupStatus.textContent = T.deleting;
			var body = new FormData();
			body.append('action', 'compressino_delete_originals');
			body.append('nonce', C.nonce);
			fetch(C.ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); }).then(function (json) {
				if (!json || !json.success) { throw new Error((json && json.data && json.data.message) || T.failed); }
				cleanupStatus.textContent = json.data.message;
				setOriginals(json.data.originals);
				// Restore buttons in the list are no longer valid.
				if (page && page.reload) { page.reload(); }
			}).catch(function (error) { cleanupStatus.textContent = error.message; }).then(function () { cleanupRun.disabled = false; });
		});
	}

	/* ---------- Images section on the Compressino screen ---------- */
	var root = document.querySelector('[data-compressino-images]');
	if (!root) { return; }

	var list = root.querySelector('[data-list]');
	var empty = root.querySelector('[data-empty]');
	var more = root.querySelector('[data-more]');
	var bulk = root.querySelector('[data-bulk]');
	var bulkText = root.querySelector('[data-bulk-text]');
	var start = root.querySelector('[data-bulk-start]');
	var stop = root.querySelector('[data-bulk-stop]');
	var progress = root.querySelector('[data-bulk-progress]');
	var bar = root.querySelector('[data-bulk-bar]');
	var state = { status: 'uncompressed', offset: 0, counts: {}, running: false, stopping: false, message: '' };

	/* Column titles per filter. The first title covers the thumbnail and the name. */
	var columns = {
		uncompressed: [T.now, T.webp, T.saving],
		restored: [T.now, T.webp, T.saving],
		compressed: [T.original, T.now, T.savedCol],
		skipped: [T.now, T.reason, '']
	};
	function headHtml() {
		var cols = columns[state.status];
		return '<div class="cp-row cp-row--head" aria-hidden="true"><span class="cp-row__title">' + esc(T.image) + '</span>' +
			cols.map(function (c) { return '<span>' + esc(c) + '</span>'; }).join('') + '<span></span></div>';
	}

	function setCounts(counts) {
		state.counts = counts || state.counts;
		root.querySelectorAll('[data-count]').forEach(function (el) {
			el.textContent = (state.counts[el.getAttribute('data-count')] || 0).toLocaleString();
		});
		updateBulk();
	}
	function updateBulk() {
		var left = state.counts.uncompressed || 0;
		bulk.hidden = state.status !== 'uncompressed' || (!left && !state.running && !state.message);
		start.hidden = state.running || !left;
		stop.hidden = !state.running;
		if (!state.running) { bulkText.textContent = state.message || fill(T.ready, [left]); }
	}

	function cell(label, html, extra) { return '<div class="cp-row__cell" data-label="' + esc(label) + '"' + (extra || '') + '>' + html + '</div>'; }
	function button(action, text, quiet) { return '<button type="button" class="cp-button' + (quiet ? ' cp-button--quiet' : '') + '" data-row-action="' + action + '">' + esc(text) + '</button>'; }

	function rowHtml(row) {
		var cols = columns[state.status], cells = '', action = '';
		if (row.status === 'compressed') {
			cells = cell(cols[0], esc(size(row.original_size))) + cell(cols[1], esc(size(row.new_size))) +
				cell(cols[2], '<strong>' + esc(fill(T.savingFmt, [size(row.original_size - row.new_size), row.saved_percent])) + '</strong>');
			if (row.can_restore) { action = button('restore', T.restore, true); }
		} else if (row.status === 'skipped') {
			cells = cell(cols[0], esc(size(row.file_size))) + cell(cols[1], esc(row.reason || '')) + '<div class="cp-row__cell"></div>';
			action = button('compress', T.again, true);
		} else {
			cells = cell(cols[0], esc(size(row.file_size))) +
				cell(cols[1], '<span class="cp-muted">' + esc(T.estimating) + '</span>', ' data-estimate-webp') +
				cell(cols[2], '', ' data-estimate-saving');
			action = button('compress', row.status === 'restored' ? T.again : T.compress, row.status === 'restored');
		}
		return '<div class="cp-row" data-id="' + row.id + '" data-status="' + esc(row.status) + '">' +
			(row.thumb ? '<img class="cp-row__thumb" src="' + esc(row.thumb) + '" alt="" width="48" height="48" loading="lazy">' : '<span class="cp-row__thumb"></span>') +
			'<div class="cp-row__name"><strong>' + esc(row.title || row.filename) + '</strong><span>' + esc(row.filename) + '</span></div>' +
			cells + '<div class="cp-row__action">' + action + '<span class="cp-row__status" role="status" aria-live="polite"></span></div></div>';
	}

	listeners.push(function (e) {
		var row = list.querySelector('.cp-row[data-id="' + e.id + '"]');
		if (!row) { return; }
		var webp = row.querySelector('[data-estimate-webp]'), saving = row.querySelector('[data-estimate-saving]');
		if (!webp) { return; }
		if (e.error) { webp.innerHTML = '<span class="cp-muted">' + esc(T.unknown) + '</span>'; return; }
		if (!e.smaller) {
			webp.innerHTML = '<span class="cp-muted">' + esc(T.notSmaller) + '</span>';
			saving.innerHTML = '<span class="cp-muted">' + esc(T.noSaving) + '</span>';
			var b = row.querySelector('[data-row-action="compress"]');
			if (b) { b.hidden = true; }
			return;
		}
		webp.textContent = fill(T.about, [e.estimate_human]);
		saving.innerHTML = '<strong>' + esc(fill(T.savingFmt, [e.saved_human, e.percent])) + '</strong>';
	});

	function askEstimates() {
		list.querySelectorAll('.cp-row[data-id] [data-estimate-webp]').forEach(function (c) {
			var row = c.closest('.cp-row');
			if (!row.hasAttribute('data-asked')) { row.setAttribute('data-asked', '1'); estimate(row.getAttribute('data-id')); }
		});
	}

	function load(reset) {
		if (reset) { state.offset = 0; waiting.length = 0; }
		more.disabled = true;
		return post('compressino_images', { status: state.status, offset: state.offset }).then(function (data) {
			var html = data.rows.map(rowHtml).join('');
			if (reset) { list.innerHTML = data.total ? headHtml() + html : ''; } else { list.insertAdjacentHTML('beforeend', html); }
			state.offset += data.rows.length;
			more.hidden = state.offset >= data.total;
			more.disabled = false;
			empty.hidden = data.total > 0;
			empty.textContent = T.empty[state.status] || '';
			setCounts(data.counts);
			askEstimates();
		}).catch(function (error) {
			more.disabled = false;
			empty.hidden = false;
			empty.textContent = error.message;
		});
	}

	root.querySelectorAll('[data-status]').forEach(function (b) {
		if (!b.classList.contains('cp-filter')) { return; }
		b.addEventListener('click', function () {
			if (state.running) { return; }
			root.querySelectorAll('.cp-filter').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
			state.status = b.getAttribute('data-status');
			state.message = '';
			updateBulk();
			load(true);
		});
	});
	more.addEventListener('click', function () { load(false); });

	list.addEventListener('click', function (event) {
		var b = event.target.closest('[data-row-action]');
		if (!b || state.running) { return; }
		var row = b.closest('[data-id]');
		var restore = b.getAttribute('data-row-action') === 'restore';
		var status = row.querySelector('.cp-row__status');
		b.hidden = true;
		status.textContent = restore ? T.restoring : T.compressing;
		post(restore ? 'compressino_restore' : 'compressino_compress', { ids: [row.getAttribute('data-id')] }).then(function (data) {
			var result = data.results[0];
			delete estimates[String(result.id)];
			setCounts(data.counts);
			if (result.result === 'failed') { status.textContent = result.message; b.hidden = false; return; }
			// The image now belongs to another filter, so it leaves this list.
			row.remove();
			state.offset = Math.max(0, state.offset - 1);
			if (!list.querySelector('.cp-row[data-id]')) { load(true); }
		}).catch(function (error) { status.textContent = error.message; b.hidden = false; });
	});

	start.addEventListener('click', function () {
		var total = state.counts.uncompressed || 0;
		var done = 0, tally = { done: 0, skipped: 0, failed: 0 }, skip = [];
		state.running = true; state.stopping = false; state.message = '';
		waiting.length = 0;
		progress.hidden = false; bar.style.width = '0%';
		root.classList.add('is-running');
		updateBulk();
		bulkText.textContent = fill(T.progress, [0, total]);
		function finish(message) {
			state.running = false;
			state.message = message;
			root.classList.remove('is-running');
			progress.hidden = true;
			updateBulk();
			load(true);
		}
		function next() {
			if (state.stopping) { finish(T.stopped); return; }
			post('compressino_compress', { bulk: 1, skip: skip }).then(function (data) {
				data.results.forEach(function (r) {
					tally[r.result]++;
					if (r.result === 'failed') { skip.push(r.id); }
					delete estimates[String(r.id)];
				});
				done += data.results.length;
				total = Math.max(total, done + data.remaining);
				bar.style.width = (total ? Math.round(100 * done / total) : 100) + '%';
				setCounts(data.counts);
				bulkText.textContent = fill(T.progress, [done, total]);
				if (data.remaining > 0 && data.results.length) { next(); }
				else { finish(fill(T.done, [tally.done, tally.skipped, tally.failed])); }
			}).catch(function (error) { finish(error.message); });
		}
		next();
	});
	stop.addEventListener('click', function () { state.stopping = true; stop.disabled = true; setTimeout(function () { stop.disabled = false; }, 1500); });

	page = { counts: setCounts, reload: function () { load(true); } };
	updateBulk();
	load(true);
})();
