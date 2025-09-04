
export default function initClients() {
  const $productList = document.querySelector('#liveProductList');
  const $form = document.querySelector('#addProductForm');
  if (!$productList || !$form) return;

  window.fetchAndRenderProductList = () => {
    fetch('ajax/ajax_live_temp_list.php?live_id=' + $form.live_id.value)
      .then(res => res.text())
      .then(html => { $productList.innerHTML = html; });
  };

  window.fetchAndRenderProductList();
  setInterval(() => {
    window.fetchAndRenderProductList();
  }, 5000);
}
