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
				if (!res.success) return alert(res.data || 'Error al procesar el CSV.');
				token = res.data.token;
				renderPreview(res.data);
			})
			.fail(function () { alert('Error de conexión.'); })
			.always(function () { $spinner.removeClass('is-active'); });
	});

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
			errCount ? txt('span', errCount + ' IDs no encontrados', 'pill pill-error') : ''
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
		token = null;
	});

	$('#inpulsia-medios-apply').on('click', function () {
		if (!token || !confirm('¿Aplicar los cambios? Podrás deshacerlos desde la pestaña Historial.')) return;
		$('#inpulsia-medios-preview').prop('hidden', true);
		run('inpulsia_medios_apply', token, 'Aplicando cambios');
	});

	$(document).on('click', '.inpulsia-medios-undo', function () {
		if (!confirm('¿Deshacer esta importación y restaurar los valores anteriores?')) return;
		$('.inpulsia-medios-undo').prop('disabled', true);
		run('inpulsia_medios_undo', $(this).data('token'), 'Deshaciendo importación');
	});

	function run(action, tok, title) {
		var $box = $('#inpulsia-medios-run').prop('hidden', false);
		var $bar = $box.find('.inpulsia-medios-progress > div').css('width', '0%');
		var $status = $('#inpulsia-medios-run-status');
		var $log = $('#inpulsia-medios-log').empty();
		var counts = { ok: 0, partial: 0, error: 0, skip: 0 };
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
					if (d.done) finish(null);
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
			if (action === 'inpulsia_medios_undo') {
				setTimeout(function () { location.reload(); }, 1500);
			} else {
				$status.append(' ', $('<a>').attr('href', cfg.history).text('Ver historial / deshacer'));
				$('#inpulsia-medios-file').val('');
			}
		}
	}
})(jQuery);
