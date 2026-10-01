/* Shared interactions. No dependencies; navigation and language forms work without JS. */
function toggleDisclosure(button, expanded) {
    const content = document.getElementById(button.getAttribute('aria-controls'));
    if (!content) return;
    button.setAttribute('aria-expanded', String(expanded));
    button.classList.toggle('collapsed', !expanded);
    content.hidden = !expanded;
}
function toggleSection(button) {
    toggleDisclosure(button, button.getAttribute('aria-expanded') !== 'true');
}
let dialogOpener = null;
function openWorkspaceDialog(dialog) {
    dialogOpener = document.activeElement;
    dialog.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    dialog.querySelector('button, [tabindex]')?.focus();
}
function closeWorkspaceDialog(dialog) {
    dialog.style.display = 'none';
    document.body.style.overflow = '';
    dialogOpener?.focus();
}
function gbsShowDetails(title, content) {
    document.getElementById('modal-title').textContent = title;
    document.getElementById('modal-body').innerHTML = content;
    openWorkspaceDialog(document.getElementById('modal'));
}
function gbsCloseDetails() { closeWorkspaceDialog(document.getElementById('modal')); }
function notifyWorkspace(message) {
    const toast = document.getElementById('workspace-toast');
    toast.textContent = message;
    toast.hidden = false;
    clearTimeout(notifyWorkspace.timer);
    notifyWorkspace.timer = setTimeout(() => { toast.hidden = true; }, 4500);
}
async function copyAssetUrl(url) {
    try {
        await navigator.clipboard.writeText(url);
        notifyWorkspace('Link copied to clipboard');
    } catch {
        const dialog = document.getElementById('copy-dialog');
        const input = document.getElementById('copy-url');
        input.value = url;
        if (document.getElementById('lightbox').style.display === 'flex') {
            closeWorkspaceDialog(document.getElementById('lightbox'));
        }
        openWorkspaceDialog(dialog);
        input.focus();
        input.select();
    }
}
document.addEventListener('DOMContentLoaded', () => {
    const search = document.getElementById('workspace-search');
    const cards = [...document.querySelectorAll('[data-search-item]')];
    const disclosures = [...document.querySelectorAll('[data-disclosure]')];
    const expand = document.getElementById('expand-all');
    const noResults = document.getElementById('no-results');
    let beforeSearch = null;
    const updateExpandLabel = () => {
        if (expand) expand.textContent = disclosures.length && disclosures.every(b => b.getAttribute('aria-expanded') === 'true') ? 'Collapse all' : 'Expand all';
    };
    disclosures.forEach(button => button.addEventListener('click', () => {
        toggleSection(button);
        updateExpandLabel();
    }));
    expand?.addEventListener('click', () => {
        const open = expand.textContent === 'Expand all';
        disclosures.forEach(button => toggleDisclosure(button, open));
        updateExpandLabel();
    });
    function filter() {
        const query = search.value.trim().toLocaleLowerCase();
        if (query && beforeSearch === null) beforeSearch = disclosures.map(b => b.getAttribute('aria-expanded') === 'true');
        let visible = 0;
        cards.forEach(card => {
            const matches = (card.dataset.search || card.textContent).toLocaleLowerCase().includes(query);
            card.hidden = !matches;
            if (matches) visible++;
        });
        document.querySelectorAll('[data-search-group]').forEach(group => {
            group.hidden = (Boolean(query) || Boolean(group.closest('#status-tab'))) && ![...group.querySelectorAll('[data-search-item]')].some(item => !item.hidden);
        });
        if (query) {
            disclosures.forEach(button => toggleDisclosure(button, true));
        } else if (beforeSearch !== null) {
            disclosures.forEach((button, i) => toggleDisclosure(button, beforeSearch[i]));
            beforeSearch = null;
        }
        document.getElementById('result-count').hidden = !query;
        document.getElementById('result-count').textContent = visible + ' of ' + cards.length + ' ' + (search.dataset.unit || 'results');
        if (noResults) noResults.hidden = visible > 0 || (!query && cards.length === 0);
        updateExpandLabel();
    }
    if (search) {
        search.addEventListener('input', filter);
        document.getElementById('clear-search')?.addEventListener('click', () => { search.value = ''; filter(); search.focus(); });
        filter();
    }
    document.querySelectorAll('.modal[role="dialog"], .lightbox[role="dialog"]').forEach(dialog => {
        dialog.addEventListener('click', event => { if (event.target === dialog) closeWorkspaceDialog(dialog); });
        dialog.addEventListener('keydown', event => {
            if (event.key === 'Escape') { closeWorkspaceDialog(dialog); return; }
            if (event.key !== 'Tab') return;
            const focusable = [...dialog.querySelectorAll('button, a[href], input, select, textarea, [tabindex="0"]')].filter(el => el.getClientRects().length);
            const first = focusable[0], last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        });
    });
    const lightbox = document.getElementById('lightbox');
    if (lightbox) {
        const preview = document.getElementById('lightbox-img');
        let copyUrl = '';
        document.querySelectorAll('.preview-button').forEach(button => button.addEventListener('click', () => {
            const image = button.querySelector('img');
            preview.src = image.currentSrc || image.src;
            preview.alt = image.alt;
            copyUrl = image.dataset.copyUrl || image.src;
            document.getElementById('lightbox-title').textContent = image.alt;
            openWorkspaceDialog(lightbox);
        }));
        document.getElementById('copy-preview').addEventListener('click', () => copyAssetUrl(copyUrl));
        preview.addEventListener('click', async () => { closeWorkspaceDialog(lightbox); await copyAssetUrl(copyUrl); });
        document.getElementById('close-preview').addEventListener('click', () => closeWorkspaceDialog(lightbox));
        document.querySelectorAll('.image-item a').forEach(link => link.addEventListener('click', event => {
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            copyAssetUrl(link.href);
        }));
        document.getElementById('close-copy').addEventListener('click', () => closeWorkspaceDialog(document.getElementById('copy-dialog')));
    }
    const buildTime = document.getElementById('build-time');
    if (buildTime) {
        const date = new Date(buildTime.dataset.time);
        buildTime.textContent = Number.isNaN(date.getTime()) ? 'Local preview' : 'Updated ' + date.toLocaleString([], {dateStyle:'medium', timeStyle:'short'});
    }
});
