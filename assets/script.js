(function ($) {
	'use strict';

	var cfg = window.inpulsiaMedios;
	var token = null;

	function txt(tag, text, cls) {
		var $el = $('<' + tag + '>').text(text == null ? '' : String(text));
		if (cls) $el.addClass(cls);
		return $el;
	}

	function idLink(id) {
		return $('<a target="_blank" rel="noopener">').attr('href', cfg.edit_url + encodeURIComponent(id)).text(id);
	}

	function shown(v) {
		return v === '' ? '(vacío)' : v;
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

		$.ajax({ url: cfg.ajax_url, type: 'POST', data: fd, processData: false, contentType: false })
			.done(function (res) {
				if (!res.success) return alert(res.data || 'Error al procesar el archivo.');
				token = res.data.token;
				renderPreview(res.data);
			})
			.fail(function () { alert('Error de conexión.'); })
			.always(function () { $spinner.removeClass('is-active'); });
	});

	/* ---------- Inline editing ---------- */

	var $grid = $('.inpulsia-medios-grid');

	function fieldValue($input) {
		return $input.is('textarea') ? $input.val() : $input.val();
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
		$('#inpulsia-medios-dirty').text(n ? n + ' imágenes modificadas' : '');
		var $alt = $tr.find('[data-field="alt"]');
		var len = $alt.val().length;
		$tr.find('.inpulsia-medios-chars').text(len + '/125').toggleClass('too-long', len > 125);
	}

	if ($grid.length) {
		$grid.find('tbody tr[data-id]').each(function () { updateRowState($(this)); });
		$grid.on('input', '[data-field]', function () { updateRowState($(this).closest('tr')); });

		$('#inpulsia-medios-check-all').on('change', function () {
			$grid.find('.inpulsia-medios-row-check').prop('checked', this.checked);
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
		if (!rows.length) return alert('No has cambiado nada.');

		var $btn = $(this).prop('disabled', true);
		$.post(cfg.ajax_url, { action: 'inpulsia_medios_stage', nonce: cfg.nonce, rows: JSON.stringify(rows) })
			.done(function (res) {
				if (!res.success) return alert(res.data || 'Error.');
				token = res.data.token;
				$('#inpulsia-medios-grid-wrap').prop('hidden', true);
				renderPreview(res.data);
			})
			.fail(function () { alert('Error de conexión.'); })
			.always(function () { $btn.prop('disabled', false); });
	});

	/* ---------- AI suggestions ---------- */

	function suggest($tr) {
		var $btn = $tr.find('.inpulsia-medios-suggest').prop('disabled', true).addClass('is-busy');
		$tr.find('.ai-error').remove();
		return $.post(cfg.ajax_url, { action: 'inpulsia_medios_suggest', nonce: cfg.nonce, id: $tr.data('id') })
			.done(function (res) {
				if (!res.success) {
					$tr.find('.col-ai').append(txt('div', res.data || 'Error', 'ai-error'));
					return;
				}
				$.each(['alt', 'filename', 'title'], function (_, f) {
					if (res.data[f]) $tr.find('[data-field="' + f + '"]').val(res.data[f]).addClass('is-ai');
				});
				updateRowState($tr);
			})
			.fail(function () { $tr.find('.col-ai').append(txt('div', 'Error de conexión', 'ai-error')); })
			.always(function () { $btn.prop('disabled', false).removeClass('is-busy'); });
	}

	$grid.on('click', '.inpulsia-medios-suggest', function () { suggest($(this).closest('tr')); });

	$('#inpulsia-medios-suggest-selected').on('click', function () {
		var queue = $grid.find('.inpulsia-medios-row-check:checked').closest('tr').toArray();
		if (!queue.length) return alert('Selecciona al menos una imagen.');
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

	/* ---------- Preview ---------- */

	function renderPreview(data) {
		var $tbody = $('#inpulsia-medios-table tbody').empty();
		var warnCount = 0, errCount = 0;

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

			var $changes = $('<td>');
			var keys = Object.keys(item.changes);
			if (!keys.length) {
				$changes.append(txt('em', 'Sin cambios', 'muted'));
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
			txt('span', data.total + ' medios con cambios', 'pill pill-ok'),
			warnCount ? txt('span', warnCount + ' con avisos', 'pill pill-warn') : '',
			errCount ? txt('span', errCount + ' con errores', 'pill pill-error') : ''
		);
		$('#inpulsia-medios-apply').prop('disabled', !data.total).text('Aplicar ' + data.total + ' cambios');
		$('#inpulsia-medios-only-warn').prop('checked', false);
		$('#inpulsia-medios-preview').prop('hidden', false);
	}

	$('#inpulsia-medios-only-warn').on('change', function () {
		$('#inpulsia-medios-table tbody tr').not('[data-warn]').toggle(!this.checked);
	});

	$('#inpulsia-medios-cancel').on('click', function () {
		$('#inpulsia-medios-preview').prop('hidden', true);
		$('#inpulsia-medios-grid-wrap').prop('hidden', false);
		token = null;
	});

	$('#inpulsia-medios-apply').on('click', function () {
		if (!token || !confirm('¿Aplicar los cambios? Podrás deshacerlos desde la pestaña Historial.')) return;
		$('#inpulsia-medios-preview').prop('hidden', true);
		window.inpulsiaMediosSaving = true;
		run('inpulsia_medios_apply', token, 'Aplicando cambios');
	});

	$(document).on('click', '.inpulsia-medios-undo', function () {
		if (!confirm('¿Deshacer esta importación y restaurar los valores anteriores?')) return;
		$('.inpulsia-medios-undo').prop('disabled', true);
		run('inpulsia_medios_undo', $(this).data('token'), 'Deshaciendo importación');
	});

	/* ---------- Batch runner ---------- */

	function run(action, tok, title) {
		var $box = $('#inpulsia-medios-run').prop('hidden', false);
		var $bar = $box.find('.inpulsia-medios-progress > div').css('width', '0%');
		var $status = $('#inpulsia-medios-run-status');
		var $log = $('#inpulsia-medios-log').empty();
		var counts = { ok: 0, partial: 0, error: 0, skip: 0 };
		var purged = [];
		$('#inpulsia-medios-run-title').text(title);

		window.onbeforeunload = function () { return true; };

		(function step(offset) {
			$.post(cfg.ajax_url, { action: action, nonce: cfg.nonce, token: tok, offset: offset })
				.done(function (res) {
					if (!res.success) return finish(res.data || 'Error.');
					var d = res.data;
					$.each(d.results, function (_, r) {
						counts[r.status] = (counts[r.status] || 0) + 1;
						$log.append($('<p>').addClass('log-' + r.status).append($('<strong>').append('ID ', idLink(r.id), ': '), txt('span', r.msg)));
					});
					var pct = d.total ? Math.round(d.next / d.total * 100) : 100;
					$bar.css('width', pct + '%');
					$status.text(d.next + ' de ' + d.total + ' procesados');
					if (d.done) { purged = d.purged || []; finish(null); }
					else step(d.next);
				})
				.fail(function () {
					finish('Se ha perdido la conexión en el registro ' + offset + '. Lo ya procesado está guardado en el Historial.');
				});
		})(0);

		function finish(error) {
			window.onbeforeunload = null;
			if (error) {
				$status.empty().append(txt('span', error, 'log-error'));
				return;
			}
			$status.text('Terminado: ' + counts.ok + ' correctos, ' + counts.partial + ' parciales, ' + counts.error + ' errores, ' + counts.skip + ' sin cambios.');
			if (purged.length) $status.append($('<br>'), txt('span', 'Cachés vaciadas: ' + purged.join(', ') + '.', 'muted'));
			if (action === 'inpulsia_medios_undo') {
				setTimeout(function () { location.reload(); }, 1500);
				return;
			}
			$status.append(' ', $('<a>').attr('href', cfg.history).text('Ver historial / deshacer'));
			$('#inpulsia-medios-file').val('');
			if ($grid.length) {
				$status.append(' · ', $('<a href="#">').text('Volver a la tabla').on('click', function (e) { e.preventDefault(); location.reload(); }));
			}
		}
	}
})(jQuery);
