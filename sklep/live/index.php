<?php
// $stream_url = URL do posta/live na FB (musi być PUBLIC)
$stream_url = $row['stream_url'] ?? '';
$isFb = (strpos($row['platform'] ?? '', 'facebook') === 0);
if ($isFb && $stream_url) {
    $embed = 'https://www.facebook.com/plugins/video.php?href='
        . rawurlencode($stream_url)
        . '&show_text=false&autoplay=true&mute=0';
?>
    <div id="fb-live-wrap" style="position:relative;max-width:900px;margin:0 auto;">
        <div id="fb-live-placeholder"
            style="background:#000;color:#fff;display:flex;align-items:center;justify-content:center;height:56.25vw;max-height:506px;max-width:900px;border-radius:12px;overflow:hidden;">
            <button id="fb-live-load"
                style="background:#1877f2;color:#fff;border:0;padding:12px 18px;border-radius:8px;cursor:pointer;">
                ▶️ Pokaż LIVE
            </button>
        </div>
        <noscript>
            <iframe src="<?= htmlspecialchars($embed) ?>" width="900" height="506" style="border:none;overflow:hidden"
                scrolling="no" frameborder="0" allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share"
                allowfullscreen loading="lazy" referrerpolicy="origin-when-cross-origin"></iframe>
        </noscript>
    </div>
    <script>
        (function() {
            const btn = document.getElementById('fb-live-load');
            const ph = document.getElementById('fb-live-placeholder');
            let tries = 0,
                loaded = false;

            function mount() {
                if (loaded) return;
                loaded = true;
                const url = <?= json_encode($embed) ?>;
                const ifr = document.createElement('iframe');
                ifr.src = url;
                ifr.width = 900;
                ifr.height = 506;
                ifr.style.border = 'none';
                ifr.style.overflow = 'hidden';
                ifr.style.width = '100%';
                ifr.style.aspectRatio = '16/9';
                ifr.allow = 'autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share';
                ifr.setAttribute('allowfullscreen', 'true');
                ifr.setAttribute('loading', 'lazy');
                ifr.referrerPolicy = 'origin-when-cross-origin';
                ph.replaceChildren(ifr);
                // timeout: jeśli FB nie dociągnie, pokaż fallback
                setTimeout(() => {
                    if (!ifr.contentWindow || ifr.contentWindow.length === 0) showFallback();
                }, 6000);
            }

            function showFallback() {
                if (tries++ > 0) return;
                ph.innerHTML = `<div style="text-align:center;padding:18px;color:#fff;">
          Nie udało się wczytać odtwarzacza Facebooka.<br>
          <a href="<?= htmlspecialchars($stream_url) ?>" target="_blank" rel="noopener"
             style="display:inline-block;margin-top:10px;background:#1877f2;color:#fff;padding:10px 14px;border-radius:8px;">
             Otwórz LIVE na Facebooku
          </a>
        </div>`;
            }
            // click-to-load
            btn?.addEventListener('click', mount);

            // lazy load po wejściu w viewport
            if ('IntersectionObserver' in window) {
                const obs = new IntersectionObserver((ents) => {
                    if (ents.some(e => e.isIntersecting)) {
                        mount();
                        obs.disconnect();
                    }
                }, {
                    rootMargin: '200px'
                });
                obs.observe(ph);
            }
        })();
    </script>
<?php
}
?>