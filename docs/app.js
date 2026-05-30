(() => {
    const renderHealthUrl = 'https://telegrammovies.onrender.com/health';
    const botUsername = 'moviesstorehdbot';
    const botUrl = `https://t.me/${botUsername}`;
    const heartbeatMs = 4 * 60 * 1000;

    const tg = window.Telegram?.WebApp;
    const wakeStatus = document.querySelector('#wakeStatus');
    const wakeDetail = document.querySelector('#wakeDetail');
    const searchForm = document.querySelector('#searchForm');
    const movieQuery = document.querySelector('#movieQuery');
    const searchHint = document.querySelector('#searchHint');

    function base64Url(text) {
        const bytes = new TextEncoder().encode(text);
        let binary = '';
        bytes.forEach((byte) => {
            binary += String.fromCharCode(byte);
        });

        return btoa(binary)
            .replace(/\+/g, '-')
            .replace(/\//g, '_')
            .replace(/=+$/g, '')
            .slice(0, 56);
    }

    function setWakeStatus(title, detail, state = '') {
        if (wakeStatus) {
            wakeStatus.textContent = title;
        }

        if (wakeDetail) {
            wakeDetail.textContent = detail;
        }

        document.body.dataset.wake = state;
    }

    async function pingRender() {
        const controller = new AbortController();
        const timeoutId = window.setTimeout(() => controller.abort(), 25000);

        try {
            const response = await fetch(`${renderHealthUrl}?source=mini-site&t=${Date.now()}`, {
                cache: 'no-store',
                mode: 'cors',
                signal: controller.signal,
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || payload.ok !== true) {
                throw new Error(`HTTP ${response.status}`);
            }

            const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            setWakeStatus('Online', `Last wake ping worked at ${time}.`, 'online');
        } catch (error) {
            setWakeStatus('Waking...', 'Render may be starting. Try the bot again in a moment.', 'pending');
        } finally {
            window.clearTimeout(timeoutId);
        }
    }

    function openBotWithQuery(query) {
        const url = query ? `${botUrl}?start=q_${base64Url(query)}` : botUrl;
        if (tg?.openTelegramLink) {
            tg.openTelegramLink(url);
            return;
        }

        window.open(url, '_blank', 'noopener,noreferrer');
    }

    function submitSearch(query) {
        if (tg?.sendData) {
            try {
                tg.sendData(JSON.stringify({ type: 'search', query }));
                searchHint.textContent = 'Sent to the bot. Check the chat for results.';
                window.setTimeout(() => tg.close?.(), 450);
                return;
            } catch (error) {
                searchHint.textContent = 'Opening the bot with your search.';
            }
        }

        searchHint.textContent = 'Opening the bot with your search.';
        openBotWithQuery(query);
    }

    if (tg) {
        document.body.classList.add('inside-telegram');
        tg.ready();
        tg.expand?.();
        tg.setHeaderColor?.('#07121f');
        tg.setBackgroundColor?.('#07121f');
    }

    searchForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        const query = movieQuery?.value.trim() ?? '';
        if (query === '') {
            movieQuery?.focus();
            searchHint.textContent = 'Type a movie name first.';
            return;
        }

        submitSearch(query);
    });

    pingRender();
    window.setInterval(pingRender, heartbeatMs);
})();
