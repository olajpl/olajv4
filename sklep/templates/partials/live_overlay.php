<?php if (!empty($active_live_id)): ?>
    <div id="live-overlay" style="position:fixed;bottom:20px;right:20px;z-index:1000;background:white;border-radius:12px;padding:12px;box-shadow:0 4px 10px rgba(0,0,0,0.15);max-width:250px;display:none;">
        <img id="live-offer-img" src="" alt="" style="width:100%;border-radius:8px;">
        <div id="live-offer-name" style="font-weight:bold;margin-top:8px;"></div>
        <div id="live-offer-price" style="color:#e60023;font-size:18px;margin-top:4px;"></div>
        <button id="live-offer-add" style="margin-top:8px;width:100%;background:#28a745;color:white;border:none;padding:8px;border-radius:6px;cursor:pointer;">➕ Dodaj</button>
    </div>
<?php endif; ?>