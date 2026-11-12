(function ($) {
	'use strict';

	var cfg = window.inpulsiaMedios;
	var token = null;
	var applyAction = 'inpulsia_medios_apply';
	var runTitle = '';

	// Translations come from PHP (wp_localize_script); %s placeholders are filled in order.
	function t(text) {
		var out = (cfg.i18n && cfg.i18n[text]) || text, args = arguments, i = 1;
		return out.replace(/%s/g, function () { return args[i++]; });
	}

	function txt(tag, text, cls) {
		var $el = $('<' + tag + '>').text(text == null ? '' : String(text));
		if (cls) $el.addClass(cls);
		return $el;
	}

	function idLink(id) {
		return $('<a target="_blank" rel="noopener">').attr('href', cfg.edit_url + encodeURIComponent(id)).text(id);
	}

	function shown(v) {
		return v === '' ? t('(vacío)') : v;
	}

	/* ---------- Import ---------- */

	$('#inpulsia-medios-form').on('submit', function (e) {
		e.preventDefault();
		var file = $('#inpulsia-medios-file')[0].files[0];
		if (!file) return;

		var fd = new FormData();
		fd.append('action', 'inpulsia_medios_preview');
		fd.append('nonce', cfg.nonce);
		fd.append('csv_file', file);

		var $spinner = $(this).find('.spinner').addClass('is-active');
		$('#inpulsia-medios-preview, #inpulsia-medios-run').prop('hidden', true);

		$.ajax({ url: cfg.ajax_url, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
			.done(function (res) {
				if (!res.success) return alert(res.data || t('Error al procesar el archivo.'));
				token = res.data.token;
				renderPreview(res.data);
			})
			.fail(function (xhr, textStatus) { alert(textStatus === 'parsererror' ? t('El servidor ha devuelto una respuesta no válida. Revisa el registro de errores de PHP.') : t('Error de conexión con el servidor.')); })
			.always(function () { $spinner.removeClass('is-active'); });
	});

	/* ---------- Inline editing ---------- */

	var $grid = $('.inpulsia-medios-grid');
	var lastField = null;
	var CAMERA = /^(img|dsc[nf]?|dcim|pxl|mvimg|photo|foto|pic|p)[-_ .]*\d|^(whatsapp|captura|screenshot|screen ?shot|imagen?|untitled|sin[-_ ]titulo)\b|^[\d_ -]+$|^[a-f0-9]{8}-[a-f0-9]{4}|^[a-z0-9]{24,}$|copia|copy|final|-v\d+$/i;

	function fieldValue($input) {
		return $input.val();
	}

	function slug(v) {
		return v.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
	}

	// Live SEO checks; they only advise, nothing is blocked.
	function hints($tr) {
		var out = {};
		var name = $tr.find('[data-field="filename"]').val() || '';
		var alt = ($tr.find('[data-field="alt"]').val() || '').trim();
		var n = [];
		if (name && CAMERA.test(name)) n.push(t('Nombre de cámara o genérico: no dice nada a Google.'));
		if (name && slug(name) !== name) n.push(t('Se guardará como «%s».', slug(name)));
		if (name.length > 60) n.push(t('Demasiado largo: mejor 3-6 palabras.'));
		out.filename = n;
		var a = [];
		if (!alt) a.push(t('Falta el alt.'));
		else {
			if (/^(imagen|foto|fotograf[ií]a|image|picture|img)\b/i.test(alt)) a.push(t('No empieces por «imagen de»: describe directamente.'));
			if (/^[a-z0-9]+(-[a-z0-9]+)+$/i.test(alt)) a.push(t('Parece un nombre de archivo, no una frase.'));
			if (alt.split(/\s+/).length < 3) a.push(t('Muy corto: describe qué se ve.'));
			var dup = $grid.find('[data-field="alt"]').filter(function () { return this !== $tr.find('[data-field="alt"]')[0] && $(this).val().trim().toLowerCase() === alt.toLowerCase(); }).length;
			if (dup) a.push(t('Alt repetido en otra imagen de esta página.'));
		}
		out.alt = a;
		$tr.find('.im-hint').each(function () {
			var list = out[$(this).data('for')] || [];
			$(this).text(list.join(' ')).toggleClass('has-hint', list.length > 0);
		});
	}

	function updateRowState($tr) {
		var dirty = false;
		$tr.find('[data-field]').each(function () {
			var changed = fieldValue($(this)) !== $(this).attr('data-orig');
			$(this).toggleClass('is-dirty', changed);
			dirty = dirty || changed;
		});
		$tr.toggleClass('row-dirty', dirty);
		var n = $grid.find('tr.row-dirty').length;
		$('#inpulsia-medios-dirty').text(n ? t('%s imágenes con cambios sin revisar', n) : '');
		var len = ($tr.find('[data-field="alt"]').val() || '').length;
		$tr.find('.inpulsia-medios-chars').text(len + '/125').toggleClass('too-long', len > 125);
		hints($tr);
	}

	function selectedRows() {
		return $grid.find('.inpulsia-medios-row-check:checked').closest('tr');
	}

	function refreshSelection() {
		var n = selectedRows().length;
		$('.im-actionbar').prop('hidden', !n);
		$('#im-selected-count').text(n === 1 ? t('1 seleccionada') : t('%s seleccionadas', n));
	}

	function setField($f, v) {
		$f.val(v);
		updateRowState($f.closest('tr'));
	}

	/* Autosave (text fields only; renaming always goes through the preview) */
	var autosave = false;
	try { autosave = localStorage.getItem('inpulsiaMediosAutosave') === '1'; } catch (e) {}
	$('#im-autosave').prop('checked', autosave).on('change', function () {
		autosave = this.checked;
		try { localStorage.setItem('inpulsiaMediosAutosave', autosave ? '1' : '0'); } catch (e) {}
		if (autosave) $grid.find('[data-field]').not('[data-field="filename"]').filter('.is-dirty').each(function () { save($(this)); });
	});

	function save($f) {
		var field = $f.data('field');
		if (!autosave || field === 'filename' || $f.val() === $f.attr('data-orig')) return;
		var $td = $f.closest('td').removeClass('is-saved is-save-error').addClass('is-saving');
		var sent = $f.val();
		$.post(cfg.ajax_url, { action: 'inpulsia_medios_autosave', nonce: cfg.nonce, id: $f.closest('tr').data('id'), field: field, value: sent }, null, 'json')
			.done(function (res) {
				if (!res.success) { $td.addClass('is-save-error').attr('title', res.data || t('Error')); return; }
				$f.attr('data-orig', res.data.value);
				if ($f.val() === sent) $f.val(res.data.value);
				$td.addClass('is-saved');
				setTimeout(function () { $td.removeClass('is-saved'); }, 1500);
				updateRowState($f.closest('tr'));
			})
			.fail(function () { $td.addClass('is-save-error').attr('title', t('Error de conexión: no se ha guardado')); })
			.always(function () { $td.removeClass('is-saving'); });
	}

	if ($grid.length) {
		$grid.find('tbody tr[data-id]').each(function () { updateRowState($(this)); });
		$grid.on('input', '[data-field]', function () { updateRowState($(this).closest('tr')); });
		$grid.on('change', '[data-field]', function () { save($(this)); });
		$grid.on('focus', '[data-field]', function () { lastField = this; });

		// Enter (Ctrl+Enter in multi-line fields) moves to the same field in the next row; Shift goes back.
		$grid.on('keydown', '[data-field]', function (e) {
			if (e.key !== 'Enter' || e.isComposing) return;
			var multi = $(this).is('textarea');
			if (multi && !(e.ctrlKey || e.metaKey)) return;
			e.preventDefault();
			var $rows = $grid.find('tbody tr[data-id]');
			var i = $rows.index($(this).closest('tr')) + (e.shiftKey ? -1 : 1);
			var $next = $rows.eq(Math.max(0, i)).find('[data-field="' + $(this).data('field') + '"]');
			if (i >= 0 && $next.length) $next.trigger('focus').trigger('select');
			else $(this).trigger('change');
		});

		// Pasting several lines from Excel fills downward; tab-separated cells fill the next columns.
		$grid.on('paste', '[data-field]', function (e) {
			var text = (e.originalEvent.clipboardData || window.clipboardData).getData('text');
			if (!/[\n\t]/.test(text.replace(/\r?\n$/, ''))) return;
			e.preventDefault();
			var lines = text.replace(/\r/g, '').replace(/\n$/, '').split('\n');
			var fields = ['filename', 'title', 'alt', 'caption', 'description'];
			var col = fields.indexOf($(this).data('field'));
			var $rows = $grid.find('tbody tr[data-id]');
			var start = $rows.index($(this).closest('tr'));
			$.each(lines, function (i, line) {
				var $tr = $rows.eq(start + i);
				if (!$tr.length) return false;
				$.each(line.split('\t'), function (j, cell) {
					var f = fields[col + j];
					if (f) { var $f = $tr.find('[data-field="' + f + '"]'); setField($f, cell.trim()); save($f); }
				});
			});
		});

		$('#inpulsia-medios-check-all').on('change', function () {
			$grid.find('.inpulsia-medios-row-check').prop('checked', this.checked);
			refreshSelection();
		});
		$grid.on('change', '.inpulsia-medios-row-check', refreshSelection);
		$('#im-clear-selection').on('click', function () {
			$grid.find('.inpulsia-medios-row-check, #inpulsia-medios-check-all').prop('checked', false);
			refreshSelection();
		});

		$('#im-copy-down').on('click', function () {
			if (!lastField) return alert(t('Escribe primero en el campo que quieres copiar.'));
			var $src = $(lastField), f = $src.data('field');
			if (f === 'filename') return alert(t('Los nombres de archivo deben ser distintos: usa una plantilla con {n}.'));
			selectedRows().each(function () {
				var $f = $(this).find('[data-field="' + f + '"]');
				if ($f[0] !== lastField) { setField($f, $src.val()); save($f); }
			});
		});

		/* Templates */
		$('.im-var').on('click', function () {
			var el = $('#im-tpl')[0], v = $(this).data('var');
			var pos = el.selectionStart == null ? el.value.length : el.selectionStart;
			el.value = el.value.slice(0, pos) + v + el.value.slice(el.selectionEnd || pos);
			el.focus();
		});
		$('#im-tpl-apply').on('click', function () {
			var field = $('#im-tpl-field').val(), tpl = $('#im-tpl').val(), onlyEmpty = $('#im-tpl-empty').is(':checked');
			if (!tpl.trim()) return;
			var $rows = selectedRows();
			if (!$rows.length) $rows = $grid.find('tbody tr[data-id]');
			var n = 0, changed = 0;
			$rows.each(function () {
				var $tr = $(this), $f = $tr.find('[data-field="' + field + '"]');
				var cur = $f.val().trim();
				if (onlyEmpty && cur && !(field === 'filename' || field === 'title' ? CAMERA.test(cur) : false)) return;
				n++;
				var vars = {
					pagina: $tr.data('page') || $tr.find('[data-field="title"]').val() || '',
					keyword: $tr.data('keyword') || $tr.data('page') || '',
					titulo: $tr.find('[data-field="title"]').val() || '',
					web: cfg.site || '',
					nombre: $tr.find('[data-field="filename"]').val() || '',
					alt: $tr.find('[data-field="alt"]').val() || '',
					n: String(n)
				};
				var v = tpl.replace(/\{(\w+)\}/g, function (m, k) { return k in vars ? vars[k] : m; })
					.replace(/\s+-\s+-\s+/g, ' - ').replace(/^[\s-]+|[\s-]+$/g, '').replace(/\s{2,}/g, ' ');
				if (field === 'filename') v = slug(v);
				if (v) { setField($f, v); save($f); changed++; }
			});
			alert(changed ? t('%s campos rellenados. Revisa la tabla y pulsa «Revisar cambios».', changed) : t('No había ningún campo que rellenar con esas condiciones.'));
		});

		window.addEventListener('beforeunload', function (e) {
			if ($grid.find('tr.row-dirty').length && !window.inpulsiaMediosSaving) {
				e.preventDefault();
				e.returnValue = '';
			}
		});
	}

	$('#inpulsia-medios-review').on('click', function () {
		var rows = [];
		$grid.find('tr.row-dirty').each(function () {
			var $tr = $(this);
			var row = { id: $tr.data('id') };
			$tr.find('[data-field]').each(function () {
				var $f = $(this), v = fieldValue($f), orig = $f.attr('data-orig');
				if (v === orig) return;
				row[$f.data('field')] = v.trim() === '' ? cfg.clear : v;
			});
			rows.push(row);
		});
		if (!rows.length) return alert(t('No has cambiado nada.'));

		var $btn = $(this).prop('disabled', true).addClass('is-loading');
		$.post(cfg.ajax_url, { action: 'inpulsia_medios_stage', nonce: cfg.nonce, rows: JSON.stringify(rows) }, null, 'json')
			.done(function (res) {
				if (!res.success) return alert(res.data || t('Error.'));
				token = res.data.token;
				$('#inpulsia-medios-grid-wrap').prop('hidden', true);
				renderPreview(res.data);
			})
			.fail(function (xhr, textStatus) { alert(textStatus === 'parsererror' ? t('El servidor ha devuelto una respuesta no válida. Revisa el registro de errores de PHP.') : t('Error de conexión con el servidor.')); })
			.always(function () { $btn.prop('disabled', false).removeClass('is-loading'); });
	});

	/* ---------- AI suggestions ---------- */

	function suggest($tr) {
		var $btn = $tr.find('.inpulsia-medios-suggest').prop('disabled', true).addClass('is-busy');
		$tr.find('.ai-error').remove();
		return $.post(cfg.ajax_url, { action: 'inpulsia_medios_suggest', nonce: cfg.nonce, id: $tr.data('id') }, null, 'json')
			.done(function (res) {
				if (!res.success) {
					$tr.find('.col-ai').append(txt('div', res.data || t('Error'), 'ai-error'));
					return;
				}
				$.each(['alt', 'filename', 'title'], function (_, f) {
					if (res.data[f]) $tr.find('[data-field="' + f + '"]').val(res.data[f]).addClass('is-ai');
				});
				updateRowState($tr);
			})
			.fail(function () { $tr.find('.col-ai').append(txt('div', t('Error de conexión'), 'ai-error')); })
			.always(function () { $btn.prop('disabled', false).removeClass('is-busy'); });
	}

	$grid.on('click', '.inpulsia-medios-suggest', function () { suggest($(this).closest('tr')); });

	$('#inpulsia-medios-suggest-selected').on('click', function () {
		var queue = selectedRows().toArray();
		if (!queue.length) return alert(t('Selecciona al menos una imagen.'));
		var $btn = $(this).prop('disabled', true);
		var running = 0;
		(function next() {
			while (running < 2 && queue.length) {
				running++;
				suggest($(queue.shift())).always(function () { running--; next(); });
			}
			if (!running && !queue.length) $btn.prop('disabled', false);
		})();
	});

	/* ---------- Optimize ---------- */

	function optimize(mode) {
		var data = {
			action: 'inpulsia_medios_opt_stage',
			nonce: cfg.nonce,
			mode: mode,
			target: $('#im-opt-format').val(),
			max_width: $('#im-opt-width').val(),
			quality: $('#im-opt-quality').val(),
			backup: $('#im-opt-backup').is(':checked'),
			ai: $('#im-opt-ai').is(':checked'),
			delete_old: $('#im-opt-delete-old').is(':checked')
		};
		if (mode === 'selected') {
			data.ids = selectedRows().map(function () { return $(this).data('id'); }).get();
			if (!data.ids.length) return alert(t('Selecciona al menos una imagen.'));
		} else {
			var params = new URLSearchParams(location.search);
			data.s = params.get('s') || '';
			data.usage = params.get('usage') || '';
			data.missing_alt = params.get('missing_alt') || '';
			data.format = params.get('format') || '';
			data.weight = params.get('weight') || '';
			data.names = params.get('names') || '';
			data.lang = params.get('lang') || '';
			data.ids = params.get('ids') || '';
		}
		var $btns = $('#inpulsia-medios-opt-selected, #inpulsia-medios-opt-all').prop('disabled', true).addClass('is-loading');
		$.post(cfg.ajax_url, data, null, 'json')
			.done(function (res) {
				if (!res.success) return alert(res.data || t('Error.'));
				token = res.data.token;
				$('#inpulsia-medios-grid-wrap').prop('hidden', true);
				renderPreview(res.data);
			})
			.fail(function (xhr, textStatus) { alert(textStatus === 'parsererror' ? t('El servidor ha devuelto una respuesta no válida. Revisa el registro de errores de PHP.') : t('Error de conexión con el servidor.')); })
			.always(function () { $btns.prop('disabled', false).removeClass('is-loading'); });
	}

	$('#inpulsia-medios-opt-selected').on('click', function () { optimize('selected'); });

	// Shortcuts from the Inicio assistant: start the task straight away (a preview still comes first).
	$(function () {
		var task = $('#inpulsia-medios-grid-wrap').data('do');
		if (task === 'optimize' && $('#inpulsia-medios-opt-all').data('total') > 0) optimize('filter');
		if (task === 'ai') {
			$('#inpulsia-medios-check-all').prop('checked', true).trigger('change');
			if ($('#inpulsia-medios-suggest-selected').length) $('#inpulsia-medios-suggest-selected').trigger('click');
			else $grid.find('[data-field="alt"]').first().trigger('focus');
		}
	});
	$('#inpulsia-medios-opt-all').on('click', function () {
		var n = $(this).data('total');
		if (n > 50 && !confirm(t('Se van a revisar %s imágenes. Puede tardar varios minutos. ¿Seguir?', n))) return;
		optimize('filter');
	});

	/* ---------- Cleanup ---------- */

	$('.im-clean-stage').on('click', function () {
		var $btn = $(this).prop('disabled', true).addClass('is-loading');
		$.post(cfg.ajax_url, { action: 'inpulsia_medios_clean_stage', nonce: cfg.nonce, kind: $(this).data('kind') }, null, 'json')
			.done(function (res) {
				if (!res.success) return alert(res.data || t('Error.'));
				token = res.data.token;
				$('#im-clean').prop('hidden', true);
				renderPreview(res.data);
			})
			.fail(function () { alert(t('Error de conexión con el servidor.')); })
			.always(function () { $btn.prop('disabled', false).removeClass('is-loading'); });
	});

	/* ---------- Preview ---------- */

	function renderPreview(data) {
		var $tbody = $('#inpulsia-medios-table tbody').empty();
		var warnCount = 0, errCount = 0;
		var isOpt = data.mode === 'optimize';
		applyAction = data.action || (isOpt ? 'inpulsia_medios_opt_apply' : 'inpulsia_medios_apply');
		runTitle = data.title || '';

		$.each(data.items, function (i, item) {
			var $tr = $('<tr>');
			$tr.append($('<td class="col-id">').append(item.edit ? idLink(item.id) : document.createTextNode(item.id)));

			if (item.error) {
				errCount++;
				$tr.addClass('row-error').attr('data-warn', '1');
				$tr.append($('<td>'), $('<td>'), $('<td>').append(txt('span', item.error, 'warn-error')));
				$tbody.append($tr);
				return;
			}

			$tr.append($('<td class="col-thumb">').append(item.thumb ? $('<img>').attr('src', item.thumb).attr('alt', '') : ''));

			if (isOpt) {
				var $plan = $('<td>').append(txt('strong', item.name));
				if (item.skip) {
					$plan.append(txt('div', item.skip, 'muted'));
					$tr.addClass('row-skip');
				}
				$.each(item.lines || [], function (_, l) { $plan.append(txt('div', l, 'change')); });
				var $w = $('<td class="col-warn">');
				$.each(item.warnings || [], function (_, w) { $w.append(txt('div', w, 'warn')); });
				if ((item.warnings || []).length) { warnCount++; $tr.attr('data-warn', '1'); }
				$tbody.append($tr.append($plan, $w));
				return;
			}

			var $changes = $('<td>');
			var keys = Object.keys(item.changes);
			if (!keys.length) {
				$changes.append(txt('em', t('Sin cambios'), 'muted'));
				$tr.addClass('row-skip');
			}
			$.each(keys, function (_, f) {
				$changes.append(
					$('<div class="change">').append(
						txt('strong', cfg.labels[f] + ': '),
						txt('span', shown(item.current[f]), 'old'),
						txt('span', ' → ', 'arrow'),
						txt('span', shown(item.changes[f]), 'new')
					)
				);
			});
			$tr.append($changes);

			var $warn = $('<td class="col-warn">');
			$.each(item.warnings, function (_, w) { $warn.append(txt('div', w, 'warn')); });
			if (item.warnings.length) { warnCount++; $tr.attr('data-warn', '1'); }
			$tr.append($warn);

			$tbody.append($tr);
		});

		$('#inpulsia-medios-summary').empty().append(
			txt('span', data.summary || (isOpt ? t('%s imágenes a optimizar · %s actuales', data.total, data.bytes) : t('%s medios con cambios', data.total)), 'pill pill-ok'),
			isOpt && data.listed > data.total ? txt('span', t('%s se omiten', data.listed - data.total), 'pill') : '',
			isOpt && data.listed > data.items.length ? txt('span', t('se muestran %s de %s', data.items.length, data.listed), 'pill') : '',
			warnCount ? txt('span', t('%s con avisos', warnCount), 'pill pill-warn') : '',
			errCount ? txt('span', t('%s con errores', errCount), 'pill pill-error') : ''
		);
		$('#inpulsia-medios-apply').prop('disabled', !data.total).text(data.label || (isOpt ? t('Optimizar %s imágenes', data.total) : t('Aplicar %s cambios', data.total)));
		$('#inpulsia-medios-only-warn').prop('checked', false);
		$('#inpulsia-medios-preview').prop('hidden', false);
	}

	$('#inpulsia-medios-only-warn').on('change', function () {
		$('#inpulsia-medios-table tbody tr').not('[data-warn]').toggle(!this.checked);
	});

	$('#inpulsia-medios-cancel').on('click', function () {
		$('#inpulsia-medios-preview').prop('hidden', true);
		$('#inpulsia-medios-grid-wrap, #im-clean').prop('hidden', false);
		token = null;
	});

	$('#inpulsia-medios-apply').on('click', function () {
		if (!token || !confirm(t('¿Aplicar los cambios? Podrás deshacerlos desde la pestaña Historial.'))) return;
		$('#inpulsia-medios-preview').prop('hidden', true);
		window.inpulsiaMediosSaving = true;
		run(applyAction, token, runTitle || (applyAction === 'inpulsia_medios_opt_apply' ? t('Optimizando imágenes') : t('Aplicando cambios')));
	});

	$(document).on('click', '.inpulsia-medios-undo', function () {
		if (!confirm(t('¿Deshacer esta importación y restaurar los valores anteriores?'))) return;
		$('.inpulsia-medios-undo').prop('disabled', true);
		run('inpulsia_medios_undo', $(this).data('token'), t('Deshaciendo importación'));
	});

	/* ---------- Batch runner ---------- */

	function run(action, tok, title) {
		var $box = $('#inpulsia-medios-run').prop('hidden', false);
		var $bar = $box.find('.inpulsia-medios-progress > div').css('width', '0%');
		$box.find('.inpulsia-medios-progress').attr({ role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-valuenow': 0 });
		$('#inpulsia-medios-run-status').attr('aria-live', 'polite');
		$('html, body').animate({ scrollTop: $box.offset().top - 40 }, 200);
		var $status = $('#inpulsia-medios-run-status');
		var $log = $('#inpulsia-medios-log').empty();
		var counts = { ok: 0, partial: 0, error: 0, skip: 0 };
		var purged = [];
		var notes = [];
		var before = 0, after = 0;
		$('#inpulsia-medios-run-title').text(title);

		window.onbeforeunload = function () { return true; };

		(function step(offset, attempt) {
			attempt = attempt || 0;
			$.post({ url: cfg.ajax_url, data: { action: action, nonce: cfg.nonce, token: tok, offset: offset }, dataType: 'json', timeout: 300000 })
				.done(function (res) {
					if (!res.success) return finish(res.data || t('Error.'));
					var d = res.data;
					$.each(d.results, function (_, r) {
						counts[r.status] = (counts[r.status] || 0) + 1;
						if (r.before) { before += r.before; after += r.after; }
						$log.append($('<p>').addClass('log-' + r.status).append($('<strong>').append('ID ', idLink(r.id), ': '), txt('span', r.msg)));
					});
					var pct = d.total ? Math.round(d.next / d.total * 100) : 100;
					$bar.css('width', pct + '%').parent().attr('aria-valuenow', pct);
					$status.text(t('%s de %s procesados', d.next, d.total));
					if (d.done) { purged = d.purged || []; notes = d.notes || []; finish(null); }
					else step(d.next);
				})
				.fail(function (xhr, textStatus) {
					// Re-running a batch is safe: rows already applied come back as "sin cambios".
					var why = textStatus === 'parsererror' ? t('respuesta no válida') : textStatus === 'timeout' ? t('tiempo agotado') : (xhr.status || t('sin conexión'));
					if (attempt < 2) {
						$status.text(t('El servidor ha fallado (%s). Reintentando…', why));
						setTimeout(function () { step(offset, attempt + 1); }, 3000 * (attempt + 1));
						return;
					}
					finish(t('El servidor ha fallado (%s) en el registro %s. Lo ya procesado está guardado en el Historial.', why, offset + 1), function () {
						window.onbeforeunload = function () { return true; };
						step(offset);
					});
				});
		})(0);

		function finish(error, resume) {
			window.onbeforeunload = null;
			if (error) {
				$status.empty().append(txt('span', error, 'log-error'));
				if (resume) {
					$status.append(' ', $('<button type="button" class="button">').text(t('Reanudar')).on('click', function () { $(this).remove(); resume(); }));
				}
				return;
			}
			$status.text(t('Terminado: %s correctos, %s parciales, %s errores, %s sin cambios.', counts.ok, counts.partial, counts.error, counts.skip));
			if (before > after) {
				var mb = function (b) { return (b / 1048576).toFixed(1).replace('.', ',') + ' MB'; };
				$status.append($('<br>'), txt('strong', t('Ahorro: %s (%s → %s, −%s %)', mb(before - after), mb(before), mb(after), Math.round((1 - after / before) * 100))));
			}
			if (purged.length) $status.append($('<br>'), txt('span', t('Cachés vaciadas: %s.', purged.join(', ')), 'muted'));
			$.each(notes, function (_, n) { $status.append($('<br>'), txt('span', n, 'muted')); });
			if (action === 'inpulsia_medios_undo') {
				setTimeout(function () { location.reload(); }, 1500);
				return;
			}
			$status.append(' ', $('<a>').attr('href', cfg.history).text(t('Ver historial / deshacer')));
			$('#inpulsia-medios-file').val('');
			if ($grid.length || $('#im-clean').length) {
				$status.append(' · ', $('<a href="#">').text($grid.length ? t('Volver a la tabla') : t('Volver a Limpieza')).on('click', function (e) { e.preventDefault(); location.reload(); }));
			}
		}
	}
})(jQuery);
