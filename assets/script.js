(function ($) {
	'use strict';

	// Export
	$('#inpulsia-medios-export').on('click', function () {
		window.location.href = inpulsiaMedios.ajax_url +
			'?action=inpulsia_medios_export&nonce=' + inpulsiaMedios.nonce;
	});

	// Upload & preview
	$('#inpulsia-medios-form').on('submit', function (e) {
		e.preventDefault();
		var file = $('#inpulsia-medios-file')[0].files[0];
		if (!file) return;

		var fd = new FormData();
		fd.append('action', 'inpulsia_medios_process');
		fd.append('action_type', 'preview');
		fd.append('nonce', inpulsiaMedios.nonce);
		fd.append('csv_file', file);

		$('#inpulsia-medios-preview, #inpulsia-medios-results').hide();

		$.ajax({
			url: inpulsiaMedios.ajax_url,
			type: 'POST',
			data: fd,
			processData: false,
			contentType: false,
			success: function (res) {
				if (!res.success) {
					alert(res.data || 'Error al procesar el CSV.');
					return;
				}
				renderPreview(res.data);
			},
			error: function () {
				alert('Error de conexión.');
			}
		});
	});

	function renderPreview(items) {
		var $tbody = $('#inpulsia-medios-table tbody').empty();
		$.each(items, function (i, item) {
			var row = '<tr>' +
				'<td>' + item.id + '</td>' +
				'<td>' + (item.thumb ? '<img src="' + item.thumb + '">' : '—') + '</td>' +
				'<td>' + diffCell(item.current_name, item.new_name) + '</td>' +
				'<td>' + diffCell(item.current_alt, item.new_alt) + '</td>' +
				'<td>' + diffCell(item.current_desc, item.new_desc) + '</td>' +
				'</tr>';
			$tbody.append(row);
		});
		$('#inpulsia-medios-preview').show();
	}

	function diffCell(current, next) {
		if (!next || next === current) {
			return escHtml(current) || '<em>vacío</em>';
		}
		return '<span>' + escHtml(current || '(vacío)') + '</span>' +
			'<span class="arrow">→</span>' +
			'<span class="changed">' + escHtml(next) + '</span>';
	}

	function escHtml(str) {
		var d = document.createElement('div');
		d.textContent = str;
		return d.innerHTML;
	}

	// Apply
	$('#inpulsia-medios-apply').on('click', function () {
		if (!confirm('¿Aplicar todos los cambios?')) return;

		var $btn = $(this).prop('disabled', true).text('Procesando…');

		$.post(inpulsiaMedios.ajax_url, {
			action: 'inpulsia_medios_process',
			action_type: 'apply',
			nonce: inpulsiaMedios.nonce
		}, function (res) {
			$btn.prop('disabled', false).text('Aplicar cambios');
			$('#inpulsia-medios-preview').hide();

			if (!res.success) {
				alert(res.data || 'Error.');
				return;
			}
			renderResults(res.data);
		});
	});

	$('#inpulsia-medios-cancel').on('click', function () {
		$('#inpulsia-medios-preview').hide();
	});

	function renderResults(items) {
		var $log = $('#inpulsia-medios-log').empty();
		$.each(items, function (i, item) {
			var cls = 'log-' + item.status;
			$log.append('<p class="' + cls + '"><strong>ID ' + item.id + ':</strong> ' + escHtml(item.msg) + '</p>');
		});
		$('#inpulsia-medios-results').show();
	}

})(jQuery);
