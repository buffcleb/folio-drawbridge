/*
 * Folio Drawbridge — admin panel behaviour: sortable tables and
 * decrypted-file download.
 *
 * Extracted from inline output. Values that depend on PHP arrive via
 * wp_localize_script() as the folioDrawbridgeAdmin object.
 */

function folioDrawbridgeAdminDownload(fileId) {
	var url = folioDrawbridgeAdmin.ajaxUrl
		+ '?action=folio_drawbridge_admin_download&file_id=' + fileId
		+ '&_wpnonce=' + encodeURIComponent(folioDrawbridgeAdmin.downloadNonce);
	window.location.href = url;
}

/**
 * Client-side table sort. Keeps data-subrow rows paired with their parent.
 * Call after DOM ready: folioDrawbridgeSortTable('my-table-id')
 */
function folioDrawbridgeSortTable(tableId) {
	var tbl = document.getElementById(tableId);
	if (!tbl) return;
	var headers = tbl.querySelectorAll('thead th');
	headers.forEach(function(th, colIdx) {
		if (th.dataset.nosort !== undefined) return;
		th.style.cursor = 'pointer';
		th.style.userSelect = 'none';
		var ind = document.createElement('span');
		ind.className = 'folio-drawbridge-sort-ind';
		ind.textContent = ' ↕';
		th.appendChild(ind);
		var asc = true;
		th.addEventListener('click', function() {
			// Reset all indicators.
			headers.forEach(function(h) {
				var i = h.querySelector('.folio-drawbridge-sort-ind');
				if (i) { i.textContent = ' ↕'; i.classList.remove('active'); }
			});
			ind.textContent = asc ? ' ↑' : ' ↓';
			ind.classList.add('active');

			// Collect primary rows (not data-subrow), each with its trailing sub-rows.
			var tbody = tbl.querySelector('tbody') || tbl;
			var allRows = Array.from(tbody.querySelectorAll('tr'));
			var groups = [];
			allRows.forEach(function(row) {
				if (row.dataset.subrow !== undefined) {
					if (groups.length) groups[groups.length - 1].sub.push(row);
				} else {
					groups.push({ row: row, sub: [] });
				}
			});

			// Sort groups by cell text, numeric if possible.
			groups.sort(function(a, b) {
				var ca = a.row.cells[colIdx] ? a.row.cells[colIdx].textContent.trim() : '';
				var cb = b.row.cells[colIdx] ? b.row.cells[colIdx].textContent.trim() : '';
				var na = parseFloat(ca.replace(/[^0-9.\-]/g, ''));
				var nb = parseFloat(cb.replace(/[^0-9.\-]/g, ''));
				if (!isNaN(na) && !isNaN(nb)) return asc ? na - nb : nb - na;
				return asc ? ca.localeCompare(cb) : cb.localeCompare(ca);
			});

			// Re-append rows in sorted order.
			groups.forEach(function(g) {
				tbody.appendChild(g.row);
				g.sub.forEach(function(s) { tbody.appendChild(s); });
			});

			asc = !asc;
		});
	});
}



/**
 * Toggles an inline edit row (vault inspector).
 */
function folioDrawbridgeAdmToggle(id) {
	var el = document.getElementById(id);
	if (el) { el.style.display = el.style.display === 'none' ? '' : 'none'; }
}

/**
 * Expands or collapses the vault list beneath a user row (Users tab).
 */
function folioDrawbridgeToggleUserVaults(userId, link) {
	var row = document.getElementById('folio-drawbridge-user-vaults-' + userId);
	if (!row) return false;
	var open = row.style.display !== 'none';
	row.style.display = open ? 'none' : '';
	var arrow = link.querySelector('span');
	if (arrow) arrow.textContent = open ? '\u25b8' : '\u25be';
	return false;
}

/*
 * Initialise every table that opted in via data-folio-drawbridge-sortable.
 * Declaring it in the markup means no page has to emit its own init script —
 * which previously forced an inline <script> per screen purely to interpolate
 * the vault id into a table id.
 */
document.addEventListener('DOMContentLoaded', function () {
	var tables = document.querySelectorAll('table[data-folio-drawbridge-sortable]');
	Array.prototype.forEach.call(tables, function (table) {
		if (table.id) { folioDrawbridgeSortTable(table.id); }
	});
});
