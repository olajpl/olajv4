<div id="live-overlay" style="position:absolute;bottom:20px;right:20px;z-index:1000;
     background:white;border-radius:12px;padding:12px;box-shadow:0 4px 10px rgba(0,0,0,0.15);
     max-width:250px;display:none;">
    <img id="live-offer-img" src="" alt="" style="width:100%;border-radius:8px;">
    <div id="live-offer-name" style="font-weight:bold;margin-top:8px;"></div>
    <div id="live-offer-price" style="color:#e60023;font-size:18px;margin-top:4px;"></div>
    <button id="live-offer-add" style="margin-top:8px;width:100%;background:#28a745;color:white;
            border:none;padding:8px;border-radius:6px;cursor:pointer;">
        ➕ Dodaj
    </button>
</div>

<script>
const liveId = <?= (int)($_GET['live_id'] ?? 0) ?>;
function refreshOffer(){
    fetch('/api/live/get_active_offer.php?live_id='+liveId)
        .then(r=>r.json())
        .then(data=>{
            if(data.success){
                document.getElementById('live-overlay').style.display='block';
                document.getElementById('live-offer-name').innerText = data.offer.name;
                document.getElementById('live-offer-price').innerText = data.offer.price_formatted;
                document.getElementById('live-offer-img').src = data.offer.image_url || '/img/no-image.png';
                document.getElementById('live-offer-add').onclick = ()=>{
                    fetch('/api/cart/add.php',{
                        method:'POST',
                        headers:{'Content-Type':'application/json'},
                        body:JSON.stringify({
                            product_id:data.offer.id,
                            quantity:1,
                            context:'live',
                            live_id:liveId
                        })
                    }).then(()=>alert('Dodano do koszyka!'));
                };
            } else {
                document.getElementById('live-overlay').style.display='none';
            }
        });
}
setInterval(refreshOffer, 5000);
refreshOffer();
</script>
