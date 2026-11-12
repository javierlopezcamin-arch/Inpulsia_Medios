/* Inpulsia Medios box for Elementor's Image widget panel. */
(function ($, cfg) {
	'use strict';
	if (!cfg) return;

	function t(text) {
		var out = (cfg.i18n && cfg.i18n[text]) || text, args = arguments, i = 1;
		return out.replace(/%s/g, function () { return args[i++]; });
	}

	function post(action, data) {
		return $.post(cfg.ajax_url, $.extend({ action: action, nonce: cfg.nonce }, data), null, 'json');
	}

	function render($box, info, view) {
		var warn = [];
		if (info.heavy) warn.push(t('Pesa más de 300 KB: conviene optimizarla.'));
		if (info.bad_name) warn.push(t('Nombre poco amigable para Google.'));
		if (!info.alt) warn.push(t('Falta el texto alternativo.'));
		$box.empty().append(
			$('<h4>').text(t('Inpulsia Medios')),
			$('<div class="im-ed-row">').append($('<span>').text(t('Peso')), $('<strong>').text(info.size).toggleClass('is-warn', info.heavy)),
			$('<div class="im-ed-row">').append($('<span>').text(t('Nombre de archivo')), $('<strong>').text(info.name).toggleClass('is-warn', info.bad_name)),
			$('<div class="im-ed-row">').append($('<span>').text(t('Alt en la biblioteca')), $('<strong>').text(info.alt || '—').toggleClass('is-warn', !info.alt)),
			$('<div>').addClass(warn.length ? 'is-warn' : 'is-ok').text(warn.length ? warn.join(' ') : t('Todo correcto.'))
		);
		var $msg = $('<div class="im-ed-msg">');
		function busy(on) { $box.find('button').prop('disabled', on); if (on) $msg.text(t('Trabajando…')); }
		function fail(res) { $msg.text((res && res.data) || t('Error de conexión con el servidor.')); }

		if (info.heavy) {
			$box.append($('<button type="button" class="elementor-button elementor-button-success">').text(t('Optimizar')).on('click', function () {
				busy(true);
				post('inpulsia_medios_opt_one', { id: info.id }).done(function (res) {
					if (!res.success) return fail(res);
					var image = { id: info.id, url: res.data.info.url };
					var container = view && view.getContainer ? view.getContainer() : null;
					if (window.$e && container) window.$e.run('document/elements/settings', { container: container, settings: { image: image } });
					render($box, res.data.info, view);
					$box.find('.im-ed-msg').text(res.data.msg + ' ' + t('Guarda la página para conservar el cambio.'));
				}).fail(function () { fail(); }).always(function () { busy(false); });
			}));
		}
		if (cfg.has_ai) {
			$box.append($('<button type="button" class="elementor-button">').text(t('Sugerir alt con IA')).on('click', function () {
				busy(true);
				post('inpulsia_medios_suggest', { id: info.id }).done(function (res) {
					if (!res.success || !res.data.alt) return fail(res);
					post('inpulsia_medios_autosave', { id: info.id, field: 'alt', value: res.data.alt }).done(function () {
						render($box, $.extend({}, info, { alt: res.data.alt }), view);
						$box.find('.im-ed-msg').text(t('Guardado.'));
					});
				}).fail(function () { fail(); }).always(function () { busy(false); });
			}));
		}
		$box.append($msg, $('<div>').append($('<a target="_blank" rel="noopener">').attr('href', info.edit).text(t('Abrir en Inpulsia Medios'))));
	}

	function attach(panel, model, view) {
		var image = model.getSetting ? model.getSetting('image') : null;
		var id = image && parseInt(image.id, 10);
		var $root = panel.$el.find('#elementor-panel-content-wrapper, #elementor-controls').first();
		$root.find('.im-ed').remove();
		model._imId = id;
		if (!id) return;
		var $box = $('<div class="im-ed">').text(t('Trabajando…'));
		$root.prepend($box);
		post('inpulsia_medios_image_info', { id: id }).done(function (res) {
			if (res.success) render($box, res.data, view); else $box.remove();
		}).fail(function () { $box.remove(); });
		// Follow image changes while the panel stays open.
		if (model.get && model.get('settings') && !model._imWatch) {
			model._imWatch = true;
			model.get('settings').on('change:image', function () {
				var current = panel.getCurrentPageView && panel.getCurrentPageView();
				if (!current || current.model !== model) return;
				var img = model.getSetting('image');
				if (img && parseInt(img.id, 10) === model._imId) return; // Same image (e.g. URL updated after optimizing).
				attach(panel, model, view);
			});
		}
	}

	$(window).on('elementor:init', function () {
		window.elementor.hooks.addAction('panel/open_editor/widget/image', attach);
	});
})(jQuery, window.inpulsiaMediosEditor);
