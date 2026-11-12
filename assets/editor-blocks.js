/* Inpulsia Medios panel for the core/image block. Plain JS (no build step). */
(function (wp, cfg) {
	'use strict';
	if (!wp || !cfg) return;

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var Button = wp.components.Button;
	var Notice = wp.components.Notice;

	function t(text) {
		var out = (cfg.i18n && cfg.i18n[text]) || text, args = arguments, i = 1;
		return out.replace(/%s/g, function () { return args[i++]; });
	}

	function post(action, data) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', cfg.nonce);
		Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
		return fetch(cfg.ajax_url, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) { return r.json(); });
	}

	function Panel(props) {
		var id = props.attributes.id;
		var s = useState(null), info = s[0], setInfo = s[1];
		var b = useState(false), busy = b[0], setBusy = b[1];
		var m = useState(''), msg = m[0], setMsg = m[1];

		useEffect(function () {
			setInfo(null);
			setMsg('');
			if (!id) return;
			post('inpulsia_medios_image_info', { id: id }).then(function (res) { if (res.success) setInfo(res.data); });
		}, [id]);

		if (!id || !info) return null;

		function run(promise, done) {
			setBusy(true);
			setMsg(t('Trabajando…'));
			promise.then(function (res) {
				if (!res.success) { setMsg(res.data || 'Error'); return; }
				done(res.data);
			}).catch(function () { setMsg(t('Error de conexión con el servidor.')); }).finally(function () { setBusy(false); });
		}

		function optimize() {
			run(post('inpulsia_medios_opt_one', { id: id }), function (d) {
				setInfo(d.info);
				props.setAttributes({ url: d.info.url });
				setMsg(d.msg + ' ' + t('Guarda la página para conservar el cambio.'));
			});
		}

		function suggest() {
			run(post('inpulsia_medios_suggest', { id: id }), function (d) {
				if (d.alt) {
					props.setAttributes({ alt: d.alt });
					post('inpulsia_medios_autosave', { id: id, field: 'alt', value: d.alt });
					setInfo(Object.assign({}, info, { alt: d.alt }));
				}
				setMsg(d.alt || '');
			});
		}

		function saveAlt() {
			run(post('inpulsia_medios_autosave', { id: id, field: 'alt', value: props.attributes.alt || '' }), function (d) {
				setInfo(Object.assign({}, info, { alt: d.value }));
				setMsg(t('Guardado.'));
			});
		}

		var blockAlt = props.attributes.alt || '';
		var warnings = [];
		if (info.heavy) warnings.push(t('Pesa más de 300 KB: conviene optimizarla.'));
		if (info.bad_name) warnings.push(t('Nombre poco amigable para Google.'));
		if (!blockAlt && !info.alt) warnings.push(t('Falta el texto alternativo.'));

		return el(InspectorControls, null,
			el(PanelBody, { title: t('Inpulsia Medios'), initialOpen: warnings.length > 0 },
				el('p', null, el('strong', null, t('Peso') + ': '), info.size, ' · ', el('strong', null, t('Nombre de archivo') + ': '), info.name),
				warnings.length
					? warnings.map(function (w, i) { return el(Notice, { key: i, status: 'warning', isDismissible: false }, w); })
					: el(Notice, { status: 'success', isDismissible: false }, t('Todo correcto.')),
				info.alt && info.alt !== blockAlt ? el('p', null, el('strong', null, t('Alt en la biblioteca') + ': '), info.alt) : null,
				el('div', { style: { display: 'flex', flexWrap: 'wrap', gap: '6px', marginTop: '8px' } },
					info.heavy ? el(Button, { variant: 'primary', isBusy: busy, disabled: busy, onClick: optimize }, t('Optimizar')) : null,
					cfg.has_ai ? el(Button, { variant: 'secondary', isBusy: busy, disabled: busy, onClick: suggest }, t('Sugerir alt con IA')) : null,
					info.alt && !blockAlt ? el(Button, { variant: 'secondary', disabled: busy, onClick: function () { props.setAttributes({ alt: info.alt }); } }, t('Usar el alt de la biblioteca')) : null,
					blockAlt && blockAlt !== info.alt ? el(Button, { variant: 'tertiary', disabled: busy, onClick: saveAlt }, t('Guardar este alt en la biblioteca')) : null
				),
				msg ? el('p', { style: { marginTop: '8px' } }, msg) : null,
				el('p', null, el('a', { href: info.edit, target: '_blank', rel: 'noopener' }, t('Abrir en Inpulsia Medios')))
			)
		);
	}

	var withPanel = wp.compose.createHigherOrderComponent(function (BlockEdit) {
		return function (props) {
			if (props.name !== 'core/image' || !props.isSelected) return el(BlockEdit, props);
			return el(wp.element.Fragment, null, el(BlockEdit, props), el(Panel, props));
		};
	}, 'inpulsiaMediosPanel');

	wp.hooks.addFilter('editor.BlockEdit', 'inpulsia-medios/panel', withPanel);
})(window.wp, window.inpulsiaMediosEditor);
