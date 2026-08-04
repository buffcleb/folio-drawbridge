/*
 * Folio Drawbridge — Settings: encryption-key generator modal.
 *
 * Extracted from inline output. Values that depend on PHP arrive via
 * wp_localize_script() as the folioDrawbridgeSettings object.
 */

var folioDrawbridgeGeneratedKey = '';

function folioDrawbridgeOpenKeyModal() {
	document.getElementById('folio-drawbridge-key-modal-overlay').style.display = 'flex';
	document.getElementById('folio-drawbridge-key-understand').checked = false;
	document.getElementById('folio-drawbridge-key-reveal').style.display = 'none';
	document.getElementById('folio-drawbridge-key-output').value = '';
	document.getElementById('folio-drawbridge-copy-confirm').style.display = 'none';
	folioDrawbridgeGeneratedKey = '';
	folioDrawbridgeFetchKey();
}

function folioDrawbridgeFetchKey() {
	document.getElementById('folio-drawbridge-key-loading').style.display = 'block';
	var body = new URLSearchParams({ action: 'folio_drawbridge_generate_key_preview', _wpnonce: folioDrawbridgeSettings.keyNonce });
	fetch(folioDrawbridgeSettings.ajaxUrl, { method: 'POST', body: body,
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
	})
	.then(function(r){ return r.json(); })
	.then(function(r) {
		document.getElementById('folio-drawbridge-key-loading').style.display = 'none';
		if (r.success) {
			folioDrawbridgeGeneratedKey = r.data.key;
			document.getElementById('folio-drawbridge-key-output').value = "define( 'FOLIO_DRAWBRIDGE_MASTER_KEY', '" + r.data.key + "' );";
		}
	});
}

function folioDrawbridgeToggleKeyReveal() {
	var checked = document.getElementById('folio-drawbridge-key-understand').checked;
	document.getElementById('folio-drawbridge-key-reveal').style.display = checked && folioDrawbridgeGeneratedKey ? '' : 'none';
}

function folioDrawbridgeCopyKey() {
	var el = document.getElementById('folio-drawbridge-key-output');
	var key = el.value;
	var confirm = document.getElementById('folio-drawbridge-copy-confirm');
	if (navigator.clipboard && navigator.clipboard.writeText) {
		navigator.clipboard.writeText(key).then(function() {
			confirm.style.display = 'inline';
			setTimeout(function(){ confirm.style.display = 'none'; }, 3000);
		});
	} else {
		// Fallback for older browsers.
		el.select();
		document.execCommand('copy');
		confirm.style.display = 'inline';
		setTimeout(function(){ confirm.style.display = 'none'; }, 3000);
	}
}

function folioDrawbridgeCloseKeyModal() {
	document.getElementById('folio-drawbridge-key-modal-overlay').style.display = 'none';
}

// Close on overlay click.
document.getElementById('folio-drawbridge-key-modal-overlay').addEventListener('click', function(e) {
	if (e.target === this) folioDrawbridgeCloseKeyModal();
});

// ── Show "apply to existing" checkboxes when settings change ────────────
(function() {
	var dlFields     = ['folio_drawbridge_allow_unlimited_downloads', 'folio_drawbridge_default_max_downloads', 'folio_drawbridge_max_download_limit'];
	var expiryFields = ['folio_drawbridge_allow_no_expiry', 'folio_drawbridge_default_expiry_days', 'folio_drawbridge_max_expiry_days'];

	function getVal(name) {
		var el = document.querySelector('[name="' + name + '"]');
		if (!el) return null;
		return el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
	}

	var dlOrig = {}, expiryOrig = {};
	dlFields.forEach(function(n)     { dlOrig[n]     = getVal(n); });
	expiryFields.forEach(function(n) { expiryOrig[n] = getVal(n); });

	function checkDl() {
		var changed = dlFields.some(function(n) { return getVal(n) !== dlOrig[n]; });
		var wrap = document.getElementById('folio-drawbridge-dl-enforce-wrap');
		wrap.style.display = changed ? '' : 'none';
		if (!changed) wrap.querySelector('input[type=checkbox]').checked = false;
	}

	function checkExpiry() {
		var changed = expiryFields.some(function(n) { return getVal(n) !== expiryOrig[n]; });
		var wrap = document.getElementById('folio-drawbridge-expiry-enforce-wrap');
		wrap.style.display = changed ? '' : 'none';
		if (!changed) wrap.querySelector('input[type=checkbox]').checked = false;
	}

	dlFields.forEach(function(n) {
		var el = document.querySelector('[name="' + n + '"]');
		if (el) el.addEventListener('change', checkDl);
	});
	expiryFields.forEach(function(n) {
		var el = document.querySelector('[name="' + n + '"]');
		if (el) el.addEventListener('change', checkExpiry);
	});
})();

