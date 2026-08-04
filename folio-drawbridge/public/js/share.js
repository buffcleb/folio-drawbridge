/*
 * Folio Drawbridge — public share page: email step, OTP step, downloads.
 *
 * Extracted from inline output. Values that depend on PHP arrive via
 * wp_localize_script() as the folioDrawbridgeData object.
 */

// dlToken is issued at runtime by the verify step; the rest arrives localized.
folioDrawbridgeData.dlToken = null;


function folioDrawbridgeRequestOtp() {
	var email = document.getElementById('folio-drawbridge-email').value.trim();
	if (!email) { folioDrawbridgeShowError('folio-drawbridge-email-error', 'Please enter your email address.'); return; }
	folioDrawbridgeHideError('folio-drawbridge-email-error');
	folioDrawbridgePost({ action: 'folio_drawbridge_request_otp', share_id: folioDrawbridgeData.shareId, email: email, _wpnonce: folioDrawbridgeData.nonce })
		.then(function(r) {
			if (r.success) {
				document.getElementById('folio-drawbridge-step-email').style.display = 'none';
				document.getElementById('folio-drawbridge-step-otp').style.display   = '';
			} else {
				folioDrawbridgeShowError('folio-drawbridge-email-error', r.data || 'An error occurred.');
			}
		});
}

function folioDrawbridgeVerifyOtp() {
	var email = document.getElementById('folio-drawbridge-email').value.trim();
	var otp   = document.getElementById('folio-drawbridge-otp').value.trim();
	if (!otp)  { folioDrawbridgeShowError('folio-drawbridge-otp-error', 'Please enter the verification code.'); return; }
	folioDrawbridgeHideError('folio-drawbridge-otp-error');
	folioDrawbridgePost({ action: 'folio_drawbridge_verify_otp', share_id: folioDrawbridgeData.shareId, email: email, otp: otp, _wpnonce: folioDrawbridgeData.nonce })
		.then(function(r) {
			if (r.success) {
				folioDrawbridgeData.dlToken = r.data.download_token;
				folioDrawbridgeRenderFiles(r.data);
				document.getElementById('folio-drawbridge-step-otp').style.display   = 'none';
				document.getElementById('folio-drawbridge-step-files').style.display  = '';
			} else {
				folioDrawbridgeShowError('folio-drawbridge-otp-error', r.data || 'Verification failed.');
			}
		});
}

/**
 * Builds the file list from the verified-OTP response.
 * Uses textContent throughout so a filename can never inject markup.
 */
function folioDrawbridgeRenderFiles(data) {
	var ul = document.getElementById('folio-drawbridge-file-list');
	ul.innerHTML = '';

	var files = data.files || [];
	document.getElementById('folio-drawbridge-no-files').style.display = files.length ? 'none' : '';

	if (data.limit_note) {
		var note = document.getElementById('folio-drawbridge-dl-limit');
		note.textContent = data.limit_note;
		note.style.display = '';
	}

	files.forEach(function(f) {
		var li = document.createElement('li');

		var nameEl = document.createElement('span');
		nameEl.className = 'folio-drawbridge-file-name';
		nameEl.textContent = f.name;

		var sizeEl = document.createElement('span');
		sizeEl.className = 'folio-drawbridge-file-size';
		sizeEl.textContent = f.size;

		var btn = document.createElement('a');
		btn.className = 'folio-drawbridge-btn folio-drawbridge-btn-sm';
		btn.href = '#';
		btn.id = 'folio-drawbridge-dl-' + f.id;
		btn.textContent = 'Download';
		btn.onclick = function() { folioDrawbridgeDownload(f.id, f.name); return false; };

		li.appendChild(nameEl);
		li.appendChild(sizeEl);
		li.appendChild(btn);
		ul.appendChild(li);
	});

	folioDrawbridgeData.zipName = data.zip_name || '';
	document.getElementById('folio-drawbridge-zip-wrap').style.display = data.zip_available ? '' : 'none';
}

function folioDrawbridgeBackToEmail() {
	document.getElementById('folio-drawbridge-step-otp').style.display   = 'none';
	document.getElementById('folio-drawbridge-step-email').style.display  = '';
	document.getElementById('folio-drawbridge-otp').value = '';
	folioDrawbridgeHideError('folio-drawbridge-otp-error');
}

function folioDrawbridgeTriggerDownload(url, fileName) {
	var a = document.createElement('a');
	a.href = url;
	// An explicit name beats an empty download attribute, which makes the
	// browser guess from the URL path (giving "download" / "admin-ajax").
	if (fileName) { a.download = fileName; }
	a.style.display = 'none';
	document.body.appendChild(a); a.click(); document.body.removeChild(a);
}

function folioDrawbridgeDownload(fileId, fileName) {
	if (!folioDrawbridgeData.dlToken) return;
	folioDrawbridgeTriggerDownload(
		folioDrawbridgeData.homeBase + '?folio_drawbridge_download=' + fileId + '&dt=' + encodeURIComponent(folioDrawbridgeData.dlToken),
		fileName
	);
}

function folioDrawbridgeDownloadZip() {
	if (!folioDrawbridgeData.dlToken) return;
	folioDrawbridgeTriggerDownload(
		folioDrawbridgeData.ajaxUrl + '?action=folio_drawbridge_zip_download&dt=' + encodeURIComponent(folioDrawbridgeData.dlToken),
		folioDrawbridgeData.zipName
	);
}

function folioDrawbridgePost(data) {
	var body = new URLSearchParams();
	Object.keys(data).forEach(function(k){ body.append(k, data[k]); });
	return fetch(folioDrawbridgeData.ajaxUrl, { method: 'POST', body: body,
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
	}).then(function(r){ return r.json(); });
}

function folioDrawbridgeShowError(id, msg) { var el = document.getElementById(id); el.textContent = msg; el.style.display = ''; }
function folioDrawbridgeHideError(id) { document.getElementById(id).style.display = 'none'; }

