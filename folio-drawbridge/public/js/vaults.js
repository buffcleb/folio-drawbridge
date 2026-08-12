/*
 * Folio Drawbridge — [folio_drawbridge_vaults] shortcode: vault CRUD,
 * chunked upload, and share creation.
 *
 * Extracted from inline output. Values that depend on PHP arrive via
 * wp_localize_script() as the folioDrawbridgeUserData object.
 */

// activeVaultId is runtime state; the rest arrives localized.
folioDrawbridgeUserData.activeVaultId = null;


function folioDrawbridgeOpenNewVaultModal() {
	document.getElementById('folio-drawbridge-vault-name').value='';
	document.getElementById('folio-drawbridge-vault-desc').value='';
	document.getElementById('folio-drawbridge-vault-expires').value='';
	folioDrawbridgeHideError2('folio-drawbridge-vault-modal-error');
	document.getElementById('folio-drawbridge-modal-vault').style.display='flex';
}
function folioDrawbridgeOpenUploadModal(vaultId) {
	folioDrawbridgeUserData.activeVaultId = vaultId;
	document.getElementById('folio-drawbridge-file-input').value='';
	document.getElementById('folio-drawbridge-upload-queue').innerHTML='';
	document.getElementById('folio-drawbridge-upload-btn').disabled=false;
	document.getElementById('folio-drawbridge-upload-cancel-btn').disabled=false;
	folioDrawbridgeHideError2('folio-drawbridge-upload-modal-error');
	document.getElementById('folio-drawbridge-modal-upload').style.display='flex';
}
function folioDrawbridgeOpenShareModal(vaultId) {
	folioDrawbridgeUserData.activeVaultId = vaultId;
	var lim = folioDrawbridgeUserData.shareLimits;
	var dlEl = document.getElementById('folio-drawbridge-share-maxdl');
	var exEl = document.getElementById('folio-drawbridge-share-expires');
	document.getElementById('folio-drawbridge-share-email').value = '';
	dlEl.value = lim.defaultDl;
	dlEl.min   = lim.dlMin;
	if (lim.dlMax > 0) { dlEl.max = lim.dlMax; } else { dlEl.removeAttribute('max'); }
	exEl.value = lim.defaultExpiry ? lim.defaultExpiry.substring(0,10) : '';
	if (lim.expiryMax) { exEl.max = lim.expiryMax.substring(0,10); } else { exEl.removeAttribute('max'); }
	if (lim.expiryRequired) { exEl.setAttribute('required',''); } else { exEl.removeAttribute('required'); }
	folioDrawbridgeHideError2('folio-drawbridge-share-modal-error');
	document.getElementById('folio-drawbridge-modal-share').style.display='flex';
}
function folioDrawbridgeCloseModal(id) { document.getElementById(id).style.display='none'; }

function folioDrawbridgeCreateVault() {
	var name    = document.getElementById('folio-drawbridge-vault-name').value.trim();
	var desc    = document.getElementById('folio-drawbridge-vault-desc').value.trim();
	var expires = document.getElementById('folio-drawbridge-vault-expires').value;
	if (!name) { folioDrawbridgeShowError2('folio-drawbridge-vault-modal-error','Vault name is required.'); return; }
	folioDrawbridgeUserPost({ action:'folio_drawbridge_create_vault', name:name, desc:desc, expires_at:expires, _wpnonce:folioDrawbridgeUserData.nonce })
		.then(function(r) {
			if (r.success) { folioDrawbridgeCloseModal('folio-drawbridge-modal-vault'); folioDrawbridgeShowNotice('Vault created. Reloading…','success'); setTimeout(function(){ location.reload(); },1200); }
			else { folioDrawbridgeShowError2('folio-drawbridge-vault-modal-error', r.data||'Error creating vault.'); }
		});
}

function folioDrawbridgeGenerateUploadId() {
	return Array.from(crypto.getRandomValues(new Uint8Array(16)))
		.map(function(b){ return b.toString(16).padStart(2,'0'); }).join('');
}

function folioDrawbridgeMvEsc(s) {
	return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function folioDrawbridgeMvMakeQueueRow(fileName) {
	var row = document.createElement('div');
	row.style.cssText = 'margin-bottom:6px;padding:7px 10px;background:#f6f7f7;border-radius:4px;font-size:12px;';
	row.innerHTML =
		'<div style="display:flex;justify-content:space-between;margin-bottom:4px;">'
		+ '<span style="font-weight:600;word-break:break-all;">' + folioDrawbridgeMvEsc(fileName) + '</span>'
		+ '<span class="folio-drawbridge-mv-qlbl" style="color:#888;white-space:nowrap;margin-left:8px;">Queued</span></div>'
		+ '<div style="background:#e0e0e0;border-radius:3px;height:7px;overflow:hidden;">'
		+ '<div class="folio-drawbridge-mv-qbar" style="background:#2271b1;height:100%;width:0%;transition:width .15s;"></div></div>';
	return row;
}

async function folioDrawbridgeUploadOneFile(file, rowEl) {
	var bar   = rowEl.querySelector('.folio-drawbridge-mv-qbar');
	var lbl   = rowEl.querySelector('.folio-drawbridge-mv-qlbl');
	// wp_localize_script() casts every top-level scalar to a string, so this
	// arrives as "4194304". Number() is required before it is used in addition:
	// "start + CHUNK" would otherwise concatenate rather than add, and every
	// chunk after the first would be sliced to the end of the file.
	var CHUNK = Number(folioDrawbridgeUserData.chunkSize);
	if (!CHUNK || CHUNK < 1) { throw new Error('Upload chunk size is not configured correctly.'); }
	var total = Math.ceil(file.size / CHUNK) || 1;
	var uid   = folioDrawbridgeGenerateUploadId();
	lbl.textContent = 'Uploading…';
	for (var i = 0; i < total; i++) {
		var start = i * CHUNK;
		var fd    = new FormData();
		fd.append('action',       'folio_drawbridge_upload_chunk');
		fd.append('_wpnonce',     folioDrawbridgeUserData.nonce);
		fd.append('vault_id',     folioDrawbridgeUserData.activeVaultId);
		fd.append('upload_id',    uid);
		fd.append('chunk_index',  i);
		fd.append('total_chunks', total);
		fd.append('file_name',    file.name);
		fd.append('total_size',   file.size);
		fd.append('chunk',        file.slice(start, Math.min(start + CHUNK, file.size)), file.name);
		var r = await fetch(folioDrawbridgeUserData.ajaxUrl, {method:'POST', body:fd});
		var j = await r.json();
		if (!j.success) throw new Error(j.data || 'Upload failed.');
		var pct = Math.round((i + 1) / total * 100);
		bar.style.width = pct + '%';
		lbl.textContent = j.data.complete ? 'Done' : pct + '%';
	}
}

async function folioDrawbridgeUploadFile() {
	var input   = document.getElementById('folio-drawbridge-file-input');
	var queueEl = document.getElementById('folio-drawbridge-upload-queue');
	folioDrawbridgeHideError2('folio-drawbridge-upload-modal-error');
	if (!input.files.length) { folioDrawbridgeShowError2('folio-drawbridge-upload-modal-error','Please select at least one file.'); return; }

	var btn = document.getElementById('folio-drawbridge-upload-btn');
	var ccl = document.getElementById('folio-drawbridge-upload-cancel-btn');
	btn.disabled = true;
	ccl.disabled = true;
	queueEl.innerHTML = '';

	var files = Array.from(input.files);
	var rows  = files.map(function(f) {
		var row = folioDrawbridgeMvMakeQueueRow(f.name);
		queueEl.appendChild(row);
		return row;
	});

	var hasError = false;
	for (var i = 0; i < files.length; i++) {
		try {
			await folioDrawbridgeUploadOneFile(files[i], rows[i]);
			rows[i].querySelector('.folio-drawbridge-mv-qlbl').style.color = '#0a3622';
		} catch(e) {
			var lbl = rows[i].querySelector('.folio-drawbridge-mv-qlbl');
			lbl.textContent = 'Error: ' + e.message;
			lbl.style.color = '#d63638';
			hasError = true;
		}
	}

	if (!hasError) {
		folioDrawbridgeCloseModal('folio-drawbridge-modal-upload');
		folioDrawbridgeShowNotice(files.length + ' file(s) encrypted and uploaded. Reloading…', 'success');
		setTimeout(function(){ location.reload(); }, 1400);
	} else {
		btn.disabled = false;
		ccl.disabled = false;
	}
}

function folioDrawbridgeCreateShare() {
	var email      = document.getElementById('folio-drawbridge-share-email').value.trim();
	var maxdl      = document.getElementById('folio-drawbridge-share-maxdl').value;
	var expiresRaw = document.getElementById('folio-drawbridge-share-expires').value;
	var expires    = expiresRaw ? expiresRaw + ' 23:59:59' : '';
	if (!email) { folioDrawbridgeShowError2('folio-drawbridge-share-modal-error','Recipient email is required.'); return; }
	folioDrawbridgeUserPost({ action:'folio_drawbridge_create_share', vault_id:folioDrawbridgeUserData.activeVaultId, email:email, max_downloads:maxdl, expires_at:expires, _wpnonce:folioDrawbridgeUserData.nonce })
		.then(function(r) {
			if (r.success) { folioDrawbridgeCloseModal('folio-drawbridge-modal-share'); folioDrawbridgeShowNotice('Share invite sent to '+email+'.','success'); setTimeout(function(){ location.reload(); },1500); }
			else { folioDrawbridgeShowError2('folio-drawbridge-share-modal-error', r.data||'Error creating share.'); }
		});
}

function folioDrawbridgeDeleteFile(fileId, vaultId) {
	if (!confirm('Permanently delete this file? This cannot be undone.')) return;
	folioDrawbridgeUserPost({ action:'folio_drawbridge_delete_file', file_id:fileId, vault_id:vaultId, _wpnonce:folioDrawbridgeUserData.nonce })
		.then(function(r) {
			if (r.success) { folioDrawbridgeShowNotice('File deleted.','success'); setTimeout(function(){ location.reload(); },800); }
			else { folioDrawbridgeShowNotice(r.data||'Error deleting file.','error'); }
		});
}

function folioDrawbridgeDeleteVault(vaultId, name) {
	if (!confirm('Permanently delete vault "'+name+'" and all its files? This cannot be undone.')) return;
	folioDrawbridgeUserPost({ action:'folio_drawbridge_delete_vault', vault_id:vaultId, _wpnonce:folioDrawbridgeUserData.nonce })
		.then(function(r) {
			if (r.success) { folioDrawbridgeShowNotice('Vault deleted. Reloading…','success'); setTimeout(function(){ location.reload(); },900); }
			else { folioDrawbridgeShowNotice(r.data||'Error deleting vault.','error'); }
		});
}

function folioDrawbridgeRevokeShare(shareId, vaultId) {
	if (!confirm('Revoke this share? The recipient will immediately lose access.')) return;
	folioDrawbridgeUserPost({ action:'folio_drawbridge_revoke_share', share_id:shareId, vault_id:vaultId, _wpnonce:folioDrawbridgeUserData.nonce })
		.then(function(r) {
			if (r.success) { folioDrawbridgeShowNotice('Share revoked. Reloading…','success'); setTimeout(function(){ location.reload(); },900); }
			else { folioDrawbridgeShowNotice(r.data||'Error revoking share.','error'); }
		});
}

function folioDrawbridgeUserPost(data) {
	var body = new URLSearchParams();
	Object.keys(data).forEach(function(k){ body.append(k, data[k]); });
	return fetch(folioDrawbridgeUserData.ajaxUrl,{method:'POST',body:body,headers:{'Content-Type':'application/x-www-form-urlencoded'}}).then(function(r){return r.json();});
}
function folioDrawbridgeShowError2(id, msg) { var el=document.getElementById(id); el.textContent=msg; el.style.display=''; }
function folioDrawbridgeHideError2(id) { document.getElementById(id).style.display='none'; }
function folioDrawbridgeShowNotice(msg,type) {
	var el=document.getElementById('folio-drawbridge-mv-notice');
	el.className='folio-drawbridge-mv-alert folio-drawbridge-mv-alert-'+(type==='success'?'success':'error');
	el.textContent=msg; el.style.display='';
	setTimeout(function(){ el.style.display='none'; },4000);
}
