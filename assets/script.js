(function ($) {
	'use strict';

	var cfg = window.inpulsiaMedios;
	var token = null;
	var applyAction = 'inpulsia_medios_apply';
	var lastTotal = 0;
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
		var errors = $grid.find('.is-save-error').length;
		$('#inpulsia-medios-dirty').text(errors ? t('%s campos sin guardar', errors) : '');
		$('#inpulsia-medios-review').prop('hidden', !errors);
		var len = ($tr.find('[data-field="alt"]').val() || '').trim().length;
		$tr.find('.inpulsia-medios-chars').text(len + '/125').toggleClass('too-long', len > 125).toggleClass('too-short', len > 0 && len < 15);
		hints($tr);
		rowStatus($tr);
		placeholders($tr);
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

	/* Every field saves itself when you leave it, file names included (old URLs redirect). */
	var pending = 0;

	function rowStatus($tr) {
		var alt = ($tr.find('[data-field="alt"]').val() || '').trim();
		var name = $tr.find('[data-field="filename"]').val() || '';
		var state = $tr.data('lost') ? 'error' : $tr.find('.is-save-error').length ? 'error' : (!alt || CAMERA.test(name) ? 'todo' : 'ok');
		var label = $tr.data('lost') ? t('Falta el archivo en el servidor') : { ok: t('Completa'), todo: !alt ? t('Falta el alt') : t('Nombre poco claro'), error: t('No se ha guardado') }[state];
		$tr.attr('data-state', state).find('.im-row-status').attr({ 'class': 'im-row-status is-' + state, title: label, 'aria-label': label });
	}

	// A grey suggestion inside empty alt/title fields; Tab accepts it.
	function suggestion($tr, field) {
		var title = ($tr.find('[data-field="title"]').val() || '').trim();
		var name = $tr.find('[data-field="filename"]').val() || '';
		var page = String($tr.data('heading') || $tr.data('page') || '');
		if (field === 'alt') return title && !CAMERA.test(title) && slug(title) !== name ? title : page;
		if (field === 'title') return !CAMERA.test(name) ? name.replace(/-/g, ' ').replace(/^./, function (c) { return c.toUpperCase(); }) : page;
		return '';
	}
	function placeholders($tr) {
		['alt', 'title'].forEach(function (f) { $tr.find('[data-field="' + f + '"]').attr('placeholder', suggestion($tr, f)); });
	}

	function updateToday(today) {
		if (!today) return;
		$('.im-today').prop('hidden', !today.count).attr('data-token', today.token).find('.im-today-text').text(t('Cambios de hoy: %s', today.count));
	}

	function save($f) {
		var field = $f.data('field');
		var before = $f.attr('data-orig');
		if ($f.val() === before) return $.Deferred().resolve().promise();
		var $td = $f.closest('td').removeClass('is-saved is-save-error').addClass('is-saving');
		$td.find('.im-cell-undo').remove();
		var sent = $f.val();
		pending++;
		return $.ajax({ url: cfg.ajax_url, type: 'POST', dataType: 'json', timeout: field === 'filename' ? 120000 : 30000, data: { action: 'inpulsia_medios_autosave', nonce: cfg.nonce, id: $f.closest('tr').data('id'), field: field, value: sent } })
			.done(function (res) {
				if (!res.success) {
					$td.addClass('is-save-error').attr('title', res.data || t('Error'));
					$td.find('.im-save-msg').remove();
					$td.append(txt('small', res.data || t('Error'), 'im-save-msg log-error'));
					return;
				}
				$td.removeAttr('title').find('.im-save-msg').remove();
				$f.attr('data-orig', res.data.value);
				if ($f.val() === sent) $f.val(res.data.value);
				$f.removeClass('is-ai');
				$td.addClass('is-saved');
				updateToday(res.data.today);
				// Ten seconds to change your mind, without going to the Historial.
				var $undo = $('<button type="button" class="button-link im-cell-undo">').text('↶ ' + t('Deshacer')).attr('aria-label', t('Deshacer este cambio')).on('click', function () {
					$undo.remove();
					$f.val(before);
					save($f).always(function () { updateRowState($f.closest('tr')); });
				});
				$td.append($undo);
				setTimeout(function () { $td.removeClass('is-saved'); $undo.remove(); }, 10000);
				updateRowState($f.closest('tr'));
				sheetSync();
			})
			.fail(function (xhr, status) {
				var why = status === 'timeout' ? t('el servidor ha tardado demasiado') : xhr.status >= 500 ? t('error %s del servidor', xhr.status) : status === 'parsererror' ? t('respuesta no válida del servidor') : xhr.status === 0 ? t('sin conexión') : t('error %s', xhr.status);
				var msg = t('No se ha guardado: %s.', why);
				$td.addClass('is-save-error').attr('title', msg);
				$td.find('.im-save-msg').remove();
				$td.append($('<button type="button" class="button-link im-save-msg">').text(msg + ' ' + t('Reintentar')).on('click', function () { $(this).remove(); save($f); }));
				if (window.console) console.warn('Inpulsia Medios', field, status, xhr.status, (xhr.responseText || '').slice(0, 500));
			})
			.always(function () { pending--; $td.removeClass('is-saving'); updateRowState($f.closest('tr')); });
	}

	if ($grid.length) {
		$grid.find('tbody tr[data-id]').each(function () { updateRowState($(this)); });
		$grid.on('input', '[data-field]', function () { updateRowState($(this).closest('tr')); });
		$grid.on('change', '[data-field]', function () { save($(this)); });
		$grid.on('focus', '[data-field]', function () { lastField = this; });
		$grid.on('keydown', '[data-field="alt"], [data-field="title"]', function (e) {
			if (e.key === 'Tab' && !e.shiftKey && !this.value && this.placeholder) {
				e.preventDefault();
				setField($(this), this.placeholder);
				save($(this));
			}
		});

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
					n: String(n),
					encabezado: $tr.data('heading') || $tr.data('page') || '',
					categoria: $tr.data('category') || '',
					ciudad: cfg.city || ''
				};
				var v = tpl.replace(/\{(\w+)\}/g, function (m, k) { return k in vars ? vars[k] : m; })
					.replace(/\s+-\s+-\s+/g, ' - ').replace(/^[\s-]+|[\s-]+$/g, '').replace(/\s{2,}/g, ' ');
				if (field === 'filename') v = slug(v);
				if (v) { setField($f, v); save($f); changed++; }
			});
			alert(changed ? t('%s campos rellenados y guardados. Puedes deshacerlos con «Deshacer todos».', changed) : t('No había ningún campo que rellenar con esas condiciones.'));
		});

		window.addEventListener('beforeunload', function (e) {
			if ((pending || $grid.find('.is-save-error').length) && !window.inpulsiaMediosSaving) {
				e.preventDefault();
				e.returnValue = '';
			}
		});
	}

	var sheetSync = function () {};

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
				$('#inpulsia-medios-grid-wrap, #im-clean').prop('hidden', true);
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
				if (res.data.source === 'context') $tr.find('.col-ai').append(txt('div', t('Sin IA: sacado de la página'), 'muted ai-note'));
				$.each(['alt', 'filename', 'title'], function (_, f) {
					var $f = $tr.find('[data-field="' + f + '"]');
					if (res.data[f] && res.data[f] !== $f.val()) { $f.val(res.data[f]).addClass('is-ai'); save($f); }
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

	function optimize(mode, extra) {
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
		} else if (extra) {
			$.extend(data, extra);
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
		// Without the options panel on the page, the saved settings (Ajustes → Optimización) are used.
		if (!$('#im-opt-format').length) data.use_saved = 1;
		var $btns = $('#inpulsia-medios-opt-selected, #inpulsia-medios-opt-all, .im-opt-run').prop('disabled', true).addClass('is-loading');
		$.post(cfg.ajax_url, data, null, 'json')
			.done(function (res) {
				if (!res.success) return alert(res.data || t('Error.'));
				token = res.data.token;
				$('#inpulsia-medios-grid-wrap, #im-clean').prop('hidden', true);
				renderPreview(res.data);
			})
			.fail(function (xhr, textStatus) { alert(textStatus === 'parsererror' ? t('El servidor ha devuelto una respuesta no válida. Revisa el registro de errores de PHP.') : t('Error de conexión con el servidor.')); })
			.always(function () { $btns.prop('disabled', false).removeClass('is-loading'); });
	}

	$('#inpulsia-medios-opt-selected').on('click', function () { optimize('selected'); });

	// Shortcuts from the Inicio assistant: start the task straight away (a preview still comes first).
	$(function () {
		var task = $('#inpulsia-medios-grid-wrap').data('do');
		if (task === 'optimize') optimize('filter');
		if (task === 'focus') setTimeout(function () { $('#im-focus-mode').trigger('click'); }, 200);
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

	/* ---------- Whole filter (not only the visible page) ---------- */

	function filterParams(data) {
		var params = new URLSearchParams(location.search);
		['s', 'usage', 'missing_alt', 'format', 'weight', 'names', 'lang', 'ids'].forEach(function (k) { data[k] = params.get(k) || ''; });
		return data;
	}

	function stageFilter($btn, data) {
		$btn.prop('disabled', true).addClass('is-loading');
		$.post(cfg.ajax_url, filterParams($.extend({ nonce: cfg.nonce }, data)), null, 'json')
			.done(function (res) {
				if (!res.success) return alert(res.data || t('Error.'));
				token = res.data.token;
				$('#inpulsia-medios-grid-wrap, #im-clean').prop('hidden', true);
				renderPreview(res.data);
			})
			.fail(function () { alert(t('Error de conexión con el servidor.')); })
			.always(function () { $btn.prop('disabled', false).removeClass('is-loading'); });
	}

	$('#im-tpl-filter').on('click', function () {
		if (!$('#im-tpl').val().trim()) return;
		stageFilter($(this), { action: 'inpulsia_medios_tpl_stage', field: $('#im-tpl-field').val(), tpl: $('#im-tpl').val(), only_empty: $('#im-tpl-empty').is(':checked') });
	});

	$('#im-ai-filter').on('click', function () {
		stageFilter($(this), { action: 'inpulsia_medios_ai_stage' });
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
		lastTotal = data.total;
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
		if (data.impact) {
			var $imp = $('<ul class="im-impact">');
			$.each([data.impact.saving, data.impact.backup], function (_, l) { if (l) $imp.append(txt('li', l)); });
			if (!data.impact.backup || data.impact.backup.indexOf('uploads/') < 0) $imp.addClass('is-risky');
			$('#inpulsia-medios-summary').append($imp);
		}
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
		if (!token) return;
		// Large optimizations need the number typed in, so nobody converts a whole library by accident.
		if (applyAction === 'inpulsia_medios_opt_apply' && lastTotal > 100) {
			var typed = prompt(t('Vas a optimizar %s imágenes. Escribe %s para confirmar.', lastTotal, lastTotal));
			if (typed === null) return;
			if (String(typed).trim() !== String(lastTotal)) { alert(t('El número no coincide: no se ha aplicado nada.')); return; }
		} else if (!confirm(t('¿Aplicar los cambios? Podrás deshacerlos desde la pestaña Historial.'))) return;
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
		var handoff = false;
		$box.find('.im-handoff').remove();
		if (action === 'inpulsia_medios_opt_apply') {
			$box.find('.inpulsia-medios-progress').after($('<p class="im-handoff">').append(
				$('<button type="button" class="button">').text(t('Seguir en segundo plano')).on('click', function () {
					handoff = true;
					$(this).prop('disabled', true).text(t('Pasando al servidor al terminar este lote…'));
				}),
				' ', txt('span', t('Podrás cerrar la pestaña; el servidor sigue solo.'), 'muted')
			));
		}

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
					else if (handoff) {
						$.post(cfg.ajax_url, { action: 'inpulsia_medios_bg_start', nonce: cfg.nonce, token: tok, offset: d.next }).done(function (r) {
							window.onbeforeunload = null;
							if (!r.success) return finish(r.data || t('Error.'), function () { step(d.next); });
							location.href = cfg.urls.i;
						});
					}
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
				$status.append(' · ', $('<a href="#">').text($grid.length ? t('Volver a la tabla') : t('Volver a Archivos')).on('click', function (e) { e.preventDefault(); location.reload(); }));
			}
		}
	}
	/* ---------- 1.8: preferences, saved filters, background jobs, history, shortcuts ---------- */

	function setPref(key, value, extra) {
		return $.post(cfg.ajax_url, $.extend({ action: 'inpulsia_medios_pref', nonce: cfg.nonce, key: key, value: value }, extra || {}));
	}
	$('.im-pref').on('change', function () {
		var key = $(this).data('key'), v = $(this).val();
		if (key === 'theme') $('.inpulsia-medios-wrap').removeClass('im-theme-auto im-theme-light im-theme-dark').addClass('im-theme-' + v);
		if (key === 'density') $('.inpulsia-medios-grid').removeClass('is-comfortable is-compact').addClass('is-' + v);
		setPref(key, v);
	});
	$('.im-col-toggle').on('change', function () {
		var hidden = $('.im-col-toggle').not(':checked').map(function () { return this.value; }).get();
		if (hidden.length === $('.im-col-toggle').length) { this.checked = true; return; }
		$('.inpulsia-medios-grid').toggleClass('hide-' + this.value, !this.checked);
		setPref('hidden', '', { cols: hidden });
	});

	$('.im-filter-save').on('click', function () {
		var name = prompt(t('Nombre del filtro:'));
		if (!name) return;
		$.post(cfg.ajax_url, { action: 'inpulsia_medios_filter_save', nonce: cfg.nonce, name: name, query: location.search.replace(/^\?/, '') })
			.done(function (r) { if (r.success) location.reload(); else alert(r.data); });
	});
	$('.im-filter-delete').on('click', function () {
		if (!confirm(t('¿Borrar este filtro guardado?'))) return;
		$.post(cfg.ajax_url, { action: 'inpulsia_medios_filter_delete', nonce: cfg.nonce, name: $(this).data('name') }).done(function () { location.reload(); });
	});

	var $bg = $('#im-bg');
	if ($bg.length && !$bg.data('done')) {
		(function poll() {
			$.post(cfg.ajax_url, { action: 'inpulsia_medios_bg_status', nonce: cfg.nonce }).done(function (r) {
				var j = r.success && r.data;
				if (!j) return $bg.remove();
				$bg.find('.inpulsia-medios-progress > div').css('width', (j.total ? Math.round(j.offset / j.total * 100) : 100) + '%');
				$bg.find('.im-bg-text').text(t('%s de %s · %s errores', j.offset, j.total, j.errors));
				if (j.done) { $bg.addClass('is-done').find('.im-bg-title').text(t('Optimización en segundo plano terminada')); return; }
				setTimeout(poll, 5000);
			}).fail(function () { setTimeout(poll, 15000); });
		})();
	}
	$(document).on('click', '.im-bg-cancel', function () {
		if (!$bg.hasClass('is-done') && !confirm(t('¿Detener la optimización? Lo ya hecho queda en el Historial.'))) return;
		$.post(cfg.ajax_url, { action: 'inpulsia_medios_bg_cancel', nonce: cfg.nonce }).done(function () { $bg.remove(); });
	});

	function filterHistory() {
		var q = ($('#im-history-search').val() || '').toLowerCase(), type = $('#im-history-type').val(), user = $('#im-history-user').val();
		$('.im-history-row').each(function () {
			var $r = $(this);
			var ok = (!q || String($r.data('text')).indexOf(q) >= 0) && (!type || $r.data('type') === type) && (!user || String($r.data('user')) === user);
			$r.toggle(ok);
			if (!ok) $r.next('.im-history-detail').prop('hidden', true);
		});
	}
	$('#im-history-search').on('input', filterHistory);
	$('#im-history-type, #im-history-user').on('change', filterHistory);
	$(document).on('click', '.im-history-toggle', function () {
		var open = $(this).attr('aria-expanded') !== 'true';
		$(this).attr('aria-expanded', open).find('.dashicons').toggleClass('dashicons-arrow-right-alt2 dashicons-arrow-down-alt2');
		$('#' + $(this).attr('aria-controls')).prop('hidden', !open);
	});
	$(document).on('click', '.im-undo-one', function () {
		var $b = $(this);
		if (!confirm(t('¿Deshacer los cambios de esta imagen?'))) return;
		$b.prop('disabled', true);
		$.post(cfg.ajax_url, { action: 'inpulsia_medios_undo_one', nonce: cfg.nonce, token: $b.data('token'), id: $b.data('id') })
			.done(function (r) {
				if (!r.success) { alert(r.data); $b.prop('disabled', false); return; }
				$b.replaceWith(txt('span', r.data.status === 'error' ? r.data.msg : t('Deshecho'), r.data.status === 'error' ? 'log-error' : 'muted'));
			})
			.fail(function () { alert(t('Error de conexión con el servidor.')); $b.prop('disabled', false); });
	});

	// Keyboard shortcuts (never while typing in a field).
	var $keys = $('#im-keys'), lastKey = '', keysOpener = null;
	function toggleKeys(open) {
		$keys.prop('hidden', !open);
		if (open) { keysOpener = document.activeElement; $keys.find('.im-keys-close').trigger('focus'); }
		else if (keysOpener) keysOpener.focus();
	}
	$('.im-keys-open').on('click', function () { toggleKeys(true); });
	$keys.on('click', function (e) { if (e.target === this || $(e.target).is('.im-keys-close')) toggleKeys(false); });
	$(document).on('keydown', function (e) {
		var typing = $(e.target).is('input, textarea, select, [contenteditable]');
		if (e.key === 'Escape') {
			if (!$keys.prop('hidden')) return toggleKeys(false);
			if (!$('#inpulsia-medios-preview').prop('hidden')) return $('#inpulsia-medios-cancel').trigger('click');
		}
		if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && $('#inpulsia-medios-review').length) { e.preventDefault(); return $('#inpulsia-medios-review').trigger('click'); }
		if (typing || e.ctrlKey || e.metaKey || e.altKey) return;
		if (e.key === '?') { e.preventDefault(); return toggleKeys($keys.prop('hidden')); }
		if (e.key === '/' && $('.inpulsia-medios-filters [name="s"]').length) { e.preventDefault(); return $('.inpulsia-medios-filters [name="s"]').trigger('focus'); }
		if (lastKey === 'g' && cfg.urls[e.key]) { location.href = cfg.urls[e.key]; }
		lastKey = e.key;
		setTimeout(function () { lastKey = ''; }, 1000);
	});

	/* ---------- 1.9: Rendimiento ---------- */
	$('#im-health-now').on('click', function () {
		var $b = $(this).prop('disabled', true).addClass('is-loading').text(t('Comprobando…'));
		$.post(cfg.ajax_url, { action: 'inpulsia_medios_health_now', nonce: cfg.nonce }).always(function () { location.reload(); });
	});
	$(document).on('click', '.im-lcp-fix', function () {
		var $b = $(this).prop('disabled', true);
		$.post(cfg.ajax_url, { action: 'inpulsia_medios_lcp_fix', nonce: cfg.nonce, page: $b.data('page'), id: $b.data('id') })
			.done(function (r) { if (r.success) location.reload(); else { alert(r.data); $b.prop('disabled', false); } });
	});
	$('#im-perf-run').on('click', function () {
		var urls = $(this).data('urls') || [], jobs = [];
		urls.forEach(function (u) { jobs.push([u, 1366, 900], [u, 390, 844]); });
		if (!jobs.length) return;
		var $btn = $(this).prop('disabled', true), $box = $('#im-perf-progress').prop('hidden', false);
		var $bar = $box.find('.inpulsia-medios-progress > div'), $st = $box.find('.im-perf-status'), $frames = $box.find('.im-perf-frames');
		var i = 0, timer = null, done = null;
		window.addEventListener('message', function (e) { if (e.origin === location.origin && e.data && e.data.inpulsiaPerf && done) done(); });
		(function next() {
			if (i >= jobs.length) { $st.text(t('Análisis terminado.')); return setTimeout(function () { location.reload(); }, 800); }
			var j = jobs[i];
			$st.text(t('Analizando %s de %s: %s', i + 1, jobs.length, j[1] < 768 ? t('móvil') : t('escritorio')));
			$frames.empty().append($('<iframe>').attr({ src: j[0], width: j[1], height: j[2], tabindex: -1, title: '' }));
			done = function () { done = null; clearTimeout(timer); i++; $bar.css('width', Math.round(i / jobs.length * 100) + '%'); setTimeout(next, 300); };
			timer = setTimeout(function () { if (done) done(); }, 25000);
		})();
	});

	/* ---------- 1.10: today bar, image sheet, complete-what-is-missing ---------- */
	$('.im-today-undo').on('click', function () {
		var tok = $('.im-today').attr('data-token');
		if (!tok || !confirm(t('¿Deshacer todos los cambios que has guardado hoy desde la tabla?'))) return;
		run('inpulsia_medios_undo', tok, t('Deshaciendo los cambios de hoy'));
	});

	var FIELDS = ['filename', 'title', 'alt', 'caption', 'description'];
	var $sheet = $('#im-sheet'), $sheetRow = null;

	function rows() { return $grid.find('tbody tr[data-id]'); }

	function whereList($tr) {
		var w = [];
		try { w = JSON.parse($tr.attr('data-where') || '[]'); } catch (e) {}
		return w;
	}

	function openSheet($tr) {
		$sheetRow = $tr;
		rows().removeClass('is-open');
		$tr.addClass('is-open');
		$sheet.find('.im-sheet-id').text('#' + $tr.data('id') + ' · ' + String($tr.data('ext') || '').toUpperCase());
		$sheet.find('.im-sheet-img').attr('href', $tr.find('.im-thumb').attr('href')).find('img').attr('src', $tr.data('large') || $tr.find('.im-thumb img').attr('src'));
		$sheet.find('.im-sheet-info').text($tr.data('info') || '');
		var $w = $sheet.find('.im-sheet-where').empty(), w = whereList($tr);
		if (w.length) {
			$w.append(txt('strong', t('Se usa en:') + ' '));
			w.forEach(function (x, i) {
				if (i) $w.append(', ');
				$w.append($('<a target="_blank" rel="noopener">').attr('href', x.u).text(x.t));
				if (x.k) $w.append(txt('span', ' (' + x.k + ')', 'muted'));
			});
		} else $w.append(txt('span', t('Sin uso detectado'), 'muted'));
		sheetSync();
		$sheet.prop('hidden', false);
		$('body').addClass('im-sheet-open');
	}

	sheetSync = function () {
		if (!$sheetRow || $sheet.prop('hidden') && !$sheetRow) return;
		FIELDS.forEach(function (f) {
			var $src = $sheetRow.find('[data-field="' + f + '"]'), $box = $sheet.find('.im-sheet-field[data-for="' + f + '"]');
			var $in = $box.find('input, textarea');
			if (!$in.is(':focus')) $in.val($src.val());
			$in.attr('placeholder', $src.attr('placeholder') || '');
			var $td = $src.closest('td');
			$box.find('.im-sheet-state').text($td.hasClass('is-saving') ? t('guardando…') : $td.hasClass('is-save-error') ? t('no se ha guardado') : $td.hasClass('is-saved') ? '✓ ' + t('guardado') : '')
				.attr('class', 'im-sheet-state' + ($td.hasClass('is-save-error') ? ' is-error' : ''));
			$box.find('.im-sheet-hint').text($td.find('.im-hint').text() + (f === 'alt' ? ' ' + $td.find('.inpulsia-medios-chars').text() : ''));
		});
	};

	function closeSheet() {
		$sheet.prop('hidden', true);
		$('body').removeClass('im-sheet-open');
		if ($sheetRow) { $sheetRow.removeClass('is-open').find('.im-thumb').trigger('focus'); }
		$sheetRow = null;
	}

	function moveSheet(step) {
		if (!$sheetRow) return;
		var $r = rows(), i = $r.index($sheetRow) + step;
		if (i >= 0 && i < $r.length) openSheet($r.eq(i));
	}

	if ($grid.length) {
		$grid.on('click', '.im-thumb', function (e) {
			if (e.ctrlKey || e.metaKey || e.shiftKey) return;
			e.preventDefault();
			openSheet($(this).closest('tr'));
			$sheet.find('#im-sheet-alt').trigger('focus');
		});
		$sheet.on('input', 'input, textarea', function () {
			if (!$sheetRow) return;
			var f = $(this).closest('.im-sheet-field').data('for');
			setField($sheetRow.find('[data-field="' + f + '"]'), $(this).val());
		});
		$sheet.on('change', 'input, textarea', function () {
			if (!$sheetRow) return;
			save($sheetRow.find('[data-field="' + $(this).closest('.im-sheet-field').data('for') + '"]'));
		});
		$sheet.on('keydown', 'input', function (e) {
			if (e.key === 'Tab' && !e.shiftKey && !this.value && this.placeholder) { e.preventDefault(); $(this).val(this.placeholder).trigger('input').trigger('change'); }
			if (e.key === 'Enter') { e.preventDefault(); $(this).trigger('change'); moveSheet(1); }
		});
		$sheet.on('keydown', function (e) {
			if (e.altKey && e.key === 'ArrowDown') { e.preventDefault(); $(document.activeElement).trigger('change'); moveSheet(1); }
			if (e.altKey && e.key === 'ArrowUp') { e.preventDefault(); $(document.activeElement).trigger('change'); moveSheet(-1); }
			if (e.key === 'Escape') { e.stopPropagation(); $(document.activeElement).trigger('change'); closeSheet(); }
		});
		$sheet.find('.im-sheet-close').on('click', closeSheet);
		$sheet.find('.im-sheet-next').on('click', function () { moveSheet(1); });
		$sheet.find('.im-sheet-prev').on('click', function () { moveSheet(-1); });
		$sheet.find('.im-sheet-suggest').on('click', function () {
			var $b = $(this).prop('disabled', true).addClass('is-busy');
			suggest($sheetRow).always(function () { $b.prop('disabled', false).removeClass('is-busy'); sheetSync(); });
		});
	}

	/* Complete what is missing: one image and one field at a time. */
	var $focus = $('#im-focus'), queue = [], qi = 0, qTotal = 0;

	function missingField($tr) {
		if ($tr.data('lost')) return '';
		if (!($tr.find('[data-field="alt"]').val() || '').trim()) return 'alt';
		if (CAMERA.test($tr.find('[data-field="filename"]').val() || '')) return 'filename';
		return '';
	}

	function showFocus() {
		while (qi < queue.length && !missingField(queue[qi])) qi++;
		if (qi >= queue.length) {
			$focus.find('h2').text(t('¡Listo! No queda nada pendiente en esta página.'));
			$focus.find('.im-focus-img, .im-focus-input, .im-focus-save, .im-focus-skip, .im-focus-ai, .im-focus-hint, .im-focus-where').hide();
			$focus.find('.im-focus-count').text('');
			return $focus.find('.im-focus-close').trigger('focus');
		}
		var $tr = queue[qi], f = missingField($tr), $src = $tr.find('[data-field="' + f + '"]');
		$focus.find('.im-focus-img, .im-focus-input, .im-focus-save, .im-focus-skip, .im-focus-ai, .im-focus-hint, .im-focus-where').show();
		$focus.find('.im-focus-count').text(t('%s de %s', qi + 1, qTotal));
		$focus.find('h2').text(f === 'alt' ? t('Describe lo que se ve (texto alternativo)') : t('Pon un nombre de archivo claro'));
		$focus.find('.im-focus-img').attr('src', $tr.data('large') || $tr.find('.im-thumb img').attr('src'));
		var w = whereList($tr);
		$focus.find('.im-focus-where').text(w.length ? t('Se usa en:') + ' ' + w.map(function (x) { return x.t + (x.k ? ' (' + x.k + ')' : ''); }).join(', ') : t('Sin uso detectado'));
		$focus.find('.im-focus-input').val(f === 'filename' ? $src.val() : '').attr('placeholder', $src.attr('placeholder') || '').data('field', f).trigger('focus').trigger('select');
		$focus.find('.im-focus-hint').text(f === 'filename' ? t('Solo letras, números y guiones. La URL antigua redirigirá a la nueva.') : t('Tab acepta la sugerencia en gris.'));
	}

	function focusSave() {
		var $tr = queue[qi], $in = $focus.find('.im-focus-input'), v = $in.val().trim() || ($in.data('field') === 'alt' ? '' : '');
		if (!v) return $in.trigger('focus');
		var $f = $tr.find('[data-field="' + $in.data('field') + '"]');
		setField($f, $in.data('field') === 'filename' ? slug(v) : v);
		save($f);
		qi++;
		showFocus();
	}

	$('#im-focus-mode').on('click', function () {
		queue = rows().map(function () { return $(this); }).get().filter(function ($tr) { return missingField($tr); });
		qi = 0;
		qTotal = queue.length;
		if (!qTotal) return alert(t('En esta página no falta nada. Usa los atajos «Sin alt» o «Nombres de cámara» para ver más.'));
		$focus.prop('hidden', false);
		showFocus();
	});
	$focus.find('.im-focus-save').on('click', focusSave);
	$focus.find('.im-focus-skip').on('click', function () { qi++; showFocus(); });
	$focus.find('.im-focus-close').on('click', function () { $focus.prop('hidden', true); $('#im-focus-mode').trigger('focus'); });
	$focus.find('.im-focus-ai').on('click', function () {
		var $b = $(this).prop('disabled', true).addClass('is-busy'), $tr = queue[qi], f = $focus.find('.im-focus-input').data('field');
		$.post(cfg.ajax_url, { action: 'inpulsia_medios_suggest', nonce: cfg.nonce, id: $tr.data('id') }, null, 'json')
			.done(function (res) { if (res.success && res.data[f]) $focus.find('.im-focus-input').val(res.data[f]).trigger('focus'); else alert(res.data || t('Error')); })
			.always(function () { $b.prop('disabled', false).removeClass('is-busy'); });
	});
	$focus.on('keydown', function (e) {
		var $in = $focus.find('.im-focus-input');
		if (e.key === 'Escape') { e.stopPropagation(); return $focus.find('.im-focus-close').trigger('click'); }
		if (!$(e.target).is($in)) return;
		if (e.key === 'Enter') { e.preventDefault(); focusSave(); }
		if (e.key === 'Tab' && !e.shiftKey && !$in.val() && $in.attr('placeholder')) { e.preventDefault(); $in.val($in.attr('placeholder')); }
	});

	$(document).on('click', '.im-relink', function () {
		var $b = $(this);
		if (!confirm(t('¿Usar %s como archivo de esta imagen? Se regeneran sus tamaños y las páginas pasan a usarlo.', $b.data('name')))) return;
		$b.prop('disabled', true).addClass('is-busy');
		$.post(cfg.ajax_url, { action: 'inpulsia_medios_relink', nonce: cfg.nonce, id: $b.data('id'), name: $b.data('name') }, null, 'json')
			.done(function (r) {
				if (!r.success) { alert(r.data); $b.prop('disabled', false).removeClass('is-busy'); return; }
				$b.closest('td').empty().append(txt('strong', '✓ ' + t('Imagen recuperada.'), 'im-text-ok'));
			})
			.fail(function () { alert(t('Error de conexión con el servidor.')); $b.prop('disabled', false).removeClass('is-busy'); });
	});

	// Similar files for each lost image, one request at a time.
	(function nextCandidates() {
		var $box = $('.im-candidates-list').not('.is-done').first();
		if (!$box.length) return;
		$box.addClass('is-done');
		var id = $box.data('id');
		$.post(cfg.ajax_url, { action: 'inpulsia_medios_lost_candidates', nonce: cfg.nonce, id: id }, null, 'json')
			.done(function (r) {
				$box.empty();
				if (!r.success) return $box.append(txt('span', r.data || t('Error'), 'log-error'));
				if (!r.data.length) return $box.append(txt('span', t('Ninguno: vuelve a subirla o cámbiala en las páginas.'), 'muted'));
				r.data.forEach(function (c) {
					$box.append($('<div class="im-candidate">').append(
						$('<a target="_blank" rel="noopener">').attr('href', c.url).append($('<img alt="" loading="lazy">').attr('src', c.url)),
						$('<span>').append(txt('code', c.name), '<br>', txt('small', c.size + ' · ' + c.date + ' · ' + c.why, 'muted')),
						$('<button type="button" class="button button-small im-relink">').attr({ 'data-id': id, 'data-name': c.name }).text(t('Usar este'))
					));
				});
			})
			.fail(function (xhr) { $box.empty().append(txt('span', t('No se ha podido buscar: %s', (xhr.responseText || xhr.status || '').toString().replace(/<[^>]+>/g, ' ').trim().slice(0, 200)), 'log-error')); })
			.always(nextCandidates);
	})();

	$('.im-opt-run').on('click', function () {
		var n = $(this).data('total');
		if (n > 50 && !confirm(t('Se van a revisar %s imágenes. Puede tardar varios minutos. ¿Seguir?', n))) return;
		optimize('filter', $(this).data('params'));
	});

})(jQuery);
