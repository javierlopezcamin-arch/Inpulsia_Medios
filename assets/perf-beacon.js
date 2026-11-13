/* Inpulsia Medios: measures images on the page for logged-in editors. No dependencies. */
(function (cfg) {
	'use strict';
	if (!cfg || !window.fetch || !window.PerformanceObserver) return;

	var forced = /[?&]inpulsia_perf=1/.test(location.search);
	var device = window.innerWidth < 768 ? 'mobile' : 'desktop';
	var key = 'inpulsiaPerf:' + cfg.page + ':' + device;
	try {
		// Normal browsing reports each page at most once a day; the analysis always reports.
		if (!forced && Date.now() - (+localStorage.getItem(key) || 0) < 864e5) return;
	} catch (e) { /* storage blocked: report anyway */ }

	var lcpEntry = null;
	try {
		new PerformanceObserver(function (list) {
			var e = list.getEntries();
			if (e.length) lcpEntry = e[e.length - 1];
		}).observe({ type: 'largest-contentful-paint', buffered: true });
	} catch (e) { /* not supported */ }

	function attachmentId(img) {
		var m = (img.className || '').match(/wp-image-(\d+)/);
		return m ? +m[1] : +(img.getAttribute('data-id') || 0);
	}

	function bytesOf(url) {
		var r = performance.getEntriesByName(url)[0];
		return r ? (r.encodedBodySize || r.transferSize || 0) : 0;
	}

	function abs(url) {
		try { return new URL(url, location.href).href; } catch (e) { return ''; }
	}

	function describe(img) {
		var src = abs(img.currentSrc || img.src);
		return {
			id: attachmentId(img),
			src: src,
			natural: img.naturalWidth,
			shown: Math.round(img.getBoundingClientRect().width * (window.devicePixelRatio || 1)),
			bytes: bytesOf(src),
			lazy: img.getAttribute('loading') === 'lazy' || /lazy/.test(img.className) && !!img.getAttribute('data-src'),
			fp: (img.getAttribute('fetchpriority') || '').toLowerCase()
		};
	}

	function collect() {
		var over = [];
		Array.prototype.forEach.call(document.images, function (img) {
			if (!img.complete || !img.naturalWidth) return;
			var d = describe(img);
			// More than twice the pixels needed, on a real photo (not icons).
			if (d.shown > 40 && d.natural > 600 && d.natural > d.shown * 2) over.push(d);
		});

		var lcp = null;
		if (lcpEntry) {
			var el = lcpEntry.element;
			if (el && el.tagName === 'IMG') {
				lcp = describe(el);
			} else if (lcpEntry.url) {
				var url = abs(lcpEntry.url);
				lcp = { id: 0, src: url, natural: 0, shown: 0, bytes: bytesOf(url), lazy: false, fp: '', bg: true };
			}
			if (lcp) lcp.ms = Math.round(lcpEntry.renderTime || lcpEntry.loadTime || lcpEntry.startTime);
		}

		var body = new FormData();
		body.append('action', 'inpulsia_medios_perf_report');
		body.append('nonce', cfg.nonce);
		body.append('page', cfg.page);
		body.append('device', device);
		body.append('data', JSON.stringify({ over: over.slice(0, 30), lcp: lcp }));
		fetch(cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' }).then(function () {
			try { localStorage.setItem(key, String(Date.now())); } catch (e) { /* ignore */ }
			if (forced && window.parent !== window) window.parent.postMessage({ inpulsiaPerf: cfg.page, device: device }, location.origin);
		});
	}

	function later() { setTimeout(collect, 2500); }
	if (document.readyState === 'complete') later();
	else window.addEventListener('load', later);
})(window.inpulsiaMediosPerf);
