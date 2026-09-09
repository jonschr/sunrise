/* Inventory refreshes are sequential; an unavailable peer does not block the others. */
(() => {
    const button = document.getElementById('sunrise-refresh-network');
    if (!button) return;
    const status = document.getElementById('sunrise-refresh-status');
    button.addEventListener('click', async () => {
        button.disabled = true;
        for (const [index, id] of sunriseNetwork.sites.entries()) {
            status.textContent = `${sunriseNetwork.refreshing} ${index + 1} / ${sunriseNetwork.sites.length}…`;
            try {
                await fetch(sunriseNetwork.url + encodeURIComponent(id), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'X-WP-Nonce': sunriseNetwork.nonce},
                });
            } catch (error) {
                // The per-site status retains the last usable snapshot; continue with the other peers.
            }
        }
        status.textContent = sunriseNetwork.finished;
        window.location.reload();
    });
})();
