(function () {
	var root = document.getElementById("navne-run-detail");
	if (!root) {
		return;
	}

	var restUrl = root.getAttribute("data-rest-url");
	var nonce   = root.getAttribute("data-nonce");
	var statusEl = root.querySelector(".navne-run-status");
	var countsEl = root.querySelector(".navne-run-counts");
	var failedEl = root.querySelector(".navne-run-failed");

	var terminalStatuses = ["complete", "cancelled"];
	var pollHandle = null;

	function render(data) {
		statusEl.textContent = data.status;
		countsEl.textContent = data.processed + " / " + data.total + " processed · " + data.failed + " failed";

		failedEl.innerHTML = "";
		(data.failed_items || []).forEach(function (item) {
			var li = document.createElement("li");
			var strong = document.createElement("strong");
			strong.textContent = item.post_title || ("Post " + item.post_id);
			li.appendChild(strong);
			li.appendChild(document.createTextNode(" (ID " + item.post_id + ") — " + (item.error_message || "")));
			failedEl.appendChild(li);
		});

		if (terminalStatuses.indexOf(data.status) !== -1 && pollHandle !== null) {
			clearInterval(pollHandle);
			pollHandle = null;
		}
	}

	function tick() {
		fetch(restUrl, {
			credentials: "same-origin",
			headers: { "X-WP-Nonce": nonce }
		})
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (data) { if (data) { render(data); } })
			.catch(function () { /* transient errors are fine; next tick retries */ });
	}

	tick();
	pollHandle = setInterval(tick, 2000);
})();
