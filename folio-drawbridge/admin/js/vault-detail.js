/*
 * Folio Drawbridge — My Vaults detail view: chunked upload client and
 * inline edit toggles.
 *
 * Extracted from inline output. Values that depend on PHP arrive via
 * wp_localize_script() as the folioDrawbridgeUd object.
 */

function folioDrawbridgeUdGenId() {
	return Array.from(crypto.getRandomValues(new Uint8Array(16)))
		.map(function(b){ return b.toString(16).padStart(2,'0'); }).join('');
}
function folioDrawbridgeUdToggle(id) {
	var el = document.getElementById(id);
	el.style.display = el.style.display === 'none' ? '' : 'none';
}
async function folioDrawbridgeUdUploadOne(file, rowEl) {
	var bar = rowEl.querySelector('.folio-drawbridge-ud-bar');
	var lbl = rowEl.querySelector('.folio-drawbridge-ud-lbl');
	// wp_localize_script() casts every top-level scalar to a string, so this
	// arrives as "4194304". Number() is required before it is used in addition:
	// "start + CHUNK" would otherwise concatenate rather than add, and every
	// chunk after the first would be sliced to the end of the file.
	var CHUNK = Number(folioDrawbridgeUd.chunkSize);
	if (!CHUNK || CHUNK < 1) { throw new Error('Upload chunk size is not configured correctly.'); }
	var total = Math.ceil(file.size / CHUNK) || 1;
	var uid   = folioDrawbridgeUdGenId();
	for (var i = 0; i < total; i++) {
		var start = i * CHUNK;
		var fd = new FormData();
		fd.append('action',       'folio_drawbridge_upload_chunk');
		fd.append('_wpnonce',     folioDrawbridgeUd.nonce);
		fd.append('vault_id',     folioDrawbridgeUd.vaultId);
		fd.append('upload_id',    uid);
		fd.append('chunk_index',  i);
		fd.append('total_chunks', total);
		fd.append('file_name',    file.name);
		fd.append('total_size',   file.size);
		fd.append('chunk',        file.slice(start, Math.min(start + CHUNK, file.size)), file.name);
		var r = await fetch(folioDrawbridgeUd.ajaxUrl, {method:'POST', body:fd});
		var j = await r.json();
		if (!j.success) throw new Error(j.data || 'Upload failed.');
		var pct = Math.round((i + 1) / total * 100);
		bar.style.width = pct + '%';
		lbl.textContent = j.data.complete ? 'Done' : pct + '%';
	}
}
function folioDrawbridgeUdMakeRow(file) {
	var row = document.createElement('div');
	row.style.cssText = 'margin-bottom:6px;padding:8px 10px;background:#f6f7f7;border-radius:4px;font-size:12px;';
	row.innerHTML =
		'<div style="display:flex;justify-content:space-between;margin-bottom:4px;">'
		+ '<span style="font-weight:600;">' + folioDrawbridgeEsc(file.name) + '</span>'
		+ '<span class="folio-drawbridge-ud-lbl" style="color:#888;">Queued</span></div>'
		+ '<div style="background:#e0e0e0;border-radius:3px;height:8px;overflow:hidden;">'
		+ '<div class="folio-drawbridge-ud-bar" style="background:#2271b1;height:100%;width:0%;transition:width .2s;"></div></div>';
	return row;
}
function folioDrawbridgeEsc(s) {
	return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
async function folioDrawbridgeUdUpload() {
	var input  = document.getElementById('folio-drawbridge-ud-file-input');
	var errEl  = document.getElementById('folio-drawbridge-ud-upload-error');
	var queueEl = document.getElementById('folio-drawbridge-ud-file-queue');
	errEl.style.display = 'none';
	if (!input.files.length) {
		errEl.textContent = 'Please select at least one file.';
		errEl.style.display = '';
		return;
	}
	var btn   = document.getElementById('folio-drawbridge-ud-upload-btn');
	btn.disabled = true;
	queueEl.innerHTML = '';
	var files = Array.from(input.files);
	var rows  = files.map(function(f) {
		var row = folioDrawbridgeUdMakeRow(f);
		queueEl.appendChild(row);
		return row;
	});
	var hasError = false;
	for (var i = 0; i < files.length; i++) {
		var lbl = rows[i].querySelector('.folio-drawbridge-ud-lbl');
		lbl.textContent = 'Uploading…';
		try {
			await folioDrawbridgeUdUploadOne(files[i], rows[i]);
			lbl.style.color = '#0a3622';
		} catch(e) {
			lbl.textContent = 'Error: ' + e.message;
			lbl.style.color = '#d63638';
			hasError = true;
		}
	}
	if (!hasError) {
		window.location.reload();
	} else {
		btn.disabled = false;
	}
}

